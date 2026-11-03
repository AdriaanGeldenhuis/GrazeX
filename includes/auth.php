<?php
/**
 * Customer authentication (shared by /feeds/, /ranch/, /account/ and checkout).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function customer_id(): ?int
{
    $c = current_customer();
    return $c ? (int) $c['id'] : null;
}

/**
 * The customer's email for matching records made without logging in (alerts, enquiries) — only once they have
 * proved they own it. Until then anyone could register with someone else's address and see that person's data.
 */
function customer_verified_email(array $customer): ?string
{
    return !empty($customer['email_verified_at']) ? (string) $customer['email'] : null;
}

function current_customer(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    $id = $_SESSION['customer_id'] ?? null;
    if (!$id) {
        return null;
    }
    $row = db_one('SELECT id, email, first_name, last_name, phone, farm_name, company_name, vat_no, vat_farmer_status,
                          email_verified_at, marketing_opt_in, status, session_version
                   FROM customers WHERE id = ?', [(int) $id]);
    // Logged out everywhere / blocked / deleted → drop the session
    if (!$row || $row['status'] !== 'active' || (int) $row['session_version'] !== (int) ($_SESSION['customer_sv'] ?? 0)) {
        unset($_SESSION['customer_id'], $_SESSION['customer_sv']);
        return null;
    }
    $cache = $row;
    return $cache;
}

/** A fixed hash with the same algorithm and cost as real ones, verified for unknown emails (equal timing). */
function gx_dummy_hash(): string
{
    return defined('PASSWORD_ARGON2ID')
        ? '$argon2id$v=19$m=65536,t=4,p=1$ZVIzUy5GNzdZeUFjNUIxYg$0duCByE55EhAxSNpoZrgJi5w9SU5wLSbjTh/BAsBD6E'
        : '$2y$12$1ojWDKw9oci1XNKmhkw.KuoRuNLD8sMTXBWOkbQtXAABwDj8A.JOe';
}

function customer_password_hash(string $password): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($password, PASSWORD_ARGON2ID)
        : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Log a customer in on this device.
 * $remember: true = "keep me logged in" (60 days), false = only this browser session,
 * null = keep this device's earlier choice (used after a password change or "log out everywhere else").
 */
function login_customer(array $customer, ?bool $remember = null): void
{
    $remember ??= remember_current_customer_id() === (int) $customer['id'];
    remember_forget_current(); // an older token on this device (another customer, or an older login) goes
    $phone = (int) ($_SESSION['push_device_customer'] ?? 0);
    session_rotate();
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_sv'] = (int) $customer['session_version'];
    // The same customer again on this phone (password change, "log out everywhere else"): it stays linked for push.
    // Anyone else: the app links the phone to the new login on the next page.
    unset($_SESSION['push_device_customer'], $_SESSION['push_device_customer_day']);
    if ($phone > 0 && class_exists('PushService') && PushService::relink($phone, (int) $customer['id'], (int) $customer['session_version'])) {
        $_SESSION['push_device_customer'] = $phone;
    }
    db_exec('UPDATE customers SET last_login_at = NOW() WHERE id = ?', [(int) $customer['id']]);
    current_customer(true);
    if ($remember) {
        remember_issue((int) $customer['id'], (int) $customer['session_version']);
    }
    // Attach any guest carts to the account
    if (class_exists('CartService')) {
        CartService::mergeGuestCarts((int) $customer['id']);
    }
}

function logout_customer(): void
{
    remember_forget_current(customer_id());
    // This phone stops getting the customer's push notifications
    if (!empty($_SESSION['push_device_customer']) && class_exists('PushService')) {
        PushService::detach((int) $_SESSION['push_device_customer'], 'customer');
    }
    unset($_SESSION['customer_id'], $_SESSION['customer_sv'], $_SESSION['push_device_customer'], $_SESSION['push_device_customer_day']);
    session_rotate();
}

// ---------------------------------------------------------------------
// "Keep me logged in" — a long-lived cookie "selector.secret". Only an HMAC of the secret is stored; the secret
// changes every time the cookie logs someone in, and a password change, "log out everywhere", blocking or
// anonymising the account (all raise customers.session_version) makes every token useless.
// ---------------------------------------------------------------------
const GX_REMEMBER_COOKIE = 'GXREMEMBER';
const GX_REMEMBER_DAYS = 60;
/** Seconds the value just replaced still works (requests that left the phone before the new cookie arrived). */
const GX_REMEMBER_GRACE = 120;

/** [selector, secret] from this request's cookie, or null. */
function remember_cookie(): ?array
{
    $v = (string) ($_COOKIE[GX_REMEMBER_COOKIE] ?? '');
    return preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $v, $m) ? [$m[1], $m[2]] : null;
}

function remember_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(GX_REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if ($expires > time()) {
        $_COOKIE[GX_REMEMBER_COOKIE] = $value;
    } else {
        unset($_COOKIE[GX_REMEMBER_COOKIE]);
    }
}

