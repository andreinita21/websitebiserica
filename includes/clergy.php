<?php
/**
 * Clergy helpers — CRUD for the "Clerul parohiei" cards on despre.html.
 *
 * Photos live in uploads/clergy/ as content-hashed JPG/PNG/WebP files. The
 * placeholder shipped at assets/img/clergy-placeholder.svg is used when a
 * record has no photo of its own (photo_path = '').
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (!defined('APP_CLERGY_UPLOAD_DIR')) {
    define('APP_CLERGY_UPLOAD_DIR', __DIR__ . '/../uploads/clergy');
}
if (!defined('APP_CLERGY_UPLOAD_URL')) {
    define('APP_CLERGY_UPLOAD_URL', 'uploads/clergy');
}
if (!defined('APP_CLERGY_PLACEHOLDER')) {
    define('APP_CLERGY_PLACEHOLDER', 'assets/img/clergy-placeholder.svg');
}
if (!defined('APP_CLERGY_MAX_BYTES')) {
    define('APP_CLERGY_MAX_BYTES', 8 * 1024 * 1024);
}
if (!defined('APP_CLERGY_ALLOWED_MIME')) {
    define('APP_CLERGY_ALLOWED_MIME', [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ]);
}

/**
 * All clergy rows ordered for display. When $publishedOnly is true only
 * is_published=1 rows are returned (used by the public API).
 */
function bsv_clergy_all(bool $publishedOnly = false): array
{
    $sql = 'SELECT id, name, role, bio, photo_path, position, is_published, created_at, updated_at
              FROM clergy';
    if ($publishedOnly) $sql .= ' WHERE is_published = 1';
    $sql .= ' ORDER BY position ASC, id ASC';
    return bsv_db()->query($sql)->fetchAll();
}

function bsv_clergy_get(int $id): ?array
{
    $stmt = bsv_db()->prepare('SELECT * FROM clergy WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Insert or update a clergy record. $data keys: id (0 for new), name, role,
 * bio, photo_path, position, is_published. Returns the row id.
 */
function bsv_clergy_save(array $data): int
{
    $pdo = bsv_db();
    $now = date('Y-m-d H:i:s');
    $id  = (int)($data['id'] ?? 0);

    $payload = [
        ':name'         => (string)($data['name'] ?? ''),
        ':role'         => (string)($data['role'] ?? ''),
        ':bio'          => (string)($data['bio'] ?? ''),
        ':photo_path'   => (string)($data['photo_path'] ?? ''),
        ':position'     => (int)($data['position'] ?? 0),
        ':is_published' => !empty($data['is_published']) ? 1 : 0,
        ':updated_at'   => $now,
    ];

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE clergy
                SET name = :name, role = :role, bio = :bio, photo_path = :photo_path,
                    position = :position, is_published = :is_published, updated_at = :updated_at
              WHERE id = :id'
        );
        $payload[':id'] = $id;
        $stmt->execute($payload);
        return $id;
    }

    $payload[':created_at'] = $now;
    $stmt = $pdo->prepare(
        'INSERT INTO clergy
            (name, role, bio, photo_path, position, is_published, created_at, updated_at)
         VALUES
            (:name, :role, :bio, :photo_path, :position, :is_published, :created_at, :updated_at)'
    );
    $stmt->execute($payload);
    return (int)$pdo->lastInsertId();
}

/** Delete a clergy row and remove its photo file (if any). */
function bsv_clergy_delete(int $id): void
{
    $row = bsv_clergy_get($id);
    if (!$row) return;
    bsv_db()->prepare('DELETE FROM clergy WHERE id = :id')->execute([':id' => $id]);
    if (!empty($row['photo_path'])) {
        bsv_clergy_delete_file((string)$row['photo_path']);
    }
}

/**
 * Validate and store an uploaded clergy photo. Returns the webroot-relative
 * file path (e.g. "uploads/clergy/abc123.jpg"). Throws RuntimeException on
 * invalid input.
 */
function bsv_clergy_store_upload(array $file): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Fișier invalid.');
    }
    switch ((int)$file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('Imaginea depășește dimensiunea maximă permisă.');
        case UPLOAD_ERR_PARTIAL:
            throw new RuntimeException('Încărcarea a fost întreruptă. Încercați din nou.');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('Selectați o imagine înainte de a salva.');
        default:
            throw new RuntimeException('Nu s-a putut salva imaginea (cod ' . (int)$file['error'] . ').');
    }

    $tmp  = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if (!$tmp || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Fișier invalid.');
    }
    if ($size <= 0 || $size > APP_CLERGY_MAX_BYTES) {
        throw new RuntimeException('Imaginea este prea mare (maxim ' . (int)(APP_CLERGY_MAX_BYTES / (1024 * 1024)) . ' MB).');
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime  = $finfo ? (string)finfo_file($finfo, $tmp) : (string)($file['type'] ?? '');
    if ($finfo) finfo_close($finfo);

    $allowed = APP_CLERGY_ALLOWED_MIME;
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Format neacceptat. Folosiți JPG, PNG sau WebP.');
    }
    $ext = $allowed[$mime];

    $dims = @getimagesize($tmp);
    if (!$dims || empty($dims[0]) || empty($dims[1])) {
        throw new RuntimeException('Imaginea nu a putut fi analizată.');
    }

    $hash = hash_file('sha256', $tmp) ?: bin2hex(random_bytes(16));
    $dir  = rtrim(APP_CLERGY_UPLOAD_DIR, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nu am putut pregăti directorul de încărcare.');
    }

    $name = substr($hash, 0, 24) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!is_file($dest)) {
        if (!@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Nu am putut salva imaginea pe disc.');
        }
    }
    @chmod($dest, 0664);

    return rtrim(APP_CLERGY_UPLOAD_URL, '/') . '/' . $name;
}

/** Remove a clergy photo file from disk. Silently ignores anything outside the uploads tree. */
function bsv_clergy_delete_file(string $relPath): void
{
    if ($relPath === '') return;
    $relPath = ltrim($relPath, '/');
    if (!str_starts_with($relPath, APP_CLERGY_UPLOAD_URL . '/')) return;
    $root = realpath(__DIR__ . '/..');
    if ($root === false) return;
    $full = realpath($root . '/' . $relPath);
    if ($full === false) return;
    $uploadsRoot = realpath(APP_CLERGY_UPLOAD_DIR);
    if ($uploadsRoot === false || !str_starts_with($full, $uploadsRoot)) return;
    @unlink($full);
}

