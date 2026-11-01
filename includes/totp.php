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

/** Accept the current code and one step either side (clock drift). */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }
    foreach ([-30, 0, 30] as $drift) {
        if (hash_equals(totp_code($secret, time() + $drift), $code)) {
            return true;
        }
    }
    return false;
}

function totp_uri(string $secret, string $email): string
{
    return 'otpauth://totp/' . rawurlencode('GrazeX:' . $email) . '?secret=' . $secret . '&issuer=GrazeX&digits=6&period=30';
}
