<?php
/**
 * Public read-only JSON feed of editable contact details.
 * Consumed by assets/js/site-contact.js to populate every page.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    bsv_json(['error' => 'Method not allowed'], 405);
}

try {
    $settings = bsv_settings_all();
} catch (Throwable $e) {
    bsv_json(['error' => 'Database error'], 500);
}

bsv_json(['contact' => $settings]);
