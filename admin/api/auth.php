<?php
/**
 * Admin auth API: {action: login, email, password} → {action: 2fa, code} | {action: logout}
 *                 {action: 2fa_setup} → secret · {action: 2fa_enable, code} · {action: 2fa_disable, password}
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$action = (string) input('action');

switch ($action) {
    case 'login':
        $d = validate_or_fail(input_all(), ['email' => 'required|email|max:191', 'password' => 'required|max:200']);
        rate_limit_or_fail('admin_login_ip', client_ip(), 10, 900);
        rate_limit_or_fail('admin_login_email', $d['email'], 5, 900);
        $a = db_one("SELECT * FROM admin_users WHERE email = ? AND status = 'active'", [$d['email']]);
        $hash = $a['password_hash'] ?? '$2y$12$JiwPOQdSNkjju0hlMtB8f.uGaZYGpNx.vwceGuUaCYUgQB1xEerim';
        if (!password_verify($d['password'], $hash) || !$a) {
            audit('admin.login_failed', null, null, ['email' => $d['email']]);
            json_error('Incorrect email or password.', 422, ['password' => 'Incorrect email or password.']);
        }
        rate_limit_clear('admin_login_email', $d['email']);
        if ((int) $a['totp_enabled'] && $a['totp_secret']) {
            session_rotate();
            $_SESSION['admin_2fa_pending'] = (int) $a['id'];
            json_ok(['redirect' => '/admin/login.php?next=' . rawurlencode(safe_next((string) input('next'), '/admin/'))]);
        }
        login_admin($a);
        json_ok(['redirect' => safe_next((string) input('next'), '/admin/')]);

    case '2fa':
        $pending = (int) ($_SESSION['admin_2fa_pending'] ?? 0);
        if (!$pending) {
            json_error('Please log in again.', 401);
        }
        rate_limit_or_fail('admin_2fa', 'a' . $pending, 6, 900);
        $a = db_one("SELECT * FROM admin_users WHERE id = ? AND status = 'active'", [$pending]);
        if (!$a || !totp_verify((string) $a['totp_secret'], (string) input('code'))) {
            json_error('That code is not correct.', 422, ['code' => 'That code is not correct.']);
        }
        login_admin($a);
        json_ok(['redirect' => safe_next((string) input('next'), '/admin/')]);

    case 'logout':
        if ($a = current_admin()) {
            audit('admin.logout', 'admin_user', (int) $a['id']);
        }
        logout_admin();
        json_ok(['redirect' => '/admin/login.php']);

    case '2fa_setup':
        $a = require_admin_api();
        $secret = totp_secret();
        $_SESSION['totp_setup'] = $secret;
        json_ok(['secret' => $secret, 'uri' => totp_uri($secret, $a['email'])]);

    case '2fa_enable':
        $a = require_admin_api();
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        if ($secret === '' || !totp_verify($secret, (string) input('code'))) {
            json_error('That code is not correct — check the time on your phone and try again.', 422, ['code' => 'Incorrect code.']);
        }
        db_exec('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $a['id']]);
        unset($_SESSION['totp_setup']);
        audit('admin.2fa_enabled', 'admin_user', (int) $a['id']);
        json_ok([], 'Two-step verification is on.');

    case '2fa_disable':
        $a = require_admin_api();
        $hash = (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$a['id']]);
        if (!password_verify((string) input('password'), $hash)) {
            json_error('Password is not correct.', 422, ['password' => 'Password is not correct.']);
        }
        db_exec('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$a['id']]);
        audit('admin.2fa_disabled', 'admin_user', (int) $a['id']);
        json_ok([], 'Two-step verification is off.');

    case 'password':
        $a = require_admin_api();
        $d = validate_or_fail(input_all(), ['current_password' => 'required|max:200', 'password' => 'required|password', 'password_confirm' => 'required|same:password']);
        if (!password_verify($d['current_password'], (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$a['id']]))) {
            json_error(t('error.validation'), 422, ['current_password' => 'Current password is not correct.']);
        }
        db_exec('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [admin_password_hash($d['password']), $a['id']]);
        login_admin(db_one('SELECT * FROM admin_users WHERE id = ?', [$a['id']]));
        json_ok([], 'Password changed.');

    default:
        json_error('Unknown action.', 400);
}
