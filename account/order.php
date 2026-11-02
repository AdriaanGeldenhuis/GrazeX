<?php
/**
 * Customer portal — one order: status timeline, payment (EFT details / POP upload / pay by card),
 * documents, items, cancel and reorder.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$o = db_one('SELECT * FROM orders WHERE order_no = ? AND customer_id = ?', [query_str('no'), $customer['id']]);
if (!$o) {
    $page = ['division' => 'account'];
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}
$div = division_code((int) $o['division_id']);
$d = division((int) $o['division_id']);
$isRanch = $div === 'ranch';
$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$o['id']]);
$history = db_all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id', [$o['id']]);
$docs = db_all("SELECT id, doc_type, doc_no, issued_at, total_cents FROM documents WHERE order_id = ? AND is_void = 0
                AND doc_type IN ('order_confirmation','tax_invoice','invoice','receipt','credit_note','doc_identification','collection_slip','delivery_note') ORDER BY id", [$o['id']]);
$payments = db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$o['id']]);
$due = OrderService::amountDue($o);
$awaitingPop = (bool) array_filter($payments, static fn($p) => $p['status'] === 'awaiting_verification');
$closed = in_array($o['status'], ['cancelled', 'expired', 'refunded'], true);
$canCancel = in_array($o['status'], ['pending_payment', 'reserved', 'awaiting_collection'], true) && $o['payment_status'] === 'unpaid' && !$awaitingPop;
$cardOpen = $canCancel && OrderService::cardCheckoutOpen((int) $o['id']);
$canCancel = $canCancel && !$cardOpen;
[$label, $cls] = OrderService::label($o['status']);
$placed = query_str('placed') === '1';
$slot = $o['collection_slot_id'] ? db_one('SELECT start_time, end_time FROM collection_slots WHERE id = ?', [$o['collection_slot_id']]) : null;
$point = $o['collection_point_id'] ? db_one('SELECT * FROM collection_points WHERE id = ?', [$o['collection_point_id']]) : null;

// Customer-friendly progress steps
$steps = $isRanch
    ? ['reserved' => 'Reserved', 'deposit_paid' => 'Deposit paid', 'finalised' => 'Weighed & invoiced', 'ready_for_collection' => 'Ready', 'collected' => 'Collected']
    : ($o['fulfilment'] === 'delivery'
        ? ['pending_payment' => 'Order placed', 'paid' => 'Paid', 'processing' => 'Being packed', 'out_for_delivery' => 'On the way', 'completed' => 'Delivered']
        : ['pending_payment' => 'Order placed', 'paid' => 'Paid', 'processing' => 'Being packed', 'ready_for_collection' => 'Ready to collect', 'completed' => 'Collected']);
if (!$isRanch && $o['payment_method'] === 'cash_on_collection') {
    $steps = ['awaiting_collection' => 'Order placed', 'processing' => 'Being packed', 'ready_for_collection' => 'Ready to collect', 'completed' => 'Collected & paid'];
}
$reached = array_column($history, 'to_status');
$keys = array_keys($steps);
$currentIdx = -1;
foreach ($keys as $i => $k) {
    if (in_array($k, $reached, true) || $o['status'] === $k) {
        $currentIdx = $i;
    }
}
if ($o['status'] === 'completed') {
    $currentIdx = count($keys) - 1;
}
$docIcons = ['order_confirmation' => 'file', 'tax_invoice' => 'file', 'invoice' => 'file', 'receipt' => 'check', 'credit_note' => 'file', 'doc_identification' => 'shield'];

$page = ['title' => 'Order ' . $o['order_no'], 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <?php if ($placed && !$closed): ?>
    <section class="placed-hero mb-3 reveal">
      <div class="big-check"><svg class="icon"><use href="#i-check"/></svg></div>
      <h1 class="display-3" tabindex="-1"><?= $isRanch ? 'Reserved!' : 'Order placed!' ?></h1>
      <p class="lead" style="margin:10px auto 0">Thanks <?= e($customer['first_name']) ?> — order <strong><?= e($o['order_no']) ?></strong> is confirmed. We've emailed your confirmation to <?= e($o['billing_email']) ?>.</p>
    </section>
  <?php endif; ?>

  <div class="acc-head">
    <div>
      <a class="link small" href="/account/orders.php">← All orders</a>
      <h1 class="display-3 mt-1"><?= e($o['order_no']) ?></h1>
      <div class="row mt-1"><span class="badge <?= e($cls) ?>"><?= e($label) ?></span><span class="muted small"><?= e($d['name']) ?> · placed <?= e(fmt_datetime($o['placed_at'])) ?></span></div>
    </div>
    <div class="row">
      <?php if (!$isRanch && !$closed): ?><button class="btn" type="button" data-act="/account/api/orders.php" data-payload='<?= e(json_encode(['action' => 'reorder', 'order_no' => $o['order_no']])) ?>'><svg class="icon"><use href="#i-repeat"/></svg>Order again</button><?php endif; ?>
      <?php if (!$isRanch && $closed): ?><button class="btn" type="button" data-act="/account/api/orders.php" data-payload='<?= e(json_encode(['action' => 'reorder', 'order_no' => $o['order_no']])) ?>'><svg class="icon"><use href="#i-repeat"/></svg>Order these again</button><?php endif; ?>
    </div>
  </div>

  <div class="od-grid">
    <div class="acc-main">
      <?php if ($due > 0 && !$closed): ?>
        <section class="card card-pad" style="border-color:var(--accent-line)">
          <h2 class="h3 mb-1">Amount due: <span class="text-accent"><?= e(money($due)) ?></span></h2>
          <?php if ($isRanch && $o['status'] === 'reserved' && (int) $o['deposit_due_cents'] > 0): ?><p class="muted small">This is the deposit that secures your animals. The balance is payable at collection.</p><?php endif; ?>
          <?php if ($o['hold_expires_at'] && in_array($o['status'], ['pending_payment', 'reserved'], true)): ?><p class="muted small">Please pay before <strong><?= e(fmt_datetime($o['hold_expires_at'])) ?></strong> — after that the reserved stock is released.</p><?php endif; ?>

          <?php if ($awaitingPop): ?>
            <div class="alert alert-info mt-1"><svg class="icon"><use href="#i-clock"/></svg><div>We received your proof of payment and are checking it. You'll get an email once it's confirmed.</div></div>
          <?php elseif ($o['payment_method'] === 'eft' || ($o['payment_method'] !== 'yoco' && in_array($o['status'], ['finalised', 'deposit_paid'], true))): ?>
            <?php if ($d['bank_account_no']): ?>
              <dl class="bank-box mt-1">
                <div><dt>Bank</dt><dd><?= e($d['bank_name']) ?></dd></div>
                <div><dt>Account name</dt><dd><?= e($d['bank_account_name']) ?></dd></div>
                <div><dt>Account number</dt><dd><?= e($d['bank_account_no']) ?> <button class="copy-btn" type="button" data-copy="<?= e($d['bank_account_no']) ?>">Copy</button></dd></div>
                <div><dt>Branch code</dt><dd><?= e($d['bank_branch_code']) ?></dd></div>
                <div><dt>Reference</dt><dd><?= e($o['order_no']) ?> <button class="copy-btn" type="button" data-copy="<?= e($o['order_no']) ?>">Copy</button></dd></div>
                <div><dt>Amount</dt><dd><?= e(money($due)) ?></dd></div>
              </dl>
            <?php else: ?>
              <div class="alert alert-warning mt-1">Our banking details will be emailed to you shortly. Use <strong><?= e($o['order_no']) ?></strong> as your reference.</div>
            <?php endif; ?>
            <form id="pop-form" class="mt-2" novalidate>
              <input type="hidden" name="order_no" value="<?= e($o['order_no']) ?>">
              <label class="drop" style="position:relative">
                <input type="file" name="pop" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                <svg class="icon icon-lg"><use href="#i-upload"/></svg>
                <strong>Upload proof of payment</strong>
                <span class="small muted" data-file-label>Tap to choose a photo or PDF</span>
              </label>
              <button class="btn btn-primary btn-block mt-1" type="submit">Send proof of payment</button>
            </form>
          <?php endif; ?>
          <?php if (YocoService::configured() && $due >= 200 && setting_bool('payment_yoco_enabled', true) && !$awaitingPop): ?>
            <a class="btn <?= $o['payment_method'] === 'yoco' ? 'btn-primary btn-lg' : '' ?> btn-block mt-2" href="/payments/pay.php?t=<?= e($o['pay_token']) ?>"><svg class="icon"><use href="#i-card"/></svg>Pay <?= e(money($due)) ?> by card now</a>
          <?php endif; ?>
          <?php if ($o['payment_method'] === 'cash_on_collection'): ?>
            <p class="muted mb-0 mt-1">Pay by cash or card when you collect<?= $o['collection_date'] ? ' on <strong>' . e(fmt_date($o['collection_date'])) . '</strong>' : '' ?>.</p>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if (!$closed): ?>
      <section class="card card-pad">
        <h2 class="h3 mb-2">Progress</h2>
        <ol class="timeline">
          <?php foreach ($keys as $i => $k): ?>
            <li class="<?= $i < $currentIdx ? 'is-done' : ($i === $currentIdx ? 'is-done is-current' : '') ?>">
              <div class="tl-title"><?= e($steps[$k]) ?></div>
              <?php foreach (array_reverse($history) as $h): if ($h['to_status'] === $k) { echo '<div class="tl-time">' . e(fmt_datetime($h['created_at'])) . '</div>'; break; } endforeach; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
      <?php else: ?>
        <div class="alert alert-danger"><div><strong><?= e($label) ?>.</strong> <?= e($o['cancel_reason'] ?? '') ?></div></div>
      <?php endif; ?>

      <section class="card card-pad">
        <h2 class="h3 mb-2"><?= $isRanch ? 'Livestock' : 'Items' ?></h2>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><strong><?= e($it['name']) ?></strong><br><span class="small muted"><?= e($it['variant_name'] ?? '') ?><?php if ($it['pricing_mode'] === 'per_kg'): ?> · <?= $it['actual_weight_kg'] !== null ? 'weighed ' . e(kg((float) $it['actual_weight_kg'])) : 'est. ' . e(kg((float) $it['est_weight_kg'])) ?><?php endif; ?></span></td>
              <td class="num"><?= (int) $it['qty'] ?></td>
              <td class="num"><?= e(money((int) $it['unit_price_cents'])) ?><?= $it['pricing_mode'] === 'per_kg' ? '/kg' : '' ?></td>
              <td class="num"><?= e(money((int) $it['line_total_cents'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <dl class="cart-totals mt-2 mb-0" style="max-width:360px;margin-left:auto">
          <div><dt>Subtotal</dt><dd><?= e(money((int) $o['subtotal_cents'])) ?></dd></div>
          <?php if ((int) $o['discount_cents']): ?><div><dt>Discount</dt><dd>−<?= e(money((int) $o['discount_cents'])) ?></dd></div><?php endif; ?>
          <?php if ($o['fulfilment'] === 'delivery'): ?><div><dt>Delivery</dt><dd><?= (int) $o['delivery_cents'] ? e(money((int) $o['delivery_cents'])) : 'Free' ?></dd></div><?php endif; ?>
          <div class="ct-total"><dt>Total</dt><dd><?= e(money((int) $o['total_cents'])) ?></dd></div>
          <?php if ((int) $o['vat_cents']): ?><div class="small"><dt>Includes VAT</dt><dd><?= e(money((int) $o['vat_cents'])) ?></dd></div><?php endif; ?>
          <div><dt>Paid</dt><dd><?= e(money((int) $o['amount_paid_cents'])) ?></dd></div>
        </dl>
      </section>
    </div>

    <aside class="acc-main">
      <section class="card card-pad">
        <h2 class="h4 mb-2"><?= $o['fulfilment'] === 'delivery' ? 'Delivery' : 'Collection' ?></h2>
        <?php if ($o['fulfilment'] === 'delivery'): ?>
          <p class="mb-1"><strong><?= e(fmt_date($o['delivery_date'], 'l j F')) ?></strong></p>
          <p class="muted small mb-0"><?= e(implode(', ', array_filter([$o['ship_name'], $o['ship_farm_name'], $o['ship_line1'], $o['ship_line2'], $o['ship_town'], $o['ship_province'], $o['ship_postal_code']]))) ?></p>
        <?php else: ?>
          <p class="mb-1"><strong><?= e(fmt_date($o['collection_date'], 'l j F')) ?></strong><?= $slot ? ' · ' . e(substr($slot['start_time'], 0, 5) . '–' . substr($slot['end_time'], 0, 5)) : '' ?></p>
          <?php if ($point): ?><p class="muted small mb-0"><?= e($point['name']) ?><?= $point['address'] ? '<br>' . nl2br(e($point['address'])) : '' ?></p><?php endif; ?>
          <?php if ($map = setting('map_url')): ?><a class="btn btn-sm mt-2" href="<?= e($map) ?>" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-pin"/></svg>Directions</a><?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="card card-pad">
        <h2 class="h4 mb-2">Documents</h2>
        <?php if ($docs): ?>
          <ul class="doc-list">
            <?php foreach ($docs as $doc): ?>
              <li><a href="/account/api/document.php?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-<?= e($docIcons[$doc['doc_type']] ?? 'file') ?>"/></svg><?= e(DocumentService::TITLES[$doc['doc_type']] ?? $doc['doc_type']) ?><small><?= e($doc['doc_no']) ?></small></a></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?><p class="muted small mb-0">Your documents appear here.</p><?php endif; ?>
      </section>

      <?php if ($cardOpen): ?>
        <p class="small muted center">A card payment for this order is in progress. If it is not completed, the order is released automatically<?= $o['hold_expires_at'] ? ' at ' . e(fmt_datetime($o['hold_expires_at'])) : '' ?>.</p>
      <?php endif; ?>
      <?php if ($canCancel): ?>
        <button class="btn btn-danger btn-block" type="button" data-act="/account/api/orders.php" data-payload='<?= e(json_encode(['action' => 'cancel', 'order_no' => $o['order_no']])) ?>'
                data-confirm="Cancel order <?= e($o['order_no']) ?>? The reserved stock will be released." data-ok="Yes, cancel" data-danger>Cancel this order</button>
      <?php endif; ?>
      <p class="small muted center">Questions? <a class="link" href="/pages/contact.php">Contact us</a><?php if ($wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, about order ' . $o['order_no'])): ?> or <a class="link" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>.</p>
    </aside>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
