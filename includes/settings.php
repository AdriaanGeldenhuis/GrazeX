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

/** srcset for an image-type content block (all generated sizes), or $fallback when no photo is set. */
function content_image_srcset(string $scope, string $key, string $fallback = ''): string
{
    $mediaId = content_scope($scope)[$key]['media_id'] ?? null;
    return $mediaId ? (media_srcset((int) $mediaId, 'wide') ?: $fallback) : $fallback;
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

/** Request-wide cache of media rows (id => row|null). */
function &media_cache(): array
{
    static $cache = [];
    return $cache;
}

/** One media row (id, base_path, variants, focal point), loaded once per request. */
function media_row(int $mediaId): ?array
{
    $cache = &media_cache();
    if (!array_key_exists($mediaId, $cache)) {
        $cache[$mediaId] = db_one('SELECT id, base_path, variants, focal_x, focal_y FROM media WHERE id = ?', [$mediaId]);
    }
    return $cache[$mediaId];
}

/** Load the media rows for a list of images in ONE query (call before rendering the list). */
function media_prime(array $ids): void
{
    $cache = &media_cache();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0 && !array_key_exists($i, $cache))));
    if (!$ids) {
        return;
    }
    foreach ($ids as $i) {
        $cache[$i] = null;
    }
    foreach (db_all('SELECT id, base_path, variants, focal_x, focal_y FROM media WHERE id IN (' . db_placeholders($ids) . ')', $ids) as $r) {
        $cache[(int) $r['id']] = $r;
    }
}

/** Drop a cached media row (after its files changed). */
function media_forget(int $mediaId): void
{
    $cache = &media_cache();
    unset($cache[$mediaId]);
}

/** Every table that can use a media item — an image is only "unused" when none of these points at it. */
const GX_MEDIA_USES = ['product_media', 'lot_media', 'content_blocks', 'gallery_items', 'testimonials', 'categories', 'brands'];

/** SQL expression counting the uses of the media id in $idExpr (a column such as m.id, or an integer). */
function media_uses_sql(string $idExpr): string
{
    return '(' . implode(' + ', array_map(static fn(string $t): string => "(SELECT COUNT(*) FROM {$t} WHERE media_id = {$idExpr})", GX_MEDIA_USES)) . ')';
}

/**
 * Public URL for a media item at a given width (closest generated size).
 * $ratio: 'wide' (16:9 crop) or 'orig' (original aspect).
 */
function media_url(int $mediaId, int $width = 960, string $ratio = 'wide'): ?string
{
    $m = media_row($mediaId);
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
    $m = media_row($mediaId);
    $variants = json_decode((string) ($m['variants'] ?? ''), true) ?: [];
    $set = $variants[$ratio] ?? [];
    $out = [];
    foreach ($set as $w => $path) {
        $out[] = '/' . ltrim($path, '/') . ' ' . (int) $w . 'w';
    }
    return implode(', ', $out);
}
