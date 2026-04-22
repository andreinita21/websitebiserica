<?php
/**
 * Backup & restore — shared library.
 *
 * Reusable functions for building, validating, restoring and auto-pruning
 * site backup archives. Two front-ends call into this file:
 *
 *   - /admin/backup.php  (interactive export/restore + daily status UI)
 *   - /bin/backup-daily.php (CLI cron entry point for nightly backups)
 *
 * The admin layout also fires bsv_backup_run_daily_if_due() from a shutdown
 * hook so the daily backup still runs on sites without crontab access.
 *
 * Concurrency: flock() on data/backups/.daily.lock serialises the daily job.
 * Schema validation and ZipSlip protection live in the import path.
 */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Archive format + policy
// ---------------------------------------------------------------------------

if (!defined('BSV_BACKUP_APP'))        define('BSV_BACKUP_APP',        'BisericaSfVasile');
if (!defined('BSV_BACKUP_FORMAT_VER')) define('BSV_BACKUP_FORMAT_VER', 1);
if (!defined('BSV_BACKUP_MAX_UPLOAD')) define('BSV_BACKUP_MAX_UPLOAD', 500 * 1024 * 1024);
if (!defined('BSV_BACKUP_KEEP_SNAPS')) define('BSV_BACKUP_KEEP_SNAPS', 5);   // pre-restore history
if (!defined('BSV_BACKUP_KEEP_DAILY')) define('BSV_BACKUP_KEEP_DAILY', 14);  // one zip per day
if (!defined('BSV_BACKUP_CONFIRM'))    define('BSV_BACKUP_CONFIRM',    'RESTAUREAZA');

if (!defined('BSV_REQUIRED_TABLES')) {
    define('BSV_REQUIRED_TABLES', [
        'events', 'event_categories', 'event_locations',
        'announcements',
        'gallery_categories', 'gallery_photos', 'gallery_photo_categories',
        'clergy',
        'site_settings',
    ]);
}

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

function bsv_backup_project_root(): string
{
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}
function bsv_backup_db_path(): string     { return APP_DB_PATH; }
function bsv_backup_uploads_dir(): string { return bsv_backup_project_root() . '/uploads'; }

