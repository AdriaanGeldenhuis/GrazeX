<?php
/**
 * GrazeX Feeds — full cart page.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$div = 'feeds';
$summary = CartService::summary($div);

$page = [
    'title' => 'Your cart',
    'division' => $div,
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
    'noindex' => true,
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <h1 class="display-2 mb-3">Your <span class="text-accent-grad">cart</span></h1>
  <div class="cart-page">
    <section class="card card-pad" data-cart-body data-full="1">
      <?= partial('cart-lines', ['summary' => $summary, 'div' => $div, 'compact' => false]) ?>
    </section>
    <?php if ($summary['lines']): ?>
    <aside class="cart-side">
      <div class="card card-pad">
        <h2 class="h3 mb-2">Summary</h2>
        <?php if ($summary['coupon']): ?>
          <div class="alert alert-success small mb-2">Coupon <strong><?= e($summary['coupon']['code']) ?></strong> applied. <a href="#" class="link" data-coupon-remove>Remove</a></div>
        <?php else: ?>
          <form id="coupon-form" class="input-group mb-2" novalidate>
            <label class="sr-only" for="code">Coupon code</label>
            <input class="input" id="code" name="code" placeholder="Coupon code" autocomplete="off" style="text-transform:uppercase">
            <button class="btn" type="submit">Apply</button>
          </form>
        <?php endif; ?>
        <?= partial('cart-foot', ['summary' => $summary, 'div' => $div]) ?>
      </div>
      <div class="card card-pad mt-2">
        <h3 class="h4 mb-1">Delivery or collection?</h3>
        <p class="muted small mb-0">Choose at checkout: collect at the depot in a time slot that suits you, or get it delivered in our local zones. <a class="link" href="/feeds/#delivery">Check your town</a>.</p>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</main>
<?= partial('cart-drawer', ['div' => $div]) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
