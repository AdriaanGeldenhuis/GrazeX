<?php
/**
 * GET /feeds/api/search.php?q= — instant search suggestions.
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_NO_SESSION', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');
$q = mb_substr(query_str('q'), 0, 80);
if (mb_strlen($q) < 2) {
    json_ok(['items' => []]);
}
rate_limit_or_fail('search', rate_limit_ip(), 90, 60);
json_ok(['items' => CatalogService::suggest($q, 6), 'all_url' => '/feeds/shop.php?q=' . rawurlencode($q)]);
