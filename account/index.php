<?php
/**
 * Customer portal — overview.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$active = db_all("SELECT o.*, d.code AS div_code FROM orders o JOIN divisions d ON d.id = o.division_id
                  WHERE o.customer_id = ? AND o.status NOT IN ('completed','cancelled','expired','refunded') ORDER BY o.placed_at DESC LIMIT 5", [$customer['id']]);
$recent = db_all("SELECT o.*, d.code AS div_code FROM orders o JOIN divisions d ON d.id = o.division_id
                  WHERE o.customer_id = ? ORDER BY o.placed_at DESC LIMIT 5", [$customer['id']]);
$lastFeeds = db_one("SELECT id, order_no FROM orders WHERE customer_id = ? AND division_id = ? AND status NOT IN ('cancelled','expired') ORDER BY placed_at DESC LIMIT 1",
    [$customer['id'], division_id('feeds')]);
$stats = db_one("SELECT COUNT(*) AS orders, COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','expired','refunded') THEN total_cents END),0) AS spent FROM orders WHERE customer_id = ?", [$customer['id']]);
$docs = (int) db_val("SELECT COUNT(*) FROM documents WHERE customer_id = ? AND doc_type IN ('tax_invoice','invoice','credit_note','receipt') AND is_void = 0", [$customer['id']]);
$alerts = (int) db_val('SELECT COUNT(*) FROM livestock_alerts WHERE (customer_id = ? OR email = ?) AND is_active = 1 AND confirmed_at IS NOT NULL', [$customer['id'], customer_verified_email($customer)]);

$page = ['title' => 'My account', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head">
    <div><span class="kicker">My account</span><h1 class="display-3 mt-1">Howzit, <?= e($customer['first_name']) ?>.</h1></div>
    <div class="row">
      <a class="btn btn-orange" href="/feeds/shop.php"><svg class="icon"><use href="#i-bag"/></svg>Shop Feeds</a>
      <a class="btn btn-green" href="/ranch/livestock.php"><svg class="icon"><use href="#i-sheep"/></svg>Livestock</a>
    </div>
  </div>
  <div class="acc-layout">
    <?php $accountTab = 'dashboard'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <?php if (!$customer['email_verified_at']): ?>
        <div class="alert alert-info"><svg class="icon"><use href="#i-mail"/></svg><div>Please confirm your email address so we can send your invoices.
          <button class="link" type="button" style="background:none;border:0;padding:0" data-act="/account/api/auth.php" data-payload='{"action":"resend_verify"}'>Resend the link</button></div></div>
      <?php endif; ?>

      <div class="quick">
        <?php if ($lastFeeds): ?>
          <a href="#" data-act="/account/api/orders.php" data-payload='<?= e(json_encode(['action' => 'reorder', 'order_no' => $lastFeeds['order_no']])) ?>'><svg class="icon"><use href="#i-repeat"/></svg><strong>Reorder feed</strong><span>Same as <?= e($lastFeeds['order_no']) ?></span></a>
        <?php else: ?>
          <a href="/feeds/calculator.php"><svg class="icon"><use href="#i-calc"/></svg><strong>Feed calculator</strong><span>How many bags?</span></a>
        <?php endif; ?>
        <a href="/account/orders.php"><svg class="icon"><use href="#i-bag"/></svg><strong><?= (int) $stats['orders'] ?> orders</strong><span><?= e(money((int) $stats['spent'], false)) ?> in total</span></a>
        <a href="/account/documents.php"><svg class="icon"><use href="#i-file"/></svg><strong><?= $docs ?> documents</strong><span>Invoices &amp; receipts</span></a>
        <a href="/account/alerts.php"><svg class="icon"><use href="#i-bell"/></svg><strong><?= $alerts ?> livestock alerts</strong><span>Get notified first</span></a>
      </div>

      <section class="card card-pad">
        <div class="row-between mb-2"><h2 class="h3">Active orders</h2><a class="link small" href="/account/orders.php">All orders</a></div>
        <?php if ($active): ?>
          <div class="order-list"><?php foreach ($active as $o): ?><?= partial('order-row', ['o' => $o]) ?><?php endforeach; ?></div>
        <?php else: ?>
          <p class="muted mb-0">No open orders right now.</p>
        <?php endif; ?>
      </section>

      <?php if (!$active && $recent): ?>
        <section class="card card-pad">
          <h2 class="h3 mb-2">Recent orders</h2>
          <div class="order-list"><?php foreach ($recent as $o): ?><?= partial('order-row', ['o' => $o]) ?><?php endforeach; ?></div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
