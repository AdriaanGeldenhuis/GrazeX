<?php
/**
 * Time-based one-time passwords (RFC 6238) for admin 2FA — works with Google Authenticator,
 * Microsoft Authenticator, Authy etc.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function totp_secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    foreach (str_split(random_bytes(20)) as $b) {
        $s .= $alphabet[ord($b) & 31];
    }
    return $s;
}

function totp_base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int) bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, ?int $time = null): string
{
    $counter = intdiv($time ?? time(), 30);
    $bin = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $bin, totp_base32_decode($secret), true);
    $offset = ord($hash[19]) & 0xf;
    $code = ((ord($hash[$offset]) & 0x7f) << 24 | (ord($hash[$offset + 1]) & 0xff) << 16 | (ord($hash[$offset + 2]) & 0xff) << 8 | (ord($hash[$offset + 3]) & 0xff)) % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

/**
 * Accept the current code and one step either side (clock drift).
 * Returns the matched 30-second time step (store it to refuse the same code twice), or null.
 */
function totp_match(string $secret, string $code): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secret === '') {
        return null;
    }
    foreach ([-30, 0, 30] as $drift) {
        $t = time() + $drift;
        if (hash_equals(totp_code($secret, $t), $code)) {
            return intdiv($t, 30);
        }
    }
    return null;
}

function totp_verify(string $secret, string $code): bool
{
    return totp_match($secret, $code) !== null;
}

/** Secrets are stored encrypted with APP_KEY ("enc:…"); older plain secrets still work. */
function totp_secret_store(string $secret): string
{
    return 'enc:' . secret_encrypt($secret);
}

function totp_secret_load(?string $stored): string
{
    $stored = (string) $stored;
    if (str_starts_with($stored, 'enc:')) {
        return (string) secret_decrypt(substr($stored, 4));
    }
    return $stored;
}

/** One-time recovery codes: returns [plain codes to show once, JSON of their hashes to store]. */
function totp_recovery_generate(int $count = 8): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$codes, json_encode(array_map(static fn($c) => token_hash(totp_recovery_normalise($c)), $codes))];
}

function totp_recovery_normalise(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/** Use (and burn) a recovery code for this admin. Safe against two requests using the same code. */
function totp_recovery_use(int $adminId, string $code): bool
{
    $norm = totp_recovery_normalise($code);
    if (strlen($norm) !== 8) {
        return false;
    }
    $stored = db_val('SELECT totp_recovery FROM admin_users WHERE id = ?', [$adminId]);
    $hashes = json_decode((string) $stored, true);
    if (!is_array($hashes)) {
        return false;
    }
    $hash = token_hash($norm);
    $i = array_search($hash, $hashes, true);
    if ($i === false) {
        return false;
    }
    unset($hashes[$i]);
    return db_exec('UPDATE admin_users SET totp_recovery = ? WHERE id = ? AND totp_recovery = ?',
        [json_encode(array_values($hashes)), $adminId, $stored]) === 1;
}

function totp_uri(string $secret, string $email): string
{
    return 'otpauth://totp/' . rawurlencode('GrazeX:' . $email) . '?secret=' . $secret . '&issuer=GrazeX&digits=6&period=30';
}
