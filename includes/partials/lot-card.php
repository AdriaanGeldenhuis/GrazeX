<?php
/** @var array $l  Row from LivestockService::lots() */
defined('GX_ROOT') || exit;
$url = '/ranch/lot.php?slug=' . rawurlencode($l['slug']);
[$badgeLabel, $badgeClass] = LivestockService::badge($l);
$price = LivestockService::priceLabel($l);
$age = LivestockService::ageLabel($l);
$delay = isset($i) ? min(6, (int) $i) * 0.05 : 0;
?>
<article class="l-card reveal" style="--d:<?= $delay ?>s">
  <a class="l-media media-16x9" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
    <?= media_img($l['media_id'] ? (int) $l['media_id'] : null, $l['title'], '(min-width: 1024px) 33vw, 100vw', 'wide', true, 'sheep') ?>
    <span class="l-flags"><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></span>
    <?php if ((int) $l['deposit_pct'] > 0 && $l['status'] !== 'coming_soon'): ?>
      <span class="l-deposit"><?= (int) $l['deposit_pct'] ?>% deposit reserves</span>
    <?php endif; ?>
  </a>
  <div class="l-body">
    <div class="l-meta"><span><?= e($l['lot_no']) ?></span><span><?= e($l['breed_name']) ?></span><span><?= e($l['category_name']) ?></span></div>
    <h3 class="l-title"><a href="<?= e($url) ?>"><?= e($l['title']) ?></a></h3>
    <dl class="l-facts">
      <div><dt>Head</dt><dd><?= $l['status'] === 'coming_soon' ? (int) $l['qty_total'] : (int) $l['qty_available'] ?></dd></div>
      <div><dt>Avg weight</dt><dd><?= $l['avg_weight_kg'] !== null ? e(kg((float) $l['avg_weight_kg'])) : '—' ?></dd></div>
      <div><dt><?= $age ? 'Age' : 'Sex' ?></dt><dd><?= e($age ?? LivestockService::sexLabel($l['sex'])) ?></dd></div>
    </dl>
    <div class="l-foot">
      <div>
        <span class="price"><?= e($price['main']) ?></span><span class="price-unit"> <?= e($price['unit']) ?></span>
        <?php if ($price['note']): ?><div class="price-note"><?= e($price['note']) ?></div><?php endif; ?>
      </div>
      <a class="btn btn-sm" href="<?= e($url) ?>"><?= $l['status'] === 'coming_soon' ? 'Preview' : 'View lot' ?> <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
    </div>
    <?php if ($l['status'] === 'coming_soon' && $l['available_from']): ?>
      <div class="l-soon"><svg class="icon icon-sm"><use href="#i-calendar"/></svg> Available from <?= e(fmt_date($l['available_from'])) ?></div>
    <?php endif; ?>
  </div>
</article>
