<?php
/** Slide-in cart for a division. @var string $div */
defined('GX_ROOT') || exit;
$summary = CartService::summary($div);
?>
<aside class="drawer" id="cart-drawer" data-division="<?= e($div) ?>" aria-hidden="true" aria-labelledby="cart-drawer-title" role="dialog">
  <div class="drawer-head">
    <h2 class="h3" id="cart-drawer-title"><?= $div === 'ranch' ? 'Your livestock selection' : 'Your cart' ?></h2>
    <button class="nav-icon-btn" type="button" data-drawer-close aria-label="Close"><svg class="icon"><use href="#i-close"/></svg></button>
  </div>
  <div class="drawer-body" data-cart-body><?= partial('cart-lines', ['summary' => $summary, 'div' => $div, 'compact' => true]) ?></div>
  <div class="drawer-foot" data-cart-foot><?= partial('cart-foot', ['summary' => $summary, 'div' => $div]) ?></div>
</aside>
<div class="drawer-backdrop" data-drawer-close></div>
