<?php
/**
 * GrazeX Feeds — current specials.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$result = CatalogService::products(['specials' => true, 'per_page' => 48, 'sort' => 'price_asc']);
$tiered = db_all('SELECT DISTINCT p.slug FROM price_tiers pt JOIN product_variants v ON v.id = pt.variant_id JOIN products p ON p.id = v.product_id WHERE p.is_active = 1 AND v.is_active = 1');
$bulk = CatalogService::products(['per_page' => 8, 'in_stock' => true]);
$bulkItems = array_values(array_filter($bulk['items'], static fn($i) => $i['tier_from'] !== null));

$page = [
    'title' => 'Specials',
    'description' => 'This week\'s feed specials and bulk deals at GrazeX Feeds.',
    'division' => 'feeds',
    'nav_current' => 'specials',
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <header class="shop-head">
    <span class="kicker">Save on feed</span>
    <h1 class="display-2 mt-1">Specials <span class="text-accent-grad">&amp; bulk deals</span></h1>
    <p class="lead mt-1">Limited-time prices while stock lasts — plus automatic bulk pricing when you buy more.</p>
  </header>

  <?php if ($result['items']): ?>
    <div class="p-grid mt-3">
      <?php foreach ($result['items'] as $i => $p): ?><?= partial('product-card', ['p' => $p, 'i' => $i]) ?><?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state mt-3"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-tag"/></svg></div><h3>No specials right now</h3><p>New specials are added regularly — check back soon.</p></div>
  <?php endif; ?>

  <?php if ($bulkItems): ?>
    <section class="section-tight mt-4">
      <div class="strip-head"><div><span class="kicker">Buy more, pay less</span><h2 class="display-3 mt-1">Bulk pricing</h2></div></div>
      <div class="p-grid">
        <?php foreach ($bulkItems as $i => $p): ?><?= partial('product-card', ['p' => $p, 'i' => $i]) ?><?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
<?= partial('cart-drawer', ['div' => 'feeds']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
