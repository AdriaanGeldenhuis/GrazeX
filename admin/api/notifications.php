<?php
/** GET → latest admin notifications · POST {action: read_all} */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_admin_api();
$ids = admin_division_ids();
// Only the notifications this admin can see: their divisions + general ones, and only the kinds they may act on
[$hide, $hideParams] = admin_notification_filter();
$mine = '(division_id IS NULL OR division_id IN (' . db_placeholders($ids) . '))' . $hide;
$params = array_merge($ids, $hideParams);
if (request_method() === 'POST') {
    csrf_require();
    db_exec('UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0 AND ' . $mine, $params);
    json_ok([], 'All caught up.');
}
$rows = db_all('SELECT * FROM admin_notifications WHERE ' . $mine . ' ORDER BY id DESC LIMIT 30', $params);
json_ok(['items' => array_map(static fn($n) => ['title' => $n['title'], 'body' => $n['body'], 'link' => $n['link'], 'is_read' => (bool) $n['is_read'],
    'when' => fmt_datetime($n['created_at'])], $rows)]);
