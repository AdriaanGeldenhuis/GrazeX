<?php
/**
 * Admin — inventory: stock levels, adjustments, goods received, stock take and the ledger.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$tab = in_array(query_str('tab'), ['stock', 'lots', 'receive', 'stocktake', 'ledger'], true) ? query_str('tab') : 'stock';
$variantFilter = query_int('variant');
$filter = query_str('filter');
$variants = db_all('SELECT v.*, p.name AS product_name, p.id AS product_id FROM product_variants v JOIN products p ON p.id = v.product_id
                    WHERE p.is_active = 1 ' . ($filter === 'low' ? 'AND v.stock_qty <= v.low_stock_threshold ' : '') . ($variantFilter ? 'AND v.id = ' . $variantFilter . ' ' : '') . '
                    ORDER BY p.name, v.sort_order');
$value = db_one('SELECT COALESCE(SUM(GREATEST(stock_qty,0) * COALESCE(cost_cents,0)),0) AS cost, COALESCE(SUM(GREATEST(stock_qty,0) * price_cents),0) AS retail, COALESCE(SUM(GREATEST(stock_qty,0)),0) AS units FROM product_variants WHERE is_active = 1');
$openTake = db_one("SELECT * FROM stock_takes WHERE status = 'open' ORDER BY id DESC LIMIT 1");
$takeItems = $openTake ? db_all('SELECT t.*, v.name, v.sku, p.name AS product_name FROM stock_take_items t JOIN product_variants v ON v.id = t.variant_id JOIN products p ON p.id = v.product_id WHERE t.stock_take_id = ? ORDER BY p.name, v.sort_order', [$openTake['id']]) : [];
$ledger = $tab === 'ledger' ? db_all('SELECT m.*, v.name AS variant_name, p.name AS product_name, l.title AS lot_title, l.lot_no, a.name AS admin_name, o.order_no
        FROM stock_movements m LEFT JOIN product_variants v ON v.id = m.variant_id LEFT JOIN products p ON p.id = v.product_id
        LEFT JOIN lots l ON l.id = m.lot_id LEFT JOIN admin_users a ON a.id = m.admin_id LEFT JOIN orders o ON o.id = m.order_id
        ' . ($variantFilter ? 'WHERE m.variant_id = ' . $variantFilter : '') . ' ORDER BY m.id DESC LIMIT 300') : [];
$lots = $tab === 'lots' ? db_all("SELECT l.*, b.name AS breed_name FROM lots l JOIN breeds b ON b.id = l.breed_id WHERE l.status <> 'archived' ORDER BY l.id DESC") : [];
$variantOpts = [];
foreach (db_all('SELECT v.id, v.name, v.sku, p.name AS pn FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 ORDER BY p.name, v.sort_order') as $v) {
    $variantOpts[$v['id']] = $v['pn'] . ' — ' . $v['name'] . ' (' . $v['sku'] . ')';
}

admin_start('Inventory', 'inventory', ['perm' => 'inventory.manage']);
?>
<div class="kpis">
  <div class="kpi"><div class="k-label">Units in stock</div><div class="k-value"><?= number_format((int) $value['units'], 0, '.', ' ') ?></div></div>
  <div class="kpi"><div class="k-label">Stock value (cost)</div><div class="k-value"><?= e(money((int) $value['cost'], false)) ?></div></div>
  <div class="kpi"><div class="k-label">Stock value (retail)</div><div class="k-value"><?= e(money((int) $value['retail'], false)) ?></div></div>
  <div class="kpi"><div class="k-label">Potential margin</div><div class="k-value"><?= e(money((int) $value['retail'] - (int) $value['cost'], false)) ?></div></div>
</div>
<div class="tabs mb-3" role="tablist">
  <?php foreach (['stock' => 'Feed stock', 'lots' => 'Livestock', 'receive' => 'Receive stock', 'stocktake' => 'Stock take', 'ledger' => 'Ledger'] as $k => $l): ?>
    <a class="tab" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" href="/admin/inventory.php?tab=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'stock'): ?>
  <div class="row mb-2"><a class="chip<?= $filter !== 'low' ? ' is-active' : '' ?>" href="/admin/inventory.php">All</a><a class="chip<?= $filter === 'low' ? ' is-active' : '' ?>" href="/admin/inventory.php?filter=low">Low stock</a></div>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><th>Product</th><th>SKU</th><th class="num">In stock</th><th class="num">Low at</th><th class="num">Cost</th><th>Adjust</th></tr></thead>
    <tbody>
    <?php foreach ($variants as $v): $s = (int) $v['stock_qty']; ?>
      <tr>
        <td data-label=""><a class="row-link" href="/admin/product.php?id=<?= (int) $v['product_id'] ?>"><?= e($v['product_name']) ?></a><br><span class="tiny dim"><?= e($v['name']) ?></span></td>
        <td data-label="SKU"><?= e($v['sku']) ?></td>
        <td class="num" data-label="Stock"><span class="<?= $s <= 0 ? 'stock-out' : ($s <= (int) $v['low_stock_threshold'] ? 'stock-low' : 'stock-ok') ?>"><?= $s ?></span></td>
        <td class="num" data-label="Low at"><?= (int) $v['low_stock_threshold'] ?></td>
        <td class="num" data-label="Cost"><?= $v['cost_cents'] !== null ? e(money((int) $v['cost_cents'])) : '—' ?></td>
        <td class="actions">
          <button class="btn btn-sm" type="button" data-admin-act="/admin/api/inventory.php" data-payload='<?= e(json_encode(['action' => 'adjust', 'variant_id' => (int) $v['id']])) ?>'
                  data-prompt="Change stock of <?= e($v['product_name'] . ' — ' . $v['name']) ?> by (e.g. +10 or -2), then a reason after a space. Example: -2 torn bags" data-prompt-field="change">± Adjust</button>
          <a class="btn btn-sm btn-ghost" href="/admin/inventory.php?tab=ledger&amp;variant=<?= (int) $v['id'] ?>">History</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'lots'): ?>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><th>Lot</th><th class="num">Available</th><th class="num">Total</th><th>Status</th><th>Adjust</th></tr></thead>
    <tbody>
    <?php foreach ($lots as $l): ?>
      <tr><td data-label=""><a class="row-link" href="/admin/lot.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a><br><span class="tiny dim"><?= e($l['lot_no']) ?> · <?= e($l['breed_name']) ?></span></td>
        <td class="num" data-label="Available"><strong><?= (int) $l['qty_available'] ?></strong></td><td class="num" data-label="Total"><?= (int) $l['qty_total'] ?></td>
        <td data-label=""><span class="badge"><?= e($l['status']) ?></span></td>
        <td class="actions"><button class="btn btn-sm" type="button" data-admin-act="/admin/api/inventory.php" data-payload='<?= e(json_encode(['action' => 'adjust_lot', 'lot_id' => (int) $l['id']])) ?>'
              data-prompt="Change available head for <?= e($l['title']) ?> by (e.g. -1 died, +3 added):" data-prompt-field="change">± Adjust</button></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'receive'): ?>
  <section class="card card-pad">
    <h2 class="h4 mb-1">Receive stock from a supplier</h2>
    <p class="muted small">Adds stock to every line, records the cost price and writes it to the ledger.</p>
    <form data-admin-form data-api="/admin/api/inventory.php" data-action="receive" data-reload novalidate>
      <div class="form-grid-3">
        <?= af_text('supplier_name', 'Supplier', '', ['optional' => true]) ?>
        <?= af_text('supplier_invoice_no', 'Supplier invoice no.', '', ['optional' => true]) ?>
        <?= af_text('received_on', 'Received on', date('Y-m-d'), ['type' => 'date']) ?>
      </div>
      <div data-repeat="lines">
        <div class="repeat-row" data-repeat-row>
          <div class="field" style="grid-column:span 2"><label>Product</label><select class="select" name="lines[0][variant_id]"><?php foreach ($variantOpts as $vid => $vl): ?><option value="<?= (int) $vid ?>"><?= e($vl) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Qty</label><input class="input" type="number" min="1" name="lines[0][qty]" required></div>
          <div class="field"><label>Cost each (R)</label><input class="input" inputmode="decimal" name="lines[0][unit_cost]"></div>
          <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button>
        </div>
      </div>
      <template data-repeat-template="lines"><div class="repeat-row" data-repeat-row>
        <div class="field" style="grid-column:span 2"><label>Product</label><select class="select" name="lines[__i__][variant_id]"><?php foreach ($variantOpts as $vid => $vl): ?><option value="<?= (int) $vid ?>"><?= e($vl) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Qty</label><input class="input" type="number" min="1" name="lines[__i__][qty]" required></div>
        <div class="field"><label>Cost each (R)</label><input class="input" inputmode="decimal" name="lines[__i__][unit_cost]"></div>
        <button class="btn btn-ghost btn-icon" type="button" data-repeat-remove>✕</button></div></template>
      <div class="row mt-2"><button class="btn" type="button" data-repeat-add="lines">＋ Add line</button><button class="btn btn-primary" type="submit">Receive stock</button></div>
    </form>
  </section>

<?php elseif ($tab === 'stocktake'): ?>
  <?php if (!$openTake): ?>
    <section class="card card-pad center">
      <h2 class="h4 mb-1">Stock take</h2>
      <p class="muted">Freeze the system counts, walk the store with your phone and type what you count. Differences are corrected and logged in one go.</p>
      <button class="btn btn-primary" type="button" data-admin-act="/admin/api/inventory.php" data-payload='{"action":"stocktake_start"}'>Start a stock take</button>
    </section>
  <?php else: ?>
    <form data-admin-form data-api="/admin/api/inventory.php" data-action="stocktake_save" novalidate>
      <input type="hidden" name="id" value="<?= (int) $openTake['id'] ?>">
      <p class="muted small">Started <?= e(fmt_datetime($openTake['created_at'])) ?>. Leave a line empty to skip it. Save as you go; complete when done.</p>
      <div class="table-wrap"><table class="table dt dt-cards">
        <thead><tr><th>Product</th><th class="num">System</th><th>Counted</th></tr></thead>
        <tbody>
        <?php foreach ($takeItems as $t): ?>
          <tr><td data-label=""><?= e($t['product_name']) ?><br><span class="tiny dim"><?= e($t['name']) ?> · <?= e($t['sku']) ?></span></td>
            <td class="num" data-label="System"><?= (int) $t['system_qty'] ?></td>
            <td data-label=""><input class="input" style="max-width:120px" type="number" min="0" inputmode="numeric" name="counts[<?= (int) $t['id'] ?>]" value="<?= $t['counted_qty'] !== null ? (int) $t['counted_qty'] : '' ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="save-bar">
        <button class="btn btn-ghost" type="button" data-admin-act="/admin/api/inventory.php" data-payload='<?= e(json_encode(['action' => 'stocktake_cancel', 'id' => (int) $openTake['id']])) ?>' data-confirm="Cancel this stock take? Counts are discarded." data-danger>Cancel</button>
        <button class="btn" type="submit">Save counts</button>
        <button class="btn btn-primary" type="button" data-admin-act="/admin/api/inventory.php" data-payload='<?= e(json_encode(['action' => 'stocktake_complete', 'id' => (int) $openTake['id']])) ?>' data-confirm="Apply the counted quantities? Differences are written to the ledger. (Save your counts first.)" data-ok="Apply">Complete stock take</button>
      </div>
    </form>
  <?php endif; ?>

<?php else: ?>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><th>When</th><th>Item</th><th>Type</th><th class="num">Change</th><th class="num">Balance</th><th>Reference</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $m): ?>
      <tr>
        <td data-label=""><?= e(fmt_datetime($m['created_at'])) ?></td>
        <td data-label=""><?= $m['lot_id'] ? e($m['lot_title'] . ' (' . $m['lot_no'] . ')') : e(($m['product_name'] ?? '?') . ' — ' . ($m['variant_name'] ?? '')) ?></td>
        <td data-label=""><span class="badge"><?= e(str_replace('_', ' ', $m['type'])) ?></span></td>
        <td class="num" data-label=""><strong class="<?= (int) $m['qty_change'] < 0 ? 'stock-low' : 'stock-ok' ?>"><?= (int) $m['qty_change'] > 0 ? '+' : '' ?><?= (int) $m['qty_change'] ?></strong></td>
        <td class="num" data-label="Balance"><?= (int) $m['balance_after'] ?></td>
        <td data-label=""><?= $m['order_no'] ? '<a class="link" href="/admin/order.php?id=' . (int) $m['order_id'] . '">' . e($m['order_no']) . '</a>' : e($m['reference'] ?? '') ?><?= $m['note'] ? '<br><span class="tiny dim">' . e($m['note']) . '</span>' : '' ?></td>
        <td data-label=""><?= e($m['admin_name'] ?? 'system') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ledger): ?><tr><td colspan="7" class="muted">No stock movements yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php admin_end(); ?>