/** Does $secret match one of the stored hashes? (Any key in the ring: tokens survive an APP_KEY change.) */
function remember_secret_matches(string $secret, ?string $storedHash): bool
{
    if ($storedHash === null || $storedHash === '') {
        return false;
    }
    foreach (gx_key_ring() as $k) {
        if (hash_equals($storedHash, hash_hmac('sha256', $secret, $k))) {
            return true;
        }
    }
    return false;
}

/** New token for this device (the cookie is set on this response). */
function remember_issue(int $customerId, int $sessionVersion): void
{
    $selector = random_token(12);
    $secret = random_token(32);
    $expires = time() + GX_REMEMBER_DAYS * 86400;
    db_exec('INSERT INTO customer_remember_tokens (customer_id, selector, validator_hash, session_version, user_agent, expires_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [$customerId, $selector, token_hash($secret), $sessionVersion, user_agent() ?: null, date('Y-m-d H:i:s', $expires)]);
    remember_set_cookie($selector . '.' . $secret, $expires);
}

/** The customer this request's remember cookie belongs to (valid secret; the account state is not checked here). */
function remember_current_customer_id(): ?int
{
    $ck = remember_cookie();
    if (!$ck) {
        return null;
    }
    $row = db_one('SELECT customer_id, validator_hash, prev_validator_hash, rotated_at FROM customer_remember_tokens WHERE selector = ?', [$ck[0]]);
    if (!$row) {
        return null;
    }
    $prevOk = $row['rotated_at'] !== null && strtotime((string) $row['rotated_at']) >= time() - GX_REMEMBER_GRACE
        && remember_secret_matches($ck[1], $row['prev_validator_hash']);
    return remember_secret_matches($ck[1], $row['validator_hash']) || $prevOk ? (int) $row['customer_id'] : null;
}

/**
 * Forget this device: its token row is deleted and the cookie removed. The row goes with the right secret, or —
 * at logout — when it belongs to the customer logging out ($ownerId): if someone else used a copy of the cookie,
 * the secret here is already stale, and their copy must not live on after the owner logs out.
 */
function remember_forget_current(?int $ownerId = null): void
{
    $ck = remember_cookie();
    if ($ck) {
        $owner = db_val('SELECT customer_id FROM customer_remember_tokens WHERE selector = ?', [$ck[0]]);
        $valid = remember_current_customer_id() !== null;
        if ($owner !== null && ($valid || ($ownerId !== null && (int) $owner === $ownerId))) {
            db_exec('DELETE FROM customer_remember_tokens WHERE selector = ?', [$ck[0]]);
            if (!$valid) {
                // The owner's copy is stale: another device used the cookie in the meantime. End that login too.
                db_exec('UPDATE customers SET session_version = session_version + 1 WHERE id = ?', [(int) $owner]);
                gx_log('warning', 'Logout with a remember cookie someone else had used — all logins of the account ended', ['customer' => (int) $owner]);
            }
        }
    }
    if (isset($_COOKIE[GX_REMEMBER_COOKIE])) {
        remember_set_cookie('', time() - 86400);
    }
}

/**
 * Someone who keeps using the site in one long session (an app left open for weeks) is never logged in again by
 * the cookie, so its 60 days would run out while they use it: once a day the same token gets a fresh 60 days.
 */
function remember_refresh_daily(array $ck): void
{
    if (($_SESSION['_remember_day'] ?? '') === date('Y-m-d')) {
        return;
    }
    $_SESSION['_remember_day'] = date('Y-m-d');
    $row = db_one('SELECT id, customer_id, validator_hash FROM customer_remember_tokens WHERE selector = ?', [$ck[0]]);
    if ($row && (int) $row['customer_id'] === customer_id() && remember_secret_matches($ck[1], $row['validator_hash'])) {
        $expires = time() + GX_REMEMBER_DAYS * 86400;
        db_exec('UPDATE customer_remember_tokens SET expires_at = ?, last_used_at = NOW() WHERE id = ?', [date('Y-m-d H:i:s', $expires), $row['id']]);
        remember_set_cookie($ck[0] . '.' . $ck[1], $expires);
    }
}

/**
 * Called by the bootstrap on every request with a session: no logged-in customer but a remember cookie →
 * log them in again with a fresh secret. A value that was replaced more than GX_REMEMBER_GRACE seconds ago
 * means the cookie was copied (the real owner and a thief both used it): the token is destroyed and every
 * login of the account ends.
 */
function remember_autologin(): void
{
    $ck = remember_cookie();
    if (!$ck) {
        if (isset($_COOKIE[GX_REMEMBER_COOKIE])) {
            remember_set_cookie('', time() - 86400); // malformed
        }
        return;
    }
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (current_customer()) {
        remember_refresh_daily($ck);
        return;
    }
    [$selector, $secret] = $ck;
    $result = db_tx(static function () use ($selector, $secret): array {
        $row = db_one('SELECT r.*, c.status, c.session_version AS current_sv FROM customer_remember_tokens r
                       JOIN customers c ON c.id = r.customer_id WHERE r.selector = ? FOR UPDATE', [$selector]);
        if (!$row) {
            return ['clear'];
        }
        $current = remember_secret_matches($secret, $row['validator_hash']);
        $recent = !$current && $row['rotated_at'] !== null && strtotime((string) $row['rotated_at']) >= time() - GX_REMEMBER_GRACE
            && remember_secret_matches($secret, $row['prev_validator_hash']);
        $valid = ($current || $recent) && $row['status'] === 'active' && strtotime((string) $row['expires_at']) > time()
            && (int) $row['session_version'] === (int) $row['current_sv'];
        if (!$valid) {
            db_exec('DELETE FROM customer_remember_tokens WHERE id = ?', [$row['id']]);
            if (!$current && !$recent) {
                // A replaced secret came back: two devices hold this cookie (one is a copy). Every login of the
                // account ends — including a session the copy already opened — and the owner logs in again.
                db_exec('UPDATE customers SET session_version = session_version + 1 WHERE id = ?', [$row['customer_id']]);
                gx_log('warning', 'Remember-me cookie used after it was replaced — all logins of the account ended', ['customer' => (int) $row['customer_id']]);
            }
            return ['clear'];
        }
        if ($recent) {
            // A request that was already on its way: log in, the newer cookie is already with the device
            return ['login', (int) $row['customer_id'], null];
        }
        $newSecret = random_token(32);
        $expires = time() + GX_REMEMBER_DAYS * 86400;
        db_exec('UPDATE customer_remember_tokens SET prev_validator_hash = validator_hash, validator_hash = ?, rotated_at = NOW(),
                 last_used_at = NOW(), expires_at = ?, user_agent = ? WHERE id = ?',
            [token_hash($newSecret), date('Y-m-d H:i:s', $expires), user_agent() ?: null, $row['id']]);
        return ['login', (int) $row['customer_id'], [$selector . '.' . $newSecret, $expires]];
    });
    if ($result[0] === 'clear') {
        remember_set_cookie('', time() - 86400);
        return;
    }
    $customer = db_one('SELECT * FROM customers WHERE id = ?', [$result[1]]);
    session_rotate();
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_sv'] = (int) $customer['session_version'];
    unset($_SESSION['push_device_customer'], $_SESSION['push_device_customer_day']);
    if ($result[2]) {
        remember_set_cookie($result[2][0], $result[2][1]);
    }
    db_exec('UPDATE customers SET last_login_at = NOW() WHERE id = ?', [(int) $customer['id']]);
    current_customer(true);
    if (class_exists('CartService')) {
        CartService::mergeGuestCarts((int) $customer['id']);
    }
}

/** Page guard: redirect to login and come back afterwards. */
function require_customer(): array
{
    $c = current_customer();
    if (!$c) {
        redirect('/account/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/account/'));
    }
    return $c;
}

/** API guard. */
function require_customer_api(): array
{
    $c = current_customer();
    if (!$c) {
        json_error(t('error.login_required'), 401);
    }
    return $c;
}

/**
 * Verify credentials. Uses a dummy hash for unknown emails so response time does not leak
 * whether an account exists.
 */
function attempt_customer_login(string $email, string $password): ?array
{
    $row = db_one('SELECT * FROM customers WHERE email = ?', [mb_strtolower(trim($email))]);
    $hash = $row['password_hash'] ?? gx_dummy_hash();
    $ok = password_verify($password, $hash);
    if (!$row || !$ok || $row['status'] !== 'active') {
        return null;
    }
    if (password_needs_rehash($row['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
        db_exec('UPDATE customers SET password_hash = ? WHERE id = ?', [customer_password_hash($password), $row['id']]);
    }
    return $row;
}

/** Create a single-use token (email verify / password reset). Returns the plain token. */
function customer_token_create(int $customerId, string $purpose, int $ttlMinutes): string
{
    $token = random_token(32);
    db_exec('UPDATE customer_tokens SET used_at = NOW() WHERE customer_id = ? AND purpose = ? AND used_at IS NULL',
        [$customerId, $purpose]);
    db_exec('INSERT INTO customer_tokens (customer_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)',
        [$customerId, $purpose, token_hash($token), date('Y-m-d H:i:s', time() + $ttlMinutes * 60)]);
    return $token;
}

/** Consume a token. Returns the customer id or null. */
function customer_token_consume(string $token, string $purpose): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    // Links sent before the site key changed keep working (their hash was made with an older key)
    $hashes = array_map(static fn(string $k) => hash_hmac('sha256', $token, $k), gx_key_ring());
    return db_tx(static function () use ($hashes, $purpose): ?int {
        $row = db_one('SELECT id, customer_id FROM customer_tokens
                       WHERE token_hash IN (' . db_placeholders($hashes) . ') AND purpose = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE',
            array_merge($hashes, [$purpose]));
        if (!$row) {
            return null;
        }
        db_exec('UPDATE customer_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
        return (int) $row['customer_id'];
    });
}
