<?php
/** Admin — messages & enquiries. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

require_admin('messages.manage');
$status = in_array(query_str('status'), ['new', 'read', 'replied', 'archived'], true) ? query_str('status') : '';
$open = query_int('id');
$rows = db_all('SELECT e.*, l.lot_no, l.title AS lot_title FROM enquiries e LEFT JOIN lots l ON l.id = e.lot_id ' . ($status ? 'WHERE e.status = ?' : "WHERE e.status <> 'archived'") . ' ORDER BY e.id DESC LIMIT 200', $status ? [$status] : []);
if ($open) {
    db_exec("UPDATE enquiries SET status = 'read' WHERE id = ? AND status = 'new'", [$open]);
}
admin_start('Messages', 'messages', ['perm' => 'messages.manage']);
?>
<div class="chips mb-2">
  <?php foreach (['' => 'Inbox', 'new' => 'New', 'replied' => 'Replied', 'archived' => 'Archived'] as $k => $l): ?><a class="chip<?= $status === $k ? ' is-active' : '' ?>" href="/admin/messages.php<?= $k ? '?status=' . e($k) : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>
<div class="order-list" style="display:grid;gap:10px">
  <?php foreach ($rows as $m): ?>
    <details class="card card-pad" <?= $open === (int) $m['id'] ? 'open' : '' ?>>
      <summary class="row-between" style="cursor:pointer;list-style:none">
        <span><strong><?= e($m['name']) ?></strong> <span class="muted small"><?= e($m['email']) ?><?= $m['phone'] ? ' · ' . e($m['phone']) : '' ?></span><br>
          <span class="small"><?= $m['lot_no'] ? '🐑 ' . e($m['lot_no'] . ' ' . $m['lot_title']) . ' · ' : '' ?><?= e(str_limit($m['message'], 90)) ?></span></span>
        <span><span class="badge<?= $m['status'] === 'new' ? ' badge-accent' : '' ?>"><?= e($m['status']) ?></span> <span class="tiny dim"><?= e(fmt_datetime($m['created_at'])) ?></span></span>
      </summary>
      <div class="prose mt-2"><p><?= nl2br(e($m['message'])) ?></p></div>
      <?php if ($m['admin_reply']): ?><div class="alert alert-success small"><div><strong>Your reply (<?= e(fmt_datetime($m['replied_at'])) ?>):</strong><br><?= nl2br(e($m['admin_reply'])) ?></div></div><?php endif; ?>
      <form class="mt-2" data-admin-form data-api="/admin/api/messages.php" data-action="reply" data-reload>
        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
        <textarea class="textarea" name="reply" rows="3" placeholder="Type your reply — it is emailed to <?= e($m['email']) ?>" required></textarea>
        <div class="row mt-1">
          <button class="btn btn-primary btn-sm" type="submit">Send reply</button>
          <?php if ($w = whatsapp_link($m['phone'], 'Hi ' . $m['name'] . ', GrazeX here about your question. ')): ?><a class="btn btn-sm btn-whatsapp" href="<?= e($w) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
          <button class="btn btn-sm btn-ghost" type="button" data-admin-act="/admin/api/messages.php" data-payload='<?= e(json_encode(['action' => 'archive', 'id' => (int) $m['id']])) ?>'>Archive</button>
        </div>
      </form>
    </details>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="empty-state"><h3>No messages</h3><p>Questions from the website land here.</p></div><?php endif; ?>
</div>
<?php admin_end(); ?>
