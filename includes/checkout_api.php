<?php
/**
 * Shared checkout API. Included by /feeds/api/checkout.php and /ranch/api/checkout.php after setting $division.
 *   POST {action: quote, fulfilment, town, payment_method}  → live totals
 *   POST {action: place, ...form}                            → creates the order
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;
/** @var string $division */

api_post();
$customer = require_customer_api();
$action = (string) input('action', 'quote');
$all = input_all();

if ($action === 'quote') {
    rate_limit_or_fail('checkout_quote', client_ip(), 120, 60);
    $town = (string) ($all['town'] ?? '');
    if (($all['address_id'] ?? 'new') !== 'new' && ctype_digit((string) ($all['address_id'] ?? ''))) {
        $town = (string) db_val('SELECT town FROM customer_addresses WHERE id = ? AND customer_id = ?', [(int) $all['address_id'], $customer['id']]);
    }
    $q = OrderService::quote($division, ['fulfilment' => $all['fulfilment'] ?? 'collection', 'town' => $town, 'payment_method' => $all['payment_method'] ?? ''], $customer);
    json_ok([
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
        if (!empty($all['save_address'])) {
            db_exec('INSERT INTO customer_addresses (customer_id, recipient_name, phone, farm_name, line1, town, postal_code, directions, gps_lat, gps_lng, is_default)
                     VALUES (?,?,?,?,?,?,?,?,?,?, NOT EXISTS (SELECT 1 FROM customer_addresses x WHERE x.customer_id = ?))', [
                $customer['id'], $addr['recipient_name'], $addr['phone'], $addr['farm_name'], $addr['line1'], $addr['town'], $addr['postal_code'],
                $addr['directions'], $addr['gps_lat'], $addr['gps_lng'], $customer['id'],
            ]);
        }
    }
}

// VAT invoices over R5 000 need the buyer's address
$q = OrderService::quote($division, $data, $customer);
if (PricingService::divisionVatBp(division_id($division)) > 0 && $q['total'] > 500000 && empty($data['billing_address'])) {
    json_error(t('error.validation'), 422, ['billing_address' => 'Your billing address is required for tax invoices over R5 000.']);
}

try {
    $order = OrderService::place($division, $data, $customer);
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}

// Remember useful details on the profile
db_exec('UPDATE customers SET phone = COALESCE(NULLIF(phone, \'\'), ?), farm_name = COALESCE(NULLIF(farm_name, \'\'), ?), vat_no = COALESCE(NULLIF(vat_no, \'\'), ?) WHERE id = ?',
    [$data['billing_phone'], $data['billing_company'], $data['billing_vat_no'], $customer['id']]);
if (!empty($data['marketing_opt_in'])) {
    db_exec('UPDATE customers SET marketing_opt_in = 1, marketing_opt_in_at = NOW() WHERE id = ?', [$customer['id']]);
}

$redirect = $order['payment_method'] === 'yoco' && OrderService::amountDue($order) > 0
    ? '/payments/pay.php?t=' . $order['pay_token']
    : '/account/order.php?no=' . rawurlencode($order['order_no']) . '&placed=1';
json_ok(['order_no' => $order['order_no'], 'redirect' => $redirect], 'Order ' . $order['order_no'] . ' placed!');
