<?php
/**
 * /payments/pay.php?t=PAY_TOKEN — start (or resume) a Yoco card payment for an order.
 * Works from email/WhatsApp links: the browser lands on our https page first and then
 * JavaScript forwards to Yoco, so the Referer header Yoco requires is always present.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$token = query_str('t');
$order = preg_match('/^[a-f0-9]{64}$/', $token) ? db_one('SELECT * FROM orders WHERE pay_token = ?', [$token]) : null;
if (!$order) {
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}
$orderUrl = '/account/order.php?no=' . rawurlencode($order['order_no']);
$due = OrderService::amountDue($order);
$error = null;
$payment = null;
if ($due <= 0) {
    redirect($orderUrl);
}
if (!in_array($order['status'], ['pending_payment', 'reserved', 'awaiting_collection', 'deposit_paid', 'finalised', 'paid', 'processing', 'ready_for_collection'], true)) {
    $error = 'This order is ' . strtolower(OrderService::label($order['status'])[0]) . ' and can no longer be paid online.';
} else {
    try {
        if (!rate_limit_hit('yoco_checkout', rate_limit_ip(), 20, 600)) {
            throw new DomainException(t('error.rate_limited'));
        }
        $payment = YocoService::checkoutForOrder($order);
    } catch (Throwable $e) {
        $error = $e instanceof DomainException || $e instanceof RuntimeException ? $e->getMessage() : t('error.generic');
        gx_log('warning', 'Pay page error', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
    }
}
$div = division_code((int) $order['division_id']);

$page = [
    'title' => 'Secure payment',
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
  <div class="pay-card card card-pad">
    <img class="pay-logo" src="/assets/img/brand/<?= e($div) ?>-logo-480.webp" alt="GrazeX <?= e(ucfirst($div)) ?>" width="240" height="120">
    <?php if ($error): ?>
      <h1 class="h3">Card payment unavailable</h1>
      <p class="muted"><?= e($error) ?></p>
      <a class="btn btn-primary btn-block" href="<?= e($orderUrl) ?>">View order &amp; other ways to pay</a>
    <?php else: ?>
      <div class="pay-spinner" aria-hidden="true"></div>
      <h1 class="h3">Taking you to secure payment…</h1>
      <p class="muted">Order <strong><?= e($order['order_no']) ?></strong> · <?= e(money((int) $payment['amount_cents'])) ?></p>
      <a class="btn btn-primary btn-lg btn-block" id="pay-go" data-redirect="<?= e($payment['redirect_url']) ?>" href="<?= e($payment['redirect_url']) ?>">
        <svg class="icon"><use href="#i-lock"/></svg> Pay <?= e(money((int) $payment['amount_cents'])) ?> now
      </a>
      <p class="tiny dim mt-2">Payments are processed securely by Yoco. Card details never touch our servers.</p>
      <a class="link small" href="<?= e($orderUrl) ?>">Back to order</a>
    <?php endif; ?>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
