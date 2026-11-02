<?php
/** Admin users API (owner only): create · update (name, role, divisions) · toggle · reset_2fa · perms */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$me = require_admin_api('users.manage');
// Managing admin logins and roles is the job of an owner who sees everything: anyone else could make themselves
// (or a new login) an owner of both divisions and lock the real owner out
if ($me['role_slug'] !== 'owner' || $me['division_scope'] !== 'all') {
    json_error('Only an owner with access to both divisions can manage admin users and permissions.', 403);
}
/**
 * There must always be an active owner who can see everything. Call inside a transaction before an active
 * all-division owner is demoted, limited to one division or disabled. Locks the owner rows, so two owners
 * changing each other at the same moment can't both succeed.
 */
$keepFullOwner = static function (int $userId): void {
    $others = db_col("SELECT a.id FROM admin_users a JOIN roles r ON r.id = a.role_id
                      WHERE r.slug = 'owner' AND a.status = 'active' AND a.division_scope = 'all' AND a.id <> ? FOR UPDATE", [$userId]);
    if (!$others) {
        throw new DomainException('This is the only active owner with access to everything — make someone else an owner first.');
    }
};

try {
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
        case 'update':
            $d = validate_or_fail(input_all(), ['id' => 'required|int|min:1', 'name' => 'required|max:150', 'role_id' => 'required|int|min:1',
                                                'division_scope' => 'required|in:all,feeds,ranch']);
            $role = db_one('SELECT id, slug FROM roles WHERE id = ?', [$d['role_id']]);
            if (!$role) {
                json_error(t('error.validation'), 422, ['role_id' => 'Choose a valid role.']);
            }
            $before = db_one('SELECT a.id, a.name, a.role_id, a.division_scope, a.status, r.slug FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$d['id']]);
            if (!$before) {
                json_error('Admin user not found.', 404);
            }
            db_tx(static function () use ($d, $role, $keepFullOwner): void {
                $cur = db_one('SELECT a.status, a.division_scope, r.slug FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ? FOR UPDATE', [$d['id']]);
                // Only matters when this person is an active owner with full access and would stop being one
                if ($cur && $cur['slug'] === 'owner' && $cur['status'] === 'active' && $cur['division_scope'] === 'all'
                    && ($role['slug'] !== 'owner' || $d['division_scope'] !== 'all')) {
                    $keepFullOwner((int) $d['id']);
                }
                db_exec('UPDATE admin_users SET name = ?, role_id = ?, division_scope = ? WHERE id = ?', [$d['name'], $d['role_id'], $d['division_scope'], $d['id']]);
            });
            audit('admin_user.updated', 'admin_user', (int) $d['id'], ['changes' => audit_diff(
                ['name' => $before['name'], 'role_id' => $before['role_id'], 'division_scope' => $before['division_scope']],
                ['name' => $d['name'], 'role_id' => $d['role_id'], 'division_scope' => $d['division_scope']])]);
            json_ok([], $d['name'] . ' is updated.');
        case 'toggle':
            $u = db_one('SELECT a.*, r.slug FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [(int) input('id')]);
            if (!$u || (int) $u['id'] === (int) $me['id']) {
                json_error('You cannot disable yourself.', 409);
            }
            db_tx(static function () use ($u, $keepFullOwner): void {
                $cur = db_one('SELECT a.status, a.division_scope, r.slug FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ? FOR UPDATE', [$u['id']]);
                if ($cur && $cur['slug'] === 'owner' && $cur['status'] === 'active' && $cur['division_scope'] === 'all') {
                    $keepFullOwner((int) $u['id']);
                }
                db_exec("UPDATE admin_users SET status = IF(status = 'active', 'disabled', 'active'), session_version = session_version + 1 WHERE id = ?", [$u['id']]);
            });
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
                        // Managing admin users stays with the owner
                        db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE id = ? AND slug <> 'users.manage'", [$r['id'], $pid]);
                    }
                }
            });
            audit('roles.permissions_saved', 'role');
            json_ok([], 'Permissions saved.');
        default:
            json_error('Unknown action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 409);
}
