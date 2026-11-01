<?php
/**
 * Admin — reports and CSV exports (sales, products, VAT, payments, stock, customers, documents, orders).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

require_admin('reports.view');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', query_str('from')) ? query_str('from') : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', query_str('to')) ? query_str('to') : date('Y-m-d');
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];
$ids = admin_division_ids();
$in = db_placeholders($ids);
$live = "o.status NOT IN ('cancelled','expired','refunded')";

// ---------------- CSV exports ----------------
$export = query_str('export');
if ($export !== '') {
    $csv = static function (string $name, array $head, array $rows): never {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="grazex-' . $name . '-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
        fputcsv($out, $head, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($r)), ',', '"', '');
        }
        exit;
    };
    $r = static fn(int $c) => number_format($c / 100, 2, '.', '');
    audit('report.export', null, null, ['type' => $export]);
    switch ($export) {
        case 'orders':
            $rows = db_all("SELECT o.order_no, d.name, o.placed_at, o.status, o.payment_status, o.payment_method, o.fulfilment, o.billing_name, o.billing_email, o.billing_phone,
                            o.subtotal_cents, o.discount_cents, o.delivery_cents, o.vat_cents, o.total_cents, o.amount_paid_cents
                            FROM orders o JOIN divisions d ON d.id = o.division_id WHERE o.division_id IN ({$in}) AND o.placed_at BETWEEN ? AND ? ORDER BY o.placed_at", array_merge($ids, $range));
            $csv('orders', ['Order', 'Division', 'Placed', 'Status', 'Payment status', 'Method', 'Fulfilment', 'Name', 'Email', 'Phone', 'Subtotal', 'Discount', 'Delivery', 'VAT', 'Total', 'Paid'],
                array_map(static fn($x) => array_merge(array_slice($x, 0, 10), array_map($r, array_map('intval', array_slice($x, 10)))), $rows));
        case 'documents':
            $rows = db_all("SELECT d.issued_at, d.doc_type, d.doc_no, o.order_no, o.billing_name, o.billing_vat_no, d.subtotal_excl_cents, d.vat_cents, d.total_cents
                            FROM documents d JOIN orders o ON o.id = d.order_id WHERE d.division_id IN ({$in}) AND d.issued_at BETWEEN ? AND ? AND d.doc_type IN ('tax_invoice','invoice','credit_note','receipt') ORDER BY d.issued_at", array_merge($ids, $range));
            $csv('documents', ['Date', 'Type', 'Number', 'Order', 'Customer', 'Customer VAT no', 'Excl VAT', 'VAT', 'Total'],
                array_map(static fn($x) => array_merge(array_slice($x, 0, 6), array_map($r, array_map('intval', array_slice($x, 6)))), $rows));
        case 'customers':
            $rows = db_all('SELECT first_name, last_name, email, phone, farm_name, company_name, vat_no, marketing_opt_in, created_at FROM customers WHERE status = \'active\' ORDER BY id');
            $csv('customers', ['First name', 'Last name', 'Email', 'Phone', 'Farm', 'Company', 'VAT no', 'Marketing opt-in', 'Joined'], $rows);
        case 'stock':
            $rows = db_all('SELECT p.name, v.name AS size, v.sku, v.stock_qty, v.cost_cents, v.price_cents FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 ORDER BY p.name');
            $csv('stock', ['Product', 'Size', 'SKU', 'In stock', 'Cost', 'Price', 'Value at cost'], array_map(static fn($x) => [$x['name'], $x['size'], $x['sku'], $x['stock_qty'],
                $r((int) $x['cost_cents']), $r((int) $x['price_cents']), $r(max(0, (int) $x['stock_qty']) * (int) $x['cost_cents'])], $rows));
        default:
            http_response_code(400);
            exit('Unknown export.');
    }
}

// ---------------- On-screen reports ----------------
$byDiv = db_all("SELECT d.name, COUNT(*) n, SUM(o.total_cents) total, SUM(o.vat_cents) vat, SUM(o.amount_paid_cents) paid
                 FROM orders o JOIN divisions d ON d.id = o.division_id WHERE {$live} AND o.division_id IN ({$in}) AND o.placed_at BETWEEN ? AND ? GROUP BY d.id", array_merge($ids, $range));
$top = db_all("SELECT oi.name, oi.variant_name, SUM(oi.qty) qty, SUM(oi.line_total_cents) revenue,
               SUM(oi.line_total_cents - COALESCE(oi.unit_cost_cents, 0) * oi.qty) AS margin, SUM(oi.unit_cost_cents IS NULL) AS no_cost
               FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE {$live} AND o.division_id IN ({$in}) AND o.placed_at BETWEEN ? AND ?
               GROUP BY oi.name, oi.variant_name ORDER BY revenue DESC LIMIT 15", array_merge($ids, $range));
$vat = db_all("SELECT d.name, dl.zero_rated, SUM(CASE WHEN doc.doc_type = 'credit_note' THEN 0 ELSE dl.line_excl_cents END) AS excl_sales,
               SUM(CASE WHEN doc.doc_type = 'credit_note' THEN dl.line_excl_cents ELSE 0 END) AS excl_credits, SUM(dl.line_vat_cents) AS vat
               FROM documents doc JOIN document_lines dl ON dl.document_id = doc.id JOIN divisions d ON d.id = doc.division_id
               WHERE doc.doc_type IN ('tax_invoice','invoice','credit_note') AND doc.is_void = 0 AND doc.division_id IN ({$in}) AND doc.issued_at BETWEEN ? AND ?
               GROUP BY d.id, dl.zero_rated", array_merge($ids, $range));
$pay = db_all("SELECT method, COUNT(*) n, SUM(amount_cents) s FROM payments WHERE status = 'succeeded' AND division_id IN ({$in}) AND created_at BETWEEN ? AND ? GROUP BY method", array_merge($ids, $range));
$cust = db_all("SELECT o.billing_name, COUNT(*) n, SUM(o.total_cents) s FROM orders o WHERE {$live} AND o.division_id IN ({$in}) AND o.placed_at BETWEEN ? AND ? GROUP BY o.customer_id, o.billing_name ORDER BY s DESC LIMIT 10", array_merge($ids, $range));
$qs = 'from=' . e($from) . '&amp;to=' . e($to);

admin_start('Reports', 'reports', ['perm' => 'reports.view']);
?>
<form class="adm-filters" method="get">
  <label class="small muted">From <input class="input" type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="small muted">To <input class="input" type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="btn btn-sm" type="submit">Update</button>
  <a class="chip" href="/admin/reports.php?from=<?= date('Y-m-01') ?>&amp;to=<?= date('Y-m-d') ?>">This month</a>
  <a class="chip" href="/admin/reports.php?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&amp;to=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Last month</a>
  <a class="chip" href="/admin/reports.php?from=<?= date('Y') ?>-03-01&amp;to=<?= date('Y-m-d') ?>">Tax year (from 1 Mar)</a>
</form>
<div class="row mb-3">
  <a class="btn btn-sm" href="/admin/reports.php?export=orders&amp;<?= $qs ?>"><svg class="icon icon-sm"><use href="#i-download"/></svg>Orders CSV</a>
  <a class="btn btn-sm" href="/admin/reports.php?export=documents&amp;<?= $qs ?>"><svg class="icon icon-sm"><use href="#i-download"/></svg>Invoices &amp; credit notes CSV (for your accountant)</a>
  <a class="btn btn-sm" href="/admin/reports.php?export=stock"><svg class="icon icon-sm"><use href="#i-download"/></svg>Stock valuation CSV</a>
  <a class="btn btn-sm" href="/admin/reports.php?export=customers"><svg class="icon icon-sm"><use href="#i-download"/></svg>Customers CSV</a>
</div>

<div class="kpis">
  <?php foreach ($byDiv as $dv): ?>
    <div class="kpi"><div class="k-label"><?= e($dv['name']) ?> sales</div><div class="k-value"><?= e(money((int) $dv['total'], false)) ?></div><div class="k-sub"><?= (int) $dv['n'] ?> orders · VAT <?= e(money((int) $dv['vat'], false)) ?></div></div>
  <?php endforeach; ?>
  <?php foreach ($pay as $p): ?>
    <div class="kpi"><div class="k-label"><?= e(strtoupper($p['method'])) ?> received</div><div class="k-value"><?= e(money((int) $p['s'], false)) ?></div><div class="k-sub"><?= (int) $p['n'] ?> payments</div></div>
  <?php endforeach; ?>
</div>

<div class="adm-grid-2">
  <section class="card card-pad">
    <h2 class="h4 mb-2">Best sellers &amp; margin</h2>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Revenue</th><th class="num">Gross margin</th></tr></thead>
      <tbody><?php foreach ($top as $t): ?>
        <tr><td><?= e($t['name']) ?> <span class="tiny dim"><?= e($t['variant_name'] ?? '') ?></span></td><td class="num"><?= (int) $t['qty'] ?></td><td class="num"><?= e(money((int) $t['revenue'], false)) ?></td>
          <td class="num"><?= (int) $t['no_cost'] ? '<span class="tiny dim">add cost price</span>' : e(money((int) $t['margin'], false)) ?></td></tr>
      <?php endforeach; ?><?php if (!$top): ?><tr><td colspan="4" class="muted">No sales in this period.</td></tr><?php endif; ?></tbody>
    </table></div>
  </section>
  <aside>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">VAT summary</h2>
      <p class="tiny dim">From issued tax invoices and credit notes. Confirm with your accountant before filing.</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Division</th><th class="num">Sales excl</th><th class="num">Credits</th><th class="num">VAT</th></tr></thead>
        <tbody><?php foreach ($vat as $v): ?>
          <tr><td><?= e($v['name']) ?><?= (int) $v['zero_rated'] ? ' <span class="badge">0%</span>' : '' ?></td><td class="num"><?= e(money((int) $v['excl_sales'], false)) ?></td>
            <td class="num"><?= e(money((int) $v['excl_credits'], false)) ?></td><td class="num"><?= e(money((int) $v['vat'], false)) ?></td></tr>
        <?php endforeach; ?><?php if (!$vat): ?><tr><td colspan="4" class="muted">No invoices in this period.</td></tr><?php endif; ?></tbody>
      </table></div>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Top customers</h2>
      <ul class="trust-list"><?php foreach ($cust as $c): ?><li><strong><?= e(money((int) $c['s'], false)) ?></strong> <?= e($c['billing_name']) ?> <span class="tiny dim">(<?= (int) $c['n'] ?>)</span></li><?php endforeach; ?></ul>
    </section>
  </aside>
</div>
<?php admin_end(); ?>
