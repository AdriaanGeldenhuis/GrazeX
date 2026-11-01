<?php
/**
 * /payments/return.php?t=PAY_TOKEN&r=success|cancel|failed — customer comes back from Yoco.
 * The page waits for the webhook (polling /payments/api/status.php). A redirect alone is never
 * treated as proof of payment.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$token = query_str('t');
$order = preg_match('/^[a-f0-9]{64}$/', $token) ? db_one('SELECT * FROM orders WHERE pay_token = ?', [$token]) : null;
if (!$order) {
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}
$result = in_array(query_str('r'), ['success', 'cancel', 'failed'], true) ? query_str('r') : 'success';
$div = division_code((int) $order['division_id']);
$orderUrl = '/account/order.php?no=' . rawurlencode($order['order_no']);

$page = [
    'title' => 'Payment',
    'division' => $div,
    'css' => ['/payments/css/payments.css'],
    'js' => ['/payments/js/payments.js'],
    'noindex' => true,
    'hide_footer' => true,
    'hide_fab' => true,
];
require GX_ROOT . '/includes/layout/head.php';
?>
<main id="main" class="pay-screen">
  <div class="pay-card card card-pad" id="pay-status" data-token="<?= e($token) ?>" data-result="<?= e($result) ?>" data-order-url="<?= e($orderUrl) ?>">
    <img class="pay-logo" src="/assets/img/brand/<?= e($div) ?>-logo-480.webp" alt="GrazeX" width="240" height="120">
    <?php if ($result === 'success'): ?>
      <div data-state="waiting">
        <div class="pay-spinner" aria-hidden="true"></div>
        <h1 class="h3">Confirming your payment…</h1>
        <p class="muted">This usually takes a few seconds. Please don't close this page.</p>
      </div>
      <div data-state="paid" hidden>
        <div class="pay-check" aria-hidden="true"><svg class="icon icon-lg"><use href="#i-check"/></svg></div>
        <h1 class="h3">Payment received — thank you!</h1>
        <p class="muted">Order <strong><?= e($order['order_no']) ?></strong>. Your documents are on their way to <?= e($order['billing_email']) ?>.</p>
        <a class="btn btn-primary btn-lg btn-block" href="<?= e($orderUrl) ?>">View your order</a>
      </div>
      <div data-state="slow" hidden>
        <h1 class="h3">Still confirming…</h1>
        <p class="muted">Your bank is taking a little longer. We'll email you as soon as the payment is confirmed — you can safely close this page.</p>
        <a class="btn btn-primary btn-block" href="<?= e($orderUrl) ?>">View your order</a>
      </div>
    <?php else: ?>
      <h1 class="h3"><?= $result === 'cancel' ? 'Payment cancelled' : 'Payment did not go through' ?></h1>
      <p class="muted">No money was taken. Your order <strong><?= e($order['order_no']) ?></strong> is still reserved for a short while.</p>
      <a class="btn btn-primary btn-lg btn-block" href="/payments/pay.php?t=<?= e($token) ?>">Try again</a>
      <a class="btn btn-block mt-1" href="<?= e($orderUrl) ?>">Pay another way</a>
    <?php endif; ?>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
