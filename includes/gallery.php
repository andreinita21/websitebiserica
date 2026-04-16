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
