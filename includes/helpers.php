<?php
/**
 * Shared helpers — Romanian date/time formatting, sanitization, validation.
 * Server-side only. The calendar.js file mirrors these names client-side.
 */

require_once __DIR__ . '/config.php';

const BSV_MONTHS_RO = [
    1 => 'ianuarie', 2 => 'februarie', 3 => 'martie',   4 => 'aprilie',
    5 => 'mai',      6 => 'iunie',     7 => 'iulie',    8 => 'august',
    9 => 'septembrie', 10 => 'octombrie', 11 => 'noiembrie', 12 => 'decembrie',
];

const BSV_MONTHS_RO_SHORT = [
    1 => 'ian', 2 => 'feb', 3 => 'mar', 4 => 'apr',  5 => 'mai', 6 => 'iun',
    7 => 'iul', 8 => 'aug', 9 => 'sep', 10 => 'oct', 11 => 'noi', 12 => 'dec',
];

const BSV_WEEKDAYS_RO = [
    0 => 'duminică', 1 => 'luni', 2 => 'marți', 3 => 'miercuri',
    4 => 'joi',      5 => 'vineri', 6 => 'sâmbătă',
];

/** Escape for safe HTML output. */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "15 aprilie 2026" */
function bsv_format_date_ro(string $isoDate): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);
    if (!$d) return $isoDate;
    $m = BSV_MONTHS_RO[(int)$d->format('n')] ?? '';
    return $d->format('j') . ' ' . $m . ' ' . $d->format('Y');
}

/** "duminică, 15 aprilie" */
function bsv_format_date_long_ro(string $isoDate): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);
    if (!$d) return $isoDate;
    $w = BSV_WEEKDAYS_RO[(int)$d->format('w')] ?? '';
    $m = BSV_MONTHS_RO[(int)$d->format('n')] ?? '';
    return $w . ', ' . $d->format('j') . ' ' . $m;
}

/** Strip a user-entered HH:MM, return null if empty/invalid. */
function bsv_clean_time(?string $raw): ?string
{
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw)) return null;
    return $raw;
}

/** Validate an ISO date (YYYY-MM-DD). */
function bsv_valid_date(?string $raw): bool
{
    if (!is_string($raw) || $raw === '') return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw;
}

/** Valid category key. */
function bsv_valid_category(?string $raw): bool
{
    return is_string($raw) && array_key_exists($raw, APP_CATEGORIES);
}

/** Send a JSON response and terminate. */
function bsv_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
