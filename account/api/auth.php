<?php
/**
 * Customer auth API.
 *   POST {action: register, first_name, last_name, email, phone, password, marketing_opt_in}
 *   POST {action: login, email, password}
 *   POST {action: logout}
 *   POST {action: forgot, email}
 *   POST {action: reset, token, password, password_confirm}
 *   POST {action: resend_verify}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$action = (string) input('action', '');

switch ($action) {
    case 'register':
        rate_limit_or_fail('register', client_ip(), 6, 3600);
        $d = validate_or_fail(input_all(), [
            'first_name' => 'required|max:100', 'last_name' => 'required|max:100', 'email' => 'required|email|max:191',
            'phone' => 'required|phone', 'password' => 'required|password', 'marketing_opt_in' => 'nullable|bool',
        ]);
        if (db_val('SELECT id FROM customers WHERE email = ?', [$d['email']])) {
            json_error(t('error.validation'), 422, ['email' => 'An account with this email already exists — log in instead.']);
        }
        $id = db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone, marketing_opt_in, marketing_opt_in_at) VALUES (?,?,?,?,?,?,?)', [
            $d['email'], customer_password_hash($d['password']), $d['first_name'], $d['last_name'], $d['phone'],
            $d['marketing_opt_in'] ? 1 : 0, $d['marketing_opt_in'] ? now() : null,
        ]);
        $c = db_one('SELECT * FROM customers WHERE id = ?', [$id]);
        login_customer($c);
        $token = customer_token_create($id, 'verify_email', 60 * 24 * 7);
        NotificationService::email('verify_email', $c['email'], $c['first_name'], ['first_name' => $c['first_name'], 'link' => app_url('/account/verify.php?token=' . $token)],
            null, [], ['label' => 'Confirm my email', 'url' => app_url('/account/verify.php?token=' . $token)]);
        audit('customer.registered', 'customer', $id);
        json_ok(['redirect' => safe_next((string) input('next', ''), '/account/')], 'Welcome to GrazeX, ' . $c['first_name'] . '!');

    case 'login':
        $d = validate_or_fail(input_all(), ['email' => 'required|email|max:191', 'password' => 'required|max:200']);
        rate_limit_or_fail('login_ip', client_ip(), 20, 900);
        rate_limit_or_fail('login_email', $d['email'], 6, 900);
        $c = attempt_customer_login($d['email'], $d['password']);
        if (!$c) {
            json_error('That email and password don\'t match. Please try again.', 422, ['password' => 'Incorrect email or password.']);
        }
        rate_limit_clear('login_email', $d['email']);
        login_customer($c);
        json_ok(['redirect' => safe_next((string) input('next', ''), '/account/')], 'Welcome back, ' . $c['first_name'] . '!');

    case 'logout':
        logout_customer();
        json_ok(['redirect' => '/'], 'You are logged out.');

    case 'forgot':
        rate_limit_or_fail('forgot', client_ip(), 5, 3600);
        $d = validate_or_fail(input_all(), ['email' => 'required|email|max:191']);
        $c = db_one("SELECT * FROM customers WHERE email = ? AND status = 'active'", [$d['email']]);
        if ($c) {
            rate_limit_or_fail('forgot_email', $d['email'], 3, 3600);
            $token = customer_token_create((int) $c['id'], 'reset_password', 60);
            $link = app_url('/account/reset.php?token=' . $token);
            NotificationService::email('reset_password', $c['email'], $c['first_name'], ['first_name' => $c['first_name'], 'link' => $link], null, [], ['label' => 'Choose a new password', 'url' => $link]);
        }
        // Same answer whether or not the account exists (no account enumeration)
        json_ok([], 'If that email has an account, a reset link is on its way.');

    case 'reset':
        rate_limit_or_fail('reset', client_ip(), 10, 3600);
        $d = validate_or_fail(input_all(), ['token' => 'required|regex:/^[a-f0-9]{64}$/', 'password' => 'required|password', 'password_confirm' => 'required|same:password']);
        $cid = customer_token_consume($d['token'], 'reset_password');
        if (!$cid) {
            json_error('This reset link has expired or was already used. Please request a new one.', 422);
        }
        // New password + log out every other session
        db_exec('UPDATE customers SET password_hash = ?, session_version = session_version + 1, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?',
            [customer_password_hash($d['password']), $cid]);
        login_customer(db_one('SELECT * FROM customers WHERE id = ?', [$cid]));
        audit('customer.password_reset', 'customer', $cid);
        json_ok(['redirect' => '/account/'], 'Your password has been changed.');

    case 'resend_verify':
        $c = require_customer_api();
        rate_limit_or_fail('resend_verify', 'c' . $c['id'], 3, 3600);
        if (!$c['email_verified_at']) {
            $token = customer_token_create((int) $c['id'], 'verify_email', 60 * 24 * 7);
            $link = app_url('/account/verify.php?token=' . $token);
            NotificationService::email('verify_email', $c['email'], $c['first_name'], ['first_name' => $c['first_name'], 'link' => $link], null, [], ['label' => 'Confirm my email', 'url' => $link]);
        }
        json_ok([], 'Check your inbox for the confirmation link.');

    default:
        json_error('Unknown action.', 400);
}
