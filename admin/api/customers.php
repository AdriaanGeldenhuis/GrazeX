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
        if (str_ends_with((string) $c['email'], '@deleted.invalid')) {
            json_error('This customer is already anonymised.', 409);
        }
        $open = (int) db_val("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status NOT IN ('completed','collected','cancelled','expired','refunded')", [$cid]);
        if ($open) {
            json_error("This customer still has {$open} open order" . ($open > 1 ? 's' : '') . '. Complete or cancel ' . ($open > 1 ? 'them' : 'it') . ' first, then anonymise.', 409);
        }
        $oldEmail = (string) $c['email'];
        // Records made without logging in (alerts, enquiries) count as theirs only if they proved the address
        $byEmail = customer_verified_email($c);
        $files = db_tx(static function () use ($cid, $byEmail): array {
            $files = array_filter([db_val('SELECT vat_farmer_doc_path FROM customers WHERE id = ? FOR UPDATE', [$cid])]);
            // Saved PDFs still hold the old details: removed, and rebuilt from the cleaned snapshot when opened again
            $files = array_merge($files, db_col('SELECT pdf_path FROM documents WHERE customer_id = ? AND pdf_path IS NOT NULL', [$cid]));
            $anon = 'deleted-' . $cid . '@deleted.invalid';
            db_exec("UPDATE customers SET email = ?, password_hash = '!', first_name = 'Deleted', last_name = 'Customer', phone = NULL, farm_name = NULL,
                     company_name = NULL, vat_no = NULL, vat_farmer_status = 'none', vat_farmer_doc_path = NULL, vat_farmer_verified_at = NULL,
                     marketing_opt_in = 0, marketing_opt_in_at = NULL, status = 'blocked', admin_notes = NULL, last_login_at = NULL,
                     session_version = session_version + 1 WHERE id = ?", [$anon, $cid]);
            // Orders stay for the 5-year tax record. A tax invoice needs the buyer's name, company, VAT number and billing
            // address; everything else personal goes (contact details, delivery address, notes, ID number).
            db_exec('UPDATE orders SET billing_email = ?, billing_phone = NULL, ship_name = NULL, ship_phone = NULL, ship_farm_name = NULL,
                     ship_line1 = NULL, ship_line2 = NULL, ship_province = NULL, ship_postal_code = NULL, ship_directions = NULL,
                     ship_gps_lat = NULL, ship_gps_lng = NULL, customer_note = NULL, transport_note = NULL, buyer_id_number = NULL
                     WHERE customer_id = ?', [$anon, $cid]);
            // The documents' own copies of those details (an issued document keeps its numbers, lines and the invoice details).
            // The document of identification keeps the buyer's ID number: it is the Stock Theft Act record of who received the animals.
            db_exec("UPDATE documents SET snapshot = JSON_REMOVE(snapshot, '$.buyer.email', '$.buyer.phone', '$.buyer.ship', '$.order.customer_note', '$.order.transport_note'),
                     pdf_path = NULL WHERE customer_id = ? AND snapshot IS NOT NULL", [$cid]);
            db_exec("UPDATE documents SET snapshot = JSON_REMOVE(snapshot, '$.buyer.id_number') WHERE customer_id = ? AND doc_type <> 'doc_identification' AND snapshot IS NOT NULL", [$cid]);
            db_exec('DELETE FROM customer_addresses WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM livestock_alerts WHERE customer_id = ? OR email = ?', [$cid, $byEmail]);
            db_exec('DELETE FROM enquiries WHERE customer_id = ? OR email = ?', [$cid, $byEmail]);
            db_exec('DELETE FROM carts WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM wishlists WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM customer_tokens WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM push_devices WHERE customer_id = ?', [$cid]);
            db_exec('DELETE FROM admin_notifications WHERE link = ?', ['/admin/customer.php?id=' . $cid]);
            return $files;
        });
        // Uploaded VAT certificate and saved PDFs (private storage)
        $root = realpath(GX_ROOT . '/storage/private');
        foreach ($files as $rel) {
            $abs = realpath(GX_ROOT . '/' . ltrim((string) $rel, '/'));
            if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs)) {
                @unlink($abs);
            }
        }
        // Our copies of emails to the old address (one being sent this second is left to finish)
        db_exec("DELETE FROM email_queue WHERE to_email = ? AND status <> 'sending'", [$oldEmail]);
        // Confirm to the person, at the address they used (the request said we would)
        NotificationService::email('account_deleted', $oldEmail, null, ['first_name' => (string) $c['first_name']]);
        audit('customer.anonymised', 'customer', $cid);
        json_ok(['redirect' => '/admin/customers.php'], 'Customer anonymised — a confirmation email was sent to them.');
    default:
        json_error('Unknown action.', 400);
}
