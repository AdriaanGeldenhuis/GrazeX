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

function customer_password_hash(string $password): string
{
    return defined('PASSWORD_ARGON2ID')
        ? password_hash($password, PASSWORD_ARGON2ID)
        : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function login_customer(array $customer): void
{
    session_rotate();
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_sv'] = (int) $customer['session_version'];
    db_exec('UPDATE customers SET last_login_at = NOW() WHERE id = ?', [(int) $customer['id']]);
    current_customer(true);
    // Attach any guest carts to the account
    if (class_exists('CartService')) {
        CartService::mergeGuestCarts((int) $customer['id']);
    }
}

function logout_customer(): void
{
    unset($_SESSION['customer_id'], $_SESSION['customer_sv']);
    session_rotate();
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
    $hash = $row['password_hash'] ?? '$2y$12$JiwPOQdSNkjju0hlMtB8f.uGaZYGpNx.vwceGuUaCYUgQB1xEerim';
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
    return db_tx(static function () use ($token, $purpose): ?int {
        $row = db_one('SELECT id, customer_id FROM customer_tokens
                       WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE',
            [token_hash($token), $purpose]);
        if (!$row) {
            return null;
        }
        db_exec('UPDATE customer_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
        return (int) $row['customer_id'];
    });
}
