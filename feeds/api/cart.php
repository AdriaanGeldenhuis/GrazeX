<?php
/** Feeds cart API — see includes/cart_api.php for actions. */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

$division = 'feeds';
require GX_ROOT . '/includes/cart_api.php';
