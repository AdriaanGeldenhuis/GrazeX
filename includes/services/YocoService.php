<?php
/**
 * Yoco Checkout API (https://payments.yoco.com) — plain cURL, no SDK.
 *
 *  - Create checkout:  POST /api/checkouts  (Bearer secret key, amount in cents, ZAR, Idempotency-Key)
 *  - Confirm payment:  webhook payment.succeeded (signature: HMAC-SHA256 over "id.timestamp.body",
 *                      key = base64-decoded whsec_ secret, header "webhook-signature: v1,<sig> ...")
 *  - Fallback check:   GET /api/checkouts/{id} (used by Yoco's own plugin; not in the public reference)
 *
 * Live payments only work from a verified domain and the browser must send a Referer, so the
 * redirect is done with JavaScript from our own https page (/payments/pay.php).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class YocoService
{
    private const BASE = 'https://payments.yoco.com';

    public static function mode(): string
    {
        return env('yoco.mode', 'test') === 'live' ? 'live' : 'test';
    }

    private static function secretKey(?string $mode = null): string
    {
        return (string) env('yoco.' . ($mode ?? self::mode()) . '_secret_key', '');
    }

    public static function configured(): bool
    {
        return self::secretKey() !== '';
    }

    /**
     * Create (or reuse) a checkout for the amount currently due on an order.
     * Returns the payments row (with redirect_url).
     */
    public static function checkoutForOrder(array $order): array
    {
        $due = OrderService::amountDue($order);
        if ($due < 200) {
            throw new DomainException($due <= 0 ? 'Nothing is due on this order.' : 'Card payments must be at least R2.00.');
        }
        // Reuse a recent pending checkout for the same amount (avoids duplicate checkouts on refresh)
        $existing = db_one("SELECT * FROM payments WHERE order_id = ? AND method = 'yoco' AND status = 'pending' AND amount_cents = ?
                            AND redirect_url IS NOT NULL AND created_at > NOW() - INTERVAL 30 MINUTE ORDER BY id DESC LIMIT 1", [$order['id'], $due]);
        if ($existing) {
            return $existing;
        }
        $isDeposit = (int) $order['division_id'] === division_id('ranch') && $order['status'] === 'reserved' && (int) $order['deposit_due_cents'] > 0;
        $purpose = $isDeposit ? 'deposit' : ((int) $order['amount_paid_cents'] > 0 ? 'balance' : 'full');
        $idem = uuid4();
        $paymentId = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, idempotency_key, provider_mode, reference)
                                VALUES (?, ?, 'yoco', ?, ?, 'pending', ?, ?, ?)",
            [$order['id'], $order['division_id'], $purpose, $due, $idem, self::mode(), $order['order_no']]);

        $base = app_url('/payments/return.php?t=' . $order['pay_token']);
        try {
            $res = self::request('POST', '/api/checkouts', [
                'amount' => $due,
                'currency' => 'ZAR',
                'successUrl' => $base . '&r=success',
                'cancelUrl' => $base . '&r=cancel',
                'failureUrl' => $base . '&r=failed',
                'externalId' => (string) $paymentId,
                'clientReferenceId' => $order['order_no'],
                'metadata' => ['order_no' => $order['order_no'], 'payment_id' => (string) $paymentId, 'purpose' => $purpose],
            ], $idem);
        } catch (Throwable $e) {
            gx_log('error', 'Yoco checkout create failed', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
            $res = [];
        }
        if (empty($res['id']) || empty($res['redirectUrl'])) {
            db_exec("UPDATE payments SET status = 'failed', notes = ? WHERE id = ?", ['Checkout create failed', $paymentId]);
            throw new RuntimeException('Card payments are unavailable right now. Please try again or choose EFT.');
        }
        db_exec('UPDATE payments SET provider_checkout_id = ?, redirect_url = ? WHERE id = ?', [$res['id'], $res['redirectUrl'], $paymentId]);
        // Keep the stock while the customer pays — but only for the first few card attempts (no endless holds)
        if ((int) db_val("SELECT COUNT(*) FROM payments WHERE order_id = ? AND method = 'yoco' AND provider_checkout_id IS NOT NULL", [$order['id']]) <= 3) {
            db_exec("UPDATE orders SET hold_expires_at = GREATEST(COALESCE(hold_expires_at, NOW()), NOW() + INTERVAL ? MINUTE) WHERE id = ? AND status IN ('pending_payment','reserved')",
                [setting_int('yoco_hold_minutes', 60), $order['id']]);
        }
        return db_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    }

    /** Fallback: ask Yoco for a checkout's status. Returns ['status' => ..., 'paymentId' => ...] or null. */
    public static function fetchCheckout(string $checkoutId, string $mode): ?array
    {
        try {
            return self::request('GET', '/api/checkouts/' . rawurlencode($checkoutId), null, null, $mode);
        } catch (Throwable $e) {
            gx_log('warning', 'Yoco checkout fetch failed', ['id' => $checkoutId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Mark a pending Yoco payment as paid (idempotent). Used by webhook and fallback check. */
    public static function settle(array $payment, int $amount, ?string $providerPaymentId, string $source): void
    {
        if ($payment['status'] === 'succeeded') {
            return;
        }
        // A test-mode checkout never counts on a live site (and the other way round)
        if (!empty($payment['provider_mode']) && $payment['provider_mode'] !== self::mode()) {
            $note = 'Paid in Yoco ' . $payment['provider_mode'] . ' mode while the site is in ' . self::mode() . ' mode — not recorded';
            if (db_exec('UPDATE payments SET notes = ? WHERE id = ? AND (notes IS NULL OR notes <> ?)', [$note, $payment['id'], $note]) === 1) {
                NotificationService::admin('payment_mismatch', 'Card payment in the wrong Yoco mode on ' . $payment['reference'], $note . '.',
                    '/admin/order.php?id=' . $payment['order_id'], (int) $payment['division_id']);
            }
            return;
        }
        if ($amount !== (int) $payment['amount_cents']) {
            // One alert per payment (status polls and webhook retries would otherwise repeat it)
            if (db_exec("UPDATE payments SET notes = 'Amount mismatch — check manually' WHERE id = ? AND (notes IS NULL OR notes <> 'Amount mismatch — check manually')", [$payment['id']]) === 1) {
                NotificationService::admin('payment_mismatch', 'Card payment amount mismatch on ' . $payment['reference'],
                    'Expected ' . money((int) $payment['amount_cents']) . ', Yoco reported ' . money($amount) . '. Check the Yoco portal.',
                    '/admin/order.php?id=' . $payment['order_id'], (int) $payment['division_id']);
            }
            return;
        }
        try {
            OrderService::recordPayment((int) $payment['order_id'], $amount, 'yoco', (int) $payment['id'],
                ['provider_payment_id' => $providerPaymentId, 'notes' => 'Confirmed via ' . $source]);
        } catch (LatePaymentException $e) {
            // The card was charged but the order is closed (expired with stock gone, cancelled or refunded):
            // record the money once, never invoice it, and tell the admin to refund.
            $recorded = db_tx(static function () use ($payment, $amount, $providerPaymentId, $e): bool {
                $n = db_exec("UPDATE payments SET status = 'succeeded', provider_payment_id = COALESCE(?, provider_payment_id), verified_at = NOW(),
                              notes = 'Paid after the order closed — refund required' WHERE id = ? AND status <> 'succeeded'",
                    [$providerPaymentId, $payment['id']]);
                if ($n !== 1) {
                    return false;
                }
                // payment_status is assigned first so it reads the old amount_paid_cents (MySQL assigns left to right)
                db_exec("UPDATE orders SET payment_status = IF(amount_paid_cents + ? >= total_cents, 'paid', 'partial'),
                         amount_paid_cents = amount_paid_cents + ?, admin_note = CONCAT(COALESCE(admin_note, ''), ?) WHERE id = ?",
                    [$amount, $amount, "\n[" . now() . '] ' . $e->getMessage() . ' Refund via the Yoco portal.', $payment['order_id']]);
                return true;
            });
            if ($recorded) {
                NotificationService::admin('refund_due', 'Refund needed: ' . $payment['reference'], $e->getMessage(), '/admin/order.php?id=' . $payment['order_id'], (int) $payment['division_id']);
            }
        }
    }

    /**
     * Verify webhook signature (Standard Webhooks scheme used by Yoco).
     * Returns the mode whose secret matched ('live' | 'test') or null.
     */
    public static function verifySignature(string $rawBody, string $id, string $timestamp, string $signatureHeader): ?string
    {
        if ($id === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return null;
        }
        $signed = $id . '.' . $timestamp . '.' . $rawBody;
        foreach (['live', 'test'] as $mode) {
            $secret = self::webhookSecret($mode);
            if ($secret === '') {
                continue;
            }
            $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
            if ($key === false) {
                continue;
            }
            $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));
            foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $part) {
                [$ver, $sig] = array_pad(explode(',', $part, 2), 2, '');
                if ($ver === 'v1' && hash_equals($expected, $sig)) {
                    return $mode;
                }
            }
        }
        return null;
    }

    /** Webhook secret for a mode: config/env.php first, else the encrypted one saved by "Connect Yoco webhook". */
    public static function webhookSecret(string $mode): string
    {
        $secret = (string) env('yoco.' . $mode . '_webhook_secret', '');
        return $secret !== '' ? $secret : (string) secret_decrypt(setting('yoco_' . $mode . '_webhook_secret_enc'));
    }

    /** Register our webhook URL with Yoco for the current mode and store the signing secret (encrypted). */
    public static function registerWebhook(): array
    {
        $mode = self::mode();
        $url = app_url('/payments/api/yoco-webhook.php');
        if (!str_starts_with($url, 'https://')) {
            throw new DomainException('The site must run on https:// before Yoco can send payment confirmations.');
        }
        $existing = self::request('GET', '/api/webhooks', null);
        foreach ((array) ($existing['subscriptions'] ?? []) as $sub) {
            if (($sub['url'] ?? '') === $url && self::webhookSecret($mode) !== '') {
                return ['mode' => $mode, 'url' => $url, 'already' => true];
            }
            if (($sub['url'] ?? '') === $url && !empty($sub['id'])) {
                self::request('DELETE', '/api/webhooks/' . rawurlencode((string) $sub['id']), null);
            }
        }
        $res = self::request('POST', '/api/webhooks', ['name' => 'GrazeX website', 'url' => $url]);
        if (empty($res['secret'])) {
            throw new RuntimeException('Yoco did not return a webhook secret.');
        }
        setting_set('yoco_' . $mode . '_webhook_secret_enc', secret_encrypt((string) $res['secret']));
        setting_set('yoco_' . $mode . '_webhook_connected_at', now());
        return ['mode' => $mode, 'url' => $url, 'already' => false];
    }

    private static function request(string $method, string $path, ?array $body, ?string $idempotencyKey = null, ?string $mode = null): array
    {
        $key = self::secretKey($mode);
        if ($key === '') {
            throw new RuntimeException('Yoco is not configured.');
        }
        $ch = curl_init(self::BASE . $path);
        $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($raw === false) {
            throw new RuntimeException('Yoco request failed: ' . $err);
        }
        $json = json_decode((string) $raw, true) ?: [];
        if ($code < 200 || $code >= 300) {
            gx_log('error', 'Yoco API error', ['code' => $code, 'path' => $path, 'body' => mb_substr((string) $raw, 0, 500)]);
            throw new RuntimeException('Yoco error ' . $code . ': ' . ($json['message'] ?? 'unknown'));
        }
        return $json;
    }
}
