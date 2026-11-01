<?php
/** GrazeX Ranch — checkout API (shared engine: includes/checkout_api.php). */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

$division = 'ranch';
require GX_ROOT . '/includes/checkout_api.php';
