<?php
/**
 * Admin payments API.
 *   {action: approve, id, amount}  — verify an EFT proof of payment
 *   {action: reject, id, reason}   — reject a POP (customer is emailed)
 *   {action: record, order_id, amount, method (cash|card|eft), reference}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$admin = require_admin_api('payments.verify');
$aid = (int) $admin['id'];

try {
    switch ((string) input('action')) {
        case 'approve':
            $p = db_one("SELECT * FROM payments WHERE id = ? AND status = 'awaiting_verification'", [(int) input('id')]);
            if (!$p || !in_array((int) $p['division_id'], admin_division_ids(), true)) {
                json_error('Payment not found or already handled.', 404);
            }
            $amount = to_cents(input('amount', number_format((int) $p['amount_cents'] / 100, 2, '.', '')));
            if (!$amount || $amount <= 0) {
                json_error('Enter the amount received.', 422);
            }
            OrderService::recordPayment((int) $p['order_id'], $amount, 'eft', (int) $p['id'], ['admin_id' => $aid, 'notes' => 'POP verified']);
            audit('payment.approved', 'payment', (int) $p['id'], ['amount' => $amount]);
            json_ok([], 'Payment approved — receipt' . ((int) $p['division_id'] === division_id('feeds') ? ' and invoice' : '') . ' sent.');

        case 'reject':
            $p = db_one("SELECT * FROM payments WHERE id = ? AND status = 'awaiting_verification'", [(int) input('id')]);
            if (!$p || !in_array((int) $p['division_id'], admin_division_ids(), true)) {
                json_error('Payment not found or already handled.', 404);
            }
            $reason = mb_substr(trim((string) input('reason', '')) ?: 'We could not find this payment in our account.', 0, 250);
            db_exec("UPDATE payments SET status = 'rejected', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE id = ?", [$reason, $aid, $p['id']]);
            $o = db_one('SELECT o.*, c.first_name FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?', [$p['order_id']]);
            OrderService::history((int) $o['id'], $o['status'], $o['status'], 'Proof of payment rejected: ' . $reason, 'admin', $aid);
            NotificationService::email('pop_rejected', $o['billing_email'], $o['billing_name'], ['first_name' => $o['first_name'], 'order_no' => $o['order_no'], 'reason' => $reason],
                (int) $o['division_id'], [], ['label' => 'Upload again', 'url' => app_url('/account/order.php?no=' . rawurlencode($o['order_no']))]);
            audit('payment.rejected', 'payment', (int) $p['id'], ['reason' => $reason]);
            json_ok([], 'POP rejected — the customer has been told.');

        case 'record':
            $o = db_one('SELECT * FROM orders WHERE id = ?', [(int) input('order_id')]);
            if (!$o || !in_array((int) $o['division_id'], admin_division_ids(), true)) {
                json_error('Order not found.', 404);
            }
            $d = validate_or_fail(input_all(), ['amount' => 'required|money', 'method' => 'required|in:cash,card,eft', 'reference' => 'nullable|max:100']);
            if ($d['amount'] <= 0 || $d['amount'] > max(OrderService::amountDue($o), (int) $o['total_cents'])) {
                json_error('That amount does not look right for this order.', 422, ['amount' => 'Check the amount.']);
            }
            OrderService::recordPayment((int) $o['id'], $d['amount'], $d['method'], null, ['admin_id' => $aid, 'reference' => $d['reference'] ?: $o['order_no']]);
            audit('payment.recorded', 'order', (int) $o['id'], ['amount' => $d['amount'], 'method' => $d['method']]);
            json_ok([], money($d['amount']) . ' recorded — receipt emailed.');

        default:
            json_error('Unknown action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}
