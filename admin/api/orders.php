<?php
/**
 * Admin orders API.
 *   {action: transition, id, to} · {action: cancel, id, reason} · {action: finalise, id, weights{itemId: kg}}
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
            OrderService::transition((int) $o['id'], $to, null, $aid);
            json_ok([], 'Order marked "' . OrderService::label($to)[0] . '".');
        case 'cancel':
            $reason = trim((string) input('reason', '')) ?: 'Cancelled by GrazeX';
            OrderService::cancel((int) $o['id'], mb_substr($reason, 0, 250), $aid);
            json_ok([], 'Order cancelled and stock returned.');
        case 'finalise':
            $weights = [];
            foreach ((array) input('weights', []) as $itemId => $kg) {
                if (!is_numeric($kg) || (float) $kg <= 0 || (float) $kg > 100000) {
                    json_error('Enter valid weights.', 422);
                }
                $weights[(int) $itemId] = round((float) $kg, 2);
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
