<?php
/**
 * General helpers: escaping, URLs, money, JSON responses, request input.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

// ---------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for use inside an HTML attribute that holds JSON. */
function e_json(mixed $value): string
{
    return e(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function app_url(string $path = ''): string
{
    return rtrim((string) env('app_url', ''), '/') . '/' . ltrim($path, '/');
}

/** Versioned asset URL so browsers cache aggressively but pick up new deploys. */
function asset(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $file = GX_ROOT . $path;
    $v = is_file($file) ? (string) filemtime($file) : GX_VERSION;
    return $path . '?v=' . $v;
}

function redirect(string $url, int $code = 302): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

/**
 * The setup wizard locks itself with a file (not only a database row), so it stays locked —
 * and shows no server diagnostics — even while the database is unreachable.
 */
function setup_locked(): bool
{
    return is_file(GX_ROOT . '/storage/private/setup.lock');
}

function setup_lock_write(): void
{
    if (!setup_locked()) {
        @file_put_contents(GX_ROOT . '/storage/private/setup.lock', date('c') . "\n");
    }
}

/** Only allow same-site relative redirect targets (prevents open redirects). */
function safe_next(?string $next, string $fallback = '/'): string
{
    if (!$next || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')
        || preg_match('/[\x00-\x20\x7F]/', $next)) {
        return $fallback;
    }
    return $next;
}

// ---------------------------------------------------------------------
// Money & numbers (all money is integer cents, VAT-inclusive)
// ---------------------------------------------------------------------
function money(?int $cents, bool $showCents = true): string
{
    $cents = (int) ($cents ?? 0);
    $neg = $cents < 0;
    $value = number_format(abs($cents) / 100, $showCents ? 2 : 0, '.', ' ');
    return ($neg ? '-' : '') . 'R ' . $value;
}

/** Parse "1 234.50" / "1234,50" / "R1,234.50" into cents. Returns null when invalid. */
function to_cents(mixed $input): ?int
{
    if (is_int($input)) {
        return $input * 100;
    }
    $s = trim((string) $input);
    $s = preg_replace('/[R\s]/i', '', $s) ?? '';
    if ($s === '') {
        return null;
    }
    // Treat a single comma followed by 1-2 digits at the end as decimal separator
    if (preg_match('/,\d{1,2}$/', $s) && !str_contains($s, '.')) {
        $s = str_replace(',', '.', $s);
    }
    $s = str_replace(',', '', $s);
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int) round(((float) $s) * 100);
}

/** VAT portion of a VAT-inclusive amount. */
function vat_from_incl(int $inclCents, int $rateBp): int
{
    if ($rateBp <= 0) {
        return 0;
    }
    return (int) round($inclCents * $rateBp / (10000 + $rateBp));
}

function kg(?float $value, int $decimals = 1): string
{
    return $value === null ? '—' : number_format($value, $decimals, '.', ' ') . ' kg';
}

// ---------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------
function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $date, string $format = 'j M Y'): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function fmt_datetime(?string $date): string
{
    return fmt_date($date, 'j M Y, H:i');
}

// ---------------------------------------------------------------------
// Strings & tokens
// ---------------------------------------------------------------------
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = strtr($text, ['ê' => 'e', 'ë' => 'e', 'é' => 'e', 'è' => 'e', 'ô' => 'o', 'ö' => 'o', 'û' => 'u',
                          'ü' => 'u', 'î' => 'i', 'ï' => 'i', 'á' => 'a', 'â' => 'a', '&' => ' and ']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * The site's secret key. If the settings file has no proper APP_KEY (32+ characters), a key derived from
 * the database credentials is used instead — never a publicly known value — and the admin shows a warning.
 */
function gx_app_key(): string
{
    $k = (string) env('app_key', '');
    if (!gx_env_placeholder($k) && strlen($k) >= 32) {
        return $k;
    }
    return hash('sha256', 'gx-fallback|' . env('db.user', '') . '|' . env('db.pass', '') . '|' . env('db.name', '') . '|' . env('db.host', ''));
}

/** Settings the owner must still add to the settings file (shown in the admin). */
function gx_settings_problems(): array
{
    $missing = array_values(array_diff(gx_env_missing($GLOBALS['GX_ENV'] ?? []), ['DB_PASS']));
    $k = (string) env('app_key', '');
    if (!in_array('APP_KEY', $missing, true) && strlen($k) < 32) {
        $missing[] = 'APP_KEY (must be at least 32 characters)';
    }
    return $missing;
}

function token_hash(string $token): string
{
    return hash_hmac('sha256', $token, gx_app_key());
}

function str_limit(?string $text, int $limit = 140): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
    return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: 'GX';
}

