<?php
/** Admin — global search (orders, customers, products, lots). */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$q = mb_substr(query_str('q'), 0, 80);
$like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
$res = ['orders' => [], 'customers' => [], 'products' => [], 'lots' => []];
require_admin();
if (mb_strlen($q) >= 2) {
    $ids = admin_division_ids();
    // Each block only for admins allowed to see it (and only their divisions)
    $res['orders'] = !admin_can('orders.view') ? [] : db_all('SELECT id, order_no, billing_name, status, total_cents FROM orders WHERE division_id IN (' . db_placeholders($ids) . ') AND (order_no LIKE ? OR billing_name LIKE ? OR billing_phone LIKE ? OR billing_email LIKE ?) ORDER BY id DESC LIMIT 10', array_merge($ids, [$like, $like, $like, $like]));
    $res['customers'] = !admin_can('customers.view') ? [] : db_all("SELECT id, first_name, last_name, email FROM customers WHERE email LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR phone LIKE ? OR farm_name LIKE ? LIMIT 10", [$like, $like, $like, $like]);
    $res['products'] = !admin_can('products.manage') || !in_array(division_id('feeds'), $ids, true) ? [] : db_all('SELECT DISTINCT p.id, p.name FROM products p LEFT JOIN product_variants v ON v.product_id = p.id WHERE p.name LIKE ? OR v.sku LIKE ? LIMIT 10', [$like, $like]);
    $res['lots'] = !admin_can('lots.manage') || !in_array(division_id('ranch'), $ids, true) ? [] : db_all('SELECT id, lot_no, title FROM lots WHERE lot_no LIKE ? OR title LIKE ? OR tag_no LIKE ? LIMIT 10', [$like, $like, $like]);
}
if (count($res['orders']) === 1 && !array_filter([$res['customers'], $res['products'], $res['lots']])) {
    redirect('/admin/order.php?id=' . (int) $res['orders'][0]['id']);
}
admin_start('Search: ' . $q, 'dashboard');
?>
<div class="grid-2">
  <section class="card card-pad"><h2 class="h4 mb-2">Orders</h2><ul class="trust-list"><?php foreach ($res['orders'] as $o): ?><li><a class="link" href="/admin/order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_no']) ?></a> <?= e($o['billing_name']) ?> <?= admin_status_badge($o['status']) ?></li><?php endforeach; ?><?= $res['orders'] ? '' : '<li class="muted">None</li>' ?></ul></section>
  <section class="card card-pad"><h2 class="h4 mb-2">Customers</h2><ul class="trust-list"><?php foreach ($res['customers'] as $c): ?><li><a class="link" href="/admin/customer.php?id=<?= (int) $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a> <span class="tiny dim"><?= e($c['email']) ?></span></li><?php endforeach; ?><?= $res['customers'] ? '' : '<li class="muted">None</li>' ?></ul></section>
  <section class="card card-pad"><h2 class="h4 mb-2">Products</h2><ul class="trust-list"><?php foreach ($res['products'] as $p): ?><li><a class="link" href="/admin/product.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a></li><?php endforeach; ?><?= $res['products'] ? '' : '<li class="muted">None</li>' ?></ul></section>
  <section class="card card-pad"><h2 class="h4 mb-2">Livestock</h2><ul class="trust-list"><?php foreach ($res['lots'] as $l): ?><li><a class="link" href="/admin/lot.php?id=<?= (int) $l['id'] ?>"><?= e($l['lot_no']) ?></a> <?= e($l['title']) ?></li><?php endforeach; ?><?= $res['lots'] ? '' : '<li class="muted">None</li>' ?></ul></section>
</div>
<?php admin_end(); ?>
