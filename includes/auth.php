<?php
/**
 * Minimal session-based authentication for the admin area.
 * Uses PHP sessions, bcrypt password verification, and CSRF tokens.
 */

require_once __DIR__ . '/config.php';

function bsv_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(APP_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function bsv_is_admin(): bool
{
    bsv_session_start();
    return !empty($_SESSION['bsv_admin']);
}

function bsv_require_admin(): void
{
    if (!bsv_is_admin()) {
        $redirect = '/admin/login.php';
        header('Location: ' . $redirect);
        exit;
    }
}

function bsv_login(string $user, string $password): bool
{
    if (!hash_equals(APP_ADMIN_USER, $user)) {
        return false;
    }
    if (!password_verify($password, APP_ADMIN_PASSWORD_HASH)) {
        return false;
    }

    bsv_session_start();
    session_regenerate_id(true);
    $_SESSION['bsv_admin']    = true;
    $_SESSION['bsv_admin_user'] = $user;
    $_SESSION['bsv_login_at'] = time();
    return true;
}

function bsv_logout(): void
{
    bsv_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'] ?? '',
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
}

function bsv_csrf_token(): string
{
    bsv_session_start();
    if (empty($_SESSION['bsv_csrf'])) {
        $_SESSION['bsv_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['bsv_csrf'];
}

function bsv_csrf_check(?string $token): bool
{
    bsv_session_start();
    return !empty($_SESSION['bsv_csrf'])
        && is_string($token)
        && hash_equals($_SESSION['bsv_csrf'], $token);
}
