<?php
/** GET → latest admin notifications · POST {action: read_all} */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_admin_api();
$ids = admin_division_ids();
if (request_method() === 'POST') {
    csrf_require();
    // Only the notifications this admin can see (their divisions + general ones)
    db_exec('UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0 AND (division_id IS NULL OR division_id IN (' . db_placeholders($ids) . '))', $ids);
    json_ok([], 'All caught up.');
}
$rows = db_all('SELECT * FROM admin_notifications WHERE division_id IS NULL OR division_id IN (' . db_placeholders($ids) . ') ORDER BY id DESC LIMIT 30', $ids);
json_ok(['items' => array_map(static fn($n) => ['title' => $n['title'], 'body' => $n['body'], 'link' => $n['link'], 'is_read' => (bool) $n['is_read'],
    'when' => fmt_datetime($n['created_at'])], $rows)]);
