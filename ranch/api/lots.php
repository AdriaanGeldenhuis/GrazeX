<?php
/**
 * GET /ranch/api/lots.php — filtered lot grid (HTML fragment + counts).
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');
$filters = [
    'status'   => in_array(query_str('status'), ['now', 'soon', 'all'], true) ? query_str('status') : 'now',
    'breed'    => preg_replace('/[^a-z0-9-]/', '', query_str('breed')),
    'category' => preg_replace('/[^a-z0-9-]/', '', query_str('category')),
    'mode'     => in_array(query_str('mode'), ['per_head', 'per_kg'], true) ? query_str('mode') : '',
    'sort'     => array_key_exists(query_str('sort'), LivestockService::SORTS) ? query_str('sort') : 'featured',
    'page'     => max(1, query_int('page', 1)),
    'per_page' => 12,
];
$result = LivestockService::lots($filters);

if ($result['items']) {
    $html = '<div class="l-grid">';
    foreach ($result['items'] as $i => $l) {
        $html .= partial('lot-card', ['l' => $l, 'i' => $i]);
    }
    $html .= '</div>' . pagination($result['page'], $result['pages'], '/ranch/livestock.php');
} else {
    $html = '<div class="empty-state"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-sheep"/></svg></div>'
          . '<h3>No lots match right now</h3><p>Set an alert and we\'ll email you when a matching lot is ready.</p>'
          . '<a class="btn btn-primary" href="/ranch/#notify">Notify me</a></div>';
}
json_ok(['html' => $html, 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages']]);
