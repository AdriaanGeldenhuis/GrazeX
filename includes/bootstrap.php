<?php
/**
 * GrazeX bootstrap — every page and API starts with:
 *   require __DIR__ . '/../includes/bootstrap.php';
 *
 * Optional flags BEFORE the require:
 *   define('GX_NO_SESSION', true);   // webhooks, cron, assets
 *   define('GX_IS_API', true);       // errors are returned as JSON
 *   define('GX_SKIP_MAINTENANCE', true);
 */
declare(strict_types=1);

if (defined('GX_ROOT')) {
    return;
}
define('GX_ROOT', dirname(__DIR__));
define('GX_START', microtime(true));

require GX_ROOT . '/config/app.php';

date_default_timezone_set(GX_TIMEZONE);
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------
// Environment — .env.php, config/.env.php, .env, config/.env or config/env.php (see includes/env.php)
// ---------------------------------------------------------------------
require GX_ROOT . '/includes/env.php';
[$GLOBALS['GX_ENV'], $GLOBALS['GX_ENV_REPORT']] = gx_env_load();
if (!$GLOBALS['GX_ENV_REPORT'] && PHP_SAPI !== 'cli') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GrazeX — setup needed</title>'
       . '<body style="background:#050606;color:#f4f4ef;font-family:system-ui;padding:40px;line-height:1.6">'
       . '<h1 style="color:#ff7a00">GrazeX is almost ready</h1><p>No settings file was found. Create <code>.env.php</code> (or <code>.env</code>) in the website root '
       . 'with the database details — see <code>.env.example</code> — then refresh.</p></body>';
    exit;
}

