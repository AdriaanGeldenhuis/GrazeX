<?php
/**
 * Shared checkout API. Included by /feeds/api/checkout.php and /ranch/api/checkout.php after setting $division.
 *   POST {action: quote, fulfilment, town, payment_method}  → live totals
 *   POST {action: place, ...form}                            → creates the order
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;
/** @var string $division */

/** Changes the cart made on its own (stock capped, item removed, coupon dropped) — the customer must see them. */
function checkout_notices(array $q): array
{
    return CartService::changes($q);
}

api_post();
$customer = require_customer_api();
$action = (string) input('action', 'quote');
$all = input_all();

if ($action === 'quote') {
    rate_limit_or_fail('checkout_quote', rate_limit_ip(), 120, 60);
    $town = (string) ($all['town'] ?? '');
    if (($all['address_id'] ?? 'new') !== 'new' && ctype_digit((string) ($all['address_id'] ?? ''))) {
        $town = (string) db_val('SELECT town FROM customer_addresses WHERE id = ? AND customer_id = ?', [(int) $all['address_id'], $customer['id']]);
    }
    $q = OrderService::quote($division, ['fulfilment' => $all['fulfilment'] ?? 'collection', 'town' => $town, 'payment_method' => $all['payment_method'] ?? ''], $customer);
    json_ok([
        'notices' => checkout_notices($q),
        'lines' => array_map(static fn($l) => ['qty' => (int) $l['qty'], 'name' => $l['name'], 'variant' => $l['variant'], 'total' => (int) $l['total']], $q['lines']),
        'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'delivery' => $q['delivery'], 'total' => $q['total'], 'vat' => $q['vat'],
        'deposit' => $q['deposit'], 'due_now' => $q['due_now'], 'balance' => $q['balance'], 'errors' => $q['errors'],
        'zone' => $q['zone'], 'methods' => OrderService::allowedPaymentMethods($division, $q['fulfilment'], $q['deposit']),
        'delivery_dates' => $q['zone'] ? array_map(static fn($d) => ['value' => $d, 'label' => fmt_date($d, 'l j F')], $q['zone']['next_dates']) : [],
    ]);
}

if ($action !== 'place') {
    json_error('Unknown action.', 400);
}

rate_limit_or_fail('checkout_place', 'c' . $customer['id'], 10, 600);
$isRanch = $division === 'ranch';
$rules = [
    'billing_name'    => 'required|max:150',
    'billing_phone'   => 'required|phone',
    'billing_company' => 'nullable|max:150',
    'billing_vat_no'  => 'nullable|regex:/^4\d{9}$/',
    'billing_address' => ($isRanch ? 'required' : 'nullable') . '|max:500',
    'fulfilment'      => 'required|in:collection,delivery',
    'payment_method'  => 'required|in:yoco,eft,cash_on_collection',
    'customer_note'   => 'nullable|max:1000',
    'accept_terms'    => 'required|bool',
    'marketing_opt_in' => 'nullable|bool',
];
if ($isRanch) {
    $rules += ['buyer_id_number' => 'required|max:20|regex:/^[A-Za-z0-9\/\-\s]{6,20}$/', 'buyer_brand_mark' => 'nullable|max:50', 'transport_note' => 'nullable|max:500'];
}
$fulfilment = $isRanch ? 'collection' : (($all['fulfilment'] ?? '') === 'delivery' ? 'delivery' : 'collection');
if ($fulfilment === 'collection') {
    $rules += ['collection_date' => 'required|date', 'collection_slot_id' => 'required|int|min:1'];
} else {
    $rules += ['delivery_date' => 'required|date'];
}
[$data, $errors] = validate($all, $rules);
if (empty($data['accept_terms'])) {
    $errors['accept_terms'] = 'Please accept the terms to continue.';
}
if ($errors) {
    json_error(t('error.validation'), 422, $errors);
}
$data['fulfilment'] = $fulfilment;

