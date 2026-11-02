<?php
/**
 * Admin auth API: {action: login, email, password} → {action: 2fa, code (6 digits or a recovery code)} | {action: logout}
 *                 {action: 2fa_setup, password} → secret · {action: 2fa_enable, code} · {action: 2fa_disable, password}
 *                 {action: password, current_password, password, password_confirm}
 *                 {action: forgot, email} · {action: reset, token, password, password_confirm} — "forgot password" by email
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
        rate_limit_or_fail('admin_login_ip', rate_limit_ip(), 10, 900);
        // Per account AND address (counted before checking, cleared on success): a stranger cannot lock the owner out
        $acct = $d['email'] . '|' . rate_limit_ip();
        rate_limit_or_fail('admin_login_email', $acct, 5, 900);
        // Account-wide cap across all addresses (slow guessing from many IPs). While it is tripped only the
        // address the admin last logged in from may still try, so an attack cannot lock the owner out either.
        $lastIp = (string) db_val('SELECT last_login_ip FROM admin_users WHERE email = ?', [$d['email']]);
        $fromKnownPlace = $lastIp !== '' && ip_bucket($lastIp) === rate_limit_ip();
        if (!$fromKnownPlace && rate_limit_blocked('admin_login_account', $d['email'])) {
            header('Retry-After: 3600');
            json_error('Too many failed logins for this account. Try again later from the place you usually log in from.', 429);
        }
        $a = db_one("SELECT * FROM admin_users WHERE email = ? AND status = 'active'", [$d['email']]);
        $hash = $a['password_hash'] ?? gx_dummy_hash();
        if (!password_verify($d['password'], $hash) || !$a) {
            rate_limit_hit('admin_login_account', $d['email'], 30, 3600);
            audit('admin.login_failed', null, null, ['email' => $d['email']]);
            json_error('Incorrect email or password.', 422, ['password' => 'Incorrect email or password.']);
        }
        rate_limit_clear('admin_login_email', $acct);
        rate_limit_clear('admin_login_account', $d['email']);
        setup_lock_write();
        if ((int) $a['totp_enabled'] && $a['totp_secret']) {
            session_rotate();
            $_SESSION['admin_2fa_pending'] = (int) $a['id'];
            $_SESSION['admin_2fa_at'] = time();
            json_ok(['redirect' => '/admin/login.php?next=' . rawurlencode(safe_next((string) input('next'), '/admin/'))]);
        }
        login_admin($a);
        json_ok(['redirect' => safe_next((string) input('next'), '/admin/')]);

    case '2fa':
        $pending = (int) ($_SESSION['admin_2fa_pending'] ?? 0);
        if (!$pending || time() - (int) ($_SESSION['admin_2fa_at'] ?? 0) > 300) {
            unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_at']);
            json_error('Please log in again.', 401);
        }
        rate_limit_or_fail('admin_2fa', 'a' . $pending, 6, 900);
        $a = db_one("SELECT * FROM admin_users WHERE id = ? AND status = 'active'", [$pending]);
        $code = (string) input('code');
        $ok = false;
        if ($a) {
            $secret = totp_secret_load($a['totp_secret']);
            $step = totp_match($secret, $code);
            if ($step !== null) {
                // Each code works once (a code seen over someone's shoulder cannot be replayed)
                $ok = db_exec('UPDATE admin_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
                    [$step, $a['id'], $step]) === 1;
                if ($ok) {
                    // Re-save with the current key (encrypts old plain secrets; heals after APP_KEY is added/changed)
                    db_exec('UPDATE admin_users SET totp_secret = ? WHERE id = ?', [totp_secret_store($secret), $a['id']]);
                }
            } elseif (totp_recovery_use((int) $a['id'], $code)) {
                $ok = true;
                audit('admin.2fa_recovery_code_used', 'admin_user', (int) $a['id']);
            }
        }
        if (!$ok) {
            json_error('That code is not correct.', 422, ['code' => 'That code is not correct.']);
        }
        unset($_SESSION['admin_2fa_at']);
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
        // Re-check the password, so a hijacked session cannot move 2FA to another phone
        rate_limit_or_fail('admin_reauth', 'a' . $a['id'], 5, 900);
        if (!password_verify((string) input('password'), (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$a['id']]))) {
            audit('admin.reauth_failed', 'admin_user', (int) $a['id']);
            json_error('Password is not correct.', 422, ['password' => 'Password is not correct.']);
        }
        $secret = totp_secret();
        $_SESSION['totp_setup'] = $secret;
        json_ok(['secret' => $secret, 'uri' => totp_uri($secret, $a['email'])]);

    case '2fa_enable':
        $a = require_admin_api();
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        if ($secret === '' || !totp_verify($secret, (string) input('code'))) {
            json_error('That code is not correct — check the time on your phone and try again.', 422, ['code' => 'Incorrect code.']);
        }
        [$codes, $hashes] = totp_recovery_generate();
        db_exec('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, totp_last_step = NULL, totp_recovery = ? WHERE id = ?',
            [totp_secret_store($secret), $hashes, $a['id']]);
        unset($_SESSION['totp_setup']);
        $_SESSION['totp_recovery_show'] = $codes; // shown once on the profile page
        audit('admin.2fa_enabled', 'admin_user', (int) $a['id']);
        json_ok([], 'Two-step verification is on — save your recovery codes.');

    case '2fa_disable':
        $a = require_admin_api();
        rate_limit_or_fail('admin_reauth', 'a' . $a['id'], 5, 900);
        $hash = (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$a['id']]);
        if (!password_verify((string) input('password'), $hash)) {
            audit('admin.reauth_failed', 'admin_user', (int) $a['id']);
            json_error('Password is not correct.', 422, ['password' => 'Password is not correct.']);
        }
        db_exec('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL, totp_recovery = NULL WHERE id = ?', [$a['id']]);
        audit('admin.2fa_disabled', 'admin_user', (int) $a['id']);
        json_ok([], 'Two-step verification is off.');

    case 'password':
        $a = require_admin_api();
        $d = validate_or_fail(input_all(), ['current_password' => 'required|max:200', 'password' => 'required|password', 'password_confirm' => 'required|same:password']);
        rate_limit_or_fail('admin_reauth', 'a' . $a['id'], 5, 900);
        if (!password_verify($d['current_password'], (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$a['id']]))) {
            audit('admin.reauth_failed', 'admin_user', (int) $a['id']);
            json_error(t('error.validation'), 422, ['current_password' => 'Current password is not correct.']);
        }
        db_exec('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [admin_password_hash($d['password']), $a['id']]);
        audit('admin.password_changed', 'admin_user', (int) $a['id']);
        login_admin(db_one('SELECT * FROM admin_users WHERE id = ?', [$a['id']]));
        json_ok([], 'Password changed.');

    case 'forgot':
        rate_limit_or_fail('admin_forgot', rate_limit_ip(), 5, 3600);
        $d = validate_or_fail(input_all(), ['email' => 'required|email|max:191']);
        // Same limit, same answer and the email sent after the response, whether or not the login exists (no enumeration)
        rate_limit_or_fail('admin_forgot_email', $d['email'], 3, 3600);
        $a = db_one("SELECT * FROM admin_users WHERE email = ? AND status = 'active'", [$d['email']]);
        if ($a) {
            $token = admin_token_create((int) $a['id']);
            $link = app_url('/admin/reset.php?token=' . $token);
            $first = explode(' ', trim((string) $a['name']))[0];
            $qid = NotificationService::email('admin_password_reset', $a['email'], $a['name'], ['first_name' => $first, 'link' => $link],
                null, [], ['label' => 'Choose a new password', 'url' => $link], false);
            if ($qid) {
                register_shutdown_function(static fn() => MailService::sendNow($qid));
            }
            audit('admin.password_reset_requested', 'admin_user', (int) $a['id']);
        }
        json_ok([], 'If that email has an admin login, a reset link is on its way. It works for one hour.');

    case 'reset':
        rate_limit_or_fail('admin_reset', rate_limit_ip(), 10, 3600);
        $d = validate_or_fail(input_all(), ['token' => 'required|regex:/^[a-f0-9]{64}$/', 'password' => 'required|password', 'password_confirm' => 'required|same:password']);
        $aid = admin_token_consume($d['token']);
        if (!$aid) {
            json_error('This reset link has expired or was already used. Ask for a new one.', 422);
        }
        // New password, every session logged out; two-step verification stays on (recovery codes still work)
        db_exec('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [admin_password_hash($d['password']), $aid]);
        rate_limit_clear('admin_login_account', (string) db_val('SELECT email FROM admin_users WHERE id = ?', [$aid]));
        audit('admin.password_reset', 'admin_user', $aid);
        json_ok(['redirect' => '/admin/login.php'], 'Password changed — log in with your new password.');

    default:
        json_error('Unknown action.', 400);
}
