<?php
/** Admin — customers. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$q = mb_substr(query_str('q'), 0, 80);
$flag = query_str('flag');
$where = ['1=1'];
$params = [];
if ($q !== '') { $like = "%{$q}%"; $where[] = '(c.email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.farm_name LIKE ?)'; array_push($params, $like, $like, $like, $like, $like); }
if ($flag === 'vat') { $where[] = "c.vat_farmer_status = 'pending'"; }
if ($flag === 'delete') { $where[] = 'c.deletion_requested_at IS NOT NULL'; }
if ($flag === 'marketing') { $where[] = 'c.marketing_opt_in = 1'; }
$perPage = 40;
$pageNo = query_page();
$sqlWhere = implode(' AND ', $where);
$total = (int) db_val("SELECT COUNT(*) FROM customers c WHERE {$sqlWhere}", $params);
$rows = db_all("SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS orders,
                (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.customer_id = c.id AND o.status NOT IN ('cancelled','expired','refunded')) AS ltv,
                (SELECT MAX(o.placed_at) FROM orders o WHERE o.customer_id = c.id) AS last_order
                FROM customers c WHERE {$sqlWhere} ORDER BY c.id DESC LIMIT {$perPage} OFFSET " . (($pageNo - 1) * $perPage), $params);

admin_start('Customers', 'customers', ['perm' => 'customers.view']);
?>
<div class="adm-bar">
  <form class="adm-filters mb-0" method="get"><input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, phone, farm…"></form>
  <div class="chips">
    <a class="chip<?= $flag === '' ? ' is-active' : '' ?>" href="/admin/customers.php">All</a>
    <a class="chip<?= $flag === 'vat' ? ' is-active' : '' ?>" href="/admin/customers.php?flag=vat">VAT farmer to verify</a>
    <a class="chip<?= $flag === 'marketing' ? ' is-active' : '' ?>" href="/admin/customers.php?flag=marketing">Marketing opt-in</a>
    <a class="chip<?= $flag === 'delete' ? ' is-active' : '' ?>" href="/admin/customers.php?flag=delete">Deletion requests</a>
    <a class="btn btn-sm" href="/admin/reports.php?export=customers"><svg class="icon icon-sm"><use href="#i-download"/></svg>CSV</a>
  </div>
</div>
<p class="muted small"><?= $total ?> customers</p>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th>Customer</th><th>Contact</th><th class="num">Orders</th><th class="num">Spent</th><th>Last order</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td data-label=""><a class="row-link" href="/admin/customer.php?id=<?= (int) $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></a><?= $c['farm_name'] ? '<br><span class="tiny dim">' . e($c['farm_name']) . '</span>' : '' ?></td>
      <td data-label=""><?= e($c['email']) ?><br><span class="tiny dim"><?= e($c['phone']) ?></span></td>
      <td class="num" data-label="Orders"><?= (int) $c['orders'] ?></td>
      <td class="num" data-label="Spent"><?= e(money((int) $c['ltv'], false)) ?></td>
      <td data-label="Last"><?= e(fmt_date($c['last_order'])) ?></td>
      <td data-label=""><?= $c['status'] === 'blocked' ? '<span class="badge badge-danger">Blocked</span>' : '' ?><?= $c['vat_farmer_status'] === 'pending' ? '<span class="badge badge-warning">VAT check</span>' : '' ?><?= $c['deletion_requested_at'] ? '<span class="badge badge-danger">Delete request</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pagination($pageNo, max(1, (int) ceil($total / $perPage)), '/admin/customers.php') ?>
<?php admin_end(); ?>
