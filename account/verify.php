<?php
/** Email verification link. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$cid = customer_token_consume(query_str('token'), 'verify_email');
if ($cid) {
    db_exec('UPDATE customers SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$cid]);
    flash('success', 'Thanks — your email address is confirmed.');
} else {
    flash('error', 'That confirmation link has expired. Log in and request a new one from your profile.');
}
redirect(current_customer() ? '/account/' : '/account/login.php');
