<?php
/** Email verification link. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$cid = customer_token_consume(query_str('token'), 'verify_email');
if ($cid) {
    db_exec('UPDATE customers SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$cid]);
    $email = (string) db_val('SELECT email FROM customers WHERE id = ?', [$cid]);
    // Alerts made in the account while the email was unproven are now confirmed by this link
    db_exec('UPDATE livestock_alerts SET confirmed_at = NOW(), confirm_token = NULL WHERE customer_id = ? AND email = ? AND confirmed_at IS NULL', [$cid, $email]);
    // Now that the address is proven, things sent from it without logging in join the account
    db_exec('UPDATE livestock_alerts SET customer_id = ? WHERE customer_id IS NULL AND email = ?', [$cid, $email]);
    db_exec('UPDATE enquiries SET customer_id = ? WHERE customer_id IS NULL AND email = ?', [$cid, $email]);
    flash('success', 'Thanks — your email address is confirmed.');
} else {
    flash('error', 'That confirmation link has expired. Log in and request a new one from your profile.');
}
redirect(current_customer() ? '/account/' : '/account/login.php');
