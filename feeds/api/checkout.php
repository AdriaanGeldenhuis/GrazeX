<?php
/** GrazeX Feeds — checkout API (shared engine: includes/checkout_api.php). */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

$division = 'feeds';
require GX_ROOT . '/includes/checkout_api.php';
