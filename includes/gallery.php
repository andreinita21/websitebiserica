<?php
/**
 * Gallery helpers — slug creation, image upload/validation, query builders.
 *
 * Photo files live under the web-accessible folder `uploads/gallery/YYYY/MM/`
 * so Apache can serve them directly without going through PHP. File names are
 * content-hashed to avoid collisions and to give each file a cacheable URL.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (!defined('APP_GALLERY_UPLOAD_DIR')) {
    define('APP_GALLERY_UPLOAD_DIR', __DIR__ . '/../uploads/gallery');
}
if (!defined('APP_GALLERY_UPLOAD_URL')) {
    define('APP_GALLERY_UPLOAD_URL', 'uploads/gallery');
}
if (!defined('APP_GALLERY_MAX_BYTES')) {
    define('APP_GALLERY_MAX_BYTES', 100 * 1024 * 1024);
}
if (!defined('APP_GALLERY_ALLOWED_MIME')) {
    define('APP_GALLERY_ALLOWED_MIME', [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
    ]);
}

/* ---- Responsive variants configuration ------------------------------------
 *
 * Each uploaded photo is automatically processed into several smaller
 * renditions so the browser can pick the smallest one that fits the layout
 * and the device pixel ratio. Any width greater than or equal to the
 * original image width is skipped (we never upscale).
 *
 * The `sizes` attribute used on the public page matches the CSS-columns
 * masonry: at 1200px+ we show 4 columns → each tile is ~25vw, etc.
 */
if (!defined('APP_GALLERY_VARIANT_WIDTHS')) {
    define('APP_GALLERY_VARIANT_WIDTHS', [400, 800, 1200, 1600, 2000]);
}
if (!defined('APP_GALLERY_WEBP_QUALITY')) {
    define('APP_GALLERY_WEBP_QUALITY', 82);
}
if (!defined('APP_GALLERY_JPEG_QUALITY')) {
    define('APP_GALLERY_JPEG_QUALITY', 84);
}
if (!defined('APP_GALLERY_AVIF_QUALITY')) {
    // AVIF at quality 55 is roughly comparable to WebP q82 in perceived
    // quality but often 25–35% smaller. Encoding is expensive though, so
    // it's only attempted when GD exposes `imageavif`.
    define('APP_GALLERY_AVIF_QUALITY', 55);
}
if (!defined('APP_GALLERY_SIZES_ATTR')) {
    define(
        'APP_GALLERY_SIZES_ATTR',
        '(min-width: 1200px) 25vw, (min-width: 860px) 33vw, (min-width: 560px) 50vw, 100vw'
    );
}

/** ASCII-slugify a category name. Romanian diacritics → plain letters. */
function bsv_gallery_slugify(string $input): string
{
    $map = [
        'ă'=>'a','â'=>'a','Ă'=>'a','Â'=>'a',
        'î'=>'i','Î'=>'i',
        'ș'=>'s','ş'=>'s','Ș'=>'s','Ş'=>'s',
        'ț'=>'t','ţ'=>'t','Ț'=>'t','Ţ'=>'t',
    ];
    $s = strtr($input, $map);
    $s = function_exists('iconv') ? (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s) : $s;
    $s = strtolower((string)$s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'categorie';
    return substr($s, 0, 64);
}

/** Guarantee unique slug across the categories table. Adds -2, -3, … if needed. */
function bsv_gallery_unique_slug(string $base, int $ignoreId = 0): string
{
    $slug = bsv_gallery_slugify($base);
    $pdo  = bsv_db();
    $n    = 1;
    $try  = $slug;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM gallery_categories WHERE slug = :s AND id <> :id LIMIT 1');
        $stmt->execute([':s' => $try, ':id' => $ignoreId]);
        if (!$stmt->fetch()) return $try;
        $n++;
        $try = $slug . '-' . $n;
    }
}

