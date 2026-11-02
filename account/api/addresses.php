<?php
/**
 * POST /account/api/addresses.php
 *   {action: create, label, recipient_name, phone, farm_name, line1, line2, town, province, postal_code, directions}
 *   {action: default, id} | {action: delete, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
$cid = (int) $customer['id'];

switch ((string) input('action')) {
    case 'create':
        if ((int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$cid]) >= 20) {
            json_error('You can save up to 20 addresses.', 409);
        }
        $d = validate_or_fail(input_all(), [
            'label' => 'nullable|max:60', 'recipient_name' => 'required|max:150', 'phone' => 'required|phone', 'farm_name' => 'nullable|max:150',
            'line1' => 'required|max:191', 'line2' => 'nullable|max:191', 'town' => 'required|max:120',
            'province' => 'nullable|in:' . implode(',', sa_provinces()), 'postal_code' => 'nullable|regex:/^[0-9]{4}$/', 'directions' => 'nullable|max:1000',
        ]);
        $isFirst = !db_val('SELECT 1 FROM customer_addresses WHERE customer_id = ?', [$cid]);
        db_exec('INSERT INTO customer_addresses (customer_id, label, recipient_name, phone, farm_name, line1, line2, town, province, postal_code, directions, is_default)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$cid, $d['label'], $d['recipient_name'], $d['phone'], $d['farm_name'], $d['line1'], $d['line2'], $d['town'], $d['province'] ?: null,
             $d['postal_code'], $d['directions'], $isFirst ? 1 : 0]);
        json_ok([], 'Address saved.');
    case 'default':
        $id = (int) input('id');
        if (!db_val('SELECT 1 FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $cid])) {
            json_error('Address not found.', 404);
        }
        db_exec('UPDATE customer_addresses SET is_default = (id = ?) WHERE customer_id = ?', [$id, $cid]);
        json_ok([], 'Default address updated.');
    case 'delete':
        db_exec('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?', [(int) input('id'), $cid]);
        json_ok([], 'Address deleted.');
    default:
        json_error('Unknown action.', 400);
}
