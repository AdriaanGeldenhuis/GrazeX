<?php
/**
 * GET /payments/api/status.php?t=PAY_TOKEN[&check=1] — payment status for the return page.
 * With check=1 (after ~10s) it asks Yoco directly as a fallback if the webhook is late.
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_NO_SESSION', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');
rate_limit_or_fail('pay_status', client_ip(), 120, 300);

$token = query_str('t');
$order = preg_match('/^[a-f0-9]{64}$/', $token) ? db_one('SELECT id, status, payment_status, amount_paid_cents, total_cents FROM orders WHERE pay_token = ?', [$token]) : null;
if (!$order) {
    json_error('Not found.', 404);
}

$pending = db_one("SELECT * FROM payments WHERE order_id = ? AND method = 'yoco' AND status = 'pending' AND provider_checkout_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$order['id']]);
if ($pending && query_str('check') === '1' && strtotime($pending['created_at']) < time() - 8) {
    $res = YocoService::fetchCheckout($pending['provider_checkout_id'], $pending['provider_mode'] ?: YocoService::mode());
    if ($res && ($res['status'] ?? '') === 'completed') {
        YocoService::settle($pending, (int) ($res['amount'] ?? 0), $res['paymentId'] ?? null, 'status check');
        $order = db_one('SELECT id, status, payment_status, amount_paid_cents, total_cents FROM orders WHERE id = ?', [$order['id']]);
    }
}

$lastYoco = db_one("SELECT status FROM payments WHERE order_id = ? AND method = 'yoco' ORDER BY id DESC LIMIT 1", [$order['id']]);
$paid = ($lastYoco['status'] ?? '') === 'succeeded';
json_ok([
    'status' => $order['status'],
    'payment_status' => $order['payment_status'],
    'paid' => $paid,
    // Charged, but the order had already closed (stock released / cancelled): the customer is refunded
    'late' => $paid && in_array($order['status'], ['expired', 'cancelled', 'refunded'], true),
    'failed' => ($lastYoco['status'] ?? '') === 'failed',
]);