/**
 * Validate and store a single uploaded image. Returns the record-ready
 * metadata array (file_path, mime_type, width, height, size_bytes, file_hash)
 * or throws RuntimeException with a user-friendly message.
 *
 * $file is one entry from $_FILES['...'].
 */
function bsv_gallery_store_upload(array $file): array
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
    if ($size <= 0 || $size > APP_GALLERY_MAX_BYTES) {
        throw new RuntimeException('Imaginea este prea mare (maxim ' . (int)(APP_GALLERY_MAX_BYTES / (1024 * 1024)) . ' MB).');
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime  = $finfo ? (string)finfo_file($finfo, $tmp) : (string)($file['type'] ?? '');
    if ($finfo) finfo_close($finfo);

    $allowed = APP_GALLERY_ALLOWED_MIME;
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Format neacceptat. Folosiți JPG, PNG, WebP, GIF sau AVIF.');
    }
    $ext = $allowed[$mime];

    $dims = @getimagesize($tmp);
    if (!$dims || empty($dims[0]) || empty($dims[1])) {
        throw new RuntimeException('Imaginea nu a putut fi analizată. Verificați fișierul și încercați din nou.');
    }

    $hash = hash_file('sha256', $tmp) ?: bin2hex(random_bytes(16));
    $year = date('Y');
    $mon  = date('m');
    $dir  = rtrim(APP_GALLERY_UPLOAD_DIR, '/') . "/$year/$mon";
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

    // Make the file readable by the webserver on deploys where umask is strict.
    @chmod($dest, 0664);

    return [
        'file_path'  => rtrim(APP_GALLERY_UPLOAD_URL, '/') . "/$year/$mon/$name",
        'mime_type'  => $mime,
        'width'      => (int)$dims[0],
        'height'     => (int)$dims[1],
        'size_bytes' => (int)$size,
        'file_hash'  => $hash,
    ];
}

/** Remove the file on disk for a photo row. Silently ignores missing files. */
function bsv_gallery_delete_file(string $relPath): void
{
    if ($relPath === '') return;
    $relPath = ltrim($relPath, '/');
    if (!str_starts_with($relPath, APP_GALLERY_UPLOAD_URL . '/')) {
        return; // never delete anything outside our uploads tree
    }
    $root = realpath(__DIR__ . '/..');
    if ($root === false) return;
    $full = realpath($root . '/' . $relPath);
    if ($full === false) return;
    $uploadsRoot = realpath(APP_GALLERY_UPLOAD_DIR);
    if ($uploadsRoot === false || !str_starts_with($full, $uploadsRoot)) return;
    @unlink($full);
}

/** Fetch a photo by id, with its categories joined as arrays. Returns null if not found. */
function bsv_gallery_photo_with_categories(int $id): ?array
{
    $pdo = bsv_db();
    $stmt = $pdo->prepare('SELECT * FROM gallery_photos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $cats = $pdo->prepare(
        'SELECT c.id, c.name, c.slug
           FROM gallery_categories c
           JOIN gallery_photo_categories pc ON pc.category_id = c.id
          WHERE pc.photo_id = :id
          ORDER BY c.position ASC, c.name ASC'
    );
    $cats->execute([':id' => $id]);
    $row['categories'] = $cats->fetchAll();
    return $row;
}

