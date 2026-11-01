<?php
/** POST /pages/api/contact.php — contact form → Admin > Messages. */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
rate_limit_or_fail('contact', client_ip(), 5, 900);
if (trim((string) input('website', '')) !== '') {
    json_ok([], 'Thanks — we will be in touch.');
}
$d = validate_or_fail(input_all(), ['name' => 'required|max:150', 'email' => 'required|email|max:191', 'phone' => 'nullable|phone',
                                    'division' => 'nullable|in:feeds,ranch', 'message' => 'required|min:5|max:3000']);
$divId = $d['division'] ? division_id($d['division']) : null;
$id = db_insert("INSERT INTO enquiries (division_id, type, customer_id, name, email, phone, message, ip) VALUES (?, 'contact', ?, ?, ?, ?, ?, ?)",
    [$divId, customer_id(), $d['name'], $d['email'], $d['phone'], $d['message'], client_ip()]);
NotificationService::admin('enquiry', 'New message from ' . $d['name'], str_limit($d['message'], 160), '/admin/messages.php?id=' . $id, $divId);
json_ok([], 'Thanks ' . explode(' ', $d['name'])[0] . '! We received your message and will reply soon.');
