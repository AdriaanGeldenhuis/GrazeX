<?php
/**
 * Shared checkout page. Included by /feeds/checkout.php and /ranch/checkout.php after setting $division.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;
/** @var string $division */

$divisionId = division_id($division);
$customer = current_customer();
$summary = CartService::summary($division);
if (!$summary['lines']) {
    flash('info', $summary['notices'][0] ?? 'Your cart is empty.');
    redirect('/' . $division . '/cart.php');
}
$isRanch = $division === 'ranch';
$quote = OrderService::quote($division, ['fulfilment' => 'collection'], $customer);
$calendar = DeliveryService::collectionCalendar($divisionId, setting_int('max_collection_days_ahead', 30));
$point = DeliveryService::collectionPoint($divisionId);
$addresses = $customer ? db_all('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC', [$customer['id']]) : [];
$towns = $isRanch ? [] : DeliveryService::allTowns();
$methods = OrderService::allowedPaymentMethods($division, 'collection', $quote['deposit']);
$d = division($divisionId);
$vatReg = (int) $d['vat_registered'] === 1;
$lastOrder = $customer ? db_one('SELECT billing_name, billing_phone, billing_company, billing_vat_no, billing_address, buyer_id_number, buyer_brand_mark
                                  FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$customer['id']]) : null;
$prefill = [
    'billing_name' => $lastOrder['billing_name'] ?? ($customer ? trim($customer['first_name'] . ' ' . $customer['last_name']) : ''),
    'billing_phone' => $lastOrder['billing_phone'] ?? ($customer['phone'] ?? ''),
    'billing_company' => $lastOrder['billing_company'] ?? ($customer['company_name'] ?? ''),
    'billing_vat_no' => $lastOrder['billing_vat_no'] ?? ($customer['vat_no'] ?? ''),
    'billing_address' => $lastOrder['billing_address'] ?? '',
    'buyer_id_number' => $lastOrder['buyer_id_number'] ?? '',
    'buyer_brand_mark' => $lastOrder['buyer_brand_mark'] ?? '',
];
$methodInfo = [
    'yoco' => ['card', 'Card or Instant EFT', 'Pay securely now with Yoco. Your order is confirmed instantly.'],
    'eft' => ['bank', 'Bank transfer (EFT)', 'Pay into our account and upload proof of payment. Stock is held for ' . setting_int('eft_hold_hours', 48) . ' hours.'],
    'cash_on_collection' => ['cash', $isRanch ? 'Pay at the farm' : 'Pay on collection', $isRanch ? 'Reserve now, pay cash, card or EFT when you collect.' : 'Pay cash or card when you collect at the depot.'],
];

