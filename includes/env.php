<?php
/**
 * Configuration loader. Reads the site settings from ANY of these files (first found wins per setting,
 * placeholder values like "CHANGE-ME…" are ignored):
 *
 *   .env.php            (web root)   — PHP: return [...] array, define('DB_HOST', …), or $variables
 *   config/.env.php
 *   .env                (web root)   — KEY=VALUE lines
 *   config/.env
 *   config/env.php                  — the original GrazeX format (return ['db' => [...], ...])
 *
 * Key names are flexible: DB_HOST / DB_DATABASE / DB_NAME / DB_USERNAME / DB_USER / DB_PASSWORD / DB_PASS,
 * APP_URL, APP_KEY, APP_DEBUG, SETUP_TOKEN, MAIL_*, SMTP_*, YOCO_* — or the nested GrazeX array keys.
 * Dotfiles are blocked from the web by .htaccess.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

/** Canonical setting => accepted (flattened, upper-case) key names. */
const GX_ENV_ALIASES = [
    'app_env'      => ['APP_ENV', 'ENV', 'ENVIRONMENT'],
    'app_debug'    => ['APP_DEBUG', 'DEBUG'],
    'app_url'      => ['APP_URL', 'SITE_URL', 'BASE_URL', 'URL'],
    'app_key'      => ['APP_KEY', 'APP_SECRET', 'SECRET'],
    'setup_token'  => ['SETUP_TOKEN', 'APP_SETUP_TOKEN', 'INSTALL_TOKEN'],
    'db.host'      => ['DB_HOST', 'DB_HOSTNAME', 'DB_SERVER', 'DATABASE_HOST', 'MYSQL_HOST', 'DBHOST', 'HOST'],
    'db.port'      => ['DB_PORT', 'DATABASE_PORT', 'MYSQL_PORT', 'PORT'],
    'db.name'      => ['DB_NAME', 'DB_DATABASE', 'DB_DBNAME', 'DATABASE_NAME', 'DATABASE_DBNAME', 'DATABASE', 'MYSQL_DATABASE', 'DBNAME', 'DB'],
    'db.user'      => ['DB_USER', 'DB_USERNAME', 'DATABASE_USER', 'DATABASE_USERNAME', 'MYSQL_USER', 'DBUSER', 'USERNAME', 'USER'],
    'db.pass'      => ['DB_PASS', 'DB_PASSWORD', 'DATABASE_PASS', 'DATABASE_PASSWORD', 'MYSQL_PASSWORD', 'DBPASS', 'PASSWORD', 'PASS'],
    'mail.host'    => ['MAIL_HOST', 'SMTP_HOST'],
    'mail.port'    => ['MAIL_PORT', 'SMTP_PORT'],
    'mail.secure'  => ['MAIL_SECURE', 'MAIL_ENCRYPTION', 'SMTP_SECURE', 'SMTP_ENCRYPTION'],
    'mail.user'    => ['MAIL_USER', 'MAIL_USERNAME', 'SMTP_USER', 'SMTP_USERNAME'],
    'mail.pass'    => ['MAIL_PASS', 'MAIL_PASSWORD', 'SMTP_PASS', 'SMTP_PASSWORD'],
    'mail.from_email' => ['MAIL_FROM_EMAIL', 'MAIL_FROM_ADDRESS', 'MAIL_FROM', 'SMTP_FROM'],
    'mail.from_name'  => ['MAIL_FROM_NAME', 'SMTP_FROM_NAME'],
    'mail.admin_email' => ['MAIL_ADMIN_EMAIL', 'MAIL_ADMIN', 'ADMIN_EMAIL'],
    'yoco.mode'    => ['YOCO_MODE'],
    'yoco.test_secret_key'     => ['YOCO_TEST_SECRET_KEY', 'YOCO_SECRET_KEY_TEST', 'YOCO_TEST_KEY'],
    'yoco.live_secret_key'     => ['YOCO_LIVE_SECRET_KEY', 'YOCO_SECRET_KEY_LIVE', 'YOCO_LIVE_KEY'],
    'yoco.test_webhook_secret' => ['YOCO_TEST_WEBHOOK_SECRET', 'YOCO_WEBHOOK_SECRET_TEST'],
    'yoco.live_webhook_secret' => ['YOCO_LIVE_WEBHOOK_SECRET', 'YOCO_WEBHOOK_SECRET_LIVE'],
    // Single-key forms that follow YOCO_MODE
    'yoco._secret_key'     => ['YOCO_SECRET_KEY', 'YOCO_KEY'],
    'yoco._webhook_secret' => ['YOCO_WEBHOOK_SECRET'],
];

/** Example / empty values that must never be used. */
function gx_env_placeholder(mixed $v): bool
{
    if ($v === null || $v === '' || is_array($v)) {
        return true;
    }
    $s = strtolower(trim((string) $v));
    return $s === '' || str_starts_with($s, 'change-me') || str_starts_with($s, 'changeme') || str_contains($s, 'sqlnn.jnbn')
        || in_array($s, ['your_database_name', 'your_database_user', 'your_database_password', 'xxx', 'todo'], true);
}

