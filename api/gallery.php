<?php
/**
 * Public read-only JSON feed for the gallery page.
 *
 * Returns:
 *   { "categories": [...], "photos": [...] }
 *
 * Query parameters (all optional):
 *   category — category slug to restrict results
 *   limit    — integer (1–500). Hard cap on photos returned.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gallery.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

$categorySlug = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$limit        = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;

try {
    $pdo = bsv_db();

    // Category list — include only categories that actually have at least one
    // published photo, so the filter bar never shows empty chips.
    $categories = $pdo->query(
        "SELECT c.id, c.name, c.slug, c.position,
                COUNT(DISTINCT pc.photo_id) AS photo_count
           FROM gallery_categories c
           LEFT JOIN gallery_photo_categories pc ON pc.category_id = c.id
           LEFT JOIN gallery_photos p ON p.id = pc.photo_id AND p.is_published = 1
          GROUP BY c.id, c.name, c.slug, c.position
          HAVING photo_count > 0
          ORDER BY c.position ASC, c.name ASC"
    )->fetchAll();

    $sql = 'SELECT p.id, p.title, p.description, p.file_path, p.mime_type,
                   p.width, p.height, p.variants, p.position, p.created_at
              FROM gallery_photos p';
    $params = [];
    if ($categorySlug !== '') {
        $sql .= ' INNER JOIN gallery_photo_categories pc ON pc.photo_id = p.id
                  INNER JOIN gallery_categories c ON c.id = pc.category_id
                  WHERE p.is_published = 1 AND c.slug = :slug';
        $params[':slug'] = $categorySlug;
    } else {
        $sql .= ' WHERE p.is_published = 1';
    }
    $sql .= ' ORDER BY p.position ASC, p.id DESC';

    if ($limit > 0) {
        $limit = min($limit, 500);
        $sql  .= ' LIMIT ' . $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Pull categories for every photo we're returning, in a single query.
    $photoCats = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare(
            "SELECT pc.photo_id, c.id, c.name, c.slug
               FROM gallery_photo_categories pc
               JOIN gallery_categories c ON c.id = pc.category_id
              WHERE pc.photo_id IN ($in)
              ORDER BY c.position ASC, c.name ASC"
        );
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) {
            $photoCats[(int)$r['photo_id']][] = [
                'id'   => (int)$r['id'],
                'name' => (string)$r['name'],
                'slug' => (string)$r['slug'],
            ];
        }
    }
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

$photos = array_map(static function (array $r) use ($photoCats): array {
    $variantsRaw = bsv_gallery_decode_variants($r['variants'] ?? null);

    // Group variants by MIME type so the front-end can emit one <source>
    // per modern format (WebP, AVIF) plus a final <img> fallback.
    $byMime = [];
    foreach ($variantsRaw as $v) {
        $mime = (string)($v['mime'] ?? 'image/webp');
        $byMime[$mime][] = [
            'w'     => (int)($v['w'] ?? 0),
            'h'     => (int)($v['h'] ?? 0),
            'url'   => (string)($v['path'] ?? ''),
            'bytes' => (int)($v['bytes'] ?? 0),
        ];
    }
    // Sort each group ascending by width so the browser can pick intelligently.
    foreach ($byMime as &$list) {
        usort($list, static fn($a, $b) => $a['w'] <=> $b['w']);
    }
    unset($list);

    return [
        'id'          => (int)$r['id'],
        'title'       => (string)$r['title'],
        'description' => (string)$r['description'],
        'url'         => (string)$r['file_path'],
        'mime_type'   => (string)$r['mime_type'],
        'width'       => $r['width'] !== null ? (int)$r['width']  : null,
        'height'      => $r['height'] !== null ? (int)$r['height'] : null,
        'variants'    => $byMime,    // { "image/webp": [...], "image/jpeg": [...] }
        'sizes'       => APP_GALLERY_SIZES_ATTR,
        'categories'  => $photoCats[(int)$r['id']] ?? [],
        'created_at'  => (string)$r['created_at'],
    ];
}, $rows);

$categories = array_map(static function (array $r): array {
    return [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'slug'        => (string)$r['slug'],
        'photo_count' => (int)$r['photo_count'],
    ];
}, $categories);

bsv_json([
    'categories' => $categories,
    'photos'     => $photos,
]);
