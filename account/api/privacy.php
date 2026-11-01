<?php
/**
 * POST /account/api/privacy.php {action: export} | {action: delete_request}  — POPIA data subject requests.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
$cid = (int) $customer['id'];
rate_limit_or_fail('privacy', 'c' . $cid, 5, 3600);

switch ((string) input('action')) {
    case 'export':
        $data = [
            'exported_at' => now(),
            'profile' => db_one('SELECT email, first_name, last_name, phone, farm_name, company_name, vat_no, vat_farmer_status, email_verified_at,
                                 marketing_opt_in, marketing_opt_in_at, created_at, last_login_at FROM customers WHERE id = ?', [$cid]),
            'addresses' => db_all('SELECT label, recipient_name, phone, farm_name, line1, line2, town, province, postal_code, directions, gps_lat, gps_lng FROM customer_addresses WHERE customer_id = ?', [$cid]),
            'orders' => db_all('SELECT order_no, status, payment_method, fulfilment, total_cents, placed_at, billing_name, billing_email, billing_phone,
                                billing_company, billing_vat_no, billing_address, buyer_id_number, ship_town FROM orders WHERE customer_id = ? ORDER BY placed_at', [$cid]),
            'livestock_alerts' => db_all('SELECT email, breed_id, category_id, is_active, created_at FROM livestock_alerts WHERE customer_id = ? OR email = ?', [$cid, $customer['email']]),
            'enquiries' => db_all('SELECT type, message, created_at FROM enquiries WHERE customer_id = ? OR email = ?', [$cid, $customer['email']]),
        ];
        audit('customer.data_export', 'customer', $cid);
        json_ok(['download' => $data, 'filename' => 'grazex-my-data-' . date('Y-m-d') . '.json'], 'Your data is downloading.');

    case 'delete_request':
        db_exec('UPDATE customers SET deletion_requested_at = NOW() WHERE id = ?', [$cid]);
        NotificationService::admin('privacy', 'Account deletion request', $customer['first_name'] . ' ' . $customer['last_name'] . ' (' . $customer['email'] . ') asked for their account to be deleted.',
            '/admin/customer.php?id=' . $cid);
        audit('customer.deletion_requested', 'customer', $cid);
        json_ok([], 'Request received. We will confirm by email once your account is removed.');

    default:
        json_error('Unknown action.', 400);
}
