<?php
/** Admin content API: {action: save, scope, blocks{key: value}, images{key: mediaId}} · {action: preview, md} */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
require_admin_api('content.manage');

if (input('action') === 'preview') {
    json_ok(['html' => markdown((string) input('md', ''))]);
}
$scope = (string) input('scope');
if (!in_array($scope, ['gateway', 'feeds', 'ranch', 'global'], true)) {
    json_error('Unknown section.', 400);
}
db_tx(static function () use ($scope): void {
    foreach ((array) input('blocks', []) as $key => $value) {
        if (!preg_match('/^[a-z0-9_]{1,100}$/', (string) $key)) {
            continue;
        }
        db_exec('UPDATE content_blocks SET value = ? WHERE scope = ? AND block_key = ?', [mb_substr((string) $value, 0, 20000), $scope, $key]);
    }
    foreach ((array) input('images', []) as $key => $mid) {
        if (!preg_match('/^[a-z0-9_]{1,100}$/', (string) $key)) {
            continue;
        }
        $mid = (int) $mid ?: null;
        db_exec("INSERT INTO content_blocks (scope, block_key, type, label, media_id) VALUES (?, ?, 'image', ?, ?) ON DUPLICATE KEY UPDATE media_id = VALUES(media_id)",
            [$scope, $key, ucfirst(str_replace('_', ' ', (string) $key)), $mid]);
    }
});
audit('content.saved', 'content', null, ['scope' => $scope]);
json_ok([], 'Content saved — it is live now.');
