<?php
/**
 * POST /ranch/api/alerts.php {email, breed?, category?} — subscribe to "new livestock" alerts.
 * GET  /ranch/api/alerts.php?unsubscribe=TOKEN         — one-click unsubscribe (from email links).
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

if (request_method() === 'GET' && isset($_GET['unsubscribe'])) {
    $token = query_str('unsubscribe');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        db_exec('UPDATE livestock_alerts SET is_active = 0 WHERE unsubscribe_token = ?', [$token]);
    }
    flash('success', 'You have been unsubscribed from livestock alerts.');
    redirect('/ranch/');
}

api_post();
rate_limit_or_fail('livestock_alert', rate_limit_ip(), 10, 3600);

$data = validate_or_fail(input_all(), [
    'email'    => 'required|email|max:191',
    'breed'    => 'nullable|max:80',
    'category' => 'nullable|max:80',
]);
$breedId = $data['breed'] ? db_val('SELECT id FROM breeds WHERE slug = ?', [$data['breed']]) : null;
$catId = $data['category'] ? db_val('SELECT id FROM livestock_categories WHERE slug = ?', [$data['category']]) : null;

$exists = db_val('SELECT id FROM livestock_alerts WHERE email = ? AND breed_id <=> ? AND category_id <=> ? AND is_active = 1',
    [$data['email'], $breedId, $catId]);
if (!$exists) {
    db_exec('INSERT INTO livestock_alerts (customer_id, email, breed_id, category_id, unsubscribe_token) VALUES (?, ?, ?, ?, ?)',
        [customer_id(), $data['email'], $breedId, $catId, random_token(32)]);
}
json_ok([], "Done! We'll email you when matching livestock is available.");
