<?php
/** Admin — one customer. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$c = db_one('SELECT * FROM customers WHERE id = ?', [query_int('id')]);
if (!$c) {
    require_admin();
    http_response_code(404);
    exit('Customer not found.');
}
$orders = db_all('SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC', [$c['id']]);
$addresses = db_all('SELECT * FROM customer_addresses WHERE customer_id = ?', [$c['id']]);
$ltv = array_sum(array_map(static fn($o) => in_array($o['status'], ['cancelled', 'expired', 'refunded'], true) ? 0 : (int) $o['total_cents'], $orders));
$api = '/admin/api/customers.php';

admin_start($c['first_name'] . ' ' . $c['last_name'], 'customers', ['perm' => 'customers.view']);
?>
<div class="adm-bar">
  <a class="btn btn-sm btn-ghost" href="/admin/customers.php"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>Customers</a>
  <?php if (admin_can('customers.manage')): ?>
  <div class="row">
    <?php if ($w = whatsapp_link($c['phone'], 'Hi ' . $c['first_name'] . ', this is GrazeX. ')): ?><a class="btn btn-sm btn-whatsapp" href="<?= e($w) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
    <button class="btn btn-sm<?= $c['status'] === 'blocked' ? ' btn-green' : ' btn-danger' ?>" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => $c['status'] === 'blocked' ? 'unblock' : 'block', 'id' => (int) $c['id']])) ?>'
            data-confirm="<?= $c['status'] === 'blocked' ? 'Unblock this customer?' : 'Block this customer? They will be logged out and cannot order.' ?>"><?= $c['status'] === 'blocked' ? 'Unblock' : 'Block' ?></button>
  </div>
  <?php endif; ?>
</div>
<div class="kpis">
  <div class="kpi"><div class="k-label">Orders</div><div class="k-value"><?= count($orders) ?></div></div>
  <div class="kpi"><div class="k-label">Lifetime spend</div><div class="k-value"><?= e(money($ltv, false)) ?></div></div>
  <div class="kpi"><div class="k-label">Customer since</div><div class="k-value" style="font-size:24px"><?= e(fmt_date($c['created_at'])) ?></div></div>
  <div class="kpi"><div class="k-label">Marketing</div><div class="k-value" style="font-size:24px"><?= $c['marketing_opt_in'] ? 'Opted in' : 'No' ?></div></div>
</div>
<div class="adm-grid-2">
  <section class="card card-pad">
    <h2 class="h4 mb-2">Orders</h2>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Order</th><th>Status</th><th class="num">Total</th><th>Placed</th></tr></thead>
      <tbody><?php foreach ($orders as $o): ?>
        <tr><td><a class="link" href="/admin/order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_no']) ?></a> <?= admin_div_chip((int) $o['division_id']) ?></td><td><?= admin_status_badge($o['status']) ?></td><td class="num"><?= e(money((int) $o['total_cents'])) ?></td><td><?= e(fmt_date($o['placed_at'])) ?></td></tr>
      <?php endforeach; ?><?php if (!$orders): ?><tr><td colspan="4" class="muted">No orders yet.</td></tr><?php endif; ?></tbody>
    </table></div>
  </section>
  <aside>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Details</h2>
      <dl class="kv">
        <dt>Email</dt><dd><a class="link" href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a><?= $c['email_verified_at'] ? ' ✓' : '' ?></dd>
        <dt>Phone</dt><dd><?= e($c['phone']) ?></dd>
        <?php if ($c['farm_name']): ?><dt>Farm</dt><dd><?= e($c['farm_name']) ?></dd><?php endif; ?>
        <?php if ($c['company_name']): ?><dt>Company</dt><dd><?= e($c['company_name']) ?></dd><?php endif; ?>
        <?php if ($c['vat_no']): ?><dt>VAT no</dt><dd><?= e($c['vat_no']) ?></dd><?php endif; ?>
        <dt>Last login</dt><dd><?= e(fmt_datetime($c['last_login_at'])) ?></dd>
      </dl>
      <?php foreach ($addresses as $a): ?><p class="small muted mt-2 mb-0">📍 <?= e(implode(', ', array_filter([$a['farm_name'], $a['line1'], $a['town']]))) ?></p><?php endforeach; ?>
    </section>
    <?php if ($c['vat_farmer_status'] !== 'none'): ?>
      <section class="card card-pad adm-section">
        <h2 class="h4 mb-1">VAT-registered farmer</h2>
        <p>Status: <strong><?= e($c['vat_farmer_status']) ?></strong></p>
        <?php if ($c['vat_farmer_doc_path']): ?><a class="btn btn-sm" href="/admin/api/documents.php?vat=<?= (int) $c['id'] ?>" target="_blank" rel="noopener">View SARS document</a><?php endif; ?>
        <?php if ($c['vat_farmer_status'] === 'pending' && admin_can('customers.manage')): ?>
          <div class="row mt-2">
            <button class="btn btn-sm btn-green" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'vat_verify', 'id' => (int) $c['id']])) ?>'>Verify</button>
            <button class="btn btn-sm btn-danger" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'vat_reject', 'id' => (int) $c['id']])) ?>'>Reject</button>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if ($c['deletion_requested_at'] && admin_can('customers.manage')): ?>
      <section class="card card-pad adm-section" style="border-color:rgba(255,95,82,.4)">
        <h2 class="h4 mb-1">Deletion requested</h2>
        <p class="small muted">Removes their login, contact details and addresses. Orders and invoices are kept (anonymised) for the 5-year legal record.</p>
        <button class="btn btn-sm btn-danger" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'anonymise', 'id' => (int) $c['id']])) ?>' data-confirm="Permanently anonymise this customer?" data-danger>Anonymise now</button>
      </section>
    <?php endif; ?>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Notes</h2>
      <form data-admin-form data-api="<?= e($api) ?>" data-action="notes">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <textarea class="textarea" name="admin_notes" rows="4"><?= e($c['admin_notes']) ?></textarea>
        <button class="btn btn-sm mt-1" type="submit">Save</button>
      </form>
    </section>
  </aside>
</div>
<?php admin_end(); ?>