$page = [
    'title' => 'Checkout',
    'division' => $division,
    'css' => ['/assets/css/store.css', '/assets/css/checkout.css'],
    'js' => ['/assets/js/checkout.js'],
    'noindex' => true,
    'hide_fab' => true,
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top checkout" data-division="<?= e($division) ?>" data-expected-total="<?= (int) $quote['total'] ?>">
  <div class="row-between mb-2">
    <h1 class="display-3"><?= $isRanch ? 'Reserve your livestock' : 'Checkout' ?></h1>
    <a class="link small" href="/<?= e($division) ?>/cart.php">← Back to <?= $isRanch ? 'selection' : 'cart' ?></a>
  </div>
  <?php $cartChanges = CartService::changes($summary); ?>
  <?php if ($cartChanges): ?>
    <div class="alert alert-warning mb-2" role="alert"><strong>Your cart was updated:</strong> <?= e(implode(' ', $cartChanges)) ?></div>
  <?php endif; ?>
  <ol class="steps mb-3" aria-label="Checkout progress">
    <li class="<?= $customer ? 'is-done' : 'is-current' ?>">Account</li>
    <li class="<?= $customer ? 'is-current' : '' ?>">Details</li>
    <li><?= $isRanch ? 'Collection' : 'Delivery' ?></li>
    <li>Payment</li>
  </ol>

  <div class="co-grid">
    <div class="co-main">
    <?php if (!$customer): ?>
      <section class="card card-pad co-auth">
        <h2 class="h3 mb-1">Let's get you set up</h2>
        <p class="muted small">An account keeps your invoices, lets you track orders and reorder in one tap.</p>
        <div class="tabs" role="tablist">
          <button class="tab" role="tab" aria-selected="true" aria-controls="auth-new" id="t-new">I'm new here</button>
          <button class="tab" role="tab" aria-selected="false" aria-controls="auth-login" id="t-login">I have an account</button>
        </div>
        <form class="tab-panel" id="auth-new" role="tabpanel" aria-labelledby="t-new" data-auth="register" novalidate>
          <div class="grid-2">
            <div class="field"><label for="r-first">First name</label><input class="input" id="r-first" name="first_name" autocomplete="given-name" required></div>
            <div class="field"><label for="r-last">Last name</label><input class="input" id="r-last" name="last_name" autocomplete="family-name" required></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="r-email">Email</label><input class="input" id="r-email" name="email" type="email" autocomplete="email" required></div>
            <div class="field"><label for="r-phone">Cellphone</label><input class="input" id="r-phone" name="phone" type="tel" autocomplete="tel" placeholder="082 123 4567" required></div>
          </div>
          <div class="field"><label for="r-pass">Create a password <span class="opt">(min <?= GX_PASSWORD_MIN ?> characters)</span></label>
            <input class="input" id="r-pass" name="password" type="password" autocomplete="new-password" minlength="<?= GX_PASSWORD_MIN ?>" required></div>
          <label class="check mb-2"><input type="checkbox" name="marketing_opt_in" value="1"> Send me specials and new livestock alerts (you can unsubscribe any time)</label>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Create account &amp; continue</button>
        </form>
        <form class="tab-panel" id="auth-login" role="tabpanel" aria-labelledby="t-login" data-auth="login" hidden novalidate>
          <div class="field"><label for="l-email">Email</label><input class="input" id="l-email" name="email" type="email" autocomplete="email" required></div>
          <div class="field"><label for="l-pass">Password</label><input class="input" id="l-pass" name="password" type="password" autocomplete="current-password" required></div>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Log in &amp; continue</button>
          <p class="small mt-2"><a class="link" href="/account/forgot.php">Forgot your password?</a></p>
        </form>
      </section>
    <?php else: ?>
      <form id="checkout-form" data-api="/<?= e($division) ?>/api/checkout.php" novalidate>
        <section class="card card-pad co-section">
          <div class="co-head"><span class="co-num">1</span><h2 class="h3">Your details</h2><span class="muted small">Logged in as <?= e($customer['email']) ?></span></div>
          <div class="grid-2">
            <div class="field"><label for="billing_name">Full name</label><input class="input" id="billing_name" name="billing_name" value="<?= e($prefill['billing_name']) ?>" autocomplete="name" required></div>
            <div class="field"><label for="billing_phone">Cellphone</label><input class="input" id="billing_phone" name="billing_phone" type="tel" value="<?= e($prefill['billing_phone']) ?>" autocomplete="tel" required></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="billing_company">Farm / company name <span class="opt">(optional)</span></label><input class="input" id="billing_company" name="billing_company" value="<?= e($prefill['billing_company']) ?>" autocomplete="organization"></div>
            <div class="field"><label for="billing_vat_no">Your VAT number <span class="opt">(optional)</span></label><input class="input" id="billing_vat_no" name="billing_vat_no" value="<?= e($prefill['billing_vat_no']) ?>" inputmode="numeric" placeholder="4xxxxxxxxx"></div>
          </div>
          <div class="field"><label for="billing_address">Billing address <?= $isRanch ? '' : '<span class="opt">(required on invoices over R5 000)</span>' ?></label>
            <textarea class="textarea" id="billing_address" name="billing_address" rows="2" autocomplete="street-address" <?= $isRanch ? 'required' : '' ?>><?= e($prefill['billing_address']) ?></textarea></div>
          <?php if ($isRanch): ?>
            <div class="grid-2">
              <div class="field"><label for="buyer_id_number">ID or company registration number</label><input class="input" id="buyer_id_number" name="buyer_id_number" value="<?= e($prefill['buyer_id_number']) ?>" required>
                <span class="hint">Needed for the livestock document of identification.</span></div>
              <div class="field"><label for="buyer_brand_mark">Your brand / tattoo mark <span class="opt">(optional)</span></label><input class="input" id="buyer_brand_mark" name="buyer_brand_mark" value="<?= e($prefill['buyer_brand_mark']) ?>"></div>
            </div>
          <?php endif; ?>
        </section>

        <section class="card card-pad co-section">
          <div class="co-head"><span class="co-num">2</span><h2 class="h3"><?= $isRanch ? 'Collection at the farm' : 'Collection or delivery' ?></h2></div>
          <?php if (!$isRanch): ?>
            <div class="option-cards co-two">
              <label class="option-card"><input type="radio" name="fulfilment" value="collection" checked><span class="oc-radio"></span>
                <span><span class="oc-title"><svg class="icon icon-sm"><use href="#i-store"/></svg> Collect</span><br><span class="oc-text"><?= e($point['name'] ?? 'Our depot') ?> · free</span></span></label>
              <label class="option-card<?= $towns ? '' : ' is-disabled' ?>"><input type="radio" name="fulfilment" value="delivery" <?= $towns ? '' : 'disabled' ?>><span class="oc-radio"></span>
                <span><span class="oc-title"><svg class="icon icon-sm"><use href="#i-truck"/></svg> Deliver</span><br><span class="oc-text"><?= $towns ? 'Local zones on set days' : 'Not available yet' ?></span></span></label>
            </div>
          <?php else: ?>
            <input type="hidden" name="fulfilment" value="collection">
          <?php endif; ?>

          <div class="co-panel" data-panel="collection">
            <?php if ($point): ?><p class="muted small mt-2"><svg class="icon icon-sm" style="display:inline;vertical-align:-3px"><use href="#i-pin"/></svg> <?= e($point['name']) ?><?= $point['address'] ? ' — ' . e($point['address']) : '' ?><?= $point['hours_text'] ? ' · ' . e($point['hours_text']) : '' ?></p><?php endif; ?>
            <?php if ($calendar): ?>
              <span class="label">Choose a day</span>
              <div class="date-chips" role="radiogroup" aria-label="Collection day">
                <?php foreach ($calendar as $i => $day): ?>
                  <label class="date-chip"><input type="radio" name="collection_date" value="<?= e($day['date']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <span><b><?= e(date('D', strtotime($day['date']))) ?></b><strong><?= e(date('j', strtotime($day['date']))) ?></strong><small><?= e(date('M', strtotime($day['date']))) ?></small></span></label>
                <?php endforeach; ?>
              </div>
              <span class="label mt-2" style="display:block">Choose a time</span>
              <?php foreach ($calendar as $i => $day): ?>
                <div class="slot-list" data-slots-for="<?= e($day['date']) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                  <?php $firstFree = true; foreach ($day['slots'] as $s): $free = $s['left'] > 0; ?>
                    <label class="slot<?= $free ? '' : ' is-full' ?>"><input type="radio" name="collection_slot_id" value="<?= (int) $s['id'] ?>" <?= $free ? '' : 'disabled' ?> <?= ($i === 0 && $free && $firstFree) ? 'checked' : '' ?>>
                      <span><?= e($s['label']) ?><small><?= $free ? ($s['left'] <= 3 ? $s['left'] . ' left' : 'Available') : 'Full' ?></small></span></label>
                  <?php if ($free) { $firstFree = false; } endforeach; ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="alert alert-warning mt-2">No collection times are open right now. Please contact us to arrange collection.</div>
            <?php endif; ?>
            <?php if ($isRanch): ?>
              <div class="field mt-2"><label for="transport_note">Transport <span class="opt">(optional)</span></label>
                <input class="input" id="transport_note" name="transport_note" placeholder="e.g. I'll bring my own trailer / please recommend a transporter"></div>
            <?php endif; ?>
          </div>

          <?php if (!$isRanch): ?>
          <div class="co-panel" data-panel="delivery" hidden>
            <?php if ($addresses): ?>
              <div class="field mt-2"><label for="address_id">Deliver to</label>
                <select class="select" id="address_id" name="address_id">
                  <?php foreach ($addresses as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" data-town="<?= e($a['town']) ?>"><?= e(($a['label'] ? $a['label'] . ' — ' : '') . $a['line1'] . ', ' . $a['town']) ?></option>
                  <?php endforeach; ?>
                  <option value="new">+ New address</option>
                </select></div>
            <?php else: ?>
              <input type="hidden" name="address_id" value="new">
            <?php endif; ?>
            <div class="new-address" <?= $addresses ? 'hidden' : '' ?>>
              <div class="grid-2 mt-2">
                <div class="field"><label for="a-name">Receiver's name</label><input class="input" id="a-name" name="address[recipient_name]" value="<?= e($prefill['billing_name']) ?>"></div>
                <div class="field"><label for="a-phone">Receiver's phone</label><input class="input" id="a-phone" name="address[phone]" type="tel" value="<?= e($prefill['billing_phone']) ?>"></div>
              </div>
              <div class="grid-2">
                <div class="field"><label for="a-farm">Farm name <span class="opt">(optional)</span></label><input class="input" id="a-farm" name="address[farm_name]"></div>
                <div class="field"><label for="a-line1">Street / farm road</label><input class="input" id="a-line1" name="address[line1]" autocomplete="address-line1"></div>
              </div>
              <div class="grid-2">
                <div class="field"><label for="a-town">Town</label><input class="input" id="a-town" name="town" list="zone-towns" autocomplete="address-level2">
                  <datalist id="zone-towns"><?php foreach ($towns as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist></div>
                <div class="field"><label for="a-postal">Postal code <span class="opt">(optional)</span></label><input class="input" id="a-postal" name="address[postal_code]" inputmode="numeric"></div>
              </div>
              <div class="field"><label for="a-dir">Directions for the driver <span class="opt">(optional)</span></label><textarea class="textarea" id="a-dir" name="address[directions]" rows="2" placeholder="e.g. 3rd gate after the silos, white farmhouse"></textarea></div>
              <div class="row"><button class="btn btn-sm" type="button" data-gps><svg class="icon icon-sm"><use href="#i-pin"/></svg>Add my GPS location</button><span class="small muted" data-gps-label></span></div>
              <input type="hidden" name="address[gps_lat]"><input type="hidden" name="address[gps_lng]">
              <label class="check mt-2"><input type="checkbox" name="save_address" value="1" checked> Save this address for next time</label>
            </div>
            <div class="zone-info mt-2" data-zone-info></div>
            <div class="field mt-2"><label for="delivery_date">Delivery day</label><select class="select" id="delivery_date" name="delivery_date"><option value="">Choose your town first</option></select></div>
          </div>
          <?php endif; ?>
        </section>

        <section class="card card-pad co-section">
          <div class="co-head"><span class="co-num">3</span><h2 class="h3">Payment</h2></div>
          <?php if ($isRanch && $quote['deposit'] > 0): ?>
            <p class="muted small">Pay the <strong><?= e(money($quote['deposit'])) ?></strong> deposit now to reserve. The balance is payable at collection<?= $quote['est_weight_kg'] > 0 ? ' (final weights confirmed on the day)' : '' ?>.</p>
          <?php elseif ($isRanch): ?>
            <p class="muted small">No deposit is needed for this reservation — nothing is paid now. You pay at the farm when you collect<?= $quote['est_weight_kg'] > 0 ? ' (final weights confirmed on the day)' : '' ?>.</p>
          <?php endif; ?>
          <div class="alert alert-warning" data-no-methods <?= $methods ? 'hidden' : '' ?>>Online payment isn't available for this order yet<?= !$isRanch ? ' — choose collection, or' : ' —' ?> please contact us to complete it.</div>
          <div class="option-cards" data-methods>
            <?php foreach ($methodInfo as $key => [$icon, $title, $text]): ?>
              <label class="option-card" data-method="<?= e($key) ?>" <?= in_array($key, $methods, true) ? '' : 'hidden' ?>>
                <input type="radio" name="payment_method" value="<?= e($key) ?>" <?= ($methods[0] ?? '') === $key ? 'checked' : '' ?>><span class="oc-radio"></span>
                <span><span class="oc-title"><svg class="icon icon-sm"><use href="#i-<?= e($icon) ?>"/></svg> <?= e($title) ?></span><br><span class="oc-text"><?= e($text) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="card card-pad co-section">
          <div class="co-head"><span class="co-num">4</span><h2 class="h3">Confirm</h2></div>
          <div class="field"><label for="customer_note">Notes for us <span class="opt">(optional)</span></label><textarea class="textarea" id="customer_note" name="customer_note" rows="2"></textarea></div>
          <label class="check"><input type="checkbox" name="accept_terms" value="1" required> I accept the <a class="link" href="/pages/page.php?slug=terms" target="_blank">terms &amp; conditions</a> and <a class="link" href="/pages/page.php?slug=privacy" target="_blank">privacy policy</a>.</label>
          <?php if (!(int) ($customer['marketing_opt_in'] ?? 0)): ?>
            <label class="check mt-1"><input type="checkbox" name="marketing_opt_in" value="1"> Send me specials and livestock alerts.</label>
          <?php endif; ?>
          <button class="btn btn-primary btn-lg btn-block mt-3 co-submit" type="submit" <?= $methods ? '' : 'disabled' ?>><svg class="icon"><use href="#i-lock"/></svg> <span data-submit-label><?= $isRanch ? 'Reserve' : 'Place order' ?></span> · <span data-total-now><?= e(money($isRanch ? $quote['deposit'] : $quote['total'])) ?></span></button>
          <p class="hint center mt-1">You'll get an email confirmation with your documents.</p>
        </section>
      </form>
    <?php endif; ?>
    </div>

    <aside class="co-summary">
      <div class="card card-pad">
        <h2 class="h4 mb-2"><?= $isRanch ? 'Your livestock' : 'Your order' ?> <span class="muted">(<?= count($quote['lines']) ?>)</span></h2>
        <ul class="co-lines">
          <?php foreach ($quote['lines'] as $l): ?>
            <li><span class="co-q"><?= (int) $l['qty'] ?>×</span><span class="co-n"><?= e($l['name']) ?><small><?= e($l['variant']) ?></small></span><span class="co-t"><?= e(money($l['total'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <dl class="cart-totals mt-2 mb-0" data-totals>
          <div><dt>Subtotal</dt><dd data-t="subtotal"><?= e(money($quote['subtotal'])) ?></dd></div>
          <div data-row="discount" <?= $quote['discount'] ? '' : 'hidden' ?>><dt>Discount</dt><dd data-t="discount">−<?= e(money($quote['discount'])) ?></dd></div>
          <?php if (!$isRanch): ?><div><dt>Delivery</dt><dd data-t="delivery"><?= $quote['delivery'] ? e(money($quote['delivery'])) : 'Free' ?></dd></div><?php endif; ?>
          <div class="ct-total"><dt>Total<?= $isRanch && $quote['est_weight_kg'] > 0 ? ' <small class="dim">(est.)</small>' : '' ?></dt><dd data-t="total"><?= e(money($quote['total'])) ?></dd></div>
          <?php if ($vatReg): ?><div class="small"><dt>Includes VAT</dt><dd data-t="vat"><?= e(money($quote['vat'])) ?></dd></div><?php endif; ?>
          <?php if ($isRanch): ?>
            <div class="text-accent"><dt>Due now (deposit)</dt><dd data-t="due_now"><?= e(money($quote['deposit'])) ?></dd></div>
            <div class="small"><dt>Balance at collection</dt><dd data-t="balance"><?= e(money($quote['balance'])) ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if ($quote['zero_rated']): ?><div class="alert alert-success small mt-2">Zero-rated feed for VAT-registered farmers applied — you save <?= e(money($quote['savings'])) ?>.</div><?php endif; ?>
      </div>
      <ul class="trust-list mt-2 small">
        <li><svg class="icon icon-sm"><use href="#i-shield"/></svg>Secure checkout · your details stay private</li>
        <li><svg class="icon icon-sm"><use href="#i-file"/></svg>Invoice &amp; documents emailed and saved in your account</li>
      </ul>
    </aside>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
