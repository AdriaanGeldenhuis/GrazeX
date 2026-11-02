<?php
/** XML sitemap for search engines (/sitemap.xml). */
declare(strict_types=1);

define('GX_NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$urls = [['/', null], ['/feeds/', null], ['/feeds/shop.php', null], ['/feeds/specials.php', null], ['/feeds/calculator.php', null],
         ['/ranch/', null], ['/ranch/livestock.php', null], ['/ranch/model.php', null], ['/pages/contact.php', null]];
// Only products that can be bought (live, with at least one live size) — the same rule as the shop
foreach (db_all('SELECT p.slug, p.updated_at FROM products p WHERE p.is_active = 1
                 AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) ORDER BY p.id') as $r) {
    $urls[] = [product_url($r['slug']), $r['updated_at']];
}
foreach (db_all('SELECT c.slug FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1) ORDER BY c.sort_order, c.id') as $r) {
    $urls[] = [category_url($r['slug']), null];
}
foreach (db_all("SELECT slug, updated_at FROM lots WHERE status IN ('available','reserved','coming_soon') ORDER BY id") as $r) {
    $urls[] = [lot_url($r['slug']), $r['updated_at']];
}
foreach (db_all('SELECT slug, updated_at FROM pages WHERE is_published = 1 ORDER BY sort_order, id') as $r) {
    $urls[] = [page_url($r['slug']), $r['updated_at']];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $mod]) {
    echo '  <url><loc>' . e(app_url($u)) . '</loc>' . ($mod ? '<lastmod>' . date('Y-m-d', strtotime($mod)) . '</lastmod>' : '') . "</url>\n";
}
echo '</urlset>';
