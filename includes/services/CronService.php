<?php
/**
 * Scheduled jobs (called by cron/run.php). One run at a time: a database lock keeps two runs from
 * overlapping (a slow run plus the next one, or two cron entries by mistake).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class CronService
{
    /** Minutes after which an email still marked "sending" is put back in the queue (the sender died mid-way). */
    public const STUCK_SENDING_MINUTES = 15;

    /** Run every job once. Returns the log line, or null when another run still holds the lock. */
    public static function run(): ?string
    {
        if (!gx_lock('cron')) {
            return null;
        }
        try {
            $started = microtime(true);
            $out = [];
            $job = static function (string $name, callable $fn) use (&$out): void {
                try {
                    $out[] = $name . ': ' . $fn();
                } catch (Throwable $e) {
                    $out[] = $name . ': FAILED ' . $e->getMessage();
                    gx_log('error', 'Cron job failed: ' . $name, ['error' => $e->getMessage()]);
                }
            };
            $job('expire unpaid orders', static fn() => OrderService::runExpiry(200));
            $job('stuck emails', static fn() => self::requeueStuckEmails() . ' re-queued');
            $job('email queue', static fn() => MailService::processQueue(250) . ' sent');
            $job('low stock digest', static fn() => self::lowStockDigest());
            $job('cleanup', static fn() => self::cleanup());
            $job('privacy retention', static fn() => self::retention());
            setting_set('last_cron_run', now());
            return sprintf('[%s] cron (PHP %s, %.1fs) — %s', date('Y-m-d H:i:s'), PHP_VERSION, microtime(true) - $started, implode(' | ', $out));
        } finally {
            gx_unlock('cron');
        }
    }

    /** Emails left in "sending" (the process died after claiming them) go back in the queue. */
    public static function requeueStuckEmails(): int
    {
        $mins = self::STUCK_SENDING_MINUTES;
        $failed = db_exec("UPDATE email_queue SET status = 'failed', last_error = 'Interrupted while sending (gave up after 5 tries)'
                           WHERE status = 'sending' AND COALESCE(claimed_at, created_at) < NOW() - INTERVAL {$mins} MINUTE AND attempts >= 5");
        return $failed + db_exec("UPDATE email_queue SET status = 'queued', claimed_at = NULL, last_error = 'Interrupted while sending — retried'
                                  WHERE status = 'sending' AND COALESCE(claimed_at, created_at) < NOW() - INTERVAL {$mins} MINUTE");
    }

    /** Daily low-stock digest (first run after 06:00). */
    public static function lowStockDigest(bool $force = false): string
    {
        if (!$force && ((int) date('G') < 6 || setting('last_low_stock_digest') === date('Y-m-d'))) {
            return 'skipped';
        }
        setting_set('last_low_stock_digest', date('Y-m-d'));
        $rows = db_all('SELECT p.name, v.name AS size, v.stock_qty FROM product_variants v JOIN products p ON p.id = v.product_id
                        WHERE p.is_active = 1 AND v.is_active = 1 AND v.stock_qty <= v.low_stock_threshold ORDER BY v.stock_qty');
        if (!$rows) {
            return 'nothing low';
        }
        $to = setting('low_stock_email') ?: (string) env('mail.admin_email', '');
        if ($to !== '') {
            // Markdown list (template uses {{items_md}}); product names are neutralised so they can't break the list
            $clean = static fn(string $s): string => str_replace(['*', '[', ']', '_', '`'], ['∗', '(', ')', ' ', "'"], $s);
            $itemsMd = implode("\n", array_map(static fn($r) => '- ' . $clean($r['name'] . ' (' . $r['size'] . ')') . ': **' . (int) $r['stock_qty'] . '**', $rows));
            $itemsPlain = implode("\n", array_map(static fn($r) => '- ' . $r['name'] . ' (' . $r['size'] . '): ' . (int) $r['stock_qty'], $rows));
            NotificationService::email('admin_low_stock', $to, 'GrazeX admin', ['count' => (string) count($rows), 'items_md' => $itemsMd, 'items' => $itemsPlain],
                division_id('feeds'), [], ['label' => 'Open inventory', 'url' => app_url('/admin/inventory.php?filter=low')], false);
        }
        return count($rows) . ' items';
    }

    /** Housekeeping: expired limits, old guest carts, used tokens, sent emails, read notices, old files. */
    public static function cleanup(): string
    {
        $a = db_exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY');
        $b = db_exec('DELETE FROM carts WHERE customer_id IS NULL AND updated_at < NOW() - INTERVAL 30 DAY');
        $c = db_exec('DELETE FROM customer_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY')
           + db_exec('DELETE FROM admin_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY');
        $d = db_exec("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < NOW() - INTERVAL 90 DAY");
        $e = db_exec('DELETE FROM admin_notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 60 DAY');
        foreach (glob(GX_ROOT . '/storage/private/documents/resend-*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400 * 7) {
                @unlink($f);
            }
        }
        foreach (glob(GX_ROOT . '/storage/logs/*.log') ?: [] as $f) {
            if (filemtime($f) < time() - 86400 * 60) {
                @unlink($f);
            }
        }
        return "rate {$a}, carts {$b}, tokens {$c}, emails {$d}, notices {$e}";
    }

    /**
     * POPIA: personal information is kept no longer than needed. Orders, documents and payments are
     * financial records and are NOT touched here (kept 5 years for SARS).
     */
    public static function retention(): string
    {
        $auditDays = max(90, setting_int('retention_audit_days', 730));
        $enquiryDays = max(30, setting_int('retention_enquiries_days', 730));
        $a = db_exec('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ? DAY', [$auditDays]);
        $b = db_exec('DELETE FROM enquiries WHERE created_at < NOW() - INTERVAL ? DAY', [$enquiryDays]);
        $c = db_exec("DELETE FROM email_queue WHERE status = 'failed' AND created_at < NOW() - INTERVAL 90 DAY");
        // Alert sign-ups that were never confirmed: the email address was given without consent being proven
        $d = db_exec('DELETE FROM livestock_alerts WHERE confirmed_at IS NULL AND created_at < NOW() - INTERVAL 7 DAY');
        return "audit {$a}, enquiries {$b}, failed emails {$c}, unconfirmed alerts {$d}";
    }
}
