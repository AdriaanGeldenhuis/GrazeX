<?php
/**
 * POST /account/api/orders.php
 *   {action: cancel, order_no}   — cancel an unpaid order (stock released)
 *   {action: reorder, order_no}  — put a previous Feeds order back in the cart
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
rate_limit_or_fail('account_orders', 'c' . $customer['id'], 30, 300);
$o = db_one('SELECT * FROM orders WHERE order_no = ? AND customer_id = ?', [(string) input('order_no', ''), $customer['id']]);
if (!$o) {
    json_error('Order not found.', 404);
}

switch ((string) input('action')) {
    case 'cancel':
        $awaiting = db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'awaiting_verification'", [$o['id']]);
        if (!in_array($o['status'], ['pending_payment', 'reserved', 'awaiting_collection'], true) || $o['payment_status'] !== 'unpaid' || $awaiting) {
            json_error('This order can no longer be cancelled online — please contact us.', 409);
        }
        try {
            OrderService::cancel((int) $o['id'], 'Cancelled by customer');
        } catch (DomainException $e) {
            json_error($e->getMessage(), 409);
        }
        json_ok([], 'Order ' . $o['order_no'] . ' cancelled.');

    case 'reorder':
        if ((int) $o['division_id'] !== division_id('feeds')) {
            json_error('Livestock lots are unique — browse what is available now.', 409);
        }
        $added = 0;
        $missing = [];
        foreach (db_all('SELECT variant_id, qty, name FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL', [$o['id']]) as $it) {
            try {
                CartService::add('feeds', (int) $it['variant_id'], (int) $it['qty']);
                $added++;
            } catch (DomainException $e) {
                $missing[] = $it['name'];
            }
        }
        if (!$added) {
            json_error('None of those products are in stock right now.', 409);
        }
        json_ok(['redirect' => '/feeds/cart.php'], $missing ? 'Added to cart. Not available: ' . implode(', ', $missing) . '.' : 'Added to your cart — ready to check out.');

    default:
        json_error('Unknown action.', 400);
}
