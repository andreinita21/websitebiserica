<?php
/**
 * Central configuration for the Biserica Sfântul Vasile event system.
 *
 * To override any value locally (e.g. change the admin password on your
 * production server), create `includes/config.local.php` — it is gitignored
 * and loaded automatically at the end of this file.
 */

// Absolute path to the SQLite database file.
if (!defined('APP_DB_PATH')) {
    define('APP_DB_PATH', __DIR__ . '/../data/events.db');
}

// Single admin account. The username is free-form, the password MUST be a
// bcrypt hash produced with password_hash(). The default hash below maps to
// the password "schimba-ma" — replace it immediately in config.local.php.
//
// Generate a new hash from the command line:
//   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT), PHP_EOL;"
if (!defined('APP_ADMIN_USER')) {
    define('APP_ADMIN_USER', 'admin');
}
if (!defined('APP_ADMIN_PASSWORD_HASH')) {
    define(
        'APP_ADMIN_PASSWORD_HASH',
        '$2y$12$qaMb0qC4KV/RBd1EElkV3e7xkrM.Rszrx/qW1hSyd1CVT7R/JMdSi'
    );
}

// How many categories are available. Used for validation and for building
// the select controls in admin. Keep the keys lowercase and ASCII-safe.
if (!defined('APP_CATEGORIES')) {
    define('APP_CATEGORIES', [
        'liturghie'  => 'Sfânta Liturghie',
        'vecernie'   => 'Vecernie / Utrenie',
        'praznic'    => 'Praznic / Sărbătoare',
        'taina'      => 'Sfântă Taină',
        'catehetic'  => 'Întâlnire catehetică',
        'caritabil'  => 'Acțiune caritabilă',
        'eveniment'  => 'Eveniment parohial',
    ]);
}

// Session name — isolated from other PHP sites sharing the same host.
if (!defined('APP_SESSION_NAME')) {
    define('APP_SESSION_NAME', 'bsv_admin_session');
}

// Load local overrides if they exist.
$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require_once $__local;
}
