<?php
/**
 * POST /account/api/profile.php
 *   {action: details, first_name, last_name, phone, farm_name, company_name, vat_no, marketing_opt_in}
 *   {action: password, current_password, password, password_confirm}
 *   {action: logout_others}
 *   multipart {action: vat_doc, vat_doc} — VAT-registered farmer certificate for verification
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
$cid = (int) $customer['id'];

switch ((string) input('action')) {
    case 'details':
        $d = validate_or_fail(input_all(), [
            'first_name' => 'required|max:100', 'last_name' => 'required|max:100', 'phone' => 'required|phone',
            'farm_name' => 'nullable|max:150', 'company_name' => 'nullable|max:150', 'vat_no' => 'nullable|regex:/^4\d{9}$/', 'marketing_opt_in' => 'nullable|bool',
        ]);
        db_exec('UPDATE customers SET first_name = ?, last_name = ?, phone = ?, farm_name = ?, company_name = ?, vat_no = ?,
                 marketing_opt_in = ?, marketing_opt_in_at = IF(? = 1 AND marketing_opt_in = 0, NOW(), marketing_opt_in_at) WHERE id = ?',
            [$d['first_name'], $d['last_name'], $d['phone'], $d['farm_name'], $d['company_name'], $d['vat_no'],
             $d['marketing_opt_in'] ? 1 : 0, $d['marketing_opt_in'] ? 1 : 0, $cid]);
        audit('customer.profile_updated', 'customer', $cid);
        json_ok([], 'Your details are saved.');

    case 'password':
        rate_limit_or_fail('change_password', 'c' . $cid, 6, 900);
        $d = validate_or_fail(input_all(), ['current_password' => 'required|max:200', 'password' => 'required|password', 'password_confirm' => 'required|same:password']);
        $hash = (string) db_val('SELECT password_hash FROM customers WHERE id = ?', [$cid]);
        if (!password_verify($d['current_password'], $hash)) {
            json_error(t('error.validation'), 422, ['current_password' => 'Your current password is not correct.']);
        }
        db_exec('UPDATE customers SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [customer_password_hash($d['password']), $cid]);
        login_customer(db_one('SELECT * FROM customers WHERE id = ?', [$cid]));
        audit('customer.password_changed', 'customer', $cid);
        json_ok([], 'Password changed. Other devices have been logged out.');

    case 'logout_others':
        db_exec('UPDATE customers SET session_version = session_version + 1 WHERE id = ?', [$cid]);
        login_customer(db_one('SELECT * FROM customers WHERE id = ?', [$cid]));
        json_ok([], 'You are logged out everywhere else.');

    case 'vat_doc':
        if (!setting_bool('zero_rating_enabled')) {
            json_error('This option is not available.', 409);
        }
        rate_limit_or_fail('vat_doc', 'c' . $cid, 5, 3600);
        try {
            $file = store_private_upload($_FILES['vat_doc'] ?? null, 'vat');
        } catch (DomainException $e) {
            json_error($e->getMessage(), 422, ['vat_doc' => $e->getMessage()]);
        }
        db_exec("UPDATE customers SET vat_farmer_status = 'pending', vat_farmer_doc_path = ? WHERE id = ?", [$file['path'], $cid]);
        NotificationService::admin('vat_farmer', 'VAT farmer verification: ' . $customer['first_name'] . ' ' . $customer['last_name'],
            'A customer uploaded a SARS Notice of Registration for zero-rated feed.', '/admin/customer.php?id=' . $cid, division_id('feeds'));
        json_ok([], 'Uploaded — we will verify it and let you know.');

    default:
        json_error('Unknown action.', 400);
}
