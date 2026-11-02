<?php
/**
 * GrazeX Feeds — storefront home.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$categories = CatalogService::categories();
$featured = CatalogService::products(['sort' => 'featured', 'per_page' => 8, 'in_stock' => true])['items'];
$specials = CatalogService::products(['specials' => true, 'per_page' => 4])['items'];
$faqs = db_all('SELECT question, answer FROM faqs WHERE is_active = 1 AND division_id = ? ORDER BY sort_order, id', [division_id('feeds')]);
$testimonials = db_all('SELECT name, location, quote, rating FROM testimonials WHERE is_active = 1 AND (division_id = ? OR division_id IS NULL) ORDER BY sort_order LIMIT 3', [division_id('feeds')]);
$catIcons = ['lick' => 'leaf', 'pellet' => 'bag', 'spark' => 'spark', 'bale' => 'grid', 'sprout' => 'leaf', 'tool' => 'settings'];

$page = [
    'title' => 'Feeds',
    'description' => content('feeds', 'hero_text'),
    'division' => 'feeds',
    'nav_current' => 'home',
    'css' => ['/assets/css/store.css', '/feeds/css/feeds.css'],
    'js' => ['/assets/js/cart.js', '/feeds/js/feeds.js'],
    'og_image' => '/assets/img/brand/og-feeds.jpg',
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main">
  <section class="store-hero">
    <div class="container store-hero-grid">
      <div>
        <span class="eyebrow hero-rise"><span class="dot"></span><?= e(content('feeds', 'hero_kicker', 'GrazeX Feeds')) ?></span>
        <h1 class="display-1 hero-rise" style="--d:.06s"><span class="text-accent-grad italic"><?= e(content('feeds', 'hero_title', 'Feed that earns.')) ?></span></h1>
        <p class="lead hero-rise" style="--d:.12s"><?= e(content('feeds', 'hero_text')) ?></p>
        <div class="search-box reveal mt-3" style="--d:.18s" data-suggest>
          <svg class="icon"><use href="#i-search"/></svg>
          <label class="sr-only" for="hero-search">Search feeds</label>
          <input class="input" id="hero-search" type="search" placeholder="Search licks, pellets, lucerne…" autocomplete="off" data-suggest-input>
          <div class="suggest" hidden data-suggest-list></div>
        </div>
        <div class="usps hero-rise" style="--d:.24s">
          <span class="usp"><svg class="icon icon-sm"><use href="#i-check"/></svg><?= e(content('feeds', 'usp_1', 'In stock & priced clearly')) ?></span>
          <span class="usp"><svg class="icon icon-sm"><use href="#i-truck"/></svg><?= e(content('feeds', 'usp_2', 'Collect or local delivery')) ?></span>
          <span class="usp"><svg class="icon icon-sm"><use href="#i-tag"/></svg><?= e(content('feeds', 'usp_3', 'Bulk pricing on 10+ bags')) ?></span>
        </div>
      </div>
      <img class="hero-logo hero-rise" style="--d:.1s" src="/assets/img/brand/feeds-logo-960.webp"
           srcset="/assets/img/brand/feeds-logo-480.webp 480w, /assets/img/brand/feeds-logo-960.webp 960w, /assets/img/brand/feeds-logo-1600.webp 1600w"
           sizes="(min-width: 1024px) 40vw, 90vw" width="960" height="480" alt="GrazeX Feeds" fetchpriority="high">
    </div>
    <div class="watermark" aria-hidden="true">FEEDS</div>
  </section>

  <section class="section-tight" aria-labelledby="cat-title">
    <div class="container">
      <div class="strip-head">
        <div><span class="kicker">Shop by category</span><h2 id="cat-title" class="display-3 mt-1">What do you need?</h2></div>
        <a class="btn btn-sm" href="/feeds/shop.php"><?= e(t('common.view_all')) ?> <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
      </div>
      <div class="cat-tiles">
        <?php foreach ($categories as $i => $c): ?>
          <a class="cat-tile reveal" style="--d:<?= $i * 0.04 ?>s" href="<?= e(category_url($c['slug'])) ?>">
            <span class="ct-icon"><svg class="icon"><use href="#i-<?= e($catIcons[$c['icon']] ?? 'bag') ?>"/></svg></span>
            <span class="ct-name"><?= e($c['name']) ?></span>
            <span class="ct-count"><?= (int) $c['product_count'] ?> product<?= (int) $c['product_count'] === 1 ? '' : 's' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php if ($specials): ?>
  <section class="section-tight" aria-labelledby="spec-title">
    <div class="container">
      <div class="strip-head">
        <div><span class="kicker">This week</span><h2 id="spec-title" class="display-3 mt-1">Specials</h2></div>
        <a class="btn btn-sm" href="/feeds/specials.php">All specials <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
      </div>
      <div class="p-grid">
        <?php foreach ($specials as $i => $p): ?><?= partial('product-card', ['p' => $p, 'i' => $i]) ?><?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="section-tight" aria-labelledby="feat-title">
    <div class="container">
      <div class="strip-head">
        <div><span class="kicker">Popular now</span><h2 id="feat-title" class="display-3 mt-1">Farmers' favourites</h2></div>
        <a class="btn btn-sm" href="/feeds/shop.php">Shop all feeds <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
      </div>
      <?php if ($featured): ?>
        <div class="p-grid">
          <?php foreach ($featured as $i => $p): ?><?= partial('product-card', ['p' => $p, 'i' => $i]) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state"><h3>Products coming soon</h3><p>We are loading our range — check back shortly.</p></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="section" aria-label="Tools">
    <div class="container grid-2">
      <div class="feature-band reveal" style="grid-template-columns:1fr">
        <div>
          <span class="kicker">Feed calculator</span>
          <h2 class="display-3 mt-1">How many bags do you need?</h2>
          <p class="muted mt-2">Choose your animals, how many and for how long — we work out the bags and the cost, and add them to your cart in one tap.</p>
          <a class="btn btn-primary mt-2" href="/feeds/calculator.php"><svg class="icon"><use href="#i-calc"/></svg>Open the calculator</a>
        </div>
      </div>
      <div class="feature-band reveal" style="--d:.08s;grid-template-columns:1fr" id="delivery">
        <div>
          <span class="kicker">Delivery checker</span>
          <h2 class="display-3 mt-1">Do we deliver to you?</h2>
          <form class="mt-2" data-delivery-check autocomplete="off">
            <label class="sr-only" for="dc-town">Your town</label>
            <div class="input-group">
              <input class="input" id="dc-town" name="town" list="dc-towns" placeholder="Type your town" required>
              <button class="btn btn-primary" type="submit">Check</button>
            </div>
            <datalist id="dc-towns"></datalist>
          </form>
          <div class="mt-2" data-delivery-result aria-live="polite"></div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($testimonials): ?>
  <section class="section-tight" aria-label="What farmers say">
    <div class="container grid-3">
      <?php foreach ($testimonials as $i => $tm): ?>
        <figure class="card card-pad reveal" style="--d:<?= $i * 0.06 ?>s;margin:0">
          <div class="text-accent" role="img" aria-label="<?= (int) $tm['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $tm['rating']) ?></div>
          <blockquote class="mt-1" style="margin-left:0">“<?= e($tm['quote']) ?>”</blockquote>
          <figcaption class="muted small"><strong><?= e($tm['name']) ?></strong><?= $tm['location'] ? ' · ' . e($tm['location']) : '' ?></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($faqs): ?>
  <section class="section" aria-labelledby="faq-title">
    <div class="container container-narrow">
      <span class="kicker">Good to know</span>
      <h2 id="faq-title" class="display-3 mt-1 mb-3">Questions</h2>
      <?php foreach ($faqs as $f): ?>
        <details class="faq"><summary><?= e($f['question']) ?></summary><div class="faq-body"><?= e($f['answer']) ?></div></details>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</main>
<?= partial('cart-drawer', ['div' => 'feeds']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
