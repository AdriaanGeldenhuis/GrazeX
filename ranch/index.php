<?php
/**
 * GrazeX Ranch — storefront home.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$divId = division_id('ranch');
$now = LivestockService::lots(['per_page' => 6]);
$soon = LivestockService::lots(['status' => 'soon', 'per_page' => 3]);
$breeds = LivestockService::breeds();
$cats = LivestockService::categories();
$stats = db_one("SELECT COUNT(*) AS lots, COALESCE(SUM(qty_available),0) AS head, ROUND(AVG(avg_weight_kg),1) AS avg_w
                 FROM lots WHERE status IN ('available','reserved') AND qty_available > 0");
$faqs = db_all('SELECT question, answer FROM faqs WHERE is_active = 1 AND division_id = ? ORDER BY sort_order, id', [$divId]);
$testimonials = db_all('SELECT name, location, quote, rating FROM testimonials WHERE is_active = 1 AND division_id = ? ORDER BY sort_order LIMIT 3', [$divId]);

$page = [
    'title' => 'Ranch',
    'description' => content('ranch', 'hero_text'),
    'division' => 'ranch',
    'nav_current' => 'home',
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'js' => ['/assets/js/cart.js', '/ranch/js/ranch.js'],
    'og_image' => '/assets/img/brand/og-ranch.jpg',
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main">
  <section class="store-hero ranch-hero">
    <div class="container store-hero-grid">
      <div>
        <span class="eyebrow hero-rise"><span class="dot"></span><?= e(content('ranch', 'hero_kicker', 'GrazeX Ranch')) ?></span>
        <h1 class="display-1 hero-rise" style="--d:.06s"><span class="text-chrome italic"><?= e(content('ranch', 'hero_title', 'Raise. Rotate. Finish.')) ?></span></h1>
        <p class="lead hero-rise" style="--d:.12s"><?= e(content('ranch', 'hero_text')) ?></p>
        <div class="hero-actions hero-rise" style="--d:.18s">
          <a class="btn btn-green btn-lg" href="/ranch/livestock.php" data-magnetic>See available livestock <svg class="icon arrow"><use href="#i-arrow"/></svg></a>
          <a class="btn btn-chrome btn-lg" href="/ranch/model.php">How we farm</a>
        </div>
        <dl class="ranch-stats hero-rise" style="--d:.24s">
          <div><dt>Lots available</dt><dd data-count="<?= (int) $stats['lots'] ?>"><?= (int) $stats['lots'] ?></dd></div>
          <div><dt>Head ready</dt><dd data-count="<?= (int) $stats['head'] ?>"><?= (int) $stats['head'] ?></dd></div>
          <div><dt>Avg weight</dt><dd><?= $stats['avg_w'] ? e(number_format((float) $stats['avg_w'], 1)) . '<small>kg</small>' : '—' ?></dd></div>
        </dl>
      </div>
      <img class="hero-logo hero-rise" style="--d:.1s" src="/assets/img/brand/ranch-logo-960.webp"
           srcset="/assets/img/brand/ranch-logo-480.webp 480w, /assets/img/brand/ranch-logo-960.webp 960w, /assets/img/brand/ranch-logo-1600.webp 1600w"
           sizes="(min-width: 1024px) 40vw, 90vw" width="960" height="480" alt="GrazeX Ranch" fetchpriority="high">
    </div>
    <div class="watermark" aria-hidden="true">RANCH</div>
  </section>

  <section class="section-tight" aria-labelledby="now-title">
    <div class="container">
      <div class="strip-head">
        <div><span class="kicker">Available now</span><h2 id="now-title" class="display-3 mt-1">On the board</h2></div>
        <a class="btn btn-sm" href="/ranch/livestock.php">All livestock <svg class="icon icon-sm arrow"><use href="#i-arrow"/></svg></a>
      </div>
      <div class="chips chips-scroll mb-3">
        <?php foreach ($cats as $c): if (!(int) $c['lot_count']) continue; ?>
          <a class="chip" href="/ranch/livestock.php?category=<?= e($c['slug']) ?>"><?= e($c['name']) ?> <span class="count"><?= (int) $c['lot_count'] ?></span></a>
        <?php endforeach; ?>
        <?php foreach ($breeds as $b): if (!(int) $b['lot_count']) continue; ?>
          <a class="chip" href="/ranch/livestock.php?breed=<?= e($b['slug']) ?>"><?= e($b['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($now['items']): ?>
        <div class="l-grid">
          <?php foreach ($now['items'] as $i => $l): ?><?= partial('lot-card', ['l' => $l, 'i' => $i]) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <div class="es-icon"><svg class="icon icon-lg"><use href="#i-sheep"/></svg></div>
          <h3>Nothing on the board right now</h3>
          <p>New groups are added regularly. Get an alert the moment the next lot is ready.</p>
          <a class="btn btn-primary" href="#notify">Notify me</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="section model-band" aria-labelledby="model-title">
    <div class="container">
      <div class="section-head reveal">
        <div><span class="kicker"><?= e(content('gateway', 'model_kicker', 'The GrazeX system')) ?></span>
          <h2 id="model-title" class="display-2 mt-1"><?= e(content('gateway', 'model_title', 'Not just sheep. Throughput.')) ?></h2></div>
        <p class="lead"><?= e(content('gateway', 'model_text')) ?></p>
      </div>
      <ol class="ranch-steps">
        <?php foreach (['Buy', 'Graze', 'Finish', 'Sell'] as $i => $step): ?>
          <li class="reveal" style="--d:<?= $i * 0.08 ?>s"><b>0<?= $i + 1 ?></b><h3><?= e($step) ?></h3><p><?= e(content('gateway', 'step_' . ($i + 1))) ?></p></li>
        <?php endforeach; ?>
      </ol>
      <div class="usps mt-3">
        <span class="usp"><svg class="icon icon-sm"><use href="#i-scale"/></svg><?= e(content('ranch', 'usp_1', 'Weighed & health-recorded')) ?></span>
        <span class="usp"><svg class="icon icon-sm"><use href="#i-lock"/></svg><?= e(content('ranch', 'usp_2', 'Reserve with a deposit')) ?></span>
        <span class="usp"><svg class="icon icon-sm"><use href="#i-file"/></svg><?= e(content('ranch', 'usp_3', 'Documents with every sale')) ?></span>
      </div>
    </div>
  </section>

  <section class="section-tight" id="notify" aria-labelledby="soon-title">
    <div class="container">
      <div class="feature-band">
        <div>
          <span class="kicker">Coming soon</span>
          <h2 id="soon-title" class="display-3 mt-1">Be first in line.</h2>
          <p class="muted mt-2">Tell us what you are looking for and we'll email you as soon as a matching lot goes on the board.</p>
          <form class="notify-form mt-2" data-alert-form novalidate>
            <div class="grid-2">
              <div class="field"><label for="al-breed">Breed</label>
                <select class="select" id="al-breed" name="breed"><option value="">Any breed</option>
                  <?php foreach ($breeds as $b): ?><option value="<?= e($b['slug']) ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="al-cat">Type</label>
                <select class="select" id="al-cat" name="category"><option value="">Any type</option>
                  <?php foreach ($cats as $c): ?><option value="<?= e($c['slug']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label for="al-email">Email</label>
              <div class="input-group"><input class="input" id="al-email" name="email" type="email" autocomplete="email" value="<?= e(current_customer()['email'] ?? '') ?>" required>
              <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-bell"/></svg>Notify me</button></div></div>
          </form>
        </div>
        <div class="soon-list">
          <?php if ($soon['items']): foreach ($soon['items'] as $i => $l): ?>
            <a class="soon-item" href="<?= e(lot_url($l['slug'])) ?>">
              <span class="badge badge-info">Coming <?= $l['available_from'] ? e(fmt_date($l['available_from'], 'j M')) : 'soon' ?></span>
              <strong><?= e($l['title']) ?></strong>
              <span class="muted small"><?= (int) $l['qty_total'] ?> head · <?= e($l['breed_name']) ?><?= $l['avg_weight_kg'] ? ' · ±' . e(kg((float) $l['avg_weight_kg'], 0)) : '' ?></span>
            </a>
          <?php endforeach; else: ?>
            <p class="muted">No upcoming lots announced yet — set an alert and we'll let you know.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if ($testimonials): ?>
  <section class="section-tight" aria-label="What buyers say">
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
  <section class="section" id="faq" aria-labelledby="faq-title">
    <div class="container container-narrow">
      <span class="kicker">Buying livestock</span>
      <h2 id="faq-title" class="display-3 mt-1 mb-3">How it works</h2>
      <?php foreach ($faqs as $f): ?>
        <details class="faq"><summary><?= e($f['question']) ?></summary><div class="faq-body"><?= e($f['answer']) ?></div></details>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</main>
<?= partial('cart-drawer', ['div' => 'ranch']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
