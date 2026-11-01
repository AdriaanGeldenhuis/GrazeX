<?php
/**
 * GrazeX Ranch — full livestock selection page.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$div = 'ranch';
$summary = CartService::summary($div);

$page = [
    'title' => 'Your selection',
    'division' => $div,
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'js' => ['/assets/js/cart.js', '/ranch/js/ranch.js'],
    'noindex' => true,
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <h1 class="display-2 mb-3">Your <span class="text-chrome italic">selection</span></h1>
  <div class="cart-page">
    <section class="card card-pad" data-cart-body data-full="1">
      <?= partial('cart-lines', ['summary' => $summary, 'div' => $div, 'compact' => false]) ?>
    </section>
    <?php if ($summary['lines']): ?>
    <aside class="cart-side">
      <div class="card card-pad">
        <h2 class="h3 mb-2">Summary</h2>
        <?= partial('cart-foot', ['summary' => $summary, 'div' => $div]) ?>
      </div>
      <div class="card card-pad mt-2">
        <h3 class="h4 mb-1">How reserving works</h3>
        <ol class="how-list">
          <li>Pay the deposit online (card) or by EFT to reserve.</li>
          <li>Book a collection time at the farm.</li>
          <li>Per-kg lots are weighed — your final invoice uses actual weights.</li>
          <li>Pay the balance and collect with your documents.</li>
        </ol>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</main>
<?= partial('cart-drawer', ['div' => $div]) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
