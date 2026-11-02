<?php
/**
 * Admin authentication + role-based permissions + optional TOTP 2FA.
 * Admin sessions are stored under separate keys from customer sessions.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function current_admin(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id || !empty($_SESSION['admin_2fa_pending'])) {
        return null;
    }
    // Idle timeout, and an absolute limit however active the session is
    if (time() - (int) ($_SESSION['admin_seen'] ?? 0) > GX_ADMIN_IDLE_SECONDS
        || time() - (int) ($_SESSION['admin_since'] ?? 0) > GX_ADMIN_MAX_SECONDS) {
        unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['admin_seen'], $_SESSION['admin_since']);
        return null;
    }
    $row = db_one('SELECT a.id, a.email, a.name, a.phone, a.role_id, a.division_scope, a.totp_enabled, a.status,
                          a.session_version, r.slug AS role_slug, r.name AS role_name
                   FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [(int) $id]);
    if (!$row || $row['status'] !== 'active' || (int) $row['session_version'] !== (int) ($_SESSION['admin_sv'] ?? 0)) {
        unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['admin_seen']);
        return null;
    }
    $_SESSION['admin_seen'] = time();
    $row['permissions'] = db_col('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                                  WHERE rp.role_id = ?', [(int) $row['role_id']]);
    $cache = $row;
    return $cache;
}

function admin_can(string $permission): bool
{
    $a = current_admin();
    return $a !== null && ($a['role_slug'] === 'owner' || in_array($permission, $a['permissions'], true));
}

/** Division ids this admin may see (scope 'all' → both). */
function admin_division_ids(): array
{
    $a = current_admin();
    if (!$a) {
        return [];
    }
    return $a['division_scope'] === 'all'
        ? array_column(GX_DIVISIONS, 'id')
        : [division_id($a['division_scope'])];
}

function login_admin(array $admin): void
{
    session_rotate();
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_sv'] = (int) $admin['session_version'];
    $_SESSION['admin_seen'] = time();
    $_SESSION['admin_since'] = time();
    unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_2fa_at']);
    db_exec('UPDATE admin_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [client_ip(), (int) $admin['id']]);
    current_admin(true);
    audit('admin.login', 'admin_user', (int) $admin['id']);
}

function logout_admin(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_sv'], $_SESSION['admin_seen'], $_SESSION['admin_since'], $_SESSION['admin_2fa_pending'],
        $_SESSION['admin_2fa_at'], $_SESSION['totp_setup'], $_SESSION['totp_recovery_show']);
    session_rotate();
}

/** Page guard. */
function require_admin(?string $permission = null): array
{
    $a = current_admin();
    if (!$a) {
        redirect('/admin/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    }
    if ($permission !== null && !admin_can($permission)) {
        http_response_code(403);
        require GX_ROOT . '/includes/layout/forbidden.php';
        exit;
    }
    return $a;
}

/** API guard. */
function require_admin_api(?string $permission = null): array
{
    $a = current_admin();
    if (!$a) {
        json_error('Your admin session has expired. Please log in again.', 401);
    }
    if ($permission !== null && !admin_can($permission)) {
        json_error('You do not have permission to do that.', 403);
    }
    return $a;
}

function admin_password_hash(string $password): string
{
    return customer_password_hash($password);
}
