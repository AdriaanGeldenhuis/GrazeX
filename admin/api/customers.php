<?php
/** Admin customers API: notes · block · unblock · vat_verify · vat_reject · anonymise */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$admin = require_admin_api('customers.manage');
$c = db_one('SELECT * FROM customers WHERE id = ?', [(int) input('id')]);
if (!$c) {
    json_error('Customer not found.', 404);
}
$cid = (int) $c['id'];
switch ((string) input('action')) {
    case 'notes':
        db_exec('UPDATE customers SET admin_notes = ? WHERE id = ?', [mb_substr((string) input('admin_notes', ''), 0, 5000), $cid]);
        json_ok([], 'Notes saved.');
    case 'block':
        db_exec("UPDATE customers SET status = 'blocked', session_version = session_version + 1 WHERE id = ?", [$cid]);
        audit('customer.blocked', 'customer', $cid);
        json_ok([], 'Customer blocked.');
    case 'unblock':
        db_exec("UPDATE customers SET status = 'active' WHERE id = ?", [$cid]);
        audit('customer.unblocked', 'customer', $cid);
        json_ok([], 'Customer unblocked.');
    case 'vat_verify':
    case 'vat_reject':
        $ok = input('action') === 'vat_verify';
        db_exec('UPDATE customers SET vat_farmer_status = ?, vat_farmer_verified_at = IF(?, NOW(), NULL) WHERE id = ?', [$ok ? 'verified' : 'rejected', $ok ? 1 : 0, $cid]);
        audit('customer.vat_' . ($ok ? 'verified' : 'rejected'), 'customer', $cid);
        json_ok([], $ok ? 'Verified — zero-rated feed now applies at checkout.' : 'Rejected.');
    case 'anonymise':
        db_tx(static function () use ($cid): void {
            $anon = 'deleted-' . $cid . '@deleted.invalid';
            db_exec("UPDATE customers SET email = ?, password_hash = '!', first_name = 'Deleted', last_name = 'Customer', phone = NULL, farm_name = NULL,
                     company_name = NULL, vat_no = NULL, vat_farmer_doc_path = NULL, marketing_opt_in = 0, status = 'blocked', admin_notes = NULL,
                     session_version = session_version + 1 WHERE id = ?", [$anon, $cid]);
            db_exec('DELETE FROM customer_addresses WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM livestock_alerts WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM carts WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM wishlists WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM customer_tokens WHERE customer_id = ?', [$cid]);
        });
        audit('customer.anonymised', 'customer', $cid);
        json_ok(['redirect' => '/admin/customers.php'], 'Customer anonymised.');
    default:
        json_error('Unknown action.', 400);
}
