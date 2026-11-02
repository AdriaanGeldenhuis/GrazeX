<?php
/**
 * Admin dashboard — today's numbers and the work queue.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$range = in_array(query_str('r'), ['today', '7', '30'], true) ? query_str('r') : '7';
$from = $range === 'today' ? date('Y-m-d 00:00:00') : date('Y-m-d 00:00:00', strtotime('-' . ((int) $range - 1) . ' days'));
$ids = admin_division_ids();
$in = db_placeholders($ids);
$live = "status NOT IN ('cancelled','expired','refunded')";

$kpi = [];
foreach (GX_DIVISIONS as $code => $d) {
    if (!in_array($d['id'], $ids, true)) {
        continue;
    }
    $kpi[$code] = db_one("SELECT COUNT(*) AS orders, COALESCE(SUM(total_cents),0) AS sales, COALESCE(SUM(amount_paid_cents),0) AS paid
                          FROM orders WHERE division_id = ? AND placed_at >= ? AND {$live}", [$d['id'], $from]);
}
$todo = [
    'pop' => (int) db_val("SELECT COUNT(*) FROM payments WHERE status = 'awaiting_verification' AND division_id IN ({$in})", $ids),
    'pack' => (int) db_val("SELECT COUNT(*) FROM orders WHERE status IN ('paid','awaiting_collection','processing') AND division_id IN ({$in})", $ids),
    'today' => (int) db_val("SELECT COUNT(*) FROM orders WHERE ((collection_date = CURDATE()) OR (delivery_date = CURDATE())) AND status NOT IN ('cancelled','expired','refunded','completed','collected') AND division_id IN ({$in})", $ids),
    'weigh' => (int) db_val("SELECT COUNT(*) FROM orders WHERE status IN ('reserved','deposit_paid') AND division_id IN ({$in})", $ids),
    'low' => (int) db_val('SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.is_active = 1 AND v.is_active = 1 AND v.stock_qty <= v.low_stock_threshold'),
    'messages' => (int) db_val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'"),
];
// 14-day sales bars per division
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-{$i} days"))] = ['feeds' => 0, 'ranch' => 0];
}
foreach (db_all("SELECT DATE(placed_at) d, division_id, SUM(total_cents) s FROM orders WHERE placed_at >= ? AND {$live} AND division_id IN ({$in}) GROUP BY DATE(placed_at), division_id",
    array_merge([array_key_first($days) . ' 00:00:00'], $ids)) as $r) {
    if (isset($days[$r['d']])) {
        $days[$r['d']][division_code((int) $r['division_id'])] = (int) $r['s'];
    }
}
$maxDay = max(1, ...array_values(array_map(static fn($d) => $d['feeds'] + $d['ranch'], $days)));
$recent = db_all("SELECT o.*, c.first_name, c.last_name FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.division_id IN ({$in}) ORDER BY o.placed_at DESC LIMIT 8", $ids);
$low = db_all('SELECT v.id, v.name, v.stock_qty, v.low_stock_threshold, p.name AS product_name, p.id AS product_id FROM product_variants v JOIN products p ON p.id = v.product_id
               WHERE p.is_active = 1 AND v.is_active = 1 AND v.stock_qty <= v.low_stock_threshold ORDER BY v.stock_qty LIMIT 8');

// Scheduled jobs (emails, reminders, clean-up) depend on the server cron; warn when it has stopped
$lastCron = setting('last_cron_run');
$cronLate = !$lastCron || strtotime($lastCron) < time() - 3 * 3600;

$admin = admin_start('Dashboard', 'dashboard', ['perm' => 'dashboard.view']);
?>
<div class="adm-bar">
  <div><h2 class="h3">Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $admin['name'])[0]) ?>.</h2>
    <p class="muted small mb-0"><?= e(date('l j F Y')) ?></p></div>
  <div class="chips">
    <?php foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days'] as $k => $l): ?>
      <a class="chip<?= $range === $k ? ' is-active' : '' ?>" href="/admin/?r=<?= e($k) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($cronLate): ?>
  <div class="alert alert-warning" role="alert" data-cron-warning><svg class="icon"><use href="#i-clock"/></svg><div>
    <strong>Scheduled jobs are not running.</strong> <?= $lastCron ? 'The last run was ' . e(fmt_datetime($lastCron)) . '.' : 'They have never run on this server.' ?>
    Queued emails, payment reminders and clean-up wait until it runs. In Xneelo's Cronjob Manager, run <code>cron/run.php</code> every 2 hours (see the README).
  </div></div>
<?php endif; ?>

<div class="kpis">
  <?php foreach ($kpi as $code => $k): ?>
    <div class="kpi"><div class="k-label"><?= e(ucfirst($code)) ?> sales</div><div class="k-value"><?= e(money((int) $k['sales'], false)) ?></div><div class="k-sub"><?= (int) $k['orders'] ?> orders</div></div>
    <div class="kpi"><div class="k-label"><?= e(ucfirst($code)) ?> received</div><div class="k-value"><?= e(money((int) $k['paid'], false)) ?></div><div class="k-sub">payments in</div></div>
  <?php endforeach; ?>
</div>

<section class="adm-section">
  <h2>To do</h2>
  <div class="todo">
    <a href="/admin/payments.php?tab=pop"><span class="t-num<?= $todo['pop'] ? '' : ' zero' ?>"><?= $todo['pop'] ?></span><span><span class="t-label">POPs to verify</span><br><span class="t-sub">EFT proof of payment</span></span></a>
    <a href="/admin/orders.php?queue=pack"><span class="t-num<?= $todo['pack'] ? '' : ' zero' ?>"><?= $todo['pack'] ?></span><span><span class="t-label">Orders to prepare</span><br><span class="t-sub">Paid / pay-on-collection</span></span></a>
    <a href="/admin/orders.php?queue=today"><span class="t-num<?= $todo['today'] ? '' : ' zero' ?>"><?= $todo['today'] ?></span><span><span class="t-label">Collections &amp; deliveries today</span><br><span class="t-sub">Feeds and Ranch</span></span></a>
    <a href="/admin/orders.php?queue=weigh"><span class="t-num<?= $todo['weigh'] ? '' : ' zero' ?>"><?= $todo['weigh'] ?></span><span><span class="t-label">Livestock to weigh / finalise</span><br><span class="t-sub">Reserved orders</span></span></a>
    <a href="/admin/inventory.php?filter=low"><span class="t-num<?= $todo['low'] ? '' : ' zero' ?>"><?= $todo['low'] ?></span><span><span class="t-label">Low stock</span><br><span class="t-sub">At or below threshold</span></span></a>
    <a href="/admin/messages.php"><span class="t-num<?= $todo['messages'] ? '' : ' zero' ?>"><?= $todo['messages'] ?></span><span><span class="t-label">New messages</span><br><span class="t-sub">Questions &amp; enquiries</span></span></a>
  </div>
</section>

<div class="adm-grid-2">
  <section class="card card-pad">
    <div class="row-between"><h2 class="h4">Sales — last 14 days</h2><div class="legend"><span><i style="background:var(--orange)"></i>Feeds</span><span><i style="background:var(--green)"></i>Ranch</span></div></div>
    <div class="bars" role="img" aria-label="Daily sales for the last 14 days">
      <?php foreach ($days as $d => $v): $tot = $v['feeds'] + $v['ranch']; ?>
        <div class="bar" title="<?= e(fmt_date($d)) ?>: <?= e(money($tot, false)) ?>">
          <?php if ($v['ranch']): ?><span class="ranch-seg" style="height:<?= round($v['ranch'] / $maxDay * 130) ?>px;background:linear-gradient(180deg,#b5f04f,var(--green))"></span><?php endif; ?>
          <span style="height:<?= max(2, round($v['feeds'] / $maxDay * 130)) ?>px"></span>
          <?= e(date('j', strtotime($d))) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="card card-pad">
    <h2 class="h4 mb-2">Low stock</h2>
    <?php if ($low): ?>
      <ul class="trust-list">
        <?php foreach ($low as $l): ?>
          <li><span class="<?= (int) $l['stock_qty'] <= 0 ? 'stock-out' : 'stock-low' ?>"><?= (int) $l['stock_qty'] ?></span><a class="link" href="/admin/product.php?id=<?= (int) $l['product_id'] ?>"><?= e($l['product_name']) ?> — <?= e($l['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="muted small mb-0">Everything is well stocked. 👌</p><?php endif; ?>
  </section>
</div>

<section class="card card-pad mt-2">
  <div class="row-between mb-2"><h2 class="h4">Latest orders</h2><a class="link small" href="/admin/orders.php">All orders</a></div>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Placed</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $o): ?>
      <tr>
        <td data-label=""><a class="row-link" href="/admin/order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_no']) ?></a> <?= admin_div_chip((int) $o['division_id']) ?></td>
        <td data-label=""><?= e($o['first_name'] . ' ' . $o['last_name']) ?></td>
        <td data-label=""><?= admin_status_badge($o['status']) ?></td>
        <td class="num" data-label=""><?= e(money((int) $o['total_cents'])) ?></td>
        <td data-label=""><?= e(fmt_datetime($o['placed_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="5" class="muted">No orders yet — they'll appear here the moment customers order.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php admin_end(); ?>
