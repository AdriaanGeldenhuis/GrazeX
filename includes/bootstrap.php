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
// Environment
// ---------------------------------------------------------------------
$envFile = GX_ROOT . '/config/env.php';
if (!is_file($envFile)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>GrazeX — setup needed</title>'
       . '<body style="background:#050606;color:#f4f4ef;font-family:system-ui;padding:40px">'
       . '<h1>GrazeX is almost ready</h1><p>Copy <code>config/env.example.php</code> to '
       . '<code>config/env.php</code> on the server and fill in the database details.</p></body>';
    exit;
}
$GLOBALS['GX_ENV'] = require $envFile;

function env(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['GX_ENV'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
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
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    gx_log('error', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? 'cli', 'trace' => GX_DEBUG ? $e->getTraceAsString() : null]);
    if (GX_IS_CLI) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $wantsJson = defined('GX_IS_API') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => GX_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.']);
    } else {
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Something went wrong — GrazeX</title>'
           . '<body style="background:#050606;color:#f4f4ef;font-family:system-ui;padding:40px;line-height:1.6">'
           . '<h1 style="color:#ff7a00">Something went wrong</h1><p>Please try again in a moment.</p>'
           . (GX_DEBUG ? '<pre style="white-space:pre-wrap;color:#9ea59f">' . htmlspecialchars((string) $e) . '</pre>' : '')
           . '<p><a style="color:#ff9f1a" href="/">Back to GrazeX</a></p></body>';
    }
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
    // Release stock from unpaid orders whose hold has expired (Xneelo cron runs only every 2 hours)
    if (!defined('GX_NO_SESSION')) {
        OrderService::maybeExpire();
    }
}
