<?php
/**
 * Admin — orders list with filters and work queues.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$ids = admin_division_ids();
$f = [
    'q' => mb_substr(query_str('q'), 0, 80),
    'division' => in_array(query_str('division'), ['feeds', 'ranch'], true) ? query_str('division') : '',
    'status' => array_key_exists(query_str('status'), OrderService::STATUS_LABELS) ? query_str('status') : '',
    'method' => in_array(query_str('method'), ['yoco', 'eft', 'cash_on_collection'], true) ? query_str('method') : '',
    'queue' => in_array(query_str('queue'), ['pack', 'today', 'weigh', 'unpaid'], true) ? query_str('queue') : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', query_str('from')) ? query_str('from') : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', query_str('to')) ? query_str('to') : '',
];
$where = ['o.division_id IN (' . db_placeholders($ids) . ')'];
$params = $ids;
if ($f['division']) { $where[] = 'o.division_id = ?'; $params[] = division_id($f['division']); }
if ($f['status']) { $where[] = 'o.status = ?'; $params[] = $f['status']; }
if ($f['method']) { $where[] = 'o.payment_method = ?'; $params[] = $f['method']; }
if ($f['from']) { $where[] = 'o.placed_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to']) { $where[] = 'o.placed_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
if ($f['q'] !== '') {
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
    $where[] = '(o.order_no LIKE ? OR o.billing_name LIKE ? OR o.billing_email LIKE ? OR o.billing_phone LIKE ? OR o.billing_company LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$queueSql = [
    'pack' => "o.status IN ('paid','awaiting_collection','processing')",
    'today' => "(o.collection_date = CURDATE() OR o.delivery_date = CURDATE()) AND o.status NOT IN ('cancelled','expired','refunded','completed','collected')",
    'weigh' => "o.status IN ('reserved','deposit_paid')",
    'unpaid' => "o.payment_status IN ('unpaid','partial') AND o.status NOT IN ('cancelled','expired','refunded')",
];
if ($f['queue']) { $where[] = $queueSql[$f['queue']]; }
$sqlWhere = implode(' AND ', $where);
$perPage = 25;
$pageNo = max(1, query_int('page', 1));
$total = (int) db_val("SELECT COUNT(*) FROM orders o WHERE {$sqlWhere}", $params);
$orders = db_all("SELECT o.*, (SELECT COUNT(*) FROM payments p WHERE p.order_id = o.id AND p.status = 'awaiting_verification') AS pops
                  FROM orders o WHERE {$sqlWhere} ORDER BY " . ($f['queue'] === 'today' ? 'o.collection_slot_id, o.id' : 'o.placed_at DESC') . "
                  LIMIT {$perPage} OFFSET " . (($pageNo - 1) * $perPage), $params);

admin_start('Orders', 'orders', ['perm' => 'orders.view']);
?>
<div class="chips mb-2">
  <a class="chip<?= !$f['queue'] ? ' is-active' : '' ?>" href="/admin/orders.php">All</a>
  <a class="chip<?= $f['queue'] === 'pack' ? ' is-active' : '' ?>" href="/admin/orders.php?queue=pack">To prepare</a>
  <a class="chip<?= $f['queue'] === 'today' ? ' is-active' : '' ?>" href="/admin/orders.php?queue=today">Today</a>
  <a class="chip<?= $f['queue'] === 'weigh' ? ' is-active' : '' ?>" href="/admin/orders.php?queue=weigh">Livestock to finalise</a>
  <a class="chip<?= $f['queue'] === 'unpaid' ? ' is-active' : '' ?>" href="/admin/orders.php?queue=unpaid">Unpaid</a>
</div>
<form class="adm-filters" method="get" data-autosubmit>
  <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Order no, name, phone…">
  <select class="select" name="division"><option value="">Both divisions</option><option value="feeds"<?= $f['division'] === 'feeds' ? ' selected' : '' ?>>Feeds</option><option value="ranch"<?= $f['division'] === 'ranch' ? ' selected' : '' ?>>Ranch</option></select>
  <select class="select" name="status"><option value="">Any status</option>
    <?php foreach (OrderService::STATUS_LABELS as $k => [$l]): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select class="select" name="method"><option value="">Any payment</option><option value="yoco"<?= $f['method'] === 'yoco' ? ' selected' : '' ?>>Card (Yoco)</option><option value="eft"<?= $f['method'] === 'eft' ? ' selected' : '' ?>>EFT</option><option value="cash_on_collection"<?= $f['method'] === 'cash_on_collection' ? ' selected' : '' ?>>On collection</option></select>
  <input class="input" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From">
  <input class="input" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To">
  <?php if ($f['queue']): ?><input type="hidden" name="queue" value="<?= e($f['queue']) ?>"><?php endif; ?>
  <a class="btn btn-sm" href="/admin/reports.php?export=orders&amp;<?= e(http_build_query(array_filter($f))) ?>"><svg class="icon icon-sm"><use href="#i-download"/></svg>CSV</a>
</form>

<p class="muted small"><?= $total ?> order<?= $total === 1 ? '' : 's' ?></p>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Payment</th><th><?= $f['queue'] === 'today' ? 'Slot' : 'When' ?></th><th class="num">Total</th><th class="num">Due</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): $due = OrderService::amountDue($o); ?>
    <tr>
      <td data-label=""><a class="row-link" href="/admin/order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_no']) ?></a> <?= admin_div_chip((int) $o['division_id']) ?></td>
      <td data-label=""><?= e($o['billing_name']) ?><br><span class="tiny dim"><?= e($o['billing_company'] ?? '') ?></span></td>
      <td data-label=""><?= admin_status_badge($o['status']) ?><?= (int) $o['pops'] ? ' <span class="badge badge-info">POP</span>' : '' ?></td>
      <td data-label=""><?= e(['yoco' => 'Card', 'eft' => 'EFT', 'cash_on_collection' => 'On collection'][$o['payment_method']]) ?> · <?= e($o['payment_status']) ?></td>
      <td data-label=""><?= $o['fulfilment'] === 'delivery' ? '🚚 ' . e(fmt_date($o['delivery_date'], 'j M')) . ' ' . e($o['ship_town']) : '📍 ' . e(fmt_date($o['collection_date'], 'j M')) ?></td>
      <td class="num" data-label=""><?= e(money((int) $o['total_cents'])) ?></td>
      <td class="num" data-label=""><?= $due > 0 && !in_array($o['status'], ['cancelled', 'expired'], true) ? '<span class="stock-low">' . e(money($due)) . '</span>' : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="7" class="muted">No orders match.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= pagination($pageNo, max(1, (int) ceil($total / $perPage)), '/admin/orders.php') ?>
<?php admin_end(); ?>
