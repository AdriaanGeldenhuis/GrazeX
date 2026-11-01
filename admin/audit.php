<?php
/** Admin — audit log of every important change. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$rows = db_all("SELECT l.*, a.name AS admin_name, CONCAT(c.first_name, ' ', c.last_name) AS customer_name FROM audit_log l
                LEFT JOIN admin_users a ON a.id = l.actor_id AND l.actor_type = 'admin'
                LEFT JOIN customers c ON c.id = l.actor_id AND l.actor_type = 'customer' ORDER BY l.id DESC LIMIT 400");
admin_start('Audit log', 'audit', ['perm' => 'audit.view']);
?>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th>When</th><th>Who</th><th>What</th><th>Item</th><th>IP</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td data-label=""><?= e(fmt_datetime($r['created_at'])) ?></td><td data-label=""><?= e($r['admin_name'] ?? $r['customer_name'] ?? $r['actor_type']) ?></td>
      <td data-label=""><code><?= e($r['action']) ?></code><?= $r['details'] ? '<br><span class="tiny dim">' . e(str_limit($r['details'], 120)) . '</span>' : '' ?></td>
      <td data-label=""><?= e($r['entity_type'] ?? '') ?> <?= $r['entity_id'] ? '#' . (int) $r['entity_id'] : '' ?></td><td data-label=""><?= e($r['ip'] ?? '') ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php admin_end(); ?>
