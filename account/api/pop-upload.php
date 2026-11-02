<?php
/**
 * POST /account/api/pop-upload.php (multipart: order_no, pop) — upload proof of payment for an EFT order.
 * Stored privately; the payment goes to the admin "POPs to verify" queue.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
rate_limit_or_fail('pop_upload', 'c' . $customer['id'], 8, 3600);

$o = db_one('SELECT * FROM orders WHERE order_no = ? AND customer_id = ?', [(string) ($_POST['order_no'] ?? ''), $customer['id']]);
if (!$o) {
    json_error('Order not found.', 404);
}
$due = OrderService::amountDue($o);
if ($due <= 0 || in_array($o['status'], ['cancelled', 'expired', 'refunded', 'completed'], true)) {
    json_error('Nothing is due on this order.', 409);
}
if (db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'awaiting_verification'", [$o['id']])) {
    json_error('We already have a proof of payment for this order and are checking it. We will email you once it is confirmed.', 409);
}
try {
    $file = store_private_upload($_FILES['pop'] ?? null, 'pop');
} catch (DomainException $e) {
    json_error($e->getMessage(), 422, ['pop' => $e->getMessage()]);
}
$isRanch = (int) $o['division_id'] === division_id('ranch');
$purpose = $isRanch && $o['status'] === 'reserved' && (int) $o['deposit_due_cents'] > 0 ? 'deposit' : ((int) $o['amount_paid_cents'] > 0 ? 'balance' : 'full');
$pid = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, pop_path, pop_original_name, pop_uploaded_at, reference)
                  VALUES (?, ?, 'eft', ?, ?, 'awaiting_verification', ?, ?, NOW(), ?)",
    [$o['id'], $o['division_id'], $purpose, $due, $file['path'], mb_substr((string) ($_FILES['pop']['name'] ?? 'pop'), 0, 255), $o['order_no']]);
OrderService::history((int) $o['id'], $o['status'], $o['status'], 'Proof of payment uploaded', 'customer', (int) $customer['id']);
NotificationService::admin('pop', 'POP uploaded for ' . $o['order_no'], $o['billing_name'] . ' uploaded proof of payment for ' . money($due) . '.',
    '/admin/payments.php?tab=pop', (int) $o['division_id']);
audit('payment.pop_uploaded', 'payment', $pid);
json_ok([], 'Thanks! We\'ll verify your payment and email you as soon as it is confirmed.');
