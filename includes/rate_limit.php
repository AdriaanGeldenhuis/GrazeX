<?php
/**
 * DB-backed fixed-window rate limiter (works on shared hosting, no Redis needed).
 *
 *   rate_limit_or_fail('login', rate_limit_ip(), 10, 900);      // 10 per 15 min per IP (IPv6: per /64)
 *   rate_limit_or_fail('login_email', $email, 5, 900);         // 5 per 15 min per account
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

/** Returns true when the request is allowed (and counts it). */
function rate_limit_hit(string $action, string $key, int $max, int $windowSeconds): bool
{
    $bucket = hash('sha256', $action . '|' . mb_strtolower($key));
    $now = time();

    return db_tx(static function () use ($bucket, $action, $max, $windowSeconds, $now): bool {
        $row = db_one('SELECT id, hits, window_start, blocked_until FROM rate_limits WHERE bucket = ? FOR UPDATE', [$bucket]);
        if (!$row) {
            db_exec('INSERT INTO rate_limits (bucket, action, hits, window_start) VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE hits = hits + 1', [$bucket, $action, date('Y-m-d H:i:s', $now)]);
            return true;
        }
        if ($row['blocked_until'] && strtotime($row['blocked_until']) > $now) {
            return false;
        }
        if (strtotime($row['window_start']) + $windowSeconds <= $now) {
            db_exec('UPDATE rate_limits SET hits = 1, window_start = ?, blocked_until = NULL WHERE id = ?',
                [date('Y-m-d H:i:s', $now), $row['id']]);
            return true;
        }
        $hits = (int) $row['hits'] + 1;
        $blocked = $hits > $max ? date('Y-m-d H:i:s', strtotime($row['window_start']) + $windowSeconds) : null;
        db_exec('UPDATE rate_limits SET hits = ?, blocked_until = ? WHERE id = ?', [$hits, $blocked, $row['id']]);
        return $hits <= $max;
    });
}

function rate_limit_or_fail(string $action, string $key, int $max, int $windowSeconds): void
{
    if (!rate_limit_hit($action, $key, $max, $windowSeconds)) {
        header('Retry-After: ' . $windowSeconds);
        json_error(t('error.rate_limited'), 429);
    }
}

/** True while this bucket is blocked. Does not count a hit (used to count only failed logins). */
function rate_limit_blocked(string $action, string $key): bool
{
    $until = db_val('SELECT blocked_until FROM rate_limits WHERE bucket = ?', [hash('sha256', $action . '|' . mb_strtolower($key))]);
    return $until !== null && strtotime((string) $until) > time();
}

/**
 * The client address used as a rate-limit key. IPv6 clients usually control a whole /64,
 * so they are limited per /64 (audit logs keep the full address via client_ip()).
 */
function rate_limit_ip(): string
{
    return ip_bucket(client_ip());
}

/** An address as a rate-limit bucket: IPv4 as is, IPv6 as its /64. */
function ip_bucket(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ($bin = @inet_pton($ip)) !== false && strlen($bin) === 16) {
        return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return $ip;
}

/** Clear a bucket (e.g. after a successful login). */
function rate_limit_clear(string $action, string $key): void
{
    db_exec('DELETE FROM rate_limits WHERE bucket = ?', [hash('sha256', $action . '|' . mb_strtolower($key))]);
}
