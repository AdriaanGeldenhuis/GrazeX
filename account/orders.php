<?php
/**
 * Customer portal — all orders.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$filter = in_array(query_str('d'), ['feeds', 'ranch'], true) ? query_str('d') : '';
$params = [$customer['id']];
$where = 'o.customer_id = ?';
if ($filter) {
    $where .= ' AND o.division_id = ?';
    $params[] = division_id($filter);
}
$perPage = 15;
$pageNo = max(1, query_int('page', 1));
$total = (int) db_val("SELECT COUNT(*) FROM orders o WHERE {$where}", $params);
$orders = db_all("SELECT o.*, d.code AS div_code FROM orders o JOIN divisions d ON d.id = o.division_id WHERE {$where}
                  ORDER BY o.placed_at DESC LIMIT {$perPage} OFFSET " . (($pageNo - 1) * $perPage), $params);

$page = ['title' => 'My orders', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head"><div><span class="kicker">My account</span><h1 class="display-3 mt-1">Orders</h1></div></div>
  <div class="acc-layout">
    <?php $accountTab = 'orders'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <div class="chips">
        <a class="chip<?= $filter === '' ? ' is-active' : '' ?>" href="/account/orders.php">All</a>
        <a class="chip<?= $filter === 'feeds' ? ' is-active' : '' ?>" href="/account/orders.php?d=feeds">Feeds</a>
        <a class="chip<?= $filter === 'ranch' ? ' is-active' : '' ?>" href="/account/orders.php?d=ranch">Ranch</a>
      </div>
      <?php if ($orders): ?>
        <div class="order-list"><?php foreach ($orders as $o): ?><?= partial('order-row', ['o' => $o]) ?><?php endforeach; ?></div>
        <?= pagination($pageNo, max(1, (int) ceil($total / $perPage)), '/account/orders.php') ?>
      <?php else: ?>
        <div class="empty-state"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-bag"/></svg></div><h3>No orders yet</h3>
          <p>When you order feed or reserve livestock, it shows up here.</p><a class="btn btn-primary" href="/feeds/shop.php">Start shopping</a></div>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
