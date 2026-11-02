<?php
/**
 * Hardened PHP sessions (configured in code — Xneelo does not allow php_value in .htaccess).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Note: session.sid_length / sid_bits_per_character / use_only_cookies / use_trans_sid are NOT set here —
    // they are deprecated from PHP 8.4 and PHP's defaults are already secure (cookie-only, 32-char ids).
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    $path = GX_ROOT . '/storage/sessions';
    if (is_dir($path) && is_writable($path)) {
        session_save_path($path);
    }

    session_name(GX_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // An id replaced by the hourly rotation keeps working for 2 minutes (requests already on their way),
    // then it is emptied — so a parallel request never logs the user out, and an old stolen id dies quickly.
    if (isset($_SESSION['_expires_at'])) {
        if ((int) $_SESSION['_expires_at'] < time()) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
        return;
    }
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - (int) $_SESSION['_created'] > 3600) {
        // Rotate the id every hour to limit the value of a stolen cookie (the old id is marked, not deleted)
        $_SESSION['_expires_at'] = time() + 120;
        session_regenerate_id(false);
        unset($_SESSION['_expires_at']);
        $_SESSION['_created'] = time();
    }
}

/** Call after login / logout / privilege change: new id and a new CSRF token. */
function session_rotate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
        unset($_SESSION['_csrf'], $_SESSION['_expires_at']);
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}