function bsv_backup_snapshots_dir(): string
{
    $dir = dirname(APP_DB_PATH) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

// ---------------------------------------------------------------------------
// Build a fresh archive
// ---------------------------------------------------------------------------

/**
 * Assemble a backup archive at $zipPath. Contains a clean DB snapshot taken
 * with VACUUM INTO (so WAL/transactions are flushed), the uploads tree, and
 * a manifest describing the archive.
 */
function bsv_backup_build_zip(string $zipPath): void
{
    $root       = bsv_backup_project_root();
    $dbSource   = bsv_backup_db_path();
    $uploadsDir = bsv_backup_uploads_dir();

    $snapDir = bsv_backup_tempdir('bsv-db-');
    $dbSnap  = $snapDir . '/events.db';
    bsv_backup_snapshot_db($dbSource, $dbSnap);

    $uploadFiles = is_dir($uploadsDir) ? bsv_backup_list_files($uploadsDir) : [];

    $manifest = [
        'app'          => BSV_BACKUP_APP,
        'version'      => BSV_BACKUP_FORMAT_VER,
        'created_at'   => date('c'),
        'db_file'      => 'data/events.db',
        'uploads_dir'  => 'uploads',
        'file_counts'  => [
            'db'      => 1,
            'uploads' => count($uploadFiles),
        ],
    ];

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        bsv_backup_rrmdir($snapDir);
        throw new RuntimeException('ZipArchive::open a eșuat.');
    }

    try {
        $zip->addFile($dbSnap, 'data/events.db');
        foreach ($uploadFiles as $absPath) {
            $rel = bsv_backup_relpath($absPath, $root);
            if ($rel === null) continue;
            $zip->addFile($absPath, $rel);
        }
        $zip->addFromString(
            'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    } finally {
        $zip->close();
        bsv_backup_rrmdir($snapDir);
    }
}

/**
 * Copy the live DB to $destPath as a clean, checkpointed snapshot.
 * Uses VACUUM INTO (available on SQLite 3.27+) so callers get a single
 * self-contained file without WAL sidecars.
 */
function bsv_backup_snapshot_db(string $source, string $destPath): void
{
    if (!is_file($source)) {
        touch($destPath);
        return;
    }
    if (file_exists($destPath)) {
        @unlink($destPath);
    }

    $pdo = new PDO('sqlite:' . $source);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $literal = "'" . str_replace("'", "''", $destPath) . "'";
    $pdo->exec('VACUUM INTO ' . $literal);
    $pdo = null;

    if (!is_file($destPath) || filesize($destPath) === 0) {
        throw new RuntimeException('Snapshot-ul bazei de date a eșuat.');
    }
}

// ---------------------------------------------------------------------------
// Restore — extraction, validation, application
// ---------------------------------------------------------------------------

/**
 * Extract $zipSrc into $destDir while rejecting any ZipSlip-style entries
 * (absolute paths, traversal, backslashes) and any unexpected top-level
 * directories. Only "data/events.db", "uploads/..." and "manifest.json"
 * are allowed.
 */
function bsv_backup_extract_zip_safely(string $zipSrc, string $destDir): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipSrc) !== true) {
        throw new RuntimeException('Arhiva nu a putut fi deschisă.');
    }

    $destReal = realpath($destDir);
    if ($destReal === false) {
        $zip->close();
        throw new RuntimeException('Director temporar invalid.');
    }

    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            $norm = str_replace('\\', '/', $name);
            if ($norm === '' || $norm[0] === '/' || strpos($norm, '..') !== false) {
                throw new RuntimeException('Cale nesigură în arhivă: ' . $name);
            }

            $allowed = ($norm === 'manifest.json')
                    || ($norm === 'data/events.db')
                    || (strpos($norm, 'uploads/') === 0);
            if (!$allowed) continue;

            $target = $destReal . '/' . $norm;
            $isDir  = substr($norm, -1) === '/';

            if ($isDir) {
                if (!is_dir($target) && !mkdir($target, 0775, true)) {
                    throw new RuntimeException('Nu am putut crea directorul: ' . $norm);
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
                throw new RuntimeException('Nu am putut crea directorul: ' . $parent);
            }

            $in  = $zip->getStream($name);
            if (!$in) throw new RuntimeException('Nu am putut citi: ' . $norm);
            $out = fopen($target, 'wb');
            if (!$out) { fclose($in); throw new RuntimeException('Nu am putut scrie: ' . $norm); }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
    } finally {
        $zip->close();
    }
}

/**
 * After extraction: check the manifest, the DB magic header, and verify that
 * the required tables exist. Throws on any mismatch.
 */
function bsv_backup_validate_extracted(string $workDir): void
{
    $manifestPath = $workDir . '/manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Arhiva nu conține manifest.json.');
    }
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Manifest invalid.');
    }
    if (($manifest['app'] ?? null) !== BSV_BACKUP_APP) {
        throw new RuntimeException('Arhiva nu provine din această aplicație.');
    }
    if ((int)($manifest['version'] ?? 0) > BSV_BACKUP_FORMAT_VER) {
        throw new RuntimeException('Versiunea arhivei este mai nouă decât aplicația.');
    }

    $dbPath = $workDir . '/data/events.db';
    if (!is_file($dbPath)) {
        throw new RuntimeException('Arhiva nu conține baza de date.');
    }

    $fh = fopen($dbPath, 'rb');
    $head = $fh ? fread($fh, 16) : '';
    if ($fh) fclose($fh);
    if (strpos($head, 'SQLite format 3') !== 0) {
        throw new RuntimeException('Fișierul events.db nu este o bază de date SQLite validă.');
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;
    } catch (Throwable $e) {
        throw new RuntimeException('Baza de date din arhivă nu poate fi deschisă.');
    }

    $have = array_map('strval', (array)$rows);
    $missing = array_diff(BSV_REQUIRED_TABLES, $have);
    if (!empty($missing)) {
        throw new RuntimeException('Tabele lipsă în arhivă: ' . implode(', ', $missing));
    }
}

