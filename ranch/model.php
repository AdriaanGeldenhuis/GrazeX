<?php
/**
 * GrazeX Ranch — "Our model": how the ranch works (content from Admin > Content).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$about = db_one("SELECT title, body_md FROM pages WHERE slug = 'about' AND is_published = 1");
$gallery = db_all('SELECT media_id, caption FROM gallery_items WHERE is_active = 1 AND (division_id = ? OR division_id IS NULL) ORDER BY sort_order, id LIMIT 9', [division_id('ranch')]);

$page = [
    'title' => 'Our model',
    'description' => content('gateway', 'model_text', 'Buy well, graze hard, finish efficiently, sell on target.'),
    'division' => 'ranch',
    'nav_current' => 'model',
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'js' => ['/assets/js/cart.js', '/ranch/js/ranch.js'],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main">
  <section class="container page-top">
    <span class="kicker"><?= e(content('gateway', 'model_kicker', 'The GrazeX system')) ?></span>
    <h1 class="display-1 mt-1"><span class="text-chrome italic"><?= e(content('gateway', 'model_title', 'Not just sheep. Throughput.')) ?></span></h1>
    <p class="lead mt-2"><?= e(content('gateway', 'model_text')) ?></p>
  </section>

  <section class="section">
    <div class="container">
      <ol class="ranch-steps ranch-steps-big">
        <?php foreach (['Buy', 'Graze', 'Finish', 'Sell'] as $i => $step): ?>
          <li class="reveal" style="--d:<?= $i * 0.08 ?>s"><b>0<?= $i + 1 ?></b><h2><?= e($step) ?></h2><p><?= e(content('gateway', 'step_' . ($i + 1))) ?></p></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <?php if ($about): ?>
  <section class="section-tight">
    <div class="container container-narrow prose reveal"><?= markdown($about['body_md']) ?></div>
  </section>
  <?php endif; ?>

  <?php if ($gallery): ?>
  <section class="section-tight">
    <div class="container ranch-gallery">
      <?php foreach ($gallery as $g): ?>
        <figure class="media-16x9 reveal"><?= media_img((int) $g['media_id'], $g['caption'] ?? '', '(min-width: 1024px) 33vw, 100vw') ?><?php if ($g['caption']): ?><figcaption><?= e($g['caption']) ?></figcaption><?php endif; ?></figure>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="section">
    <div class="container">
      <div class="feature-band">
        <div><span class="kicker">Ready when you are</span><h2 class="display-3 mt-1">See what's on the board.</h2></div>
        <div class="row"><a class="btn btn-green btn-lg" href="/ranch/livestock.php">Available livestock <svg class="icon arrow"><use href="#i-arrow"/></svg></a></div>
      </div>
    </div>
  </section>
</main>
<?= partial('cart-drawer', ['div' => 'ranch']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
