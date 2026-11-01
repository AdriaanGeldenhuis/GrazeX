<?php
/**
 * DB-backed settings and CMS content blocks (cached per request).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            gx_log('error', 'Settings unavailable: ' . $e->getMessage());
        }
    }
    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $all = settings_all();
    $v = $all[$key] ?? null;
    return ($v === null || $v === '') ? $default : (string) $v;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key);
    return is_numeric($v) ? (int) $v : $default;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting($key);
    return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

function setting_set(string $key, ?string $value): void
{
    db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    settings_all(true);
}

/**
 * Content block for a page scope ('gateway', 'feeds', 'ranch', 'global').
 * Returns the value, or $default when the block is missing/empty.
 */
function content(string $scope, string $key, string $default = ''): string
{
    $blocks = content_scope($scope);
    $v = $blocks[$key]['value'] ?? null;
    return ($v === null || $v === '') ? $default : (string) $v;
}

/** Image URL for an image-type content block, or $fallback. */
function content_image(string $scope, string $key, string $fallback = '', int $width = 1600): string
{
    $blocks = content_scope($scope);
    $mediaId = $blocks[$key]['media_id'] ?? null;
    if ($mediaId) {
        $url = media_url((int) $mediaId, $width);
        if ($url) {
            return $url;
        }
    }
    return $fallback;
}

function content_lines(string $scope, string $key): array
{
    $lines = preg_split('/\R/', content($scope, $key)) ?: [];
    return array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
}

function content_scope(string $scope): array
{
    static $cache = [];
    if (!isset($cache[$scope])) {
        $cache[$scope] = [];
        try {
            foreach (db_all('SELECT block_key, value, media_id FROM content_blocks WHERE scope = ?', [$scope]) as $row) {
                $cache[$scope][$row['block_key']] = $row;
            }
        } catch (PDOException $e) {
            gx_log('error', 'Content unavailable: ' . $e->getMessage());
        }
    }
    return $cache[$scope];
}

/**
 * Public URL for a media item at a given width (closest generated size).
 * $ratio: 'wide' (16:9 crop) or 'orig' (original aspect).
 */
function media_url(int $mediaId, int $width = 960, string $ratio = 'wide'): ?string
{
    static $cache = [];
    if (!array_key_exists($mediaId, $cache)) {
        $cache[$mediaId] = db_one('SELECT base_path, variants FROM media WHERE id = ?', [$mediaId]);
    }
    $m = $cache[$mediaId];
    if (!$m) {
        return null;
    }
    $variants = json_decode((string) $m['variants'], true) ?: [];
    $set = $variants[$ratio] ?? $variants['orig'] ?? [];
    if (!$set) {
        return null;
    }
    ksort($set);
    $chosen = null;
    foreach ($set as $w => $path) {
        $chosen = $path;
        if ((int) $w >= $width) {
            break;
        }
    }
    return $chosen ? '/' . ltrim($chosen, '/') : null;
}

/** srcset string for a media item. */
function media_srcset(int $mediaId, string $ratio = 'wide'): string
{
    $m = db_one('SELECT variants FROM media WHERE id = ?', [$mediaId]);
    $variants = json_decode((string) ($m['variants'] ?? ''), true) ?: [];
    $set = $variants[$ratio] ?? [];
    $out = [];
    foreach ($set as $w => $path) {
        $out[] = '/' . ltrim($path, '/') . ' ' . (int) $w . 'w';
    }
    return implode(', ', $out);
}