/** Replace a photo's categories with the given ids. Unknown ids are ignored. */
function bsv_gallery_set_photo_categories(int $photoId, array $categoryIds): void
{
    $pdo = bsv_db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM gallery_photo_categories WHERE photo_id = :p')
            ->execute([':p' => $photoId]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), fn($v) => $v > 0)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $known = $pdo->prepare("SELECT id FROM gallery_categories WHERE id IN ($in)");
            $known->execute($ids);
            $valid = array_map('intval', array_column($known->fetchAll(), 'id'));
            if ($valid) {
                $ins = $pdo->prepare('INSERT OR IGNORE INTO gallery_photo_categories (photo_id, category_id) VALUES (:p, :c)');
                foreach ($valid as $cid) {
                    $ins->execute([':p' => $photoId, ':c' => $cid]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Fetch all categories ordered by position. */
function bsv_gallery_all_categories(): array
{
    return bsv_db()
        ->query('SELECT id, name, slug, position FROM gallery_categories ORDER BY position ASC, name ASC')
        ->fetchAll();
}


/* ============================================================================
 * IMAGE OPTIMIZATION — responsive renditions
 *
 * After a photo is uploaded we generate several smaller copies in modern
 * formats (WebP always, AVIF when GD supports it, plus a resized original-
 * format copy) and remember what we produced in the `variants` column.
 *
 * The public page then assembles a <picture> element so each visitor's
 * browser downloads only the smallest file that fits their viewport and
 * device pixel ratio. This typically cuts transfer by 70–90 % on phones.
 * ========================================================================== */

/** Capability flags for the image stack available on this host. */
function bsv_gallery_image_support(): array
{
    return [
        'gd'   => extension_loaded('gd') && function_exists('imagecreatetruecolor'),
        'jpeg' => function_exists('imagejpeg'),
        'png'  => function_exists('imagepng'),
        'webp' => function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
        'avif' => function_exists('imageavif') && function_exists('imagecreatefromavif'),
        'gif'  => function_exists('imagegif')  && function_exists('imagecreatefromgif'),
        'exif' => function_exists('exif_read_data'),
    ];
}

/** Load an image from disk for the given MIME. Returns GdImage|false. */
function bsv_gallery_gd_load(string $path, string $mime)
{
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
        case 'image/png':  return function_exists('imagecreatefrompng')  ? @imagecreatefrompng($path)  : false;
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        case 'image/gif':  return function_exists('imagecreatefromgif')  ? @imagecreatefromgif($path)  : false;
        case 'image/avif': return function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false;
    }
    return false;
}

/** Respect JPEG EXIF orientation so portraits aren't resized sideways. */
function bsv_gallery_gd_apply_orientation($img, string $path, string $mime)
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($path);
    if (!$exif || empty($exif['Orientation'])) return $img;
    switch ((int)$exif['Orientation']) {
        case 3: return imagerotate($img, 180, 0) ?: $img;
        case 6: return imagerotate($img, -90, 0) ?: $img;
        case 8: return imagerotate($img,  90, 0) ?: $img;
    }
    return $img;
}

/** Resize a GD image to the given width, preserving aspect ratio. Destroys src. */
function bsv_gallery_gd_resize($src, int $targetWidth, bool $preserveAlpha)
{
    $sw = imagesx($src); $sh = imagesy($src);
    if ($sw <= $targetWidth) return $src;
    $ratio = $targetWidth / $sw;
    $dw = $targetWidth;
    $dh = max(1, (int) round($sh * $ratio));

    $dst = imagecreatetruecolor($dw, $dh);
    if ($preserveAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dw, $dh, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);
    return $dst;
}

/** Save a GD image to disk in the chosen format. Returns bytes written or 0 on failure. */
function bsv_gallery_gd_save($img, string $fmt, string $path): int
{
    $ok = false;
    switch ($fmt) {
        case 'webp': $ok = function_exists('imagewebp') && @imagewebp($img, $path, APP_GALLERY_WEBP_QUALITY); break;
        case 'avif': $ok = function_exists('imageavif') && @imageavif($img, $path, APP_GALLERY_AVIF_QUALITY); break;
        case 'jpeg': $ok = function_exists('imagejpeg') && @imagejpeg($img, $path, APP_GALLERY_JPEG_QUALITY); break;
        case 'png':  $ok = function_exists('imagepng')  && @imagepng($img, $path, 6);                        break;
    }
    if (!$ok) return 0;
    @chmod($path, 0664);
    return (int) (@filesize($path) ?: 0);
}

/**
 * Produce responsive variants for the given photo. Writes resized files
 * next to the original and updates the `variants` JSON column. Safe to
 * re-run: existing variant files for this photo are removed first.
 *
 * Returns:
 *   [ 'status' => 'ok' | 'skipped' | 'error',
 *     'count'  => int,
 *     'message'=> string ]
 */
function bsv_gallery_generate_variants(int $photoId): array
{
    $pdo  = bsv_db();
    $stmt = $pdo->prepare('SELECT id, file_path, mime_type FROM gallery_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch();
    if (!$row) return ['status' => 'error', 'count' => 0, 'message' => 'Fotografie negăsită.'];

    $caps = bsv_gallery_image_support();
    if (!$caps['gd']) {
        return ['status' => 'skipped', 'count' => 0, 'message' => 'Extensia GD nu este disponibilă.'];
    }

    $root = realpath(__DIR__ . '/..');
    if ($root === false) return ['status' => 'error', 'count' => 0, 'message' => 'Rădăcina site-ului nu a putut fi rezolvată.'];
    $src = $root . '/' . ltrim((string)$row['file_path'], '/');
    if (!is_file($src)) {
        return ['status' => 'error', 'count' => 0, 'message' => 'Fișierul original lipsește.'];
    }

    // Clear previous variants — both the files and the DB record — so we can
    // re-emit a consistent set.
    bsv_gallery_delete_variant_files($photoId);
    $pdo->prepare('UPDATE gallery_photos SET variants = NULL WHERE id = :id')
        ->execute([':id' => $photoId]);

    $mime = (string)$row['mime_type'];

    // Processing a multi-megapixel JPEG through GD is memory-hungry. Bump
    // the limit temporarily; fall back gracefully if ini_set is disabled.
    $prevMem = ini_get('memory_limit');
    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    // Some GD encoders (notably libavif on certain builds) print progress
    // info directly to stdout, breaking subsequent header() calls in the
    // caller. Capture anything they emit for the whole processing run.
    ob_start();

    // Load once to measure dimensions (accounting for EXIF rotation).
    $probe = bsv_gallery_gd_load($src, $mime);
    if (!$probe) {
        ob_end_clean();
        @ini_set('memory_limit', (string)$prevMem);
        return ['status' => 'error', 'count' => 0, 'message' => 'Nu am putut decoda imaginea (' . $mime . ').'];
    }
    $probe = bsv_gallery_gd_apply_orientation($probe, $src, $mime);
    $origW = imagesx($probe);
    $origH = imagesy($probe);
    imagedestroy($probe);

    // Work out which output formats are worth producing.
    $preserveAlpha = in_array($mime, ['image/png', 'image/webp', 'image/gif', 'image/avif'], true);
    $formats = [];
    if ($caps['webp']) $formats[] = 'webp';
    if ($caps['avif']) $formats[] = 'avif';

    // Fallback format for browsers without WebP/AVIF support.
    $fallbackFmt = null;
    $fallbackExt = null;
    $fallbackMime = null;
    switch ($mime) {
        case 'image/png':
            if ($caps['png'])  { $fallbackFmt = 'png';  $fallbackExt = 'png'; $fallbackMime = 'image/png'; }
            break;
        case 'image/webp':
            // If the original is already WebP, skip the "original-format" fallback:
            // everyone that can't decode WebP won't be able to decode it regardless,
            // and we'd only duplicate bytes.
            break;
        case 'image/gif':
        case 'image/jpeg':
        case 'image/avif':
        default:
            if ($caps['jpeg']) { $fallbackFmt = 'jpeg'; $fallbackExt = 'jpg'; $fallbackMime = 'image/jpeg'; }
            break;
    }
    if ($fallbackFmt) $formats[] = $fallbackFmt;

    if (!$formats) {
        ob_end_clean();
        @ini_set('memory_limit', (string)$prevMem);
        return ['status' => 'skipped', 'count' => 0, 'message' => 'Niciun encoder potrivit.'];
    }

    $dir     = dirname($src);
    $baseFs  = pathinfo($src, PATHINFO_FILENAME);
    $relDir  = rtrim(dirname((string)$row['file_path']), '/');

    $variants = [];
    foreach (APP_GALLERY_VARIANT_WIDTHS as $targetW) {
        if ($targetW >= $origW) continue; // no upscaling

        foreach ($formats as $fmt) {
            $ext  = ($fmt === 'jpeg') ? 'jpg' : $fmt;
            $name = $baseFs . '-' . $targetW . '.' . $ext;
            $outPath = $dir . '/' . $name;

            // Re-decode the original for each emission so we never stack
            // resample artefacts on top of each other.
            $gd = bsv_gallery_gd_load($src, $mime);
            if (!$gd) continue;
            $gd = bsv_gallery_gd_apply_orientation($gd, $src, $mime);
            $gd = bsv_gallery_gd_resize($gd, $targetW, $preserveAlpha);

            $bytes = bsv_gallery_gd_save($gd, $fmt, $outPath);
            $rw = imagesx($gd); $rh = imagesy($gd);
            imagedestroy($gd);

            if ($bytes <= 0) continue;

            $variants[] = [
                'w'     => $rw,
                'h'     => $rh,
                'fmt'   => $fmt,
                'mime'  => ($fmt === 'jpeg') ? 'image/jpeg'
                         : (($fmt === 'png') ? 'image/png'
                         : (($fmt === 'avif') ? 'image/avif' : 'image/webp')),
                'path'  => $relDir . '/' . $name,
                'bytes' => $bytes,
            ];
        }
    }

    $pdo->prepare('UPDATE gallery_photos SET variants = :v, updated_at = :u WHERE id = :id')
        ->execute([
            ':v'  => $variants ? json_encode($variants, JSON_UNESCAPED_SLASHES) : null,
            ':u'  => date('Y-m-d H:i:s'),
            ':id' => $photoId,
        ]);

    ob_end_clean();
    @ini_set('memory_limit', (string)$prevMem);

    return [
        'status'  => $variants ? 'ok' : 'skipped',
        'count'   => count($variants),
        'message' => $variants
            ? 'Variante generate: ' . count($variants)
            : 'Imaginea este deja mai mică decât cel mai mic prag — variante sărite.',
    ];
}

/** Remove just the variant files for a photo (keeps the original and DB row). */
function bsv_gallery_delete_variant_files(int $photoId): void
{
    $pdo = bsv_db();
    $stmt = $pdo->prepare('SELECT variants FROM gallery_photos WHERE id = :id');
    $stmt->execute([':id' => $photoId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['variants'])) return;
    $list = json_decode((string)$row['variants'], true);
    if (!is_array($list)) return;
    foreach ($list as $v) {
        if (!empty($v['path'])) bsv_gallery_delete_file((string)$v['path']);
    }
}

/** Read and decode the variants JSON for a photo row. Returns [] on missing/invalid. */
function bsv_gallery_decode_variants($raw): array
{
    if (!$raw) return [];
    $list = json_decode((string)$raw, true);
    return is_array($list) ? $list : [];
}

/**
 * Batch helper — regenerate variants for every photo that currently has none,
 * or for ALL photos when $force is true. Returns a summary counters array.
 */
function bsv_gallery_regenerate_all(bool $force = false): array
{
    $pdo = bsv_db();
    $sql = 'SELECT id FROM gallery_photos';
    if (!$force) $sql .= ' WHERE variants IS NULL OR variants = ""';
    $ids = array_map('intval', array_column($pdo->query($sql)->fetchAll(), 'id'));

    $ok = 0; $skipped = 0; $errors = 0;
    foreach ($ids as $id) {
        $res = bsv_gallery_generate_variants($id);
        if      ($res['status'] === 'ok')      $ok++;
        elseif  ($res['status'] === 'skipped') $skipped++;
        else                                   $errors++;
    }
    return [
        'processed' => count($ids),
        'ok'        => $ok,
        'skipped'   => $skipped,
        'errors'    => $errors,
    ];
}
