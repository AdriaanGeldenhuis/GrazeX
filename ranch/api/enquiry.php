<?php
/**
 * POST /ranch/api/enquiry.php — question about a lot. Lands in Admin > Messages.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
rate_limit_or_fail('enquiry', rate_limit_ip(), 5, 900);

// Honeypot: bots fill hidden fields
if (trim((string) input('website', '')) !== '') {
    json_ok([], 'Thanks — we will be in touch.');
}

$data = validate_or_fail(input_all(), [
    'lot_id'  => 'nullable|int|min:1',
    'name'    => 'required|max:150',
    'email'   => 'required|email|max:191',
    'phone'   => 'nullable|phone',
    'message' => 'required|min:5|max:3000',
]);
$lot = $data['lot_id'] ? db_one('SELECT id, lot_no, title FROM lots WHERE id = ?', [$data['lot_id']]) : null;

$id = db_insert('INSERT INTO enquiries (division_id, type, lot_id, customer_id, name, email, phone, message, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
    division_id('ranch'), $lot ? 'lot' : 'contact', $lot['id'] ?? null, customer_id(),
    $data['name'], $data['email'], $data['phone'], $data['message'], client_ip(),
]);
NotificationService::admin('enquiry', 'New question' . ($lot ? ' about ' . $lot['lot_no'] : ''), $data['name'] . ': ' . str_limit($data['message'], 120),
    '/admin/messages.php?id=' . $id, division_id('ranch'));

json_ok([], 'Thanks! We received your question and will reply soon.');
