<?php
/**
 * Admin documents API.
 *   GET ?id=   → PDF (original rendering)
 *   GET ?pop=  → proof-of-payment file
 *   GET ?vat=  → customer's VAT registration document
 *   POST {action: resend, id} → email the document to the customer again (marked COPY)
 */
declare(strict_types=1);

require __DIR__ . '/../../includes/bootstrap.php';

if (request_method() === 'POST') {
    define('GX_IS_API', true);
    api_post();
    require_admin_api('orders.manage'); // emails the customer, so more than view rights
    $doc = db_one('SELECT d.*, o.billing_email, o.billing_name, o.order_no FROM documents d JOIN orders o ON o.id = d.order_id WHERE d.id = ?', [(int) input('id')]);
    if (!$doc || !in_array((int) $doc['division_id'], admin_division_ids(), true)) {
        json_error('Document not found.', 404);
    }
    rate_limit_or_fail('doc_resend', 'd' . $doc['id'], 5, 3600);
    $pdf = DocumentService::pdf((int) $doc['id'], in_array($doc['doc_type'], ['tax_invoice', 'invoice', 'credit_note'], true));
    $rel = 'storage/private/documents/resend-' . random_token(6) . '-' . $pdf['filename'];
    file_put_contents(GX_ROOT . '/' . $rel, $pdf['bytes']);
    $title = DocumentService::TITLES[$doc['doc_type']] ?? 'Document';
    $html = NotificationService::layout($title . ' ' . $doc['doc_no'], '<p>Hi ' . e(explode(' ', $doc['billing_name'])[0]) . ',</p><p>As requested, please find your '
        . e(strtolower($title)) . ' for order <strong>' . e($doc['order_no']) . '</strong> attached.</p>', division((int) $doc['division_id']));
    $qid = MailService::queue($doc['billing_email'], $doc['billing_name'], $title . ' ' . $doc['doc_no'], $html, null, [['path' => $rel, 'name' => $pdf['filename']]], (int) $doc['division_id']);
    MailService::sendNow($qid);
    audit('document.resent', 'document', (int) $doc['id']);
    json_ok([], 'Emailed to ' . $doc['billing_email'] . '.');
}

require_admin('documents.view');
if (isset($_GET['pop'])) {
    require_admin('payments.verify');
    $p = db_one('SELECT * FROM payments WHERE id = ?', [query_int('pop')]);
    if (!$p || !$p['pop_path'] || !in_array((int) $p['division_id'], admin_division_ids(), true)) {
        http_response_code(404);
        exit('Not found.');
    }
    stream_private_file($p['pop_path'], 'POP-' . $p['reference'] . '.' . pathinfo($p['pop_path'], PATHINFO_EXTENSION));
}
if (isset($_GET['vat'])) {
    require_admin('customers.manage');
    $c = db_one('SELECT id, vat_farmer_doc_path FROM customers WHERE id = ?', [query_int('vat')]);
    if (!$c || !$c['vat_farmer_doc_path']) {
        http_response_code(404);
        exit('Not found.');
    }
    stream_private_file($c['vat_farmer_doc_path'], 'VAT-registration-' . $c['id'] . '.' . pathinfo($c['vat_farmer_doc_path'], PATHINFO_EXTENSION));
}
$doc = db_one('SELECT * FROM documents WHERE id = ?', [query_int('id')]);
if (!$doc || !in_array((int) $doc['division_id'], admin_division_ids(), true)) {
    http_response_code(404);
    exit('Document not found.');
}
try {
    $pdf = DocumentService::pdf((int) $doc['id'], false);
} catch (Throwable $e) {
    gx_log('error', 'Admin PDF failed', ['doc' => $doc['id'], 'error' => $e->getMessage()]);
    http_response_code(503);
    exit('PDF could not be generated: ' . (GX_SHOW_ERRORS ? e($e->getMessage()) : 'please check the server log.'));
}
PdfService::stream($pdf['bytes'], $pdf['filename']);
