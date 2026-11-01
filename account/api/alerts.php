<?php
/**
 * POST /account/api/alerts.php  {action: create, breed, category} | {action: toggle, id} | {action: delete, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
$owns = 'id = ? AND (customer_id = ? OR email = ?)';

switch ((string) input('action')) {
    case 'create':
        $d = validate_or_fail(input_all(), ['breed' => 'nullable|max:80', 'category' => 'nullable|max:80']);
        $b = $d['breed'] ? db_val('SELECT id FROM breeds WHERE slug = ?', [$d['breed']]) : null;
        $c = $d['category'] ? db_val('SELECT id FROM livestock_categories WHERE slug = ?', [$d['category']]) : null;
        db_exec('INSERT INTO livestock_alerts (customer_id, email, breed_id, category_id, unsubscribe_token) VALUES (?,?,?,?,?)',
            [$customer['id'], $customer['email'], $b, $c, random_token(32)]);
        json_ok([], 'Alert added.');
    case 'toggle':
        db_exec("UPDATE livestock_alerts SET is_active = 1 - is_active WHERE {$owns}", [(int) input('id'), $customer['id'], $customer['email']]);
        json_ok([], 'Alert updated.');
    case 'delete':
        db_exec("DELETE FROM livestock_alerts WHERE {$owns}", [(int) input('id'), $customer['id'], $customer['email']]);
        json_ok([], 'Alert deleted.');
    default:
        json_error('Unknown action.', 400);
}
