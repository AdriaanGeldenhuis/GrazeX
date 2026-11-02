<?php
/**
 * POST /payments/api/yoco-webhook.php — Yoco Checkout API webhook.
 * Register this URL once per mode (test/live) with Yoco and put each whsec_ secret in config/env.php.
 * No session / CSRF here: authenticity comes from the HMAC signature.
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_NO_SESSION', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('POST');

$raw = (string) file_get_contents('php://input');
$mode = YocoService::verifySignature($raw,
    (string) ($_SERVER['HTTP_WEBHOOK_ID'] ?? ''),
    (string) ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? ''),
    (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? ''));
if ($mode === null) {
    gx_log('warning', 'Yoco webhook signature rejected', ['ip' => client_ip()]);
    json_error('Invalid signature.', 403);
}

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    json_error('Bad payload.', 400);
}
$payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
$checkoutId = (string) ($payload['metadata']['checkoutId'] ?? '');

// Idempotency: each event is processed once. A repeat of an event whose first run failed
// (processed_at still NULL) is processed again — settle()/recordPayment() are idempotent per payment row.
try {
    db_exec('INSERT INTO payment_webhook_events (provider, event_id, event_type, checkout_id, payload) VALUES (?, ?, ?, ?, ?)',
        ['yoco', (string) $event['id'], (string) $event['type'], $checkoutId ?: null, $raw]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
        throw $e;
    }
    $prev = db_one('SELECT processed_at FROM payment_webhook_events WHERE provider = ? AND event_id = ?', ['yoco', (string) $event['id']]);
    if ($prev && $prev['processed_at'] !== null) {
        json_ok(['duplicate' => true]);
    }
}

$result = 'ignored';
// The webhook receives every payment on the Yoco account — only act on our own checkouts
$payment = $checkoutId !== '' ? db_one('SELECT * FROM payments WHERE provider_checkout_id = ?', [$checkoutId]) : null;
if ($payment) {
    switch ($event['type']) {
        case 'payment.succeeded':
            if (($payload['currency'] ?? 'ZAR') !== 'ZAR') {
                $result = 'wrong currency';
                break;
            }
            YocoService::settle($payment, (int) ($payload['amount'] ?? 0), isset($payload['id']) ? (string) $payload['id'] : null, 'webhook (' . $mode . ')');
            $result = 'settled';
            break;
        case 'payment.failed':
            db_exec("UPDATE payments SET status = 'failed', notes = 'Declined at Yoco' WHERE id = ? AND status = 'pending'", [$payment['id']]);
            $result = 'failed';
            break;
        case 'refund.succeeded':
            db_exec("UPDATE payments SET status = 'refunded', notes = CONCAT(COALESCE(notes,''), ' Refund confirmed by Yoco.') WHERE id = ?", [$payment['id']]);
            db_exec("UPDATE orders SET payment_status = 'refunded' WHERE id = ? AND amount_paid_cents > 0
                     AND NOT EXISTS (SELECT 1 FROM payments WHERE order_id = ? AND status = 'succeeded')", [$payment['order_id'], $payment['order_id']]);
            NotificationService::admin('refund', 'Yoco refund confirmed for ' . $payment['reference'], money((int) ($payload['amount'] ?? 0)) . ' refunded.',
                '/admin/order.php?id=' . $payment['order_id'], (int) $payment['division_id'], false);
            $result = 'refunded';
            break;
        case 'refund.failed':
            NotificationService::admin('refund', 'Yoco refund FAILED for ' . $payment['reference'], (string) ($payload['failureReason'] ?? ''),
                '/admin/order.php?id=' . $payment['order_id'], (int) $payment['division_id']);
            $result = 'refund failed';
            break;
    }
}
db_exec('UPDATE payment_webhook_events SET processed_at = NOW(), result = ? WHERE provider = ? AND event_id = ?', [$result, 'yoco', (string) $event['id']]);
json_ok(['result' => $result]);
