<?php
/**
 * GrazeX gateway — the cinematic landing that splits into Ranch and Feeds.
 * All copy is editable in Admin > Content (scope "gateway").
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// Live numbers (fail soft so the landing never breaks)
$stats = ['lots' => 0, 'products' => 0, 'specials' => 0];
try {
    $stats['lots'] = (int) db_val("SELECT COUNT(*) FROM lots WHERE status = 'available' AND qty_available > 0");
    $stats['products'] = (int) db_val('SELECT COUNT(DISTINCT p.id) FROM products p JOIN product_variants v ON v.product_id = p.id
                                       WHERE p.is_active = 1 AND v.is_active = 1 AND v.stock_qty > 0');
    $stats['specials'] = (int) db_val('SELECT COUNT(DISTINCT v.product_id) FROM product_variants v JOIN products p ON p.id = v.product_id
                                       WHERE p.is_active = 1 AND v.is_active = 1 AND v.special_price_cents IS NOT NULL
                                       AND (v.special_from IS NULL OR v.special_from <= NOW()) AND (v.special_to IS NULL OR v.special_to >= NOW())');
} catch (Throwable $e) {
    gx_log('warning', 'Gateway stats failed: ' . $e->getMessage());
}

$testimonials = [];
try {
    $testimonials = db_all('SELECT name, location, quote, rating FROM testimonials WHERE is_active = 1 ORDER BY sort_order, id LIMIT 3');
} catch (Throwable $e) {
    // optional section
}

$marquee = content_lines('gateway', 'marquee') ?: ['GrazeX Ranch', 'GrazeX Feeds'];
$wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
$ranchImg = content_image('gateway', 'ranch_card_image', '/assets/img/brand/ranch-logo-960.webp', 960);
$feedsImg = content_image('gateway', 'feeds_card_image', '/assets/img/brand/feeds-logo-960.webp', 960);
// Phones download the small version, big screens the large one
$brandSet = static fn(string $n): string => "/assets/img/brand/{$n}-logo-480.webp 480w, /assets/img/brand/{$n}-logo-960.webp 960w, /assets/img/brand/{$n}-logo-1600.webp 1600w";
$ranchSet = content_image_srcset('gateway', 'ranch_card_image', $brandSet('ranch'));
$feedsSet = content_image_srcset('gateway', 'feeds_card_image', $brandSet('feeds'));

$page = [
    'title' => '',
    'division' => 'gateway',
    'css' => ['/assets/css/landing.css'],
    'js' => ['/assets/js/landing.js'],
    'body_class' => 'page-gateway',
    'jsonld' => [[
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'GrazeX',
        'url' => app_url('/'),
        'logo' => app_url('/assets/img/brand/ranch-logo-960.webp'),
        'email' => setting('contact_email'),
        'telephone' => setting('contact_phone'),
        'sameAs' => array_values(array_filter([setting('social_facebook'), setting('social_instagram'), setting('social_tiktok')])),
    ]],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main">

  <!-- HERO -->
  <section class="hero" aria-labelledby="hero-title">
    <div class="hero-copy">
      <span class="eyebrow hero-rise"><span class="dot"></span><?= e(content('gateway', 'hero_eyebrow', 'Pasture. Livestock. Feed.')) ?></span>
      <h1 id="hero-title" class="display-1 hero-title hero-rise" style="--d:.08s">
        <span class="text-stroke"><?= e(content('gateway', 'hero_title_1', 'Built on')) ?></span>
        <span class="text-fire italic"><?= e(content('gateway', 'hero_title_2', 'the land.')) ?></span>
      </h1>
      <p class="lead hero-rise" style="--d:.16s"><?= e(content('gateway', 'hero_text')) ?></p>
      <div class="hero-actions hero-rise" style="--d:.24s">
        <a class="btn btn-orange btn-lg" href="/feeds/" data-magnetic><?= e(t('gateway.shop_feeds')) ?> <svg class="icon arrow"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-chrome btn-lg" href="/ranch/" data-magnetic><?= e(t('gateway.enter_ranch')) ?></a>
      </div>
      <dl class="hero-stats hero-rise" style="--d:.32s">
        <div><dt class="sr-only">Livestock</dt><dd><span class="hs-num" data-count="<?= $stats['lots'] ?>"><?= (int) $stats['lots'] ?></span><span class="hs-label"><?= e(t('gateway.lots_available')) ?></span></dd></div>
        <div><dt class="sr-only">Feeds</dt><dd><span class="hs-num" data-count="<?= $stats['products'] ?>"><?= (int) $stats['products'] ?></span><span class="hs-label"><?= e(t('gateway.products')) ?></span></dd></div>
        <div><dt class="sr-only">Specials</dt><dd><span class="hs-num" data-count="<?= $stats['specials'] ?>"><?= (int) $stats['specials'] ?></span><span class="hs-label"><?= e(t('gateway.specials')) ?></span></dd></div>
      </dl>
    </div>

    <div class="hero-art hero-rise" style="--d:.1s" aria-hidden="true">
      <div class="orb"></div>
      <div class="ring"></div>
      <div class="logo-stage" data-tilt>
        <img class="logo-slide is-active" src="/assets/img/brand/ranch-logo-960.webp"
             srcset="/assets/img/brand/ranch-logo-480.webp 480w, /assets/img/brand/ranch-logo-960.webp 960w, /assets/img/brand/ranch-logo-1600.webp 1600w"
             sizes="(min-width: 1024px) 46vw, 92vw" width="960" height="480" alt="" fetchpriority="high">
        <img class="logo-slide" src="/assets/img/brand/feeds-logo-960.webp"
             srcset="/assets/img/brand/feeds-logo-480.webp 480w, /assets/img/brand/feeds-logo-960.webp 960w, /assets/img/brand/feeds-logo-1600.webp 1600w"
             sizes="(min-width: 1024px) 46vw, 92vw" width="960" height="480" alt="" loading="lazy">
      </div>
    </div>
    <div class="hero-watermark" aria-hidden="true">GRAZEX</div>
  </section>

  <!-- MARQUEE -->
  <div class="marquee" aria-hidden="true">
    <div class="marquee-track">
      <?php for ($loop = 0; $loop < 2; $loop++): foreach ($marquee as $m): ?>
        <span><?= e($m) ?><i>✦</i></span>
      <?php endforeach; endfor; ?>
    </div>
  </div>

  <!-- DIVISIONS -->
  <section class="section" id="divisions" aria-labelledby="div-title">
    <div class="container">
      <div class="section-head reveal">
        <div>
          <span class="kicker"><?= e(content('gateway', 'split_kicker', 'One brand. Two engines.')) ?></span>
          <h2 id="div-title" class="display-2"><?= e(content('gateway', 'split_title', 'Ranch meets retail.')) ?></h2>
        </div>
        <p class="lead"><?= e(content('gateway', 'split_text')) ?></p>
      </div>

      <div class="portals">
        <a class="portal portal-ranch reveal" href="/ranch/">
          <img src="<?= e($ranchImg) ?>" srcset="<?= e($ranchSet) ?>" sizes="(min-width: 1024px) 50vw, 100vw" alt="" loading="lazy" width="1600" height="800">
          <div class="portal-copy">
            <div class="portal-num">01 / GrazeX Ranch</div>
            <h3 class="display-3"><?= e(content('gateway', 'ranch_card_title', 'Raise. Rotate. Finish.')) ?></h3>
            <p><?= e(content('gateway', 'ranch_card_text')) ?></p>
            <div class="portal-foot">
              <span class="badge badge-success"><span class="dot"></span><?= (int) $stats['lots'] ?> <?= e(t('gateway.lots_available')) ?></span>
              <span class="portal-cta"><?= e(t('gateway.explore_ranch')) ?> <svg class="icon arrow"><use href="#i-arrow"/></svg></span>
            </div>
          </div>
        </a>
        <a class="portal portal-feeds reveal" style="--d:.1s" href="/feeds/">
          <img src="<?= e($feedsImg) ?>" srcset="<?= e($feedsSet) ?>" sizes="(min-width: 1024px) 50vw, 100vw" alt="" loading="lazy" width="1600" height="800">
          <div class="portal-copy">
            <div class="portal-num">02 / GrazeX Feeds</div>
            <h3 class="display-3"><?= e(content('gateway', 'feeds_card_title', 'Feed that earns.')) ?></h3>
            <p><?= e(content('gateway', 'feeds_card_text')) ?></p>
            <div class="portal-foot">
              <span class="badge badge-accent"><?= (int) $stats['products'] ?> <?= e(t('gateway.products')) ?></span>
              <span class="portal-cta"><?= e(t('gateway.shop_feeds')) ?> <svg class="icon arrow"><use href="#i-arrow"/></svg></span>
            </div>
          </div>
        </a>
      </div>
    </div>
  </section>

  <!-- MODEL -->
  <section class="section model" id="model" aria-labelledby="model-title">
    <div class="container">
      <div class="section-head reveal">
        <div>
          <span class="kicker"><?= e(content('gateway', 'model_kicker', 'The GrazeX system')) ?></span>
          <h2 id="model-title" class="display-2"><?= e(content('gateway', 'model_title', 'Not just sheep. Throughput.')) ?></h2>
        </div>
        <p class="lead"><?= e(content('gateway', 'model_text')) ?></p>
      </div>
      <ol class="process">
        <?php foreach (['Buy', 'Graze', 'Finish', 'Sell'] as $i => $step): ?>
          <li class="step reveal" style="--d:<?= $i * 0.08 ?>s">
            <b>0<?= $i + 1 ?></b>
            <h3><?= e($step) ?></h3>
            <p><?= e(content('gateway', 'step_' . ($i + 1))) ?></p>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <?php if ($testimonials): ?>
  <!-- TESTIMONIALS -->
  <section class="section section-tight" aria-label="What farmers say">
    <div class="container grid-3">
      <?php foreach ($testimonials as $i => $tm): ?>
        <figure class="card card-pad quote reveal" style="--d:<?= $i * 0.08 ?>s">
          <?php if ($tm['rating']): ?><div class="stars" role="img" aria-label="<?= (int) $tm['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $tm['rating']) ?></div><?php endif; ?>
          <blockquote>“<?= e($tm['quote']) ?>”</blockquote>
          <figcaption><strong><?= e($tm['name']) ?></strong><?= $tm['location'] ? ' · ' . e($tm['location']) : '' ?></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- CTA -->
  <section class="cta" id="contact" aria-labelledby="cta-title">
    <div class="container">
      <div class="cta-box reveal">
        <span class="kicker">GrazeX • Ranch &amp; Feeds</span>
        <h2 id="cta-title" class="display-2"><?= e(content('gateway', 'cta_title', 'A farm brand that works like a business.')) ?></h2>
        <p class="lead"><?= e(content('gateway', 'cta_text')) ?></p>
        <div class="hero-actions">
          <a class="btn btn-orange btn-lg" href="/feeds/shop.php" data-magnetic>View feed range <svg class="icon arrow"><use href="#i-arrow"/></svg></a>
          <a class="btn btn-lg" href="/ranch/livestock.php">See available livestock</a>
          <?php if ($wa): ?><a class="btn btn-whatsapp btn-lg" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg>WhatsApp</a><?php endif; ?>
          <?php if ($map = setting('map_url')): ?><a class="btn btn-ghost btn-lg" href="<?= e($map) ?>" target="_blank" rel="noopener"><svg class="icon"><use href="#i-pin"/></svg><?= e(t('common.directions')) ?></a><?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