// Delivery address: saved one (must belong to this customer) or a new one
if ($fulfilment === 'delivery') {
    $addressId = (string) ($all['address_id'] ?? 'new');
    if ($addressId !== 'new') {
        $a = db_one('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [(int) $addressId, $customer['id']]);
        if (!$a) {
            json_error('Please choose a delivery address.', 422, ['address_id' => 'Please choose a delivery address.']);
        }
        $data['address'] = $a;
        $data['town'] = $a['town'];
    } else {
        $addrIn = is_array($all['address'] ?? null) ? $all['address'] : [];
        [$addr, $aErr] = validate($addrIn + ['town' => $all['town'] ?? ''], [
            'recipient_name' => 'required|max:150', 'phone' => 'required|phone', 'farm_name' => 'nullable|max:150',
            'line1' => 'required|max:191', 'postal_code' => 'nullable|max:10', 'directions' => 'nullable|max:1000',
            'gps_lat' => 'nullable|numeric|min:-90|max:90', 'gps_lng' => 'nullable|numeric|min:-180|max:180', 'town' => 'required|max:120',
        ]);
        if ($aErr) {
            $mapped = [];
            foreach ($aErr as $k => $v) {
                $mapped[$k === 'town' ? 'town' : "address[{$k}]"] = $v;
            }
            json_error(t('error.validation'), 422, $mapped);
        }
        $data['address'] = $addr;
        $data['town'] = $addr['town'];
        $saveAddress = !empty($all['save_address']) ? $addr : null; // saved once the order is placed
    }
}

// VAT invoices over R5 000 need the buyer's address
$q = OrderService::quote($division, $data, $customer);
// The cart changed on its own (less stock, item gone, coupon dropped): show it before taking the order
if ($changed = checkout_notices($q)) {
    json_error(implode(' ', $changed) . ' Please check your order and place it again.', 409, ['notices' => implode(' ', $changed)]);
}
// The page sends the total it showed; a different total now (price, special, coupon, stock) must be seen first
if (isset($all['expected_total']) && is_numeric($all['expected_total']) && (int) $all['expected_total'] !== (int) $q['total']) {
    $msg = 'Your total is now ' . money((int) $q['total']) . ' (it was ' . money((int) $all['expected_total']) . '). Please check your order and place it again.';
    json_error($msg, 409, ['notices' => $msg]);
}
if (PricingService::divisionVatBp(division_id($division)) > 0 && $q['total'] > 500000 && empty($data['billing_address'])) {
    json_error(t('error.validation'), 422, ['billing_address' => 'Your billing address is required for tax invoices over R5 000.']);
}

try {
    $order = OrderService::place($division, $data, $customer);
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}

// Remember useful details on the profile (the billing company is the company name, not the farm name)
db_exec('UPDATE customers SET phone = COALESCE(NULLIF(phone, \'\'), ?), company_name = COALESCE(NULLIF(company_name, \'\'), ?), vat_no = COALESCE(NULLIF(vat_no, \'\'), ?) WHERE id = ?',
    [$data['billing_phone'], $data['billing_company'], $data['billing_vat_no'], $customer['id']]);
// "Save this address": only now that the order exists, never twice, at most 20 per customer
if (!empty($saveAddress)) {
    try {
        $dup = db_val('SELECT id FROM customer_addresses WHERE customer_id = ? AND line1 = ? AND town = ?', [$customer['id'], $saveAddress['line1'], $saveAddress['town']]);
        $count = (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$customer['id']]);
        if ($dup) {
            // Same address again: keep it current (new directions / GPS pin / contact), never blank out what was there
            db_exec('UPDATE customer_addresses SET recipient_name = COALESCE(NULLIF(?, \'\'), recipient_name), phone = COALESCE(NULLIF(?, \'\'), phone),
                     farm_name = COALESCE(NULLIF(?, \'\'), farm_name), postal_code = COALESCE(NULLIF(?, \'\'), postal_code),
                     directions = COALESCE(NULLIF(?, \'\'), directions), gps_lat = COALESCE(?, gps_lat), gps_lng = COALESCE(?, gps_lng) WHERE id = ?', [
                $saveAddress['recipient_name'], $saveAddress['phone'], $saveAddress['farm_name'], $saveAddress['postal_code'], $saveAddress['directions'],
                $saveAddress['gps_lat'], $saveAddress['gps_lng'], $dup,
            ]);
        } elseif ($count < 20) {
            db_insert('INSERT INTO customer_addresses (customer_id, recipient_name, phone, farm_name, line1, town, postal_code, directions, gps_lat, gps_lng, is_default)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
                $customer['id'], $saveAddress['recipient_name'], $saveAddress['phone'], $saveAddress['farm_name'], $saveAddress['line1'], $saveAddress['town'],
                $saveAddress['postal_code'], $saveAddress['directions'], $saveAddress['gps_lat'], $saveAddress['gps_lng'], $count === 0 ? 1 : 0,
            ]);
        }
    } catch (Throwable $e) {
        gx_log('warning', 'Saving the delivery address failed', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
    }
}
if (!empty($data['marketing_opt_in'])) {
    // Keep the date of the first opt-in (SET runs left to right: date first)
    db_exec('UPDATE customers SET marketing_opt_in_at = IF(marketing_opt_in = 1 AND marketing_opt_in_at IS NOT NULL, marketing_opt_in_at, NOW()), marketing_opt_in = 1 WHERE id = ?', [$customer['id']]);
}

$redirect = $order['payment_method'] === 'yoco' && OrderService::amountDue($order) > 0
    ? '/payments/pay.php?t=' . $order['pay_token']
    : '/account/order.php?no=' . rawurlencode($order['order_no']) . '&placed=1';
json_ok(['order_no' => $order['order_no'], 'redirect' => $redirect], 'Order ' . $order['order_no'] . ' placed!');
