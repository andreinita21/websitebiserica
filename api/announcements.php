<?php
/**
 * Public read-only JSON feed of published announcements.
 *
 * Query parameters (all optional):
 *   limit — integer (1–100). Hard cap on rows returned.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;

$sql = "SELECT id, title, body, tag, relevant_on, relevant_until, visible_days, created_at
        FROM announcements
        WHERE is_published = 1
          AND (relevant_until IS NULL OR date('now','localtime') <= relevant_until)
          AND (
               visible_days IS NULL
            OR date('now','localtime') <= date(substr(created_at,1,10), '+' || visible_days || ' days')
          )
        ORDER BY CASE WHEN visible_days IS NOT NULL THEN 0 ELSE 1 END,
                 relevant_on DESC,
                 id DESC";

if ($limit > 0) {
    $limit = min($limit, 100);
    $sql  .= ' LIMIT ' . $limit;
}

try {
    $stmt = bsv_db()->query($sql);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

$announcements = array_map(static function (array $r): array {
    $mode = 'single';
    if ($r['relevant_until'] !== null && $r['relevant_until'] !== '') {
        $mode = 'interval';
    } elseif ($r['visible_days'] !== null && (int)$r['visible_days'] > 0) {
        $mode = 'duration';
    }
    return [
        'id'             => (int)$r['id'],
        'title'          => (string)$r['title'],
        'body'           => (string)$r['body'],
        'tag'            => (string)$r['tag'],
        'relevant_on'    => (string)$r['relevant_on'],
        'relevant_until' => $r['relevant_until'] !== null ? (string)$r['relevant_until'] : null,
        'visible_days'   => $r['visible_days']   !== null ? (int)$r['visible_days']     : null,
        'date_mode'      => $mode,
    ];
}, $rows);

bsv_json(['announcements' => $announcements]);
