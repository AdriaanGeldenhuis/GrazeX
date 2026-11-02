<?php
/** @var array $p  Row from CatalogService::products() */
defined('GX_ROOT') || exit;
$url = product_url($p['slug']);
$stock = (int) $p['stock_total'];
$single = (int) $p['active_variants'] === 1;
$onSpecial = (int) $p['on_special'] === 1;
$delay = isset($i) ? min(6, (int) $i) * 0.05 : 0;
?>
<article class="p-card reveal" style="--d:<?= $delay ?>s">
  <a class="p-media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
    <?= media_img($p['media_id'] ? (int) $p['media_id'] : null, $p['name'], '(min-width: 1024px) 25vw, 50vw', 'orig', true, 'bag') ?>
    <span class="p-flags">
      <?php if ($onSpecial): ?><span class="badge badge-solid">Special</span><?php endif; ?>
      <?php if ($p['tier_from']): ?><span class="badge badge-accent">Bulk deals</span><?php endif; ?>
    </span>
  </a>
  <div class="p-body">
    <div class="p-meta"><?= e($p['category_name']) ?><?= $p['brand_name'] ? ' · ' . e($p['brand_name']) : '' ?></div>
    <h3 class="p-title"><a href="<?= e($url) ?>"><?= e($p['name']) ?></a></h3>
    <div class="p-foot">
      <div class="p-price">
        <?php if (!$single): ?><span class="price-unit">from</span><?php endif; ?>
        <span class="price"><?= e(money((int) $p['min_price'])) ?></span>
        <?php if ($onSpecial && (int) $p['min_regular'] > (int) $p['min_price']): ?><span class="price-old"><?= e(money((int) $p['min_regular'])) ?></span><?php endif; ?>
      </div>
      <?= stock_badge($stock, (int) ($p['low_threshold'] ?? 5)) ?>
    </div>
    <?php if ($stock > 0 && $single && $p['default_variant_id']): ?>
      <button class="btn btn-primary btn-sm btn-block" type="button" data-add-to-cart data-division="feeds" data-target="<?= (int) $p['default_variant_id'] ?>">
        <svg class="icon icon-sm"><use href="#i-plus"/></svg> Add to cart
      </button>
    <?php elseif ($stock > 0): ?>
      <a class="btn btn-sm btn-block" href="<?= e($url) ?>">Choose size <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
    <?php else: ?>
      <a class="btn btn-sm btn-block" href="<?= e($url) ?>">View details</a>
    <?php endif; ?>
  </div>
</article>
