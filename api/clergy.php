<?php
/**
 * Public read-only JSON feed of clergy members.
 * Consumed by assets/js/site-clergy.js to populate the despre.html "Clerul parohiei" section.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/clergy.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

try {
    $rows = bsv_clergy_all(true);
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

$placeholder = APP_CLERGY_PLACEHOLDER;
$members = array_map(static function (array $r) use ($placeholder): array {
    $photo = (string)($r['photo_path'] ?? '');
    return [
        'id'        => (int)$r['id'],
        'name'      => (string)$r['name'],
        'role'      => (string)$r['role'],
        'bio'       => (string)$r['bio'],
        'photo_url' => $photo !== '' ? $photo : $placeholder,
        'has_photo' => $photo !== '',
    ];
}, $rows);

bsv_json(['members' => $members]);
