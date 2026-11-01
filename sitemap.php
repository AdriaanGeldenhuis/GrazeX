<?php
/** XML sitemap for search engines (/sitemap.xml). */
declare(strict_types=1);

define('GX_NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$urls = [['/', null], ['/feeds/', null], ['/feeds/shop.php', null], ['/feeds/specials.php', null], ['/feeds/calculator.php', null],
         ['/ranch/', null], ['/ranch/livestock.php', null], ['/ranch/model.php', null], ['/pages/contact.php', null]];
foreach (db_all('SELECT slug, updated_at FROM products WHERE is_active = 1') as $r) {
    $urls[] = ['/feeds/product/' . $r['slug'], $r['updated_at']];
}
foreach (db_all("SELECT slug, updated_at FROM lots WHERE status IN ('available','reserved','coming_soon')") as $r) {
    $urls[] = ['/ranch/lot/' . $r['slug'], $r['updated_at']];
}
foreach (db_all('SELECT slug, updated_at FROM pages WHERE is_published = 1') as $r) {
    $urls[] = ['/pages/page.php?slug=' . $r['slug'], $r['updated_at']];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $mod]) {
    echo '  <url><loc>' . e(app_url($u)) . '</loc>' . ($mod ? '<lastmod>' . date('Y-m-d', strtotime($mod)) . '</lastmod>' : '') . "</url>\n";
}
echo '</urlset>';
