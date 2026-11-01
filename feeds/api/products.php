<?php
/**
 * GET /feeds/api/products.php — filtered product grid (HTML fragment + counts).
 * Same filters as shop.php: q, category, species, sort, in_stock, specials, page.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');

$filters = [
    'q'        => mb_substr(query_str('q'), 0, 80),
    'category' => preg_replace('/[^a-z0-9-]/', '', query_str('category')),
    'species'  => preg_replace('/[^a-z0-9-]/', '', query_str('species')),
    'sort'     => array_key_exists(query_str('sort'), CatalogService::SORTS) ? query_str('sort') : 'featured',
    'in_stock' => query_str('in_stock') === '1',
    'specials' => query_str('specials') === '1',
    'page'     => max(1, query_int('page', 1)),
    'per_page' => 12,
];
$result = CatalogService::products($filters);

$html = '';
if ($result['items']) {
    $html .= '<div class="p-grid">';
    foreach ($result['items'] as $i => $p) {
        $html .= partial('product-card', ['p' => $p, 'i' => $i]);
    }
    $html .= '</div>' . pagination($result['page'], $result['pages'], '/feeds/shop.php');
} else {
    $html = '<div class="empty-state"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-search"/></svg></div>'
          . '<h3>No products match</h3><p>Try another search or clear the filters.</p><a class="btn" href="/feeds/shop.php">Clear filters</a></div>';
}

json_ok(['html' => $html, 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages']]);
