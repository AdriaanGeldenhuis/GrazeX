<?php
/**
 * GrazeX environment configuration — TEMPLATE.
 *
 * 1. Copy this file to config/env.php on the server (via SFTP / File Manager).
 * 2. Fill in the real values below.
 * 3. config/env.php is NEVER committed to git and NEVER overwritten by the deploy.
 */
declare(strict_types=1);

return [
    // 'production' on Xneelo, 'local' on your own machine.
    'app_env'   => 'production',
    // Show full error messages in the browser. Keep FALSE in production.
    'app_debug' => false,
    // Full site address, no trailing slash.
    'app_url'   => 'https://www.grazex.co.za',
    // Random secret used to sign tokens. Generate 64 hex chars, e.g. with: php -r "echo bin2hex(random_bytes(32));"
    'app_key'   => 'CHANGE-ME-64-HEX-CHARACTERS',
    // One-time token you type into /setup/ to create the owner account.
    'setup_token' => 'CHANGE-ME-SETUP-TOKEN',

    // Database (Xneelo: Manage MySQL — the host looks like sqlNN.jnbN.host-h.net)
    'db' => [
        'host' => 'sqlNN.jnbN.host-h.net',
        'port' => 3306,
        'name' => 'your_database_name',
        'user' => 'your_database_user',
        'pass' => 'your_database_password',
    ],

    // Outgoing email (Xneelo: smtp.<your-domain> port 465 SSL, username = full email address)
    'mail' => [
        'host'       => 'smtp.grazex.co.za',
        'port'       => 465,
        'secure'     => 'ssl',            // 'ssl' (465) or 'tls' (587)
        'user'       => 'orders@grazex.co.za',
        'pass'       => '',
        'from_email' => 'orders@grazex.co.za',
        'from_name'  => 'GrazeX',
        // Where admin alerts go (new orders, POP uploads, low stock)
        'admin_email' => 'orders@grazex.co.za',
    ],

    // Yoco Checkout API (Yoco App > Checkout API). Live keys unlock after domain verification.
    'yoco' => [
        'mode'                => 'test',  // 'test' or 'live'
        'test_secret_key'     => '',      // sk_test_...
        'live_secret_key'     => '',      // sk_live_...
        'test_webhook_secret' => '',      // whsec_... (shown once when the webhook is registered)
        'live_webhook_secret' => '',
    ],
];