/** wa.me link with optional prefilled message. */
function whatsapp_link(?string $number, string $message = ''): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $number) ?? '';
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '0')) {
        $digits = '27' . substr($digits, 1);
    }
    return 'https://wa.me/' . $digits . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

// ---------------------------------------------------------------------
// Request
// ---------------------------------------------------------------------
function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Decoded JSON body (cached). */
function input_json(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : [];
    }
    return $data;
}

/** Read one input value from JSON body, POST or GET (in that order). */
function input(string $key, mixed $default = null): mixed
{
    $json = input_json();
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

/** All input merged: GET < POST < JSON. */
function input_all(): array
{
    return array_merge($_GET, $_POST, input_json());
}

function query_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

/** ?page= as a safe page number (1…10000) — huge values must never reach OFFSET arithmetic. */
function query_page(string $key = 'page'): int
{
    return max(1, min(10000, query_int($key, 1)));
}

function query_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}

// ---------------------------------------------------------------------
// JSON API responses
// ---------------------------------------------------------------------
function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $data = [], string $message = ''): never
{
    $payload = ['ok' => true, 'data' => $data];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    json_response($payload);
}

function json_error(string $message, int $status = 400, array $errors = []): never
{
    $payload = ['ok' => false, 'error' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    json_response($payload, $status);
}

/** Enforce HTTP method for an API endpoint. */
function require_method(string ...$methods): void
{
    if (!in_array(request_method(), $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_error('Method not allowed.', 405);
    }
}

/**
 * www.example.com ↔ example.com: GET/HEAD requests on the other variant go to the APP_URL host (one site, one
 * session cookie, one set of search results). Never for POSTs (webhooks, forms) and never for unrelated hosts.
 */
function canonical_host_redirect(): ?string
{
    $app = parse_url((string) env('app_url', ''));
    $want = strtolower((string) ($app['host'] ?? ''));
    $have = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($want === '' || $have === '' || $want === $have || !in_array(request_method(), ['GET', 'HEAD'], true)) {
        return null;
    }
    if ($have !== 'www.' . $want && 'www.' . $have !== $want) {
        return null;
    }
    $origin = ($app['scheme'] ?? 'https') . '://' . $want . (isset($app['port']) ? ':' . $app['port'] : '');
    return $origin . ($_SERVER['REQUEST_URI'] ?? '/');
}

// ---------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(self), geolocation=(self), microphone=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; "
        . "frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://www.google.com https://maps.google.com; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------------------------------------------------------------------
// Divisions
// ---------------------------------------------------------------------
function division(string|int $codeOrId): ?array
{
    $GLOBALS['GX_DIV_CACHE'] ??= [];
    $key = (string) $codeOrId;
    if (!array_key_exists($key, $GLOBALS['GX_DIV_CACHE'])) {
        $GLOBALS['GX_DIV_CACHE'][$key] = is_int($codeOrId) || ctype_digit($key)
            ? db_one('SELECT * FROM divisions WHERE id = ?', [(int) $codeOrId])
            : db_one('SELECT * FROM divisions WHERE code = ?', [$key]);
    }
    return $GLOBALS['GX_DIV_CACHE'][$key];
}

function division_id(string $code): int
{
    if (!isset(GX_DIVISIONS[$code])) {
        throw new InvalidArgumentException('Unknown division: ' . $code);
    }
    return GX_DIVISIONS[$code]['id'];
}

function division_code(int $id): string
{
    foreach (GX_DIVISIONS as $code => $d) {
        if ($d['id'] === $id) {
            return $code;
        }
    }
    throw new InvalidArgumentException('Unknown division id: ' . $id);
}

// ---------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------
function audit(string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
{
    $admin = function_exists('current_admin') ? current_admin() : null;
    $customerId = function_exists('customer_id') ? customer_id() : null;
    $actorType = $admin ? 'admin' : ($customerId ? 'customer' : 'system');
    $actorId = $admin['id'] ?? $customerId;
    try {
        db_exec('INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, details, ip, user_agent)
                 VALUES (?,?,?,?,?,?,?,?)', [
            $actorType, $actorId, $action, $entityType, $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            GX_IS_CLI ? null : client_ip(), GX_IS_CLI ? 'cli' : user_agent(),
        ]);
    } catch (Throwable $e) {
        gx_log('warning', 'Audit write failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Secrets at rest (encrypted with app_key — a leaked database alone does not expose them)
// ---------------------------------------------------------------------
function secret_encrypt(string $plain): string
{
    $key = hash('sha256', gx_app_key(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function secret_decrypt(?string $stored): ?string
{
    $raw = base64_decode((string) $stored, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    // Current key first; then the raw settings value, which older versions used even when it was empty or short
    foreach (array_unique([gx_app_key(), (string) env('app_key', '')]) as $k) {
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $k, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain !== false) {
            return $plain;
        }
    }
    return null;
}
