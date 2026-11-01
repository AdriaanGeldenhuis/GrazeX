<?php
/** Admin messages API: {action: reply, id, reply} · {action: archive, id} */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
require_admin_api('messages.manage');
$m = db_one('SELECT * FROM enquiries WHERE id = ?', [(int) input('id')]);
if (!$m) {
    json_error('Message not found.', 404);
}
if (input('action') === 'archive') {
    db_exec("UPDATE enquiries SET status = 'archived' WHERE id = ?", [$m['id']]);
    json_ok([], 'Archived.');
}
$d = validate_or_fail(input_all(), ['reply' => 'required|min:2|max:5000']);
NotificationService::email('enquiry_reply', $m['email'], $m['name'], ['reply' => $d['reply']], $m['division_id'] ? (int) $m['division_id'] : null);
db_exec("UPDATE enquiries SET status = 'replied', admin_reply = ?, replied_at = NOW() WHERE id = ?", [$d['reply'], $m['id']]);
audit('enquiry.replied', 'enquiry', (int) $m['id']);
json_ok([], 'Reply sent to ' . $m['email'] . '.');
