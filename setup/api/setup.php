<?php
/**
 * POST /setup/api/setup.php — create the owner admin (only while none exists).
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
if (setup_locked()) {
    json_error('Setup has already been completed.', 409);
}
rate_limit_or_fail('setup', rate_limit_ip(), 5, 900);

$data = validate_or_fail(input_all(), [
    'setup_token'      => 'required|max:200',
    'name'             => 'required|max:150',
    'email'            => 'required|email|max:191',
    'password'         => 'required|password',
    'password_confirm' => 'required|same:password',
]);

$expected = (string) env('setup_token', '');
if (gx_env_placeholder($expected) || strlen($expected) < 12 || !hash_equals($expected, (string) $data['setup_token'])) {
    json_error('That setup token is not correct.', 422, ['setup_token' => 'That setup token is not correct.']);
}

$admin = db_tx(static function () use ($data): array {
    $ownerRoleId = (int) db_val("SELECT id FROM roles WHERE slug = 'owner' FOR UPDATE");
    if (!$ownerRoleId) {
        json_error('Roles are missing — import database/install.sql first.', 500);
    }
    if ((int) db_val('SELECT COUNT(*) FROM admin_users WHERE role_id = ?', [$ownerRoleId]) > 0) {
        json_error('Setup has already been completed.', 409);
    }
    $id = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (?, ?, ?, ?, ?)',
        [$ownerRoleId, $data['email'], admin_password_hash($data['password']), $data['name'], 'all']);
    setting_set('setup_completed_at', now());
    return db_one('SELECT * FROM admin_users WHERE id = ?', [$id]);
});

setup_lock_write();
login_admin($admin);
audit('setup.owner_created', 'admin_user', (int) $admin['id']);

json_ok(['redirect' => '/admin/'], 'Owner account created. Welcome to GrazeX!');
