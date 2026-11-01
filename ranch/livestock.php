<?php
/**
 * GrazeX Ranch — availability board with filters.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

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
$breeds = LivestockService::breeds();
$cats = LivestockService::categories();

$page = [
    'title' => 'Available livestock',
    'description' => 'Sheep available now from GrazeX Ranch — weaner lambs, slaughter lambs, ewes and rams. Reserve online with a deposit.',
    'division' => 'ranch',
    'nav_current' => 'livestock',
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'js' => ['/assets/js/cart.js', '/ranch/js/ranch.js'],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <header class="shop-head">
    <span class="kicker">GrazeX Ranch</span>
    <h1 class="display-2 mt-1">The <span class="text-chrome italic">board.</span></h1>
    <p class="lead mt-1">Every lot is weighed and health-recorded. Reserve online with a deposit and collect at the farm.</p>
  </header>

  <form class="shop-filters" id="lot-filters" action="/ranch/livestock.php" method="get">
    <div class="chips" role="group" aria-label="Availability">
      <?php foreach (['now' => 'Available now', 'soon' => 'Coming soon', 'all' => 'Everything'] as $k => $label): ?>
        <button class="chip" type="button" data-filter="status" data-value="<?= e($k) ?>" aria-pressed="<?= $filters['status'] === $k ? 'true' : 'false' ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="filter-group">
      <span class="filter-label">Type</span>
      <div class="chips chips-scroll">
        <button class="chip" type="button" data-filter="category" data-value="" aria-pressed="<?= $filters['category'] === '' ? 'true' : 'false' ?>">All types</button>
        <?php foreach ($cats as $c): ?>
          <button class="chip" type="button" data-filter="category" data-value="<?= e($c['slug']) ?>" aria-pressed="<?= $filters['category'] === $c['slug'] ? 'true' : 'false' ?>"><?= e($c['name']) ?><?= (int) $c['lot_count'] ? ' <span class="count">' . (int) $c['lot_count'] . '</span>' : '' ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="toolbar">
      <div class="row">
        <label class="sr-only" for="breed">Breed</label>
        <select class="select" id="breed" name="breed">
          <option value="">All breeds</option>
          <?php foreach ($breeds as $b): ?><option value="<?= e($b['slug']) ?>"<?= $filters['breed'] === $b['slug'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="sr-only" for="mode">Pricing</label>
        <select class="select" id="mode" name="mode">
          <option value="">Per head &amp; per kg</option>
          <option value="per_head"<?= $filters['mode'] === 'per_head' ? ' selected' : '' ?>>Priced per head</option>
          <option value="per_kg"<?= $filters['mode'] === 'per_kg' ? ' selected' : '' ?>>Priced per kg</option>
        </select>
      </div>
      <div class="row">
        <span class="result-count" data-result-count aria-live="polite"><?= (int) $result['total'] ?> lot<?= $result['total'] === 1 ? '' : 's' ?></span>
        <label class="sr-only" for="sort">Sort</label>
        <select class="select" id="sort" name="sort">
          <?php foreach (LivestockService::SORTS as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <input type="hidden" name="category" value="<?= e($filters['category']) ?>">
    <noscript><button class="btn" type="submit">Apply</button></noscript>
  </form>

  <div data-results>
    <?php if ($result['items']): ?>
      <div class="l-grid"><?php foreach ($result['items'] as $i => $l): ?><?= partial('lot-card', ['l' => $l, 'i' => $i]) ?><?php endforeach; ?></div>
      <?= pagination($result['page'], $result['pages'], '/ranch/livestock.php') ?>
    <?php else: ?>
      <div class="empty-state">
        <div class="es-icon"><svg class="icon icon-lg"><use href="#i-sheep"/></svg></div>
        <h3>No lots match right now</h3>
        <p>Set an alert and we'll email you when a matching lot is ready.</p>
        <a class="btn btn-primary" href="/ranch/#notify">Notify me</a>
      </div>
    <?php endif; ?>
  </div>
</main>
<?= partial('cart-drawer', ['div' => 'ranch']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