/**
 * Replace the live DB and uploads tree with the freshly extracted copy.
 */
function bsv_backup_apply_restore(string $workDir): void
{
    $livDb   = bsv_backup_db_path();
    $newDb   = $workDir . '/data/events.db';
    $livUpl  = bsv_backup_uploads_dir();
    $newUpl  = $workDir . '/uploads';

    $dbDir = dirname($livDb);
    if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true)) {
        throw new RuntimeException('Nu pot crea directorul pentru baza de date.');
    }

    // Remove any stale WAL sidecars — they belong to the OLD DB.
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        $side = $livDb . $suffix;
        if (is_file($side)) @unlink($side);
    }

    if (!@rename($newDb, $livDb)) {
        if (!@copy($newDb, $livDb)) {
            throw new RuntimeException('Nu pot înlocui fișierul events.db.');
        }
        @unlink($newDb);
    }
    @chmod($livDb, 0664);

    if (is_dir($livUpl)) {
        bsv_backup_rrmdir_contents($livUpl);
    } else {
        @mkdir($livUpl, 0775, true);
    }
    if (is_dir($newUpl)) {
        bsv_backup_move_contents($newUpl, $livUpl);
    }
}

// ---------------------------------------------------------------------------
// Snapshot helpers (pre-restore + daily)
// ---------------------------------------------------------------------------

/**
 * Create a pre-restore snapshot of the current site state and prune older
 * ones. Returns the absolute path of the snapshot (or null if the site has
 * no DB yet — in that case there is nothing to protect).
 */
function bsv_backup_auto_snapshot_current(): ?string
{
    if (!is_file(bsv_backup_db_path())) return null;

    $dir = bsv_backup_snapshots_dir();
    $path = $dir . '/pre-restore-' . date('Y-m-d_His') . '.zip';
    bsv_backup_build_zip($path);
    bsv_backup_prune_pattern($dir, 'pre-restore-*.zip', BSV_BACKUP_KEEP_SNAPS);
    return $path;
}

