<?php
/**
 * Push notifications to the GrazeX Android app (Firebase Cloud Messaging, HTTP v1 API).
 *
 * No library: the service-account key signs a short JWT (RS256, openssl) that Google swaps for an access token.
 * Push is "off" until the owner puts the Firebase service-account JSON on the server (storage/private/
 * firebase-service-account.json, or FIREBASE_CREDENTIALS in the settings file). Until then devices are still
 * registered, but nothing is queued.
 *
 * Messages are data-only: the app builds the notification itself (channel, tap opens the right page).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class PushService
{
    /** Pushes one device may get per hour (anything above is dropped, the email still goes). */
    public const MAX_PER_DEVICE_HOUR = 20;
    public const MAX_ATTEMPTS = 5;
    public const STUCK_SENDING_MINUTES = 15;

    /** Admin alert type → the permission a staff member needs to get it on their phone. */
    public const STAFF_TYPES = [
        'new_order'        => 'orders.view',
        'pop'              => 'payments.verify',
        'payment_mismatch' => 'payments.verify',
        'payment_overdue'  => 'payments.verify',
        'refund_due'       => 'payments.refund',
        'refund'           => 'payments.refund',
        'enquiry'          => 'messages.manage',
        'privacy'          => 'customers.manage',
    ];

    public const CHANNELS = ['orders', 'livestock', 'staff'];

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var null|callable(string $url, array $headers, string $body): array{0:int,1:string} */
    private static $transport = null;
    private static array|false|null $credentials = null;
    private static bool $sendScheduled = false;

    // -----------------------------------------------------------------
    // Set-up
    // -----------------------------------------------------------------

    /** Tests: send through $fn instead of the network (null = back to normal). */
    public static function useTransport(?callable $fn): void
    {
        self::$transport = $fn;
    }

    /** Tests: use these credentials instead of the file (null = read the file again). */
    public static function useCredentials(?array $creds): void
    {
        self::$credentials = $creds === null ? null : (self::validCredentials($creds) ?: false);
        @unlink(self::tokenCacheFile());
    }

    public static function credentialsPath(): string
    {
        $p = trim((string) env('firebase.credentials', ''));
        if ($p === '') {
            return GX_ROOT . '/storage/private/firebase-service-account.json';
        }
        return str_starts_with($p, '/') ? $p : GX_ROOT . '/' . $p;
    }

    /** The service-account key, or null when push is not set up (missing, unreadable or incomplete file). */
    public static function credentials(): ?array
    {
        if (self::$credentials === null) {
            $file = self::credentialsPath();
            $json = is_file($file) && is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
            self::$credentials = is_array($json) ? (self::validCredentials($json) ?: false) : false;
        }
        return self::$credentials ?: null;
    }

    private static function validCredentials(array $c): ?array
    {
        foreach (['project_id', 'client_email', 'private_key'] as $k) {
            if (!is_string($c[$k] ?? null) || trim($c[$k]) === '') {
                return null;
            }
        }
        if (($c['type'] ?? 'service_account') !== 'service_account' || !preg_match('/^[a-z0-9-]{4,40}$/', $c['project_id'])
            || !openssl_pkey_get_private($c['private_key'])) {
            return null;
        }
        return $c;
    }

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    // -----------------------------------------------------------------
    // Devices
    // -----------------------------------------------------------------

    /**
     * Link this phone's FCM token to the logged-in customer ('customer') or staff member ('admin').
     * One phone can be linked to a customer and a staff member at the same time. Returns the device id.
     */
    public static function register(string $token, string $who, int $userId, int $sessionVersion, ?string $appVersion = null, string $platform = 'android'): int
    {
        if (!self::validToken($token)) {
            throw new DomainException('This is not a valid device token.');
        }
        if (!in_array($who, ['customer', 'admin'], true) || !in_array($platform, ['android', 'ios', 'web'], true)) {
            throw new InvalidArgumentException('Unknown device owner or platform.');
        }
        $appVersion = $appVersion !== null && preg_match('/^[0-9A-Za-z.\-+]{1,20}$/', $appVersion) ? $appVersion : null;
        $hash = hash('sha256', $token);
        [$idCol, $svCol] = $who === 'customer' ? ['customer_id', 'customer_sv'] : ['admin_user_id', 'admin_sv'];
        $staffRows = $who === 'customer' ? "channel <> 'staff'" : "channel = 'staff'";
        return db_tx(static function () use ($token, $hash, $idCol, $svCol, $userId, $sessionVersion, $appVersion, $platform, $staffRows): int {
            $row = db_one("SELECT id, {$idCol} AS owner FROM push_devices WHERE token_hash = ? FOR UPDATE", [$hash]);
            if ($row) {
                $id = (int) $row['id'];
                if ((int) $row['owner'] !== $userId) {
                    // The phone changes hands (a shared farm phone): nothing of the previous person's may still
                    // arrive on it or show up in the new person's data download
                    db_exec("DELETE FROM push_queue WHERE device_id = ? AND {$staffRows}", [$id]);
                }
                db_exec("UPDATE push_devices SET {$idCol} = ?, {$svCol} = ?, platform = ?, app_version = ?, last_seen_at = NOW() WHERE id = ?",
                    [$userId, $sessionVersion, $platform, $appVersion, $id]);
                return $id;
            }
            return db_insert("INSERT INTO push_devices ({$idCol}, {$svCol}, platform, app_version, token_hash, token, last_seen_at)
                              VALUES (?, ?, ?, ?, ?, ?, NOW())", [$userId, $sessionVersion, $platform, $appVersion, $hash, $token]);
        });
    }

    /** The FCM token format (Google does not publish one; this is generous but keeps junk out). */
    public static function validToken(string $token): bool
    {
        return strlen($token) >= 20 && strlen($token) <= 4096 && preg_match('/^[A-Za-z0-9_:\-.]+$/', $token) === 1;
    }

    /** Stop sending to this phone for the customer or the staff member (logout). Their pushes for it (waiting or sent) go. */
    public static function detach(int $deviceId, string $who): void
    {
        if ($who === 'customer') {
            db_exec("DELETE FROM push_queue WHERE device_id = ? AND channel <> 'staff'", [$deviceId]);
            db_exec('UPDATE push_devices SET customer_id = NULL, customer_sv = NULL WHERE id = ?', [$deviceId]);
        } elseif ($who === 'admin') {
            db_exec("DELETE FROM push_queue WHERE device_id = ? AND channel = 'staff'", [$deviceId]);
            db_exec('UPDATE push_devices SET admin_user_id = NULL, admin_sv = NULL WHERE id = ?', [$deviceId]);
        }
        db_exec('DELETE FROM push_devices WHERE id = ? AND customer_id IS NULL AND admin_user_id IS NULL', [$deviceId]);
    }

    /**
     * Is this session's phone link still in place? (The phone may have been forgotten: app uninstalled, not used for
     * 60 days, a password change.) Also marks the phone as in use — at most once a day per session. A link that is
     * gone is dropped from the session, so the page links the phone again. 0 = deliberately not linked.
     */
    public static function checkLink(string $who): bool
    {
        $key = 'push_device_' . $who;
        if (!isset($_SESSION[$key])) {
            return false;
        }
        $id = (int) $_SESSION[$key];
        if ($id === 0 || ($_SESSION[$key . '_day'] ?? '') === date('Y-m-d')) {
            return true;
        }
        [$idCol, $svCol, $table] = $who === 'customer' ? ['customer_id', 'customer_sv', 'customers'] : ['admin_user_id', 'admin_sv', 'admin_users'];
        $ok = (bool) db_val("SELECT 1 FROM push_devices d JOIN {$table} u ON u.id = d.{$idCol}
                             WHERE d.id = ? AND d.{$svCol} = u.session_version AND u.status = 'active'", [$id]);
        if (!$ok) {
            unset($_SESSION[$key], $_SESSION[$key . '_day']);
            return false;
        }
        db_exec('UPDATE push_devices SET last_seen_at = NOW() WHERE id = ?', [$id]);
        $_SESSION[$key . '_day'] = date('Y-m-d');
        return true;
    }

    /** The same customer logged in again on this phone (password change, "log out everywhere else"): keep it linked. */
    public static function relink(int $deviceId, int $customerId, int $sessionVersion): bool
    {
        if (!db_val('SELECT 1 FROM push_devices WHERE id = ? AND customer_id = ?', [$deviceId, $customerId])) {
            return false;
        }
        db_exec('UPDATE push_devices SET customer_sv = ?, last_seen_at = NOW() WHERE id = ?', [$sessionVersion, $deviceId]);
        return true;
    }

    /** The same, by token — only if that token is linked to this user. */
    public static function detachToken(string $token, string $who, int $userId): bool
    {
        $col = $who === 'customer' ? 'customer_id' : 'admin_user_id';
        $id = db_val("SELECT id FROM push_devices WHERE token_hash = ? AND {$col} = ?", [hash('sha256', $token), $userId]);
        if (!$id) {
            return false;
        }
        self::detach((int) $id, $who);
        return true;
    }

    /** Every phone of this customer stops getting their pushes (account anonymised). */
    public static function forgetCustomer(int $customerId): void
    {
        foreach (db_col('SELECT id FROM push_devices WHERE customer_id = ?', [$customerId]) as $id) {
            self::detach((int) $id, 'customer');
        }
    }

    // -----------------------------------------------------------------
    // Queueing
    // -----------------------------------------------------------------

    /**
     * Push to every phone where this customer is logged in to the app (only logins still valid: a password change or
     * "log out everywhere" stops it). Text comes from config/lang (push.<key>.title / .body). Returns the number queued.
     */
    public static function toCustomer(int $customerId, string $key, array $vars, ?string $url = null, ?string $tag = null, string $channel = 'orders'): int
    {
        if (!self::configured()) {
            return 0;
        }
        $title = self::text('push.' . $key . '.title', $vars);
        $body = self::text('push.' . $key . '.body', $vars);
        if ($title === null || $body === null) {
            return 0; // no push for this kind of email
        }
        $devices = db_col("SELECT d.id FROM push_devices d JOIN customers c ON c.id = d.customer_id
                           WHERE d.customer_id = ? AND c.status = 'active' AND d.customer_sv = c.session_version", [$customerId]);
        return self::enqueueAll($devices, $channel, $title, $body, $url, $tag);
    }

    /**
     * Push an admin alert to the staff who may act on it (permission and division), on their own phones.
     * Only the alert's title travels (order numbers, amounts): names, emails and messages stay behind the admin
     * login — the push passes through Google and shows on a lock screen. The link opens the details.
     */
    public static function toStaff(string $type, string $title, ?string $body, ?string $link, ?int $divisionId): int
    {
        $perm = self::STAFF_TYPES[$type] ?? null;
        if ($perm === null || !self::configured()) {
            return 0;
        }
        $staff = db_all("SELECT a.id, a.division_scope, r.slug AS role_slug,
                                EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                                       WHERE rp.role_id = a.role_id AND p.slug = ?) AS allowed
                         FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.status = 'active'", [$perm]);
        $ids = [];
        foreach ($staff as $s) {
            $inDivision = $divisionId === null || $s['division_scope'] === 'all' || division_id((string) $s['division_scope']) === $divisionId;
            if (($s['role_slug'] === 'owner' || (int) $s['allowed'] === 1) && $inDivision) {
                $ids[] = (int) $s['id'];
            }
        }
        if (!$ids) {
            return 0;
        }
        $devices = db_col('SELECT d.id FROM push_devices d JOIN admin_users a ON a.id = d.admin_user_id
                           WHERE d.admin_user_id IN (' . db_placeholders($ids) . ') AND d.admin_sv = a.session_version', $ids);
        $tag = $type . '-' . substr(hash('sha256', (string) ($link ?? $title)), 0, 12);
        $pushTitle = match ($type) {
            'enquiry' => 'New customer message', // the admin title carries the sender's name
            default => $title,
        };
        return self::enqueueAll($devices, 'staff', $pushTitle, 'Tap to open it in the GrazeX admin.', $link, $tag);
    }

    /** A push to one staff member's own phones (the "Send test notification" button). */
    public static function toAdminUser(int $adminId, string $title, string $body, ?string $link = null): int
    {
        if (!self::configured()) {
            return 0;
        }
        $devices = db_col("SELECT d.id FROM push_devices d JOIN admin_users a ON a.id = d.admin_user_id
                           WHERE d.admin_user_id = ? AND a.status = 'active' AND d.admin_sv = a.session_version", [$adminId]);
        return self::enqueueAll($devices, 'staff', $title, $body, $link, 'test-' . $adminId);
    }

    private static function text(string $key, array $vars): ?string
    {
        $text = t($key, array_filter($vars, static fn($v) => is_scalar($v)));
        return $text === $key ? null : $text;
    }

    private static function enqueueAll(array $deviceIds, string $channel, string $title, string $body, ?string $url, ?string $tag): int
    {
        $channel = in_array($channel, self::CHANNELS, true) ? $channel : 'orders';
        $url = $url !== null && preg_match('#^/(?!/)[^\s]*$#', $url) ? mb_substr($url, 0, 500) : null; // a path on this site only
        $n = 0;
        foreach ($deviceIds as $deviceId) {
            $recent = (int) db_val('SELECT COUNT(*) FROM push_queue WHERE device_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$deviceId]);
            if ($recent >= self::MAX_PER_DEVICE_HOUR) {
                continue;
            }
            db_exec('INSERT INTO push_queue (device_id, channel, title, body, url, tag) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $deviceId, $channel, mb_substr($title, 0, 120), mb_substr($body, 0, 400), $url, $tag !== null ? mb_substr($tag, 0, 64) : null]);
            $n++;
        }
        if ($n && !self::$sendScheduled) {
            // Sent once the visitor has their answer (straight away in cron and tests)
            self::$sendScheduled = true;
            gx_after_response('push send', static function (): void {
                self::$sendScheduled = false;
                self::sendQueued(50);
            });
        }
        return $n;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /** Send what is due. Returns the number sent. In tests nothing leaves the machine unless a transport is set. */
    public static function sendQueued(int $limit = 100): int
    {
        $creds = self::credentials();
        if ($creds === null || (self::$transport === null && (defined('GX_TESTING') || defined('GX_TEST_DB_NAME')))) {
            return 0;
        }
        // Who the phone belongs to NOW: a push queued before a logout, "log out everywhere", a block or a disabled
        // staff account must not go out any more
        $rows = db_all("SELECT q.*, d.token,
                               CASE WHEN q.channel = 'staff'
                                    THEN (a.status = 'active' AND d.admin_sv = a.session_version)
                                    ELSE (c.status = 'active' AND d.customer_sv = c.session_version) END AS still_theirs
                        FROM push_queue q JOIN push_devices d ON d.id = q.device_id
                        LEFT JOIN customers c ON c.id = d.customer_id LEFT JOIN admin_users a ON a.id = d.admin_user_id
                        WHERE q.status = 'queued' AND q.send_after <= NOW() ORDER BY q.id LIMIT " . max(1, $limit));
        $sent = 0;
        $retriedAuth = false;
        foreach ($rows as $row) {
            if ((int) $row['still_theirs'] !== 1) {
                db_exec("UPDATE push_queue SET status = 'failed', last_error = 'The login on this phone ended before it was sent' WHERE id = ? AND status = 'queued'", [$row['id']]);
                continue;
            }
            if (db_exec("UPDATE push_queue SET status = 'sending', attempts = attempts + 1, claimed_at = NOW() WHERE id = ? AND status = 'queued'", [$row['id']]) !== 1) {
                continue;
            }
            $token = self::accessToken($creds);
            [$status, $resp] = $token === null ? [0, 'Could not get an access token from Google'] : self::post($creds, $token, $row);
            if ($status === 401 && !$retriedAuth) {
                // The cached access token was revoked or expired early: get a new one and try once more
                $retriedAuth = true;
                $token = self::accessToken($creds, true);
                [$status, $resp] = $token === null ? [0, 'Could not get an access token from Google'] : self::post($creds, $token, $row);
            }
            $outcome = self::outcome($status, $resp);
            if ($outcome === 'sent') {
                db_exec("UPDATE push_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?", [$row['id']]);
                $sent++;
                continue;
            }
            $error = mb_substr('HTTP ' . $status . ': ' . self::errorMessage($resp), 0, 255);
            if ($outcome === 'gone') {
                // The app was uninstalled or the token is no longer valid: forget the phone
                db_exec('DELETE FROM push_devices WHERE id = ?', [$row['device_id']]);
                continue;
            }
            $attempts = (int) $row['attempts'] + 1;
            if ($outcome === 'retry' && $attempts < self::MAX_ATTEMPTS) {
                db_exec("UPDATE push_queue SET status = 'queued', last_error = ?, send_after = NOW() + INTERVAL ? MINUTE WHERE id = ?",
                    [$error, 2 ** $attempts, $row['id']]);
            } else {
                db_exec("UPDATE push_queue SET status = 'failed', last_error = ? WHERE id = ?", [$error, $row['id']]);
            }
            gx_log($outcome === 'config' ? 'error' : 'warning', $outcome === 'config'
                ? 'Push refused: the Firebase service-account file is for another project than the app (google-services.json)'
                : 'Push not sent', ['id' => $row['id'], 'error' => $error]);
            if ($outcome !== 'failed') {
                // Google busy or unreachable, or a credentials problem: stop here (a page or cron run must never wait
                // on one timeout after another); the rest is tried on the next run
                break;
            }
        }
        return $sent;
    }

    /**
     * 'sent'; 'gone' (forget the device); 'retry' (Google busy / network / access token); 'config' (the server's
     * Firebase project is not the app's — a set-up mistake, so no phone is forgotten); 'failed' (this message never works).
     */
    private static function outcome(int $status, string $resp): string
    {
        if ($status >= 200 && $status < 300) {
            return 'sent';
        }
        $err = json_decode($resp, true)['error'] ?? [];
        $codes = [];
        foreach ((array) ($err['details'] ?? []) as $d) {
            if (is_array($d) && isset($d['errorCode'])) {
                $codes[] = (string) $d['errorCode'];
            }
        }
        $message = (string) ($err['message'] ?? '');
        if (in_array('SENDER_ID_MISMATCH', $codes, true)) {
            return 'config';
        }
        if ($status === 404 || in_array('UNREGISTERED', $codes, true) || ($status === 400 && stripos($message, 'registration token') !== false)) {
            return 'gone';
        }
        if ($status === 0 || $status === 401 || $status === 429 || $status >= 500) {
            return 'retry';
        }
        return 'failed';
    }

    private static function errorMessage(string $resp): string
    {
        $msg = json_decode($resp, true)['error']['message'] ?? null;
        return is_string($msg) ? $msg : mb_substr(trim($resp), 0, 200);
    }

    /** One FCM v1 request. Data-only: the app shows it (all values must be strings). */
    private static function post(array $creds, string $accessToken, array $row): array
    {
        $data = ['title' => (string) $row['title'], 'body' => (string) $row['body'], 'channel' => (string) $row['channel']];
        if ($row['url'] !== null) {
            $data['url'] = (string) $row['url'];
        }
        if ($row['tag'] !== null) {
            $data['tag'] = (string) $row['tag'];
        }
        $message = ['token' => (string) $row['token'], 'data' => $data, 'android' => ['priority' => 'HIGH', 'ttl' => '86400s']];
        if ($row['tag'] !== null) {
            $message['android']['collapse_key'] = (string) $row['tag'];
        }
        return self::http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($creds['project_id']) . '/messages:send',
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json; charset=utf-8'],
            json_encode(['message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function tokenCacheFile(): string
    {
        return GX_ROOT . '/storage/private/fcm-access-token.json';
    }

    /** Google access token (cached for its lifetime minus 5 minutes, per service account). */
    private static function accessToken(array $creds, bool $refresh = false): ?string
    {
        $file = self::tokenCacheFile();
        if (!$refresh && is_file($file)) {
            $c = json_decode((string) @file_get_contents($file), true);
            if (is_array($c) && ($c['email'] ?? '') === $creds['client_email'] && (int) ($c['expires'] ?? 0) > time() + 60 && is_string($c['token'] ?? null)) {
                return $c['token'];
            }
        }
        $now = time();
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . $b64(json_encode(['iss' => $creds['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URI, 'iat' => $now, 'exp' => $now + 3600]));
        $key = openssl_pkey_get_private($creds['private_key']);
        if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        [$status, $resp] = self::http(self::TOKEN_URI, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($signature)]));
        $json = json_decode($resp, true);
        if ($status !== 200 || !is_string($json['access_token'] ?? null)) {
            gx_log('error', 'Firebase access token refused', ['status' => $status, 'error' => self::errorMessage($resp)]);
            return null;
        }
        $life = max(120, (int) ($json['expires_in'] ?? 3600));
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['email' => $creds['client_email'], 'token' => $json['access_token'], 'expires' => $now + $life - 300])) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
        return $json['access_token'];
    }

    /** POST; returns [HTTP status (0 = network error), body]. */
    private static function http(string $url, array $headers, string $body): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $resp = curl_exec($ch);
        $status = $resp === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $resp === false ? $err : (string) $resp];
    }

    // -----------------------------------------------------------------
    // Housekeeping (cron) and status (admin)
    // -----------------------------------------------------------------

    /** Pushes left in "sending" (the process died mid-way) go back in the queue. */
    public static function requeueStuck(): int
    {
        $mins = self::STUCK_SENDING_MINUTES;
        return db_exec("UPDATE push_queue SET status = IF(attempts >= " . self::MAX_ATTEMPTS . ", 'failed', 'queued'), claimed_at = NULL,
                        last_error = 'Interrupted while sending' WHERE status = 'sending' AND COALESCE(claimed_at, created_at) < NOW() - INTERVAL {$mins} MINUTE");
    }

    /**
     * Old queue rows, and phones whose app was not opened for as long as "keep me logged in" lasts (the app links the
     * phone again each time it starts, so an app still in use is never forgotten).
     */
    public static function cleanup(): string
    {
        $q = db_exec("DELETE FROM push_queue WHERE created_at < NOW() - INTERVAL 30 DAY AND status IN ('sent','failed')")
           + db_exec("DELETE FROM push_queue WHERE status = 'queued' AND created_at < NOW() - INTERVAL 2 DAY"); // too late to be useful
        $d = db_exec('DELETE FROM push_devices WHERE COALESCE(last_seen_at, created_at) < NOW() - INTERVAL ? DAY', [GX_REMEMBER_DAYS]);
        return "queue {$q}, devices {$d}";
    }

    /** For Admin → Settings. */
    public static function stats(): array
    {
        return [
            'configured' => self::configured(),
            'customer_devices' => (int) db_val('SELECT COUNT(*) FROM push_devices WHERE customer_id IS NOT NULL'),
            'staff_devices' => (int) db_val('SELECT COUNT(*) FROM push_devices WHERE admin_user_id IS NOT NULL'),
            'queued' => (int) db_val("SELECT COUNT(*) FROM push_queue WHERE status IN ('queued','sending')"),
            'failed' => (int) db_val("SELECT COUNT(*) FROM push_queue WHERE status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY"),
            'sent' => (int) db_val("SELECT COUNT(*) FROM push_queue WHERE status = 'sent' AND sent_at > NOW() - INTERVAL 7 DAY"),
        ];
    }
}
