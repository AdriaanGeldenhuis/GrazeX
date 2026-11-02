<?php
/**
 * Admin — one order: actions, payments, livestock weights, documents, history, notes.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$o = db_one('SELECT * FROM orders WHERE id = ?', [query_int('id')]);
if (!$o || !in_array((int) $o['division_id'], admin_division_ids(), true)) {
    require_admin();
    http_response_code(404);
    exit('Order not found.');
}
$isRanch = (int) $o['division_id'] === division_id('ranch');
$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
$payments = db_all('SELECT p.*, a.name AS verified_name FROM payments p LEFT JOIN admin_users a ON a.id = p.verified_by WHERE p.order_id = ? ORDER BY p.id', [$o['id']]);
$docs = db_all('SELECT * FROM documents WHERE order_id = ? ORDER BY id', [$o['id']]);
$history = db_all('SELECT h.*, a.name AS admin_name FROM order_status_history h LEFT JOIN admin_users a ON a.id = h.actor_id AND h.actor_type = \'admin\' WHERE h.order_id = ? ORDER BY h.id DESC', [$o['id']]);
$customer = db_one('SELECT * FROM customers WHERE id = ?', [$o['customer_id']]);
$orderCount = (int) db_val('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$o['customer_id']]);
$due = OrderService::amountDue($o);
$next = OrderService::nextStatuses($o);
$closed = in_array($o['status'], ['cancelled', 'expired', 'refunded'], true);
$slot = $o['collection_slot_id'] ? db_one('SELECT start_time, end_time FROM collection_slots WHERE id = ?', [$o['collection_slot_id']]) : null;
$api = '/admin/api/orders.php';
$transitionLabels = [
    'processing' => ['Start preparing', 'btn-primary'], 'ready_for_collection' => ['Mark ready for collection', 'btn-primary'],
    'out_for_delivery' => ['Out for delivery', 'btn-primary'], 'completed' => ['Mark completed', 'btn-green'], 'collected' => ['Mark collected', 'btn-green'],
    'refunded' => ['Mark refunded', 'btn-danger'],
];
$wa = whatsapp_link($o['billing_phone'], 'Hi ' . explode(' ', $o['billing_name'])[0] . ', this is GrazeX about your order ' . $o['order_no'] . '. ');

admin_start('Order ' . $o['order_no'], 'orders', ['perm' => 'orders.view']);
?>
<div class="od-head">
  <a class="btn btn-sm btn-ghost" href="/admin/orders.php"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>Orders</a>
  <?= admin_div_chip((int) $o['division_id']) ?> <?= admin_status_badge($o['status']) ?>
  <span class="badge"><?= e(strtoupper($o['payment_status'])) ?></span>
  <span class="muted small">Placed <?= e(fmt_datetime($o['placed_at'])) ?><?= $o['hold_expires_at'] && !$closed ? ' · hold until ' . e(fmt_datetime($o['hold_expires_at'])) : '' ?></span>
</div>

<?php if (admin_can('orders.manage') && !$closed): ?>
<div class="od-actions mb-3">
  <?php foreach ($next as $to): if (in_array($to, ['cancelled', 'finalised', 'refunded'], true)) continue; [$lbl, $cls] = $transitionLabels[$to] ?? [OrderService::label($to)[0], '']; ?>
    <button class="btn <?= e($cls) ?>" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'transition', 'id' => (int) $o['id'], 'to' => $to])) ?>'><?= e($lbl) ?></button>
  <?php endforeach; ?>
  <?php if (in_array('cancelled', $next, true)): ?>
    <button class="btn btn-danger" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'cancel', 'id' => (int) $o['id']])) ?>'
            data-prompt="Reason for cancelling (the customer will see this):" data-prompt-field="reason" data-confirm="Cancel <?= e($o['order_no']) ?>? Stock goes back on the shelf<?= (int) $o['amount_paid_cents'] ? ' and a refund is flagged' : '' ?>." data-ok="Cancel order" data-danger>Cancel order</button>
  <?php endif; ?>
  <?php if (in_array('refunded', $next, true) && admin_can('payments.refund')): ?>
    <button class="btn btn-danger" type="button" data-modal-open="refund-modal">Refund…</button>
  <?php endif; ?>
  <?php if ($wa): ?><a class="btn btn-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg>WhatsApp</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="adm-grid-2">
  <div>
    <?php if ($isRanch && in_array($o['status'], ['reserved', 'deposit_paid'], true) && admin_can('orders.manage')): ?>
      <section class="card card-pad adm-section" style="border-color:var(--accent-line)">
        <h2 class="h4 mb-1">Weigh &amp; finalise</h2>
        <p class="muted small">Enter the actual total live weight per line (per-kg lots). Per-head lines keep their price. This issues the final invoice and emails it.</p>
        <form data-admin-form data-api="<?= e($api) ?>" data-action="finalise" data-reload>
          <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
          <?php foreach ($items as $it): ?>
            <div class="repeat-row" style="grid-template-columns:1fr 160px">
              <div><strong><?= e($it['name']) ?></strong><br><span class="small muted"><?= (int) $it['qty'] ?> head · <?= $it['pricing_mode'] === 'per_kg' ? e(money((int) $it['unit_price_cents'])) . '/kg · est. ' . e(kg((float) $it['est_weight_kg'])) : 'per head ' . e(money((int) $it['unit_price_cents'])) ?></span></div>
              <?php if ($it['pricing_mode'] === 'per_kg'): ?>
                <div class="field"><label for="w-<?= (int) $it['id'] ?>">Actual total kg</label><input class="input" id="w-<?= (int) $it['id'] ?>" type="number" step="0.1" min="1" inputmode="decimal" name="weights[<?= (int) $it['id'] ?>]" value="<?= e($it['actual_weight_kg'] ?? '') ?>" placeholder="est. <?= e((string) (float) $it['est_weight_kg']) ?>" required></div>
              <?php else: ?><div class="muted small">No weighing needed</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-scale"/></svg>Finalise &amp; send invoice</button>
        </form>
      </section>
    <?php endif; ?>

    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Items</h2>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr><td><strong><?= e($it['name']) ?></strong> <span class="small muted"><?= e($it['variant_name'] ?? '') ?> · <?= e($it['sku'] ?? '') ?></span>
            <?php if ($it['pricing_mode'] === 'per_kg'): ?><br><span class="small muted"><?= $it['actual_weight_kg'] !== null ? 'Weighed ' . e(kg((float) $it['actual_weight_kg'])) : 'Est. ' . e(kg((float) $it['est_weight_kg'])) ?></span><?php endif; ?></td>
            <td class="num"><?= (int) $it['qty'] ?></td><td class="num"><?= e(money((int) $it['unit_price_cents'])) ?><?= $it['pricing_mode'] === 'per_kg' ? '/kg' : '' ?></td><td class="num"><?= e(money((int) $it['line_total_cents'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <dl class="cart-totals mt-2 mb-0" style="max-width:360px;margin-left:auto">
        <div><dt>Subtotal</dt><dd><?= e(money((int) $o['subtotal_cents'])) ?></dd></div>
        <?php if ((int) $o['discount_cents']): ?><div><dt>Discount</dt><dd>−<?= e(money((int) $o['discount_cents'])) ?></dd></div><?php endif; ?>
        <?php if ($o['fulfilment'] === 'delivery'): ?><div><dt>Delivery</dt><dd><?= e(money((int) $o['delivery_cents'])) ?></dd></div><?php endif; ?>
        <div class="ct-total"><dt>Total</dt><dd><?= e(money((int) $o['total_cents'])) ?></dd></div>
        <div class="small"><dt>VAT included</dt><dd><?= e(money((int) $o['vat_cents'])) ?></dd></div>
        <?php if ((int) $o['deposit_due_cents']): ?><div><dt>Deposit due</dt><dd><?= e(money((int) $o['deposit_due_cents'])) ?></dd></div><?php endif; ?>
        <div><dt>Paid</dt><dd><?= e(money((int) $o['amount_paid_cents'])) ?></dd></div>
        <div class="text-accent"><dt>Outstanding</dt><dd><?= e(money($due)) ?></dd></div>
      </dl>
    </section>

    <section class="card card-pad adm-section">
      <div class="row-between mb-2"><h2 class="h4">Payments</h2>
        <?php if ($due > 0 && !$closed && admin_can('payments.verify')): ?><button class="btn btn-sm btn-primary" type="button" data-modal-open="pay-modal"><svg class="icon icon-sm"><use href="#i-cash"/></svg>Record payment</button><?php endif; ?></div>
      <?php if ($payments): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>When</th><th>Method</th><th>Status</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_datetime($p['created_at'])) ?></td>
              <td><?= e(strtoupper($p['method'])) ?> · <?= e($p['purpose']) ?><?= $p['provider_payment_id'] ? '<br><span class="tiny dim">' . e($p['provider_payment_id']) . '</span>' : '' ?></td>
              <td><span class="badge <?= $p['status'] === 'succeeded' ? 'badge-success' : ($p['status'] === 'awaiting_verification' ? 'badge-info' : '') ?>"><?= e(str_replace('_', ' ', $p['status'])) ?></span>
                <?= $p['verified_name'] ? '<br><span class="tiny dim">by ' . e($p['verified_name']) . '</span>' : '' ?><?= $p['rejection_reason'] ? '<br><span class="tiny dim">' . e($p['rejection_reason']) . '</span>' : '' ?></td>
              <td class="num"><?= e(money((int) $p['amount_cents'])) ?></td>
              <td class="actions">
                <?php if ($p['pop_path']): ?><a class="btn btn-sm" href="/admin/api/documents.php?pop=<?= (int) $p['id'] ?>" target="_blank" rel="noopener">View POP</a><?php endif; ?>
                <?php if ($p['status'] === 'awaiting_verification' && admin_can('payments.verify')): ?>
                  <button class="btn btn-sm btn-green" type="button" data-admin-act="/admin/api/payments.php" data-payload='<?= e(json_encode(['action' => 'approve', 'id' => (int) $p['id']])) ?>' data-prompt="Amount received (R):" data-prompt-field="amount" data-prompt-default="<?= e(number_format((int) $p['amount_cents'] / 100, 2, '.', '')) ?>">Approve</button>
                  <button class="btn btn-sm btn-danger" type="button" data-admin-act="/admin/api/payments.php" data-payload='<?= e(json_encode(['action' => 'reject', 'id' => (int) $p['id']])) ?>' data-prompt="Why can't you match this payment? (sent to customer)" data-prompt-field="reason">Reject</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><p class="muted small mb-0">No payments yet.</p><?php endif; ?>
    </section>

    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">History</h2>
      <ol class="timeline">
        <?php foreach ($history as $h): ?>
          <li class="is-done"><div class="tl-title"><?= e(OrderService::label($h['to_status'])[0]) ?><?= $h['note'] ? ' — <span class="muted">' . e($h['note']) . '</span>' : '' ?></div>
            <div class="tl-time"><?= e(fmt_datetime($h['created_at'])) ?> · <?= e($h['admin_name'] ?? $h['actor_type']) ?></div></li>
        <?php endforeach; ?>
      </ol>
    </section>
  </div>

  <aside>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Customer</h2>
      <dl class="kv">
        <dt>Name</dt><dd><a class="link" href="/admin/customer.php?id=<?= (int) $o['customer_id'] ?>"><?= e($o['billing_name']) ?></a> <span class="tiny dim">(<?= $orderCount ?> orders)</span></dd>
        <?php if ($o['billing_company']): ?><dt>Company</dt><dd><?= e($o['billing_company']) ?></dd><?php endif; ?>
        <dt>Email</dt><dd><a class="link" href="mailto:<?= e($o['billing_email']) ?>"><?= e($o['billing_email']) ?></a></dd>
        <dt>Phone</dt><dd><a class="link" href="tel:<?= e($o['billing_phone']) ?>"><?= e($o['billing_phone']) ?></a></dd>
        <?php if ($o['billing_vat_no']): ?><dt>VAT no</dt><dd><?= e($o['billing_vat_no']) ?></dd><?php endif; ?>
        <?php if ($o['billing_address']): ?><dt>Address</dt><dd><?= nl2br(e($o['billing_address'])) ?></dd><?php endif; ?>
        <?php if ($isRanch): ?><dt>ID / Reg</dt><dd><?= e($o['buyer_id_number'] ?? '—') ?></dd><dt>Brand mark</dt><dd><?= e($o['buyer_brand_mark'] ?: '—') ?></dd><?php endif; ?>
        <?php if ($customer && $customer['vat_farmer_status'] !== 'none'): ?><dt>VAT farmer</dt><dd><?= e($customer['vat_farmer_status']) ?></dd><?php endif; ?>
      </dl>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2"><?= $o['fulfilment'] === 'delivery' ? 'Delivery' : 'Collection' ?></h2>
      <?php if ($o['fulfilment'] === 'delivery'): ?>
        <p class="mb-1"><strong><?= e(fmt_date($o['delivery_date'], 'l j F')) ?></strong></p>
        <p class="small mb-1"><?= e(implode(', ', array_filter([$o['ship_name'], $o['ship_phone'], $o['ship_farm_name'], $o['ship_line1'], $o['ship_line2'], $o['ship_town'], $o['ship_postal_code']]))) ?></p>
        <?php if ($o['ship_directions']): ?><p class="small muted mb-1"><?= e($o['ship_directions']) ?></p><?php endif; ?>
        <?php if ($o['ship_gps_lat']): ?><a class="btn btn-sm" href="https://www.google.com/maps?q=<?= e($o['ship_gps_lat'] . ',' . $o['ship_gps_lng']) ?>" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-pin"/></svg>Open GPS pin</a><?php endif; ?>
      <?php else: ?>
        <p class="mb-0"><strong><?= e(fmt_date($o['collection_date'], 'l j F')) ?></strong><?= $slot ? ' · ' . e(substr($slot['start_time'], 0, 5) . '–' . substr($slot['end_time'], 0, 5)) : '' ?></p>
      <?php endif; ?>
      <?php if ($o['transport_note']): ?><p class="small mt-1 mb-0">🚛 <?= e($o['transport_note']) ?></p><?php endif; ?>
      <?php if ($o['customer_note']): ?><div class="alert alert-info small mt-2">💬 <?= e($o['customer_note']) ?></div><?php endif; ?>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Documents</h2>
      <ul class="doc-list">
        <?php foreach ($docs as $d): ?>
          <li class="row-between" style="gap:6px">
            <a class="link small" href="/admin/api/documents.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= e(DocumentService::TITLES[$d['doc_type']] ?? $d['doc_type']) ?> · <?= e($d['doc_no']) ?></a>
            <?php if ($d['is_void']): ?><span class="badge badge-danger">Void</span><?php endif; ?>
            <?php if (!in_array($d['doc_type'], ['pick_list', 'collection_slip', 'delivery_note'], true)): ?>
              <button class="btn btn-sm btn-ghost" type="button" data-admin-act="/admin/api/documents.php" data-payload='<?= e(json_encode(['action' => 'resend', 'id' => (int) $d['id']])) ?>' data-no-reload title="Email to customer">✉</button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="row mt-2">
        <button class="btn btn-sm" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'document', 'id' => (int) $o['id'], 'type' => $o['fulfilment'] === 'delivery' ? 'delivery_note' : 'pick_list'])) ?>'>Pick list</button>
        <?php if ($o['payment_status'] !== 'unpaid' && !$isRanch): ?><button class="btn btn-sm" type="button" data-admin-act="<?= e($api) ?>" data-payload='<?= e(json_encode(['action' => 'document', 'id' => (int) $o['id'], 'type' => 'tax_invoice'])) ?>'>Invoice</button><?php endif; ?>
      </div>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Internal notes</h2>
      <form data-admin-form data-api="<?= e($api) ?>" data-action="note">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        <textarea class="textarea" name="admin_note" rows="4" placeholder="Only admins see this"><?= e($o['admin_note']) ?></textarea>
        <button class="btn btn-sm mt-1" type="submit">Save note</button>
      </form>
    </section>
  </aside>
</div>

<dialog class="modal" id="pay-modal" aria-labelledby="pay-title">
  <form data-modal-form data-api="/admin/api/payments.php" data-action="record" novalidate>
    <div class="modal-head"><h3 id="pay-title">Record payment — <?= e($o['order_no']) ?></h3><button class="nav-icon-btn" type="button" data-modal-close>✕</button></div>
    <div class="modal-body">
      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <div class="field"><label for="pm-amount">Amount (R)</label><input class="input" id="pm-amount" name="amount" inputmode="decimal" value="<?= e(number_format($due / 100, 2, '.', '')) ?>" required></div>
      <div class="field"><span class="label">Method</span>
        <div class="option-cards co-two" style="grid-template-columns:1fr 1fr">
          <?php foreach (['cash' => 'Cash', 'card' => 'Card machine', 'eft' => 'EFT (received)'] as $k => $l): ?>
            <label class="option-card"><input type="radio" name="method" value="<?= e($k) ?>" <?= $k === 'cash' ? 'checked' : '' ?>><span class="oc-radio"></span><span class="oc-title"><?= e($l) ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <div class="field"><label for="pm-ref">Reference <span class="opt">(optional)</span></label><input class="input" id="pm-ref" name="reference"></div>
      <p class="hint">A receipt is issued and emailed automatically<?= !$isRanch ? ', plus the tax invoice once fully paid' : '' ?>.</p>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record payment</button></div>
  </form>
</dialog>
<?php if (in_array('refunded', $next, true) && admin_can('payments.refund')): ?>
<dialog class="modal" id="refund-modal" aria-labelledby="refund-title">
  <form data-modal-form data-api="<?= e($api) ?>" data-action="refund" novalidate>
    <div class="modal-head"><h3 id="refund-title">Refund <?= e($o['order_no']) ?></h3><button class="nav-icon-btn" type="button" data-modal-close aria-label="Close">✕</button></div>
    <div class="modal-body">
      <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
      <p class="muted small">A credit note is issued against the invoice and the payments are marked refunded. Pay the money back yourself (EFT, or the Yoco portal for card payments).</p>
      <div class="field"><label for="rf-reason">Reason (shown on the credit note)</label><input class="input" id="rf-reason" name="reason" maxlength="250" required></div>
      <label class="check"><input type="checkbox" name="restock" value="1"> The goods came back — put them back in stock</label>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit">Refund order</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php admin_end(); ?>
