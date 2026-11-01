<?php
/**
 * GET /feeds/api/delivery-check.php?town=Name   → zone details (or not delivered)
 * GET /feeds/api/delivery-check.php?suggest=Ri  → town suggestions
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_NO_SESSION', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');
rate_limit_or_fail('delivery_check', client_ip(), 60, 60);

if (isset($_GET['suggest'])) {
    json_ok(['towns' => DeliveryService::suggestTowns(query_str('suggest'))]);
}

$town = mb_substr(query_str('town'), 0, 120);
if ($town === '') {
    json_error('Type your town.', 422, ['town' => 'Type your town.']);
}
$zone = DeliveryService::zoneForTown($town);
$point = DeliveryService::collectionPoint(division_id('feeds'));
json_ok([
    'town' => $town,
    'delivers' => (bool) $zone,
    'zone' => $zone ? DeliveryService::describe($zone) : null,
    'next_dates' => $zone ? array_map(static fn($d) => fmt_date($d, 'D j M'), DeliveryService::deliveryDates($zone, 3)) : [],
    'collection' => $point ? ['name' => $point['name'], 'hours' => $point['hours_text']] : null,
]);
