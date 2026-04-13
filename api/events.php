<?php
/**
 * Public read-only JSON feed of published events.
 *
 * Query parameters (all optional):
 *   from   — ISO date (YYYY-MM-DD). Only events on or after this date.
 *   to     — ISO date (YYYY-MM-DD). Only events on or before this date.
 *   limit  — integer (1–100). Hard cap on rows returned.
 *   upcoming — "1" to restrict to events today and later (used by homepage).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

$from     = isset($_GET['from'])  ? trim((string)$_GET['from'])  : '';
$to       = isset($_GET['to'])    ? trim((string)$_GET['to'])    : '';
$limit    = isset($_GET['limit']) ? (int)$_GET['limit']           : 0;
$upcoming = !empty($_GET['upcoming']);

$sql = 'SELECT id, title, description, event_date, start_time, end_time,
               location, category, is_published
        FROM events
        WHERE is_published = 1';
$params = [];

if (bsv_valid_date($from)) {
    $sql .= ' AND event_date >= :from';
    $params[':from'] = $from;
} elseif ($upcoming) {
    $sql .= " AND event_date >= date('now','localtime')";
}

if (bsv_valid_date($to)) {
    $sql .= ' AND event_date <= :to';
    $params[':to'] = $to;
}

$sql .= ' ORDER BY event_date ASC, start_time ASC NULLS LAST, id ASC';

if ($limit > 0) {
    $limit = min($limit, 100);
    $sql  .= ' LIMIT ' . $limit;
}

try {
    $stmt = bsv_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

$events = array_map(static function (array $r): array {
    $catKey   = (string)($r['category'] ?? '');
    $catLabel = APP_CATEGORIES[$catKey] ?? 'Eveniment';
    return [
        'id'             => (int)$r['id'],
        'title'          => (string)$r['title'],
        'description'    => (string)$r['description'],
        'date'           => (string)$r['event_date'],
        'start_time'     => $r['start_time'] !== null ? (string)$r['start_time'] : null,
        'end_time'       => $r['end_time']   !== null ? (string)$r['end_time']   : null,
        'location'       => (string)$r['location'],
        'category'       => $catKey,
        'category_label' => $catLabel,
    ];
}, $rows);

bsv_json(['events' => $events]);
