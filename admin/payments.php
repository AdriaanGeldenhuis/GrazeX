<?php
/** Admin — payments: proof-of-payment queue and payment history. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$tab = query_str('tab') === 'all' ? 'all' : 'pop';
$ids = admin_division_ids();
$in = db_placeholders($ids);
$pops = db_all("SELECT p.*, o.order_no, o.billing_name, o.total_cents, o.amount_paid_cents, o.id AS oid FROM payments p JOIN orders o ON o.id = p.order_id
                WHERE p.status = 'awaiting_verification' AND p.division_id IN ({$in}) ORDER BY p.created_at", $ids);
$all = $tab === 'all' ? db_all("SELECT p.*, o.order_no, o.billing_name FROM payments p JOIN orders o ON o.id = p.order_id
                WHERE p.division_id IN ({$in}) ORDER BY p.id DESC LIMIT 200", $ids) : [];
$sumMonth = db_all("SELECT method, COUNT(*) n, SUM(amount_cents) s FROM payments WHERE status = 'succeeded' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND division_id IN ({$in}) GROUP BY method", $ids);

admin_start('Payments', 'payments', ['perm' => 'payments.verify']);
?>
<div class="kpis">
  <?php foreach ($sumMonth as $s): ?>
    <div class="kpi"><div class="k-label"><?= e(strtoupper($s['method'])) ?> this month</div><div class="k-value"><?= e(money((int) $s['s'], false)) ?></div><div class="k-sub"><?= (int) $s['n'] ?> payments</div></div>
  <?php endforeach; ?>
</div>
<div class="tabs mb-3" role="tablist">
  <a class="tab" role="tab" aria-selected="<?= $tab === 'pop' ? 'true' : 'false' ?>" href="/admin/payments.php">POPs to verify (<?= count($pops) ?>)</a>
  <a class="tab" role="tab" aria-selected="<?= $tab === 'all' ? 'true' : 'false' ?>" href="/admin/payments.php?tab=all">All payments</a>
</div>

<?php if ($tab === 'pop'): ?>
  <?php if (!$pops): ?>
    <div class="empty-state"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-check"/></svg></div><h3>All caught up</h3><p>No proofs of payment are waiting.</p></div>
  <?php endif; ?>
  <div class="grid-2">
    <?php foreach ($pops as $p): $isImg = !str_ends_with((string) $p['pop_path'], '.pdf'); ?>
      <section class="card card-pad">
        <div class="row-between mb-2"><div><a class="row-link link" href="/admin/order.php?id=<?= (int) $p['oid'] ?>"><?= e($p['order_no']) ?></a> · <?= e($p['billing_name']) ?></div><?= admin_div_chip((int) $p['division_id']) ?></div>
        <?php if ($isImg): ?>
          <a href="/admin/api/documents.php?pop=<?= (int) $p['id'] ?>" target="_blank" rel="noopener"><img class="pop-thumb" src="/admin/api/documents.php?pop=<?= (int) $p['id'] ?>" alt="Proof of payment for <?= e($p['order_no']) ?>" loading="lazy"></a>
        <?php else: ?>
          <a class="btn btn-block" href="/admin/api/documents.php?pop=<?= (int) $p['id'] ?>" target="_blank" rel="noopener" data-download><svg class="icon"><use href="#i-file"/></svg>Open PDF proof of payment</a>
        <?php endif; ?>
        <dl class="kv mt-2"><dt>Expected</dt><dd><strong><?= e(money((int) $p['amount_cents'])) ?></strong> (<?= e($p['purpose']) ?>)</dd><dt>Reference</dt><dd><?= e($p['order_no']) ?></dd><dt>Uploaded</dt><dd><?= e(fmt_datetime($p['pop_uploaded_at'])) ?></dd></dl>
        <p class="hint">Check your bank statement for this reference and amount before approving.</p>
        <div class="row">
          <button class="btn btn-green" type="button" data-admin-act="/admin/api/payments.php" data-payload='<?= e(json_encode(['action' => 'approve', 'id' => (int) $p['id']])) ?>' data-prompt="Amount received (R):" data-prompt-field="amount" data-prompt-default="<?= e(number_format((int) $p['amount_cents'] / 100, 2, '.', '')) ?>"><svg class="icon"><use href="#i-check"/></svg>Approve</button>
          <button class="btn btn-danger" type="button" data-admin-act="/admin/api/payments.php" data-payload='<?= e(json_encode(['action' => 'reject', 'id' => (int) $p['id']])) ?>' data-prompt="Why can't you match this payment? (sent to customer)" data-prompt-field="reason">Reject</button>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><th>When</th><th>Order</th><th>Method</th><th>Status</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($all as $p): ?>
      <tr><td data-label=""><?= e(fmt_datetime($p['created_at'])) ?></td><td data-label=""><a class="link" href="/admin/order.php?id=<?= (int) $p['order_id'] ?>"><?= e($p['order_no']) ?></a> · <?= e($p['billing_name']) ?></td>
        <td data-label=""><?= e(strtoupper($p['method'])) ?> · <?= e($p['purpose']) ?></td><td data-label=""><span class="badge<?= $p['status'] === 'succeeded' ? ' badge-success' : '' ?>"><?= e(str_replace('_', ' ', $p['status'])) ?></span></td>
        <td class="num" data-label=""><?= e(money((int) $p['amount_cents'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php admin_end(); ?>