function env(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['GX_ENV'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value ?? $default;
}

define('GX_DEBUG', (bool) env('app_debug', false));
define('GX_IS_CLI', PHP_SAPI === 'cli');

// ---------------------------------------------------------------------
// Runtime folders (storage/ is not deployed, so create it on first run)
// ---------------------------------------------------------------------
(function (): void {
    $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
    $dirs = ['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/private',
             'storage/private/pop', 'storage/private/documents', 'storage/private/vat', 'uploads', 'uploads/media'];
    foreach ($dirs as $d) {
        $path = GX_ROOT . '/' . $d;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
    if (!is_file(GX_ROOT . '/storage/.htaccess')) {
        @file_put_contents(GX_ROOT . '/storage/.htaccess', $deny);
    }
    // Belt and braces: no script execution inside uploads/ (root .htaccess also blocks this)
    if (!is_file(GX_ROOT . '/uploads/.htaccess')) {
        @file_put_contents(GX_ROOT . '/uploads/.htaccess',
            "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\n"
            . "<FilesMatch \"\\.(php\\d?|phtml|phar|pl|py|cgi|sh)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n</FilesMatch>\n");
    }
})();

// ---------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', GX_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', GX_ROOT . '/storage/logs/php-' . date('Y-m') . '.log');

function gx_log(string $level, string $message, array $context = []): void
{
    $line = sprintf("[%s] %s: %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
    @file_put_contents(GX_ROOT . '/storage/logs/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Deprecations (e.g. after a PHP upgrade) must never take the site down — log them once and carry on.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        static $seen = [];
        $key = $file . ':' . $line;
        if (!isset($seen[$key]) && !str_contains($file, '/vendor/')) {
            $seen[$key] = true;
            gx_log('deprecated', $message, ['file' => $file, 'line' => $line]);
            if (defined('GX_TESTING')) {
                $GLOBALS['GX_DEPRECATIONS'][] = $message . ' (' . basename($file) . ':' . $line . ')';
            }
        }
        return true;
    }
    // In production a warning/notice is logged and the page keeps working; debug and tests stay strict.
    if (!GX_DEBUG && !defined('GX_TESTING') && in_array($severity, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE], true)) {
        gx_log('warning', $message, ['file' => $file, 'line' => $line, 'uri' => $_SERVER['REQUEST_URI'] ?? 'cli']);
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** Is this a "database not reachable / not set up" problem (as opposed to a bug)? */
function gx_is_db_setup_error(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $code = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
    return in_array($code, [1044, 1045, 1049, 1146, 2002, 2003, 2005, 2006, 2013], true)
        || str_contains($e->getMessage(), 'getaddrinfo') || str_contains($e->getMessage(), 'Connection refused');
}

set_exception_handler(static function (Throwable $e): void {
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $dbSetup = gx_is_db_setup_error($e);
    gx_log('error', $e->getMessage(), ['ref' => $ref, 'type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? 'cli', 'trace' => GX_DEBUG ? $e->getTraceAsString() : null]);
    if (GX_IS_CLI) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code($dbSetup ? 503 : 500);
        header('Cache-Control: no-store');
    }
    $wantsJson = defined('GX_IS_API') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $msg = $dbSetup ? 'We are busy setting things up. Please try again shortly.' : 'Something went wrong. Please try again.';
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => GX_DEBUG ? $e->getMessage() : $msg . ' (ref ' . $ref . ')', 'ref' => $ref]);
        exit;
    }
    $title = $dbSetup ? 'Back <span style="color:#ff7a00">soon.</span>' : 'Something went <span style="color:#ff7a00">wrong.</span>';
    $text = $dbSetup ? 'We are busy with a quick update. Please try again in a few minutes.' : 'Please try again in a moment. If it keeps happening, let us know.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>GrazeX</title><link rel="icon" href="/assets/img/brand/favicon.svg"></head>'
       . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#050606;color:#f4f4ef;font-family:Inter,system-ui,sans-serif;padding:24px;text-align:center">'
       . '<main style="max-width:560px"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX" width="260" height="130" style="max-width:70vw;height:auto">'
       . '<h1 style="font-size:clamp(34px,7vw,56px);line-height:1;margin:18px 0 12px;text-transform:uppercase;letter-spacing:-.01em">' . $title . '</h1>'
       . '<p style="color:#a7aea8;font-size:17px;line-height:1.6">' . $text . '</p>'
       . '<p style="margin:26px 0"><a href="/" style="display:inline-block;padding:14px 22px;border-radius:14px;background:linear-gradient(90deg,#ff7a00,#ff9f1a);color:#141414;font-weight:800;text-decoration:none">Back to GrazeX</a></p>'
       . '<p style="color:#7f8781;font-size:12px">Reference ' . $ref . '</p>'
       . (GX_DEBUG ? '<pre style="text-align:left;white-space:pre-wrap;color:#9ea59f;font-size:12px">' . htmlspecialchars((string) $e) . '</pre>' : '')
       . '</main></body></html>';
    exit;
});

// ---------------------------------------------------------------------
// Libraries
// ---------------------------------------------------------------------
if (is_file(GX_ROOT . '/vendor/autoload.php')) {
    require GX_ROOT . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $file = GX_ROOT . '/includes/services/' . basename(str_replace('\\', '/', $class)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require GX_ROOT . '/includes/helpers.php';
require GX_ROOT . '/includes/db.php';
require GX_ROOT . '/includes/settings.php';
require GX_ROOT . '/includes/view.php';
require GX_ROOT . '/includes/i18n.php';
require GX_ROOT . '/includes/validator.php';
require GX_ROOT . '/includes/rate_limit.php';
require GX_ROOT . '/includes/session.php';
require GX_ROOT . '/includes/csrf.php';
require GX_ROOT . '/includes/auth.php';
require GX_ROOT . '/includes/admin_auth.php';
require GX_ROOT . '/includes/uploads.php';
require GX_ROOT . '/includes/totp.php';

// ---------------------------------------------------------------------
// Request setup
// ---------------------------------------------------------------------
if (!GX_IS_CLI) {
    send_security_headers();
    if (!defined('GX_NO_SESSION')) {
        session_start_secure();
    }
    if (!defined('GX_SKIP_MAINTENANCE') && setting_bool('maintenance_mode') && !current_admin()) {
        http_response_code(503);
        header('Retry-After: 3600');
        if (defined('GX_IS_API')) {
            json_error('We are busy with maintenance. Please try again shortly.', 503);
        }
        require GX_ROOT . '/includes/layout/maintenance.php';
        exit;
    }
    // Release stock from unpaid orders whose hold has expired (Xneelo cron runs only every 2 hours).
    // Runs AFTER the page has been sent, so no visitor ever waits for it.
    if (!defined('GX_NO_SESSION')) {
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            OrderService::maybeExpire();
        });
    }
}
