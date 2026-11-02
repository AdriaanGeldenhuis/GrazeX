<?php
/**
 * GrazeX scheduled jobs — ONE dispatcher for Xneelo's Cronjob Manager (runs at most every 2 hours).
 *   Command: /usr/bin/php-wrapper /usr/www/users/YOURUSER/cron/run.php
 * Time-critical work (stock release, payments) also runs on page loads and via the Yoco webhook,
 * so nothing depends on this running exactly on time. The jobs live in CronService.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
define('GX_NO_SESSION', true);
$_SERVER['REQUEST_URI'] = '/cron';
require __DIR__ . '/../includes/bootstrap.php';

$line = CronService::run() ?? sprintf('[%s] cron skipped — the previous run is still busy', date('Y-m-d H:i:s'));
@file_put_contents(GX_ROOT . '/storage/logs/cron.log', $line . "\n", FILE_APPEND | LOCK_EX);
echo $line, "\n";
