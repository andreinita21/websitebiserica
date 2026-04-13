<?php
/**
 * Copy this file to `includes/config.local.php` and edit the values below
 * to override the defaults from `includes/config.php`. This file is
 * gitignored so your real credentials stay local.
 *
 * Generate a new password hash:
 *   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
 */

define('APP_ADMIN_USER', 'parohie');
define('APP_ADMIN_PASSWORD_HASH', '$2y$10$REPLACE_WITH_YOUR_OWN_BCRYPT_HASH');
