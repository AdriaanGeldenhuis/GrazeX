<?php
/**
 * GET /account/api/document.php?id= — stream one of the customer's own documents as PDF.
 *     &dl=1 — as a download (the GrazeX app saves it to the phone's Downloads folder)
 * Tax invoices and credit notes downloaded here are marked "COPY" (the emailed one is the original).
 */
declare(strict_types=1);

require __DIR__ . '/../../includes/bootstrap.php';

$customer = require_customer();
$doc = db_one("SELECT * FROM documents WHERE id = ? AND customer_id = ? AND is_void = 0
               AND doc_type IN ('order_confirmation','tax_invoice','invoice','receipt','credit_note','doc_identification','collection_slip','delivery_note')",
    [query_int('id'), $customer['id']]);
if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}
if (!rate_limit_hit('doc_download', 'c' . $customer['id'], 60, 600)) {
    http_response_code(429);
    exit(t('error.rate_limited'));
}
$copy = in_array($doc['doc_type'], ['tax_invoice', 'invoice', 'credit_note'], true);
// Counted at most once per 2 minutes per document (opening it again, or the app saving it, is the same print)
if (rate_limit_hit('doc_print', 'd' . $doc['id'], 1, 120)) {
    db_exec('UPDATE documents SET print_count = print_count + 1 WHERE id = ?', [$doc['id']]);
}
try {
    $pdf = DocumentService::pdf((int) $doc['id'], $copy);
} catch (Throwable $e) {
    gx_log('error', 'PDF render failed', ['doc' => $doc['id'], 'error' => $e->getMessage()]);
    http_response_code(503);
    exit('This document is being prepared — please try again in a minute.');
}
PdfService::stream($pdf['bytes'], $pdf['filename'], query_str('dl') === '1');
