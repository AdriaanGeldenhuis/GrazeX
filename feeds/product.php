<?php
/**
 * GrazeX Feeds — product detail.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', query_str('slug'));
$p = $slug !== '' ? CatalogService::product($slug) : null;
if (!$p || !$p['variants']) {
    $page = ['division' => 'feeds'];
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}

$related = CatalogService::related($p);
// Default variant = first in stock
$default = $p['variants'][0];
foreach ($p['variants'] as $v) {
    if ((int) $v['stock_qty'] > 0) {
        $default = $v;
        break;
    }
}
$variantsJson = array_map(static fn($v) => [
    'id' => (int) $v['id'], 'name' => $v['name'], 'sku' => $v['sku'], 'stock' => max(0, (int) $v['stock_qty']),
    'low' => (int) $v['low_stock_threshold'], 'price' => (int) $v['effective'], 'regular' => (int) $v['price_cents'],
    'special' => (bool) $v['on_special'], 'special_to' => $v['on_special'] && $v['special_to'] ? fmt_date($v['special_to']) : null,
    'kg' => (int) $v['weight_grams'] > 0 ? round((int) $v['weight_grams'] / 1000, 1) : null,
    'tiers' => array_map(static fn($t) => ['min' => (int) $t['min_qty'], 'price' => (int) $t['price_cents']], $v['tiers']),
], $p['variants']);
$url = app_url('/feeds/product.php?slug=' . rawurlencode($p['slug']));
$wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX Feeds, I have a question about ' . $p['name'] . ' (' . $url . ')');
$firstImg = $p['media'] ? media_url((int) $p['media'][0], 960, 'orig') : null;

$page = [
    'title' => $p['meta_title'] ?: $p['name'],
    'description' => $p['meta_description'] ?: ($p['short_description'] ?? ''),
    'division' => 'feeds',
    'nav_current' => 'shop',
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
    'og_image' => $p['media'] ? (media_url((int) $p['media'][0], 1600, 'wide') ?? '') : '',
    'jsonld' => [[
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $p['name'], 'sku' => $default['sku'],
        'description' => $p['short_description'], 'brand' => $p['brand_name'] ? ['@type' => 'Brand', 'name' => $p['brand_name']] : null,
        'image' => $firstImg ? app_url($firstImg) : null,
        'offers' => array_map(static fn($v) => ['@type' => 'Offer', 'sku' => $v['sku'], 'priceCurrency' => 'ZAR',
            'price' => number_format((int) $v['effective'] / 100, 2, '.', ''), 'url' => $url,
            'availability' => (int) $v['stock_qty'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'], $p['variants']),
    ]],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container">
  <div class="detail">
    <div class="gallery" data-gallery>
      <div class="gallery-main is-product">
        <?= media_img($p['media'][0] ?? null, $p['name'], '(min-width: 1024px) 50vw, 100vw', 'orig', false, 'bag') ?>
      </div>
      <?php if (count($p['media']) > 1): ?>
        <div class="gallery-thumbs" role="list">
          <?php foreach ($p['media'] as $i => $mid): ?>
            <button type="button" role="listitem" data-gallery-src="<?= e(media_url((int) $mid, 960, 'orig')) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Image <?= $i + 1 ?>">
              <?= media_img((int) $mid, '', '76px', 'orig') ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="buy-box" id="buy-box" data-variants="<?= e_json($variantsJson) ?>" data-default="<?= (int) $default['id'] ?>">
      <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="/feeds/">Feeds</a><span>/</span><a href="/feeds/shop.php?category=<?= e($p['category_slug']) ?>"><?= e($p['category_name']) ?></a>
      </nav>
      <div class="row">
        <?php if ($p['brand_name']): ?><span class="badge"><?= e($p['brand_name']) ?></span><?php endif; ?>
        <?php foreach ($p['species'] as $s): ?><span class="badge badge-accent"><?= e($s['name']) ?></span><?php endforeach; ?>
      </div>
      <h1><?= e($p['name']) ?></h1>
      <?php if ($p['short_description']): ?><p class="lead mb-0"><?= e($p['short_description']) ?></p><?php endif; ?>

      <div>
        <div class="price-row">
          <span class="price price-lg" data-price><?= e(money((int) $default['effective'])) ?></span>
          <span class="price-old" data-price-old<?= $default['on_special'] ? '' : ' hidden' ?>><?= e(money((int) $default['price_cents'])) ?></span>
          <span class="price-unit" data-price-unit></span>
          <?= vat_note('feeds') ?>
        </div>
        <div class="row mt-1"><span data-stock><?= stock_badge((int) $default['stock_qty'], (int) $default['low_stock_threshold']) ?></span><span class="badge badge-solid" data-special-badge hidden>Special</span></div>
      </div>

      <?php if (count($p['variants']) > 1): ?>
        <fieldset style="border:0;padding:0;margin:0">
          <legend class="label mb-1">Size</legend>
          <div class="variant-picker">
            <?php foreach ($p['variants'] as $v): ?>
              <label>
                <input type="radio" name="variant" value="<?= (int) $v['id'] ?>" <?= (int) $v['id'] === (int) $default['id'] ? 'checked' : '' ?> <?= (int) $v['stock_qty'] <= 0 ? 'disabled' : '' ?>>
                <span><strong><?= e($v['name']) ?></strong><small><?= e(money((int) $v['effective'])) ?><?= (int) $v['stock_qty'] <= 0 ? ' · out of stock' : '' ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endif; ?>

      <div class="tier-table" data-tiers hidden></div>

      <div class="buy-actions">
        <div class="stepper">
          <button type="button" data-step="down" aria-label="Less">−</button>
          <input id="qty" type="number" inputmode="numeric" min="1" max="<?= max(1, (int) $default['stock_qty']) ?>" value="1" aria-label="Quantity">
          <button type="button" data-step="up" aria-label="More">+</button>
        </div>
        <button class="btn btn-primary btn-lg" type="button" data-add-to-cart data-target="<?= (int) $default['id'] ?>" data-qty-from="#qty" id="add-btn" <?= (int) $default['stock_qty'] <= 0 ? 'disabled' : '' ?>>
          <svg class="icon"><use href="#i-cart"/></svg> Add to cart · <span data-line-total><?= e(money((int) $default['effective'])) ?></span>
        </button>
      </div>
      <p class="hint mb-0" data-tier-hint></p>

      <ul class="trust-list">
        <li><svg class="icon icon-sm"><use href="#i-store"/></svg>Collect at the depot — pick a time slot at checkout</li>
        <li><svg class="icon icon-sm"><use href="#i-truck"/></svg><span>Local delivery on set days · <a class="link" href="/feeds/#delivery">check your town</a></span></li>
        <li><svg class="icon icon-sm"><use href="#i-file"/></svg>Invoice emailed and saved in your account</li>
        <?php if ($p['reg_no']): ?><li><svg class="icon icon-sm"><use href="#i-shield"/></svg>Registration no. <?= e($p['reg_no']) ?> (Act 36 of 1947)</li><?php endif; ?>
      </ul>
      <?php if ($wa): ?>
        <a class="btn btn-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg>Ask about this product</a>
      <?php endif; ?>
    </div>
  </div>

  <section class="section-tight">
    <div class="tabs" role="tablist" aria-label="Product information">
      <button class="tab" role="tab" aria-selected="true" aria-controls="tab-desc" id="t-desc">Description</button>
      <?php if ($p['feeding_guide_md']): ?><button class="tab" role="tab" aria-selected="false" aria-controls="tab-guide" id="t-guide">Feeding guide</button><?php endif; ?>
      <?php if ($p['specs']): ?><button class="tab" role="tab" aria-selected="false" aria-controls="tab-specs" id="t-specs">Nutrition</button><?php endif; ?>
    </div>
    <div class="tab-panel prose" role="tabpanel" id="tab-desc" aria-labelledby="t-desc"><?= markdown($p['description_md']) ?: '<p class="muted">More information coming soon.</p>' ?></div>
    <?php if ($p['feeding_guide_md']): ?><div class="tab-panel prose" role="tabpanel" id="tab-guide" aria-labelledby="t-guide" hidden><?= markdown($p['feeding_guide_md']) ?></div><?php endif; ?>
    <?php if ($p['specs']): ?>
      <div class="tab-panel" role="tabpanel" id="tab-specs" aria-labelledby="t-specs" hidden>
        <table class="spec-table" style="max-width:560px"><tbody>
          <?php foreach ($p['specs'] as $s): ?><tr><td><?= e($s['label']) ?></td><td><?= e($s['value']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($p['spec_sheet_path']): ?><a class="btn btn-sm mt-2" href="<?= e($p['spec_sheet_path']) ?>" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-download"/></svg>Download spec sheet</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($related): ?>
  <section class="section-tight">
    <div class="strip-head"><h2 class="display-3">You may also need</h2></div>
    <div class="p-grid">
      <?php foreach ($related as $i => $rp): ?><?= partial('product-card', ['p' => $rp, 'i' => $i]) ?><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</main>

<div class="sticky-buy" data-sticky-buy aria-hidden="true">
  <div class="sb-info"><div class="sb-name"><?= e($p['name']) ?></div><div class="price" style="font-size:17px" data-price-sticky><?= e(money((int) $default['effective'])) ?></div></div>
  <button class="btn btn-primary" type="button" data-scroll-buy>Add to cart</button>
</div>
<?= partial('cart-drawer', ['div' => 'feeds']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
