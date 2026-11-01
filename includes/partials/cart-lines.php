<?php
/** @var array $summary  CartService::summary()  @var string $div  @var bool $compact */
defined('GX_ROOT') || exit;
$compact ??= false;
?>
<?php foreach ($summary['notices'] as $n): ?>
  <div class="alert alert-warning small mb-2"><?= e($n) ?></div>
<?php endforeach; ?>
<?php if (!$summary['lines']): ?>
  <div class="empty-state">
    <div class="es-icon"><svg class="icon icon-lg"><use href="#i-<?= $div === 'ranch' ? 'sheep' : 'bag' ?>"/></svg></div>
    <h3><?= $div === 'ranch' ? 'No livestock selected yet' : 'Your cart is empty' ?></h3>
    <p class="mb-2"><?= $div === 'ranch' ? 'Browse the available lots and reserve the animals you want.' : 'Add feed, licks and supplements — we will have them ready for you.' ?></p>
    <a class="btn btn-primary" href="<?= $div === 'ranch' ? '/ranch/livestock.php' : '/feeds/shop.php' ?>"><?= $div === 'ranch' ? 'See available livestock' : 'Start shopping' ?></a>
  </div>
<?php else: ?>
  <ul class="cart-lines<?= $compact ? ' is-compact' : '' ?>">
    <?php foreach ($summary['lines'] as $line): ?>
      <li class="cart-line" data-item="<?= (int) $line['id'] ?>">
        <a class="cl-media" href="<?= e($line['url']) ?>" tabindex="-1" aria-hidden="true">
          <?= media_img($line['media_id'], $line['name'], '96px', $line['type'] === 'lot' ? 'wide' : 'orig', true, $line['type'] === 'lot' ? 'sheep' : 'bag') ?>
        </a>
        <div class="cl-info">
          <a class="cl-name" href="<?= e($line['url']) ?>"><?= e($line['name']) ?></a>
          <div class="cl-variant"><?= e($line['variant']) ?></div>
          <?php if ($line['type'] === 'lot'): ?>
            <div class="cl-note">
              <?php if ($line['pricing_mode'] === 'per_kg'): ?>
                <?= e(money($line['price_cents'])) ?>/kg · est. <?= e(kg($line['est_weight_kg'])) ?> total
              <?php else: ?>
                <?= e(money($line['unit'], false)) ?> per head
              <?php endif; ?>
              <?php if ($line['deposit'] > 0): ?> · deposit <?= e(money($line['deposit'])) ?><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="cl-note">
              <?= e(money($line['unit'])) ?> each<?php if ($line['tier_min']): ?> <span class="badge badge-accent">bulk price</span><?php endif; ?>
              <?php if ($line['next_tier']): ?><br><span class="text-accent">Buy <?= (int) $line['next_tier']['min_qty'] ?>+ for <?= e(money($line['next_tier']['price_cents'])) ?> each</span><?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($line['notice']): ?><div class="cl-warn"><?= e($line['notice']) ?></div><?php endif; ?>
          <div class="cl-actions">
            <div class="stepper stepper-sm">
              <button type="button" data-step="down" aria-label="Less">−</button>
              <input type="number" inputmode="numeric" min="1" max="<?= (int) $line['max'] ?>" value="<?= (int) $line['qty'] ?>" data-cart-qty="<?= (int) $line['id'] ?>" aria-label="Quantity">
              <button type="button" data-step="up" aria-label="More">+</button>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" data-cart-remove="<?= (int) $line['id'] ?>" aria-label="Remove <?= e($line['name']) ?>"><svg class="icon icon-sm"><use href="#i-trash"/></svg></button>
          </div>
        </div>
        <div class="cl-total"><?= e(money($line['total'])) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
