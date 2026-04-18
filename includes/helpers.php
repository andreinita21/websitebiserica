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

/**
 * Event categories as [slug => label]. Falls back to the APP_CATEGORIES
 * constant if the DB is unreachable (e.g. during CLI tooling), so callers
 * never see an empty list.
 */
function bsv_categories(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    if (function_exists('bsv_db')) {
        try {
            $rows = bsv_db()->query(
                'SELECT slug, label FROM event_categories ORDER BY position ASC, id ASC'
            )->fetchAll();
            if ($rows) {
                $cache = [];
                foreach ($rows as $r) {
                    $cache[(string)$r['slug']] = (string)$r['label'];
                }
                return $cache;
            }
        } catch (Throwable $e) {
            // fall through to APP_CATEGORIES fallback
        }
    }
    $cache = defined('APP_CATEGORIES') && is_array(APP_CATEGORIES) ? APP_CATEGORIES : [];
    return $cache;
}

/** Look up the display label for a category slug. Returns a generic fallback
 *  if the slug is unknown (e.g. the category was deleted after rows were saved). */
function bsv_category_label(?string $slug): string
{
    if (!is_string($slug) || $slug === '') return 'Eveniment';
    $all = bsv_categories();
    return $all[$slug] ?? 'Eveniment';
}

/** Valid category key (must currently exist in event_categories). */
function bsv_valid_category(?string $raw): bool
{
    if (!is_string($raw) || $raw === '') return false;
    return array_key_exists($raw, bsv_categories());
}

/** Saved event locations, ordered by position then name. */
function bsv_locations(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    if (function_exists('bsv_db')) {
        try {
            $rows = bsv_db()->query(
                'SELECT id, name, position FROM event_locations ORDER BY position ASC, name ASC'
            )->fetchAll();
            foreach ($rows as $r) {
                $cache[] = [
                    'id'       => (int)$r['id'],
                    'name'     => (string)$r['name'],
                    'position' => (int)$r['position'],
                ];
            }
        } catch (Throwable $e) {}
    }
    return $cache;
}

const BSV_RECURRENCE_TYPES = ['weekly', 'monthly', 'yearly'];

/** Valid recurrence type (or null / '' for one-off). */
function bsv_valid_recurrence(?string $raw): bool
{
    if ($raw === null || $raw === '') return true;
    return in_array($raw, BSV_RECURRENCE_TYPES, true);
}

/**
 * Expand a single event row into concrete dated occurrences that fall within
 * [$fromIso, $toIso] (inclusive). A non-recurring row yields at most one
 * occurrence (its own event_date). A recurring row yields every occurrence
 * within the window, bounded by recurrence_end_date if set.
 *
 * Each returned row is a copy of $event with event_date set to the occurrence
 * date. Other fields (id, title, times, etc.) are left untouched — callers
 * therefore see the same id across multiple occurrences of the same series.
 */
function bsv_expand_event_occurrences(array $event, string $fromIso, string $toIso): array
{
    $type    = $event['recurrence_type'] ?? null;
    $anchor  = (string)($event['event_date'] ?? '');
    $endRule = $event['recurrence_end_date'] ?? null;

    if (!bsv_valid_date($anchor)) return [];
    if (!bsv_valid_date($fromIso) || !bsv_valid_date($toIso)) return [];
    if ($fromIso > $toIso) return [];

    // Effective end-of-series is min(window end, rule end if set).
    $effectiveTo = $toIso;
    if (is_string($endRule) && $endRule !== '' && bsv_valid_date($endRule) && $endRule < $effectiveTo) {
        $effectiveTo = $endRule;
    }

    // Non-recurring: include if anchor falls in the window.
    if (!bsv_valid_recurrence($type) || $type === null || $type === '') {
        if ($anchor >= $fromIso && $anchor <= $toIso) {
            return [$event];
        }
        return [];
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor);
    if (!$dt) return [];

    $out    = [];
    $safety = 5000;
    while ($safety-- > 0) {
        $iso = $dt->format('Y-m-d');
        if ($iso > $effectiveTo) break;
        if ($iso >= $fromIso) {
            $instance = $event;
            $instance['event_date'] = $iso;
            $out[] = $instance;
        }
        $next = _bsv_advance_occurrence($dt, $anchor, (string)$type);
        if ($next === null) break;
        $dt = $next;
    }
    return $out;
}

/** Advance a recurrence cursor to the next valid occurrence. */
function _bsv_advance_occurrence(DateTimeImmutable $current, string $anchorIso, string $type): ?DateTimeImmutable
{
    if ($type === 'weekly') {
        return $current->modify('+7 days') ?: null;
    }
    if ($type === 'monthly') {
        $day = (int)substr($anchorIso, 8, 2);
        $y = (int)$current->format('Y');
        $m = (int)$current->format('n');
        // Walk forward up to 24 months looking for a month that contains the anchor day.
        for ($i = 0; $i < 24; $i++) {
            $m++;
            if ($m > 12) { $m = 1; $y++; }
            if (checkdate($m, $day, $y)) {
                return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $y, $m, $day)) ?: null;
            }
        }
        return null;
    }
    if ($type === 'yearly') {
        $month = (int)substr($anchorIso, 5, 2);
        $day   = (int)substr($anchorIso, 8, 2);
        $y = (int)$current->format('Y');
        // Feb 29 falls only every ~4 years — walk up to 8 years just in case.
        for ($i = 0; $i < 8; $i++) {
            $y++;
            if (checkdate($month, $day, $y)) {
                return DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $y, $month, $day)) ?: null;
            }
        }
        return null;
    }
    return null;
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
