<?php
/**
 * GrazeX Feeds — catalogue with instant filters (server-rendered first, then fetch).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$filters = [
    'q'        => query_str('q'),
    'category' => preg_replace('/[^a-z0-9-]/', '', query_str('category')),
    'species'  => preg_replace('/[^a-z0-9-]/', '', query_str('species')),
    'sort'     => array_key_exists(query_str('sort'), CatalogService::SORTS) ? query_str('sort') : 'featured',
    'in_stock' => query_str('in_stock') === '1',
    'specials' => query_str('specials') === '1',
    'page'     => max(1, query_int('page', 1)),
    'per_page' => 12,
];
$result = CatalogService::products($filters);
$categories = CatalogService::categories();
$species = CatalogService::species();
$activeCat = null;
foreach ($categories as $c) {
    if ($c['slug'] === $filters['category']) {
        $activeCat = $c;
    }
}

$page = [
    'title' => $activeCat ? $activeCat['name'] : 'Shop feeds',
    'description' => $activeCat['description'] ?? 'Shop livestock feed, licks, supplements and farm essentials from GrazeX Feeds.',
    'division' => 'feeds',
    'nav_current' => 'shop',
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <header class="shop-head">
    <span class="kicker">GrazeX Feeds</span>
    <h1 class="display-2 mt-1"><?= e($activeCat['name'] ?? 'The feed shop') ?></h1>
    <?php if (!empty($activeCat['description'])): ?><p class="lead mt-1"><?= e($activeCat['description']) ?></p><?php endif; ?>
  </header>

  <form class="shop-filters" id="shop-filters" action="/feeds/shop.php" method="get" role="search">
    <div class="search-box" data-suggest id="search">
      <svg class="icon"><use href="#i-search"/></svg>
      <label class="sr-only" for="q">Search</label>
      <input class="input" id="q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Search by name, brand or SKU…" autocomplete="off" data-suggest-input>
      <div class="suggest" hidden data-suggest-list></div>
    </div>

    <div class="filter-group">
      <span class="filter-label">Category</span>
      <div class="chips chips-scroll" role="group" aria-label="Category">
        <button class="chip" type="button" data-filter="category" data-value="" aria-pressed="<?= $filters['category'] === '' ? 'true' : 'false' ?>">All</button>
        <?php foreach ($categories as $c): ?>
          <button class="chip" type="button" data-filter="category" data-value="<?= e($c['slug']) ?>" aria-pressed="<?= $filters['category'] === $c['slug'] ? 'true' : 'false' ?>"><?= e($c['name']) ?> <span class="count"><?= (int) $c['product_count'] ?></span></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="filter-group">
      <span class="filter-label">Animal</span>
      <div class="chips chips-scroll" role="group" aria-label="Animal">
        <button class="chip" type="button" data-filter="species" data-value="" aria-pressed="<?= $filters['species'] === '' ? 'true' : 'false' ?>">All animals</button>
        <?php foreach ($species as $s): if (!(int) $s['product_count']) continue; ?>
          <button class="chip" type="button" data-filter="species" data-value="<?= e($s['slug']) ?>" aria-pressed="<?= $filters['species'] === $s['slug'] ? 'true' : 'false' ?>"><?= e($s['name']) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="toolbar">
      <div class="row">
        <label class="check"><input type="checkbox" name="in_stock" value="1" <?= $filters['in_stock'] ? 'checked' : '' ?>> In stock only</label>
        <label class="check"><input type="checkbox" name="specials" value="1" <?= $filters['specials'] ? 'checked' : '' ?>> Specials</label>
      </div>
      <div class="row">
        <span class="result-count" data-result-count aria-live="polite"><?= (int) $result['total'] ?> product<?= $result['total'] === 1 ? '' : 's' ?></span>
        <label class="sr-only" for="sort">Sort</label>
        <select class="select" id="sort" name="sort">
          <?php foreach (CatalogService::SORTS as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $filters['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <input type="hidden" name="category" value="<?= e($filters['category']) ?>">
    <input type="hidden" name="species" value="<?= e($filters['species']) ?>">
    <noscript><button class="btn" type="submit">Apply filters</button></noscript>
  </form>

  <div id="product-results" data-results>
    <?php if ($result['items']): ?>
      <div class="p-grid">
        <?php foreach ($result['items'] as $i => $p): ?><?= partial('product-card', ['p' => $p, 'i' => $i]) ?><?php endforeach; ?>
      </div>
      <?= pagination($result['page'], $result['pages'], '/feeds/shop.php') ?>
    <?php else: ?>
      <div class="empty-state">
        <div class="es-icon"><svg class="icon icon-lg"><use href="#i-search"/></svg></div>
        <h3>No products match</h3>
        <p>Try another search or clear the filters.</p>
        <a class="btn" href="/feeds/shop.php">Clear filters</a>
      </div>
    <?php endif; ?>
  </div>
</main>
<?= partial('cart-drawer', ['div' => 'feeds']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
