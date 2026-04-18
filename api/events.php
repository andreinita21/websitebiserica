<?php
/**
 * Public read-only JSON feed of published events.
 *
 * Query parameters (all optional):
 *   from        — ISO date (YYYY-MM-DD). Only occurrences on or after this date.
 *   to          — ISO date (YYYY-MM-DD). Only occurrences on or before this date.
 *   limit       — integer (1–200). Hard cap on rows returned.
 *   upcoming    — "1" to restrict to today and later (used by homepage).
 *   recurrence  — "weekly" | "monthly" | "yearly". Only return events with that
 *                 recurrence type (empty/omitted returns all).
 *   distinct    — "1" to return at most one occurrence per source event (the
 *                 next upcoming one). Useful when rendering a compact list of
 *                 recurring series.
 *
 * Recurring events stored once in the DB are expanded here into concrete dated
 * occurrences within the requested window, so clients never need to understand
 * recurrence rules.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

$from       = isset($_GET['from'])  ? trim((string)$_GET['from'])  : '';
$to         = isset($_GET['to'])    ? trim((string)$_GET['to'])    : '';
$limit      = isset($_GET['limit']) ? (int)$_GET['limit']           : 0;
$upcoming   = !empty($_GET['upcoming']);
$distinct   = !empty($_GET['distinct']);
$recurrence = isset($_GET['recurrence']) ? trim((string)$_GET['recurrence']) : '';

$today = date('Y-m-d');

// Resolve the expansion window. Callers that just pass `upcoming=1` get a
// default one-year horizon so recurring series can emit a reasonable list.
$windowFrom = bsv_valid_date($from) ? $from : ($upcoming ? $today : $today);
$windowTo   = bsv_valid_date($to)   ? $to   : date('Y-m-d', strtotime('+1 year', strtotime($windowFrom)));

$sql = 'SELECT id, title, description, event_date, start_time, end_time,
               location, category, recurrence_type, recurrence_end_date, is_published
        FROM events
        WHERE is_published = 1';
$params = [];

// Filter by recurrence type when specified (empty string = any).
if ($recurrence !== '') {
    if (!bsv_valid_recurrence($recurrence) || $recurrence === '') {
        bsv_json(['error' => 'Invalid recurrence'], 400);
    }
    $sql .= ' AND recurrence_type = :recurrence';
    $params[':recurrence'] = $recurrence;
}

// A row is relevant to the window if either:
//   (a) non-recurring and its anchor falls in [from, to]; OR
//   (b) recurring, anchor is <= windowTo, and the recurrence has not already ended before windowFrom.
$sql .= ' AND event_date <= :window_to';
$sql .= ' AND (
              (recurrence_type IS NULL AND event_date >= :window_from)
              OR
              (recurrence_type IN (\'weekly\',\'monthly\',\'yearly\')
               AND (recurrence_end_date IS NULL OR recurrence_end_date >= :window_from))
         )';
$params[':window_from'] = $windowFrom;
$params[':window_to']   = $windowTo;

$sql .= ' ORDER BY event_date ASC, start_time ASC, id ASC';

try {
    $stmt = bsv_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

// Expand recurring rows into concrete dated occurrences.
$occurrences = [];
foreach ($rows as $r) {
    foreach (bsv_expand_event_occurrences($r, $windowFrom, $windowTo) as $occ) {
        $occurrences[] = $occ;
    }
}

// Sort occurrences chronologically (expansion may emit out-of-order rows
// because DB rows come back ordered by anchor date, not occurrence date).
usort($occurrences, static function (array $a, array $b): int {
    $cmp = strcmp((string)$a['event_date'], (string)$b['event_date']);
    if ($cmp !== 0) return $cmp;
    $as = (string)($a['start_time'] ?? '99:99');
    $bs = (string)($b['start_time'] ?? '99:99');
    return strcmp($as, $bs);
});

// `distinct=1` collapses a series into its next upcoming occurrence.
if ($distinct) {
    $seen = [];
    $deduped = [];
    foreach ($occurrences as $o) {
        $id = (int)$o['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $deduped[] = $o;
    }
    $occurrences = $deduped;
}

// Apply limit after expansion so the caller sees at most N occurrences.
if ($limit > 0) {
    $limit = min($limit, 200);
    $occurrences = array_slice($occurrences, 0, $limit);
}

$events = array_map(static function (array $r): array {
    $catKey   = (string)($r['category'] ?? '');
    $rec      = $r['recurrence_type'] ?? null;
    return [
        'id'                  => (int)$r['id'],
        'title'               => (string)$r['title'],
        'description'         => (string)$r['description'],
        'date'                => (string)$r['event_date'],
        'start_time'          => $r['start_time'] !== null ? (string)$r['start_time'] : null,
        'end_time'            => $r['end_time']   !== null ? (string)$r['end_time']   : null,
        'location'            => (string)$r['location'],
        'category'            => $catKey,
        'category_label'      => bsv_category_label($catKey),
        'category_color'      => bsv_category_color($catKey),
        'recurrence_type'     => ($rec === null || $rec === '') ? null : (string)$rec,
        'recurrence_end_date' => isset($r['recurrence_end_date']) && $r['recurrence_end_date'] !== ''
            ? (string)$r['recurrence_end_date']
            : null,
    ];
}, $occurrences);

bsv_json(['events' => $events]);
