<?php
/**
 * GrazeX scheduled jobs — ONE dispatcher for Xneelo's Cronjob Manager (runs at most every 2 hours).
 *   Command: /usr/bin/php-wrapper /usr/www/users/YOURUSER/cron/run.php
 * Time-critical work (stock release, payments) also runs on page loads and via the Yoco webhook,
 * so nothing depends on this running exactly on time.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
define('GX_NO_SESSION', true);
$_SERVER['REQUEST_URI'] = '/cron';
require __DIR__ . '/../includes/bootstrap.php';

$started = microtime(true);
$out = [];
$run = static function (string $name, callable $fn) use (&$out): void {
    try {
        $out[] = $name . ': ' . $fn();
    } catch (Throwable $e) {
        $out[] = $name . ': FAILED ' . $e->getMessage();
        gx_log('error', 'Cron job failed: ' . $name, ['error' => $e->getMessage()]);
    }
};

$run('expire unpaid orders', static fn() => OrderService::expireDue(200) . ' expired');
$run('EFT reminders', static function (): string { OrderService::sendReminders(); return 'ok'; });
$run('email queue', static fn() => MailService::processQueue(250) . ' sent');

// Daily low-stock digest (first run after 06:00)
$run('low stock digest', static function (): string {
    if ((int) date('G') < 6 || setting('last_low_stock_digest') === date('Y-m-d')) {
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
        $items = implode("\n", array_map(static fn($r) => '- ' . $r['name'] . ' (' . $r['size'] . '): **' . (int) $r['stock_qty'] . '**', $rows));
        NotificationService::email('admin_low_stock', $to, 'GrazeX admin', ['count' => (string) count($rows), 'items' => $items], division_id('feeds'), [],
            ['label' => 'Open inventory', 'url' => app_url('/admin/inventory.php?filter=low')], false);
    }
    return count($rows) . ' items';
});

// Housekeeping
$run('cleanup', static function (): string {
    $a = db_exec('DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY');
    $b = db_exec('DELETE FROM carts WHERE customer_id IS NULL AND updated_at < NOW() - INTERVAL 30 DAY');
    $c = db_exec('DELETE FROM customer_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY');
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
});

setting_set('last_cron_run', now());
$line = sprintf('[%s] cron (PHP %s, %.1fs) — %s', date('Y-m-d H:i:s'), PHP_VERSION, microtime(true) - $started, implode(' | ', $out));
@file_put_contents(GX_ROOT . '/storage/logs/cron.log', $line . "\n", FILE_APPEND | LOCK_EX);
echo $line, "\n";
