<?php
/**
 * Admin media API.
 *   POST multipart {action: upload, file}            → {id, thumb, large, wide}
 *   GET  ?action=list                                → latest images
 *   GET  ?action=get&id=                             → one image
 *   POST {action: update, id, focal_x, focal_y, alt_text}
 *   POST {action: delete, id}                        → only when unused
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

$admin = require_admin_api('media.manage');

$out = static function (array $m): array {
    $id = (int) $m['id'];
    return ['id' => $id, 'thumb' => media_url($id, 480, 'wide') ?? media_url($id, 480, 'orig'), 'large' => media_url($id, 1600, 'orig'),
            'wide' => media_url($id, 960, 'wide'), 'focal_x' => (float) $m['focal_x'], 'focal_y' => (float) $m['focal_y'],
            'alt_text' => $m['alt_text'], 'name' => $m['original_name'], 'width' => (int) $m['width'], 'height' => (int) $m['height']];
};

if (request_method() === 'GET') {
    if (query_str('action') === 'get') {
        $m = db_one('SELECT * FROM media WHERE id = ?', [query_int('id')]);
        $m ? json_ok($out($m)) : json_error('Not found.', 404);
    }
    json_ok(['items' => array_map($out, db_all('SELECT * FROM media ORDER BY id DESC LIMIT 150'))]);
}

api_post();
$action = (string) input('action');
try {
    switch ($action) {
        case 'upload':
            rate_limit_or_fail('media_upload', 'a' . $admin['id'], 120, 600);
            $id = store_media_image($_FILES['file'] ?? null, (string) ($_POST['alt'] ?? ''));
            audit('media.uploaded', 'media', $id);
            json_ok($out(db_one('SELECT * FROM media WHERE id = ?', [$id])), 'Image uploaded.');
        case 'update':
            $m = db_one('SELECT * FROM media WHERE id = ?', [(int) input('id')]);
            if (!$m) {
                json_error('Not found.', 404);
            }
            $fx = max(0, min(1, (float) input('focal_x', $m['focal_x'])));
            $fy = max(0, min(1, (float) input('focal_y', $m['focal_y'])));
            if (abs($fx - (float) $m['focal_x']) > 0.001 || abs($fy - (float) $m['focal_y']) > 0.001) {
                media_refocus((int) $m['id'], $fx, $fy);
            }
            db_exec('UPDATE media SET alt_text = ? WHERE id = ?', [mb_substr((string) input('alt_text', ''), 0, 255) ?: null, $m['id']]);
            json_ok($out(db_one('SELECT * FROM media WHERE id = ?', [$m['id']])), 'Image updated.');
        case 'delete':
            $id = (int) input('id');
            $used = (int) db_val('SELECT (SELECT COUNT(*) FROM product_media WHERE media_id = ?) + (SELECT COUNT(*) FROM lot_media WHERE media_id = ?)
                                 + (SELECT COUNT(*) FROM content_blocks WHERE media_id = ?) + (SELECT COUNT(*) FROM gallery_items WHERE media_id = ?)', [$id, $id, $id, $id]);
            if ($used) {
                json_error('This image is still used on the site — remove it there first.', 409);
            }
            $m = db_one('SELECT * FROM media WHERE id = ?', [$id]);
            if ($m) {
                foreach (json_decode((string) $m['variants'], true) ?: [] as $set) {
                    foreach ($set as $path) {
                        $abs = GX_ROOT . '/' . $path;
                        if (str_starts_with($path, 'uploads/media/') && is_file($abs)) {
                            @unlink($abs);
                        }
                    }
                }
                db_exec('DELETE FROM media WHERE id = ?', [$id]);
                audit('media.deleted', 'media', $id);
            }
            json_ok([], 'Image deleted.');
        default:
            json_error('Unknown action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 422);
}