function bsv_backup_prune_pattern(string $dir, string $glob, int $keep): void
{
    $files = glob($dir . '/' . $glob) ?: [];
    if (count($files) <= $keep) return;
    rsort($files);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

/**
 * Safe lookup for any snapshot file (pre-restore OR daily). Accepts only a
 * bare filename that matches one of the two known patterns and exists.
 */
function bsv_backup_resolve_snapshot(?string $name): ?string
{
    if (!is_string($name) || $name === '') return null;
    $ok = preg_match('/^pre-restore-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $name)
       || preg_match('/^daily-\d{4}-\d{2}-\d{2}\.zip$/', $name);
    if (!$ok) return null;
    $path = bsv_backup_snapshots_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

function bsv_backup_daily_path_for(string $dateIso): string
{
    return bsv_backup_snapshots_dir() . '/daily-' . $dateIso . '.zip';
}

/**
 * Return info about the most recently produced daily backup, or null if
 * none exist yet.
 */
function bsv_backup_latest_daily(): ?array
{
    $files = glob(bsv_backup_snapshots_dir() . '/daily-*.zip') ?: [];
    if (!$files) return null;
    rsort($files);
    $p = $files[0];
    return [
        'path'  => $p,
        'name'  => basename($p),
        'size'  => (int)filesize($p),
        'mtime' => (int)filemtime($p),
    ];
}

/**
 * Run the daily backup job if today's zip is missing. Re-entrant and safe
 * to call from multiple requests: the first one to acquire the lock wins,
 * others return 'locked' immediately.
 *
 * Returns one of:
 *   ['status' => 'created', 'path' => ...]   — new backup was produced
 *   ['status' => 'up_to_date']                — already have today's backup
 *   ['status' => 'locked']                    — another run is in progress
 *   ['status' => 'error', 'message' => ...]   — creation failed
 */
function bsv_backup_run_daily_if_due(): array
{
    // Nothing to protect if there is no DB yet.
    if (!is_file(bsv_backup_db_path())) {
        return ['status' => 'up_to_date'];
    }

    $today   = date('Y-m-d');
    $target  = bsv_backup_daily_path_for($today);

    // Cheap fast-path: today's file already exists, no locking needed.
    if (is_file($target) && filesize($target) > 0) {
        return ['status' => 'up_to_date'];
    }

    $lockPath = bsv_backup_snapshots_dir() . '/.daily.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        return ['status' => 'error', 'message' => 'Nu pot crea fișierul de blocare.'];
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['status' => 'locked'];
    }

    try {
        // Re-check inside the lock — another worker may have just finished.
        if (is_file($target) && filesize($target) > 0) {
            return ['status' => 'up_to_date'];
        }

        $tmp = $target . '.partial';
        if (file_exists($tmp)) @unlink($tmp);

        bsv_backup_build_zip($tmp);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return ['status' => 'error', 'message' => 'Nu pot muta arhiva în loc.'];
        }

        bsv_backup_prune_pattern(bsv_backup_snapshots_dir(), 'daily-*.zip', BSV_BACKUP_KEEP_DAILY);

        return ['status' => 'created', 'path' => $target];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Install a shutdown hook that runs the daily backup *after* the HTTP
 * response has been delivered, so admin page loads are not slowed down by
 * the once-per-day zip job. Safe to call from any admin entry point.
 */
function bsv_backup_schedule_daily_after_response(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;

    register_shutdown_function(static function (): void {
        // Stream the response to the client first. Available under php-fpm
        // and apache2handler; a no-op (or absent) on the built-in dev server.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        // Errors in the daily job must never surface on the admin page.
        try { bsv_backup_run_daily_if_due(); } catch (Throwable $e) { /* log silently */ }
    });
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function bsv_backup_tempdir(string $prefix): string
{
    $base = sys_get_temp_dir();
    for ($i = 0; $i < 10; $i++) {
        $dir = $base . '/' . $prefix . bin2hex(random_bytes(6));
        if (@mkdir($dir, 0700, true)) return $dir;
    }
    throw new RuntimeException('Nu am putut crea un director temporar.');
}

function bsv_backup_list_files(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

function bsv_backup_relpath(string $abs, string $root): ?string
{
    $abs  = str_replace('\\', '/', $abs);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (strpos($abs, $root) !== 0) return null;
    return substr($abs, strlen($root));
}

function bsv_backup_rrmdir(string $dir): void
{
    if (!is_dir($dir)) { @unlink($dir); return; }
    $items = scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        is_dir($p) ? bsv_backup_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function bsv_backup_rrmdir_contents(string $dir): void
{
    $items = @scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        is_dir($p) ? bsv_backup_rrmdir($p) : @unlink($p);
    }
}

function bsv_backup_move_contents(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0775, true)) {
        throw new RuntimeException('Nu pot crea: ' . $to);
    }
    $items = @scandir($from);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $from . '/' . $item;
        $dst = $to   . '/' . $item;
        if (is_dir($src)) {
            bsv_backup_move_contents($src, $dst);
            @rmdir($src);
        } else {
            if (!@rename($src, $dst)) {
                if (!@copy($src, $dst)) {
                    throw new RuntimeException('Nu pot muta: ' . $src);
                }
                @unlink($src);
            }
        }
    }
}

function bsv_backup_upload_error_text(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'fișier prea mare';
        case UPLOAD_ERR_PARTIAL:   return 'încărcare incompletă';
        case UPLOAD_ERR_NO_FILE:   return 'niciun fișier selectat';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION: return 'eroare de server';
        default:                   return 'eroare necunoscută (cod ' . $code . ')';
    }
}

function bsv_backup_human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0; $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$n : number_format($n, 1)) . ' ' . $units[$i];
}

function bsv_backup_dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $total = 0;
    foreach (bsv_backup_list_files($dir) as $f) {
        $s = @filesize($f);
        if ($s !== false) $total += (int)$s;
    }
    return $total;
}

/**
 * PHP's current upload ceiling (min of post_max_size and upload_max_filesize).
 */
function bsv_backup_upload_ceiling(): int
{
    $parse = static function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
        $unit = strtolower(substr($v, -1));
        $num  = (int)$v;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    };
    return min($parse((string)ini_get('post_max_size')), $parse((string)ini_get('upload_max_filesize')));
}
