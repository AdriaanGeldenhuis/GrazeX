<?php
/** Admin users API (owner only): create · toggle · reset_2fa · perms */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$me = require_admin_api('users.manage');
// Managing admin logins and roles is the owner's job only (anyone else could make themselves an owner)
if ($me['role_slug'] !== 'owner') {
    json_error('Only the owner can manage admin users and permissions.', 403);
}
switch ((string) input('action')) {
    case 'create':
        $d = validate_or_fail(input_all(), ['name' => 'required|max:150', 'email' => 'required|email|max:191', 'role_id' => 'required|int|min:1',
                                            'division_scope' => 'required|in:all,feeds,ranch', 'password' => 'required|password']);
        if (!db_val('SELECT 1 FROM roles WHERE id = ?', [$d['role_id']])) {
            json_error(t('error.validation'), 422, ['role_id' => 'Choose a valid role.']);
        }
        if (db_val('SELECT 1 FROM admin_users WHERE email = ?', [$d['email']])) {
            json_error(t('error.validation'), 422, ['email' => 'That email already has an admin login.']);
        }
        $id = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (?,?,?,?,?)',
            [$d['role_id'], $d['email'], admin_password_hash($d['password']), $d['name'], $d['division_scope']]);
        audit('admin_user.created', 'admin_user', $id);
        json_ok([], $d['name'] . ' can now log in at /admin/.');
    case 'toggle':
        $u = db_one('SELECT a.*, r.slug FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [(int) input('id')]);
        if (!$u || (int) $u['id'] === (int) $me['id']) {
            json_error('You cannot disable yourself.', 409);
        }
        if ($u['slug'] === 'owner' && $u['status'] === 'active' && (int) db_val("SELECT COUNT(*) FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE r.slug = 'owner' AND a.status = 'active'") <= 1) {
            json_error('There must always be one active owner.', 409);
        }
        db_exec("UPDATE admin_users SET status = IF(status = 'active', 'disabled', 'active'), session_version = session_version + 1 WHERE id = ?", [$u['id']]);
        audit('admin_user.toggled', 'admin_user', (int) $u['id']);
        json_ok([], 'Updated.');
    case 'reset_2fa':
        db_exec('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?', [(int) input('id')]);
        audit('admin_user.2fa_reset', 'admin_user', (int) input('id'));
        json_ok([], '2FA reset — they can set it up again from their profile.');
    case 'perms':
        $map = (array) input('perm', []);
        if ($map && array_is_list($map)) {
            // perm must be keyed by role id ({"2": [...], "3": [...]}) — a plain list would wipe the wrong roles
            json_error('Permissions arrived in an unexpected format — nothing was changed. Reload the page and try again.', 400);
        }
        db_tx(static function () use ($map): void {
            foreach (db_all("SELECT id FROM roles WHERE slug <> 'owner'") as $r) {
                db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$r['id']]);
                foreach (array_unique(array_map('intval', (array) ($map[$r['id']] ?? []))) as $pid) {
                    db_exec('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE id = ?', [$r['id'], $pid]);
                }
            }
        });
        audit('roles.permissions_saved', 'role');
        json_ok([], 'Permissions saved.');
    default:
        json_error('Unknown action.', 400);
}
