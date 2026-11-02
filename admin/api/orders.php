<?php
/**
 * Admin orders API.
 *   {action: transition, id, to} · {action: cancel, id, reason} · {action: finalise, id, weights{itemId: kg}}
 *   {action: refund, id, reason, restock} — completed orders only; needs the payments.refund permission
 *   {action: mark_refunded, id, note} — money on a cancelled/expired order was paid back; needs payments.refund
 *   {action: note, id, admin_note} · {action: document, id, type}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$admin = require_admin_api('orders.manage');
$o = db_one('SELECT * FROM orders WHERE id = ?', [(int) input('id')]);
if (!$o || !in_array((int) $o['division_id'], admin_division_ids(), true)) {
    json_error('Order not found.', 404);
}
$aid = (int) $admin['id'];

try {
    switch ((string) input('action')) {
        case 'transition':
            $to = (string) input('to');
            if ($to === 'refunded' && !admin_can('payments.refund')) {
                json_error('You do not have permission to refund orders.', 403);
            }
            OrderService::transition((int) $o['id'], $to, null, $aid);
            json_ok([], 'Order marked "' . OrderService::label($to)[0] . '".');
        case 'cancel':
            $reason = trim((string) input('reason', '')) ?: 'Cancelled by GrazeX';
            OrderService::cancel((int) $o['id'], mb_substr($reason, 0, 250), $aid);
            json_ok([], 'Order cancelled and stock returned.');
        case 'refund':
            if (!admin_can('payments.refund')) {
                json_error('You do not have permission to refund orders.', 403);
            }
            $reason = trim((string) input('reason', ''));
            if ($reason === '') {
                json_error(t('error.validation'), 422, ['reason' => 'Give a reason — it appears on the credit note.']);
            }
            OrderService::refund((int) $o['id'], mb_substr($reason, 0, 250), $aid, !empty(input('restock')));
            json_ok([], 'Order refunded — credit note issued. Pay the money back by EFT or in the Yoco portal.');
        case 'mark_refunded':
            if (!admin_can('payments.refund')) {
                json_error('You do not have permission to refund orders.', 403);
            }
            $note = trim((string) input('note', '')) ?: 'Refunded';
            OrderService::markMoneyRefunded((int) $o['id'], mb_substr($note, 0, 250), $aid);
            json_ok([], 'Marked as refunded.');
        case 'finalise':
            $weights = [];
            foreach ((array) input('weights', []) as $itemId => $kg) {
                if (!is_numeric($kg) || (float) $kg <= 0 || (float) $kg > 100000) {
                    json_error('Enter valid weights.', 422);
                }
                $weights[(int) $itemId] = round((float) $kg, 2);
            }
            // Every per-kg line must be weighed, and only lines of this order count
            $perKg = array_map('intval', db_col("SELECT id FROM order_items WHERE order_id = ? AND pricing_mode = 'per_kg'", [$o['id']]));
            if (array_diff($perKg, array_keys($weights)) || array_diff(array_keys($weights), $perKg)) {
                json_error('Enter the actual weight for every livestock line, then try again (reload the page if this keeps happening).', 422);
            }
            OrderService::finalise((int) $o['id'], $weights, $aid);
            json_ok([], 'Finalised — invoice emailed to the customer.');
        case 'note':
            db_exec('UPDATE orders SET admin_note = ? WHERE id = ?', [mb_substr((string) input('admin_note', ''), 0, 5000), $o['id']]);
            json_ok([], 'Note saved.');
        case 'document':
            $type = (string) input('type');
            if (!in_array($type, ['pick_list', 'delivery_note', 'collection_slip', 'tax_invoice'], true)) {
                json_error('Unknown document.', 400);
            }
            if ($type === 'tax_invoice' && $o['payment_status'] === 'unpaid') {
                json_error('Invoices are issued once payment is received.', 409);
            }
            $id = DocumentService::issue((int) $o['id'], $type, ['admin_id' => $aid]);
            json_ok(['redirect' => '/admin/api/documents.php?id=' . $id], 'Document ready.');
        default:
            json_error('Unknown action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}
