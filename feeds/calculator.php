<?php
/**
 * GrazeX Feeds — feed calculator. Rules (daily intake per product/animal) are set in Admin.
 * Calculation runs in the browser; prices/stock come from the live catalogue.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$rows = db_all('SELECT r.id, r.animal_class, r.intake_grams_per_day, r.notes, s.slug AS species_slug, s.name AS species_name,
                       p.id AS product_id, p.name AS product_name, p.slug AS product_slug
                FROM calculator_rules r
                JOIN species s ON s.id = r.species_id
                JOIN products p ON p.id = r.product_id AND p.is_active = 1
                ORDER BY s.sort_order, p.name, r.animal_class');
// Sizes and bulk prices for all products in two queries (not one per rule and per size)
$productIds = array_values(array_unique(array_map(static fn($r) => (int) $r['product_id'], $rows)));
$variantsByProduct = [];
$tiersByVariant = [];
if ($productIds) {
    foreach (db_all('SELECT * FROM product_variants WHERE product_id IN (' . db_placeholders($productIds) . ') AND is_active = 1 AND weight_grams > 0
                     ORDER BY weight_grams, id', $productIds) as $v) {
        $variantsByProduct[(int) $v['product_id']][] = $v;
    }
    $variantIds = array_map(static fn($v) => (int) $v['id'], array_merge([], ...array_values($variantsByProduct)));
    if ($variantIds) {
        foreach (db_all('SELECT variant_id, min_qty, price_cents FROM price_tiers WHERE variant_id IN (' . db_placeholders($variantIds) . ') ORDER BY min_qty', $variantIds) as $t) {
            $tiersByVariant[(int) $t['variant_id']][] = $t;
        }
    }
}
$rules = [];
foreach ($rows as $r) {
    $variants = $variantsByProduct[(int) $r['product_id']] ?? [];
    $rules[] = [
        'id' => (int) $r['id'], 'species' => $r['species_slug'], 'species_name' => $r['species_name'], 'animal' => $r['animal_class'],
        'grams' => (int) $r['intake_grams_per_day'], 'notes' => $r['notes'], 'product' => $r['product_name'],
        'url' => product_url($r['product_slug']),
        'variants' => array_map(static fn($v) => [
            'id' => (int) $v['id'], 'name' => $v['name'], 'grams' => (int) $v['weight_grams'], 'stock' => max(0, (int) $v['stock_qty']),
            'price' => PricingService::basePrice($v),
            'tiers' => array_map(static fn($t) => ['min' => (int) $t['min_qty'], 'price' => (int) $t['price_cents']], $tiersByVariant[(int) $v['id']] ?? []),
        ], $variants),
    ];
}
$rules = array_values(array_filter($rules, static fn($r) => $r['variants']));
$speciesList = [];
foreach ($rules as $r) {
    $speciesList[$r['species']] = $r['species_name'];
}

$page = [
    'title' => 'Feed calculator',
    'description' => 'Work out how many bags of feed you need for your animals — and what it will cost.',
    'division' => 'feeds',
    'nav_current' => 'calculator',
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <header class="shop-head">
    <span class="kicker">Feed calculator</span>
    <h1 class="display-2 mt-1">How many <span class="text-accent-grad">bags?</span></h1>
    <p class="lead mt-1">Tell us your animals and how long you need to feed them. We'll do the maths — and the cart.</p>
  </header>

  <?php if (!$rules): ?>
    <div class="empty-state mt-3"><h3>Calculator coming soon</h3><p>We are setting up feeding rates for our products.</p></div>
  <?php else: ?>
  <div class="calc mt-3" id="calc" data-rules="<?= e_json($rules) ?>">
    <form class="card card-pad calc-form" novalidate>
      <div class="field">
        <span class="label">1. Your animals</span>
        <div class="chips" role="group" aria-label="Animal">
          <?php $first = true; foreach ($speciesList as $slug => $name): ?>
            <button class="chip" type="button" data-calc-species="<?= e($slug) ?>" aria-pressed="<?= $first ? 'true' : 'false' ?>"><?= e($name) ?></button>
          <?php $first = false; endforeach; ?>
        </div>
      </div>
      <div class="field">
        <label for="calc-rule">2. Feed &amp; animal type</label>
        <select class="select" id="calc-rule"></select>
        <div class="hint" data-calc-note></div>
      </div>
      <div class="grid-2">
        <div class="field">
          <label for="calc-head">3. Number of animals</label>
          <input class="input" id="calc-head" type="number" inputmode="numeric" min="1" max="100000" value="50">
        </div>
        <div class="field">
          <label for="calc-days">4. Number of days</label>
          <input class="input" id="calc-days" type="number" inputmode="numeric" min="1" max="365" value="30">
          <div class="chips mt-1">
            <button class="chip" type="button" data-days="7">1 week</button>
            <button class="chip" type="button" data-days="30">1 month</button>
            <button class="chip" type="button" data-days="90">3 months</button>
          </div>
        </div>
      </div>
    </form>

    <section class="calc-result card card-pad" aria-live="polite">
      <span class="kicker">Your estimate</span>
      <div class="calc-big"><span data-calc-kg>0</span><small>kg of feed</small></div>
      <div class="calc-options" data-calc-options></div>
      <p class="hint mt-2">Estimates use typical daily intake. Actual intake depends on grazing quality, weather and animal condition.</p>
    </section>
  </div>
  <?php endif; ?>
</main>
<?= partial('cart-drawer', ['div' => 'feeds']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
