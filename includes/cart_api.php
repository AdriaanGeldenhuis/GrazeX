<?php
/**
 * Shared cart API handler. Included by /feeds/api/cart.php and /ranch/api/cart.php
 * after they set $division ('feeds' | 'ranch').
 *
 * GET                       → current cart
 * POST {action: add, target_id, qty}
 * POST {action: update, item_id, qty}
 * POST {action: remove, item_id}
 * POST {action: coupon, code} | {action: remove_coupon}
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;
/** @var string $division */

$respond = static function (string $division, string $message = ''): never {
    $summary = CartService::summary($division);
    json_ok([
        'count' => CartService::count($division),
        'subtotal' => $summary['subtotal'],
        'total' => $summary['subtotal'] - $summary['discount'],
        'lines' => count($summary['lines']),
        'body' => partial('cart-lines', ['summary' => $summary, 'div' => $division, 'compact' => (bool) input('compact', true)]),
        'foot' => partial('cart-foot', ['summary' => $summary, 'div' => $division]),
    ], $message);
};

if (request_method() === 'GET') {
    $respond($division);
}

api_post();
rate_limit_or_fail('cart', client_ip(), 120, 60);

$action = (string) input('action', '');
try {
    switch ($action) {
        case 'add':
            $data = validate_or_fail(input_all(), ['target_id' => 'required|int|min:1', 'qty' => 'required|int|min:1|max:9999']);
            $msg = CartService::add($division, $data['target_id'], $data['qty']);
            $respond($division, $msg);
        case 'update':
            $data = validate_or_fail(input_all(), ['item_id' => 'required|int|min:1', 'qty' => 'required|int|min:0|max:9999']);
            CartService::updateQty($division, $data['item_id'], $data['qty']);
            $respond($division);
        case 'remove':
            $data = validate_or_fail(input_all(), ['item_id' => 'required|int|min:1']);
            CartService::remove($division, $data['item_id']);
            $respond($division, 'Removed from your cart.');
        case 'coupon':
            rate_limit_or_fail('coupon', client_ip(), 15, 600);
            $data = validate_or_fail(input_all(), ['code' => 'required|max:40']);
            $summary = CartService::summary($division);
            $check = PricingService::checkCoupon($data['code'], division_id($division), $summary['subtotal'], customer_id());
            if (!$check['ok']) {
                json_error($check['error'], 422, ['code' => $check['error']]);
            }
            CartService::setCoupon($division, (int) $check['coupon']['id']);
            $respond($division, 'Coupon applied.');
        case 'remove_coupon':
            CartService::setCoupon($division, null);
            $respond($division, 'Coupon removed.');
        default:
            json_error('Unknown cart action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}