/** Flatten nested arrays: ['db' => ['host' => 'x']] → ['DB_HOST' => 'x'] */
function gx_env_flatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = strtoupper(trim($prefix . (string) $k, '_'));
        if (is_array($v)) {
            $out += gx_env_flatten($v, $key . '_');
        } elseif (is_scalar($v) || $v === null) {
            $out[$key] = $v;
        }
    }
    return $out;
}

/** Parse KEY=VALUE text (.env style). */
function gx_env_parse_dotenv(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';' || !str_contains($line, '=')) {
            continue;
        }
        $line = preg_replace('/^export\s+/i', '', $line) ?? $line;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $q = $v[0];
            $end = strpos($v, $q, 1);
            $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
            if ($q === '"') {
                $v = str_replace(['\\n', '\\"'], ["\n", '"'], $v);
            }
        } else {
            $v = trim(preg_replace('/\s+#.*$/', '', $v) ?? $v);
        }
        $out[strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $k) ?? $k)] = $v;
    }
    return $out;
}

/** Read a PHP config file in isolation: returned array, constants it defines, or variables it sets. */
function gx_env_read_php(string $file): array
{
    $constsBefore = get_defined_constants(true)['user'] ?? [];
    $envBefore = getenv();
    $result = (static function (string $__file): array {
        ob_start();
        try {
            $__ret = include $__file;
        } finally {
            ob_end_clean();
        }
        $__vars = get_defined_vars();
        unset($__vars['__file'], $__vars['__ret']);
        return [$__ret, $__vars];
    })($file);
    [$ret, $vars] = $result;
    $flat = [];
    if (is_array($ret)) {
        $flat += gx_env_flatten($ret);
    }
    foreach ($vars as $k => $v) {
        if (is_array($v)) {
            $flat += gx_env_flatten($v, $k . '_');
        } elseif (is_scalar($v)) {
            $flat[strtoupper($k)] = $v;
        }
    }
    foreach (array_diff_key(get_defined_constants(true)['user'] ?? [], $constsBefore) as $k => $v) {
        if (is_scalar($v)) {
            $flat[strtoupper($k)] = $v;
        }
    }
    foreach (array_diff_key(getenv() ?: [], $envBefore ?: []) as $k => $v) {
        $flat[strtoupper($k)] = $v;
    }
    return $flat;
}

/**
 * Load and merge all config sources. Returns [config, report].
 * report: [file => ['status' => 'used'|'empty'|'error', 'keys' => [...canonical keys provided], 'error' => ?string]]
 */
function gx_env_load(): array
{
    $candidates = [
        '.env.php' => 'php', 'config/.env.php' => 'php', '.env' => 'dotenv', 'config/.env' => 'dotenv', 'config/env.php' => 'php',
    ];
    $config = [];
    $report = [];
    foreach ($candidates as $rel => $type) {
        $file = GX_ROOT . '/' . $rel;
        if (!@is_file($file) || !@is_readable($file)) {
            continue;
        }
        try {
            $raw = (string) @file_get_contents($file);
            // A ".env.php" written as plain KEY=VALUE text (no <?php) is read as .env, never executed
            $flat = ($type === 'php' && str_contains($raw, '<?php')) ? gx_env_read_php($file) : gx_env_parse_dotenv($raw);
        } catch (Throwable $e) {
            $report[$rel] = ['status' => 'error', 'keys' => [], 'error' => get_class($e) . ' on line ' . $e->getLine() . ': ' . $e->getMessage()];
            continue;
        }
        // A PDO DSN ("mysql:host=…;dbname=…;port=…") anywhere in the file also counts
        foreach ($flat as $v) {
            if (is_string($v) && preg_match('/^mysql:/i', $v)) {
                foreach (explode(';', substr($v, 6)) as $part) {
                    [$dk, $dv] = array_pad(explode('=', $part, 2), 2, '');
                    $map = ['host' => 'DB_HOST', 'dbname' => 'DB_NAME', 'port' => 'DB_PORT'];
                    if (isset($map[strtolower(trim($dk))])) {
                        $flat[$map[strtolower(trim($dk))]] ??= trim($dv);
                    }
                }
            }
        }
        $mode = null;
        foreach (GX_ENV_ALIASES['yoco.mode'] as $a) {
            if (isset($flat[$a]) && !gx_env_placeholder($flat[$a])) {
                $mode = strtolower((string) $flat[$a]) === 'live' ? 'live' : 'test';
            }
        }
        $provided = [];
        foreach (GX_ENV_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $a) {
                if (!array_key_exists($a, $flat) || gx_env_placeholder($flat[$a])) {
                    continue;
                }
                $target = match ($canonical) {
                    'yoco._secret_key' => 'yoco.' . ($mode ?? 'test') . '_secret_key',
                    'yoco._webhook_secret' => 'yoco.' . ($mode ?? 'test') . '_webhook_secret',
                    default => $canonical,
                };
                [$g, $k] = array_pad(explode('.', $target, 2), 2, null);
                $already = $k === null ? isset($config[$g]) : isset($config[$g][$k]);
                if (!$already) {
                    $val = $flat[$a];
                    if ($k === null) {
                        $config[$g] = $val;
                    } else {
                        $config[$g][$k] = $val;
                    }
                    $provided[] = $target;
                }
                break;
            }
        }
        $report[$rel] = ['status' => $provided ? 'used' : 'empty', 'keys' => $provided, 'error' => null];
    }

    // Normalise types and defaults
    $config['app_debug'] = in_array(strtolower((string) ($config['app_debug'] ?? '')), ['1', 'true', 'yes', 'on'], true);
    $config['app_env'] ??= 'production';
    if (isset($config['app_key']) && str_starts_with((string) $config['app_key'], 'base64:')) {
        $config['app_key'] = bin2hex((string) base64_decode(substr((string) $config['app_key'], 7)));
    }
    if (isset($config['app_url'])) {
        $config['app_url'] = rtrim((string) $config['app_url'], '/');
    }
    $config['db']['port'] = (int) ($config['db']['port'] ?? 3306) ?: 3306;
    $config['mail']['port'] = (int) ($config['mail']['port'] ?? 465) ?: 465;
    $config['mail']['secure'] = strtolower((string) ($config['mail']['secure'] ?? 'ssl')) === 'tls' ? 'tls' : 'ssl';
    $config['mail']['from_email'] ??= $config['mail']['user'] ?? null;
    $config['mail']['from_name'] ??= 'GrazeX';
    $config['mail']['admin_email'] ??= $config['mail']['from_email'] ?? null;
    $config['yoco']['mode'] = strtolower((string) ($config['yoco']['mode'] ?? 'test')) === 'live' ? 'live' : 'test';
    return [$config, $report];
}

