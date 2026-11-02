<?php
/**
 * POST /account/api/alerts.php  {action: create, breed, category} | {action: toggle, id} | {action: delete, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
// Own alerts: made while logged in, or for the account's email once it is verified
$owns = 'id = ? AND (customer_id = ? OR email = ?)';
$email = customer_verified_email($customer);

switch ((string) input('action')) {
    case 'create':
        $d = validate_or_fail(input_all(), ['breed' => 'nullable|max:80', 'category' => 'nullable|max:80']);
        $b = $d['breed'] ? db_val('SELECT id FROM breeds WHERE slug = ?', [$d['breed']]) : null;
        $c = $d['category'] ? db_val('SELECT id FROM livestock_categories WHERE slug = ?', [$d['category']]) : null;
        // A verified email is live at once; otherwise the alert waits for the emailed confirmation link
        $live = LivestockService::subscribe((string) $customer['email'], (int) $customer['id'], $b !== null ? (int) $b : null, $c !== null ? (int) $c : null,
            $email !== null);
        json_ok([], $live ? 'Alert added.' : 'Almost done — check your inbox and click the link to confirm this alert.');
    case 'toggle':
        db_exec("UPDATE livestock_alerts SET is_active = 1 - is_active WHERE {$owns}", [(int) input('id'), $customer['id'], $email]);
        json_ok([], 'Alert updated.');
    case 'delete':
        db_exec("DELETE FROM livestock_alerts WHERE {$owns}", [(int) input('id'), $customer['id'], $email]);
        json_ok([], 'Alert deleted.');
    default:
        json_error('Unknown action.', 400);
}