/** Settings that must be present for the site to work. */
function gx_env_missing(array $config): array
{
    $missing = [];
    foreach (['db.host' => 'DB_HOST', 'db.name' => 'DB_NAME', 'db.user' => 'DB_USER', 'db.pass' => 'DB_PASS', 'app_url' => 'APP_URL', 'app_key' => 'APP_KEY'] as $k => $label) {
        [$g, $s] = array_pad(explode('.', $k, 2), 2, null);
        $v = $s === null ? ($config[$g] ?? null) : ($config[$g][$s] ?? null);
        if (gx_env_placeholder($v)) {
            $missing[] = $label;
        }
    }
    return $missing;
}

/**
 * Explain the database state in plain words (never shows passwords).
 * Returns ['ok' => bool, 'state' => string, 'message' => string].
 */
function gx_db_diagnose(): array
{
    $cfg = $GLOBALS['GX_ENV']['db'] ?? [];
    $missing = array_keys(array_filter(['DB_HOST' => $cfg['host'] ?? null, 'DB_NAME' => $cfg['name'] ?? null, 'DB_USER' => $cfg['user'] ?? null, 'DB_PASS' => $cfg['pass'] ?? null],
        static fn($v) => gx_env_placeholder($v)));
    if ($missing) {
        return ['ok' => false, 'state' => 'missing', 'message' => 'Database settings missing in your settings file: ' . implode(', ', $missing) . '.'];
    }
    try {
        $pdo = db();
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
        $msg = match (true) {
            $code === 1045 => 'The database refused the username or password (DB_USER / DB_PASS).',
            $code === 1044 => 'This database user has no access to database "' . $cfg['name'] . '" — give the user access in Manage MySQL.',
            $code === 1049 => 'No database called "' . $cfg['name'] . '" exists on that server — check DB_NAME.',
            in_array($code, [2002, 2003, 2005, 2006], true) => 'Cannot reach the database server "' . $cfg['host'] . '" — check DB_HOST (on Xneelo it looks like sqlNN.jnbN.host-h.net or dediNNN.cpt1.host-h.net).',
            default => 'Could not connect to the database (error ' . $code . ').',
        };
        return ['ok' => false, 'state' => 'connect', 'message' => $msg];
    }
    $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    if ($tables === 0) {
        return ['ok' => false, 'state' => 'empty', 'message' => 'Connected — but the database is empty. Paste database/install.sql into phpMyAdmin (SQL tab) and click Go.'];
    }
    $hasCore = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('divisions','settings','orders','products','lots')")->fetchColumn();
    if ($hasCore < 5) {
        return ['ok' => false, 'state' => 'partial', 'message' => 'Connected — but some GrazeX tables are missing (' . $tables . ' tables found). Import database/install.sql into an EMPTY database.'];
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM divisions')->fetchColumn() < 2) {
        return ['ok' => false, 'state' => 'seed', 'message' => 'Connected — tables exist but the starter data is missing. Re-import database/install.sql into an empty database.'];
    }
    return ['ok' => true, 'state' => 'ok', 'message' => 'Connected — ' . $tables . ' tables.'];
}
