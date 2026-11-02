<?php
/**
 * GrazeX Ranch — lot detail with reservation, health records and weight history.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', query_str('slug'));
$l = $slug !== '' ? LivestockService::lot($slug) : null;
if (!$l) {
    $page = ['division' => 'ranch'];
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}
redirect_to_pretty(lot_url($l['slug']));

$canBuy = in_array($l['status'], ['available', 'reserved'], true) && (int) $l['qty_available'] > 0;
[$badgeLabel, $badgeClass] = LivestockService::badge($l);
$price = LivestockService::priceLabel($l);
$age = LivestockService::ageLabel($l);
$url = app_url(lot_url($l['slug']));
$wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX Ranch, I am interested in ' . $l['title'] . ' (' . $l['lot_no'] . ') ' . $url);
$lotJson = ['id' => (int) $l['id'], 'mode' => $l['pricing_mode'], 'price' => (int) $l['price_cents'],
            'avg' => $l['avg_weight_kg'] !== null ? (float) $l['avg_weight_kg'] : null, 'deposit' => (int) $l['deposit_pct'], 'max' => max(1, (int) $l['qty_available'])];
$customer = current_customer();

// Weight history chart (inline SVG, server-rendered)
$chart = '';
if (count($l['weighs']) >= 2) {
    $w = 600; $h = 220; $pad = 34;
    $vals = array_map(static fn($x) => (float) $x['avg_weight_kg'], $l['weighs']);
    $min = floor(min($vals) - 2); $max = ceil(max($vals) + 2);
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $pad + ($w - 2 * $pad) * ($n > 1 ? $i / ($n - 1) : 0);
        $y = $h - $pad - ($h - 2 * $pad) * (($v - $min) / max(1, $max - $min));
        $pts[] = [round($x, 1), round($y, 1), $v, $l['weighs'][$i]['weighed_on']];
    }
    $line = implode(' ', array_map(static fn($p) => $p[0] . ',' . $p[1], $pts));
    $area = $line . ' ' . end($pts)[0] . ',' . ($h - $pad) . ' ' . $pts[0][0] . ',' . ($h - $pad);
    $gain = $vals[$n - 1] - $vals[0];
    $daysSpan = max(1, (strtotime($l['weighs'][$n - 1]['weighed_on']) - strtotime($l['weighs'][0]['weighed_on'])) / 86400);
    $adg = $gain / $daysSpan * 1000;
    ob_start(); ?>
    <figure class="weigh-chart">
      <svg viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="Average weight rose from <?= e(kg($vals[0])) ?> to <?= e(kg($vals[$n - 1])) ?>">
        <defs><linearGradient id="wg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#86d51a" stop-opacity=".35"/><stop offset="1" stop-color="#86d51a" stop-opacity="0"/></linearGradient></defs>
        <polygon points="<?= $area ?>" fill="url(#wg)"/>
        <polyline points="<?= $line ?>" fill="none" stroke="#86d51a" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
        <?php foreach ($pts as $p): ?>
          <circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="5" fill="#050606" stroke="#b5f04f" stroke-width="2.5"/>
          <text x="<?= $p[0] ?>" y="<?= $p[1] - 12 ?>" text-anchor="middle" fill="#f4f4ef" font-size="13" font-weight="700"><?= e(number_format($p[2], 1)) ?></text>
          <text x="<?= $p[0] ?>" y="<?= $h - 10 ?>" text-anchor="middle" fill="#7f8781" font-size="11"><?= e(fmt_date($p[3], 'j M')) ?></text>
        <?php endforeach; ?>
      </svg>
      <figcaption class="row-between small"><span class="muted">Average live weight (kg)</span>
        <span class="badge badge-success">+<?= e(number_format($gain, 1)) ?> kg · ±<?= (int) round($adg) ?> g/day</span></figcaption>
    </figure>
    <?php $chart = (string) ob_get_clean();
}

$page = [
    'title' => $l['meta_title'] ?: $l['title'] . ' (' . $l['lot_no'] . ')',
    'description' => $l['meta_description'] ?: ($l['short_description'] ?? ''),
    'division' => 'ranch',
    'nav_current' => 'livestock',
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'js' => ['/assets/js/cart.js', '/ranch/js/ranch.js'],
    'og_image' => $l['media'] ? (media_url((int) $l['media'][0], 1600, 'wide') ?? '') : '',
    'canonical' => lot_url($l['slug']),
    'jsonld' => [[
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $l['title'], 'sku' => $l['lot_no'],
        'description' => $l['short_description'], 'category' => trim(($l['breed_name'] ?? '') . ' ' . ($l['category_name'] ?? '')),
        'image' => $l['media'] ? app_url((string) media_url((int) $l['media'][0], 1600, 'wide')) : null, 'url' => $url,
        'offers' => ['@type' => 'Offer', 'priceCurrency' => 'ZAR', 'url' => $url,
            // Per-kg lots are priced per kilogram of live weight
            'price' => number_format((int) $l['price_cents'] / 100, 2, '.', ''),
            'priceSpecification' => $l['pricing_mode'] === 'per_kg'
                ? ['@type' => 'UnitPriceSpecification', 'price' => number_format((int) $l['price_cents'] / 100, 2, '.', ''), 'priceCurrency' => 'ZAR', 'unitCode' => 'KGM']
                : null,
            'availability' => $l['status'] === 'coming_soon' ? 'https://schema.org/PreOrder'
                : ($canBuy ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut'),
            'availabilityStarts' => $l['status'] === 'coming_soon' ? $l['available_from'] : null],
    ], jsonld_breadcrumbs([['Ranch', '/ranch/'], ['Livestock', '/ranch/livestock.php'], [$l['title'], lot_url($l['slug'])]])],
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container">
  <div class="detail">
    <div class="gallery">
      <div class="gallery-main is-lot">
        <?= media_img($l['media'][0] ?? null, $l['title'], '(min-width: 1024px) 55vw, 100vw', 'wide', false, 'sheep') ?>
        <span class="l-flags"><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></span>
      </div>
      <?php if (count($l['media']) > 1): ?>
        <div class="gallery-thumbs">
          <?php foreach ($l['media'] as $i => $mid): ?>
            <button type="button" data-gallery-src="<?= e(media_url((int) $mid, 1600, 'wide')) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Photo <?= $i + 1 ?>"><?= media_img((int) $mid, '', '76px', 'wide') ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($l['video_url'] && preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/)([\w-]{11})#', $l['video_url'], $vm)): ?>
        <div class="media-16x9" style="border-radius:20px">
          <iframe src="https://www.youtube-nocookie.com/embed/<?= e($vm[1]) ?>" title="Video of <?= e($l['title']) ?>" loading="lazy" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
        </div>
      <?php endif; ?>
    </div>

    <div class="buy-box" id="lot-box" data-lot="<?= e_json($lotJson) ?>">
      <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/ranch/">Ranch</a><span>/</span><a href="/ranch/livestock.php">Livestock</a><span>/</span><span><?= e($l['lot_no']) ?></span></nav>
      <div class="row">
        <span class="badge"><?= e($l['breed_name']) ?></span>
        <span class="badge badge-accent"><?= e($l['category_name']) ?></span>
        <span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
      </div>
      <h1><?= e($l['title']) ?></h1>
      <?php if ($l['short_description']): ?><p class="lead mb-0"><?= e($l['short_description']) ?></p><?php endif; ?>

      <dl class="lot-facts">
        <div><dt>Available</dt><dd><?= $l['status'] === 'coming_soon' ? (int) $l['qty_total'] . ' soon' : (int) $l['qty_available'] . ' head' ?></dd></div>
        <div><dt>Avg weight</dt><dd><?= $l['avg_weight_kg'] !== null ? e(kg((float) $l['avg_weight_kg'])) : '—' ?></dd></div>
        <div><dt>Sex</dt><dd><?= e(LivestockService::sexLabel($l['sex'])) ?></dd></div>
        <div><dt>Age</dt><dd><?= e($age ?? '—') ?></dd></div>
        <?php if ($l['tag_no']): ?><div><dt>Tag</dt><dd><?= e($l['tag_no']) ?></dd></div><?php endif; ?>
        <div><dt>Lot</dt><dd><?= e($l['lot_no']) ?></dd></div>
      </dl>

      <div>
        <div class="price-row"><span class="price price-lg"><?= e($price['main']) ?></span><span class="price-unit"><?= e($price['unit']) ?></span><?= vat_note('ranch') ?></div>
        <?php if ($price['note']): ?><div class="price-note"><?= e($price['note']) ?> at the current average weight</div><?php endif; ?>
      </div>

      <?php if ($canBuy): ?>
        <div class="reserve-calc card card-pad">
          <div class="buy-actions">
            <div class="stepper">
              <button type="button" data-step="down" aria-label="Fewer">−</button>
              <input id="lot-qty" type="number" inputmode="numeric" min="1" max="<?= (int) $l['qty_available'] ?>" value="<?= min((int) $l['qty_available'], (int) $l['qty_total'] > 1 ? 5 : 1) ?>" aria-label="Number of animals">
              <button type="button" data-step="up" aria-label="More">+</button>
            </div>
            <?php if ((int) $l['qty_available'] > 1): ?><button class="btn btn-sm" type="button" data-take-all="<?= (int) $l['qty_available'] ?>">Take all <?= (int) $l['qty_available'] ?></button><?php endif; ?>
          </div>
          <dl class="cart-totals mt-2 mb-0">
            <div><dt><?= $l['pricing_mode'] === 'per_kg' ? 'Estimated total' : 'Total' ?></dt><dd data-lot-total>—</dd></div>
            <?php if ($l['pricing_mode'] === 'per_kg'): ?><div><dt>Estimated weight</dt><dd data-lot-weight>—</dd></div><?php endif; ?>
            <?php if ((int) $l['deposit_pct'] > 0): ?><div class="ct-total"><dt>Deposit to reserve (<?= (int) $l['deposit_pct'] ?>%)</dt><dd data-lot-deposit>—</dd></div><?php endif; ?>
          </dl>
          <button class="btn btn-primary btn-lg btn-block mt-2" type="button" data-add-to-cart data-target="<?= (int) $l['id'] ?>" data-qty-from="#lot-qty" id="reserve-btn">
            <svg class="icon"><use href="#i-lock"/></svg> Reserve these animals
          </button>
          <?php if ($l['pricing_mode'] === 'per_kg'): ?>
            <p class="hint mt-1 mb-0">Priced per kg live weight. Your final invoice uses the actual weights on collection day.</p>
          <?php endif; ?>
        </div>
      <?php elseif ($l['status'] === 'coming_soon'): ?>
        <div class="alert alert-info"><svg class="icon"><use href="#i-calendar"/></svg><div><strong>Coming <?= $l['available_from'] ? e(fmt_date($l['available_from'])) : 'soon' ?>.</strong> Get an email the moment this lot opens for reservations.</div></div>
        <form class="input-group" data-alert-form data-breed="<?= e($l['breed_slug']) ?>" data-category="<?= e($l['category_slug']) ?>" novalidate>
          <label class="sr-only" for="soon-email">Email</label>
          <input class="input" id="soon-email" name="email" type="email" placeholder="Your email" value="<?= e($customer['email'] ?? '') ?>" required>
          <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-bell"/></svg>Notify me</button>
        </form>
      <?php else: ?>
        <div class="alert alert-warning">This lot has been sold. <a class="link" href="/ranch/livestock.php">See what's available</a>.</div>
      <?php endif; ?>

      <ul class="trust-list">
        <li><svg class="icon icon-sm"><use href="#i-pin"/></svg>Collect at the farm<?= $l['location'] ? ' · ' . e($l['location']) : '' ?> — book a collection time at checkout</li>
        <li><svg class="icon icon-sm"><use href="#i-file"/></svg>Tax invoice and document of identification with every sale</li>
        <li><svg class="icon icon-sm"><use href="#i-shield"/></svg>Health records below — vaccinations, dosing and withdrawal periods</li>
      </ul>
      <div class="row">
        <?php if ($wa): ?><a class="btn btn-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg>WhatsApp</a><?php endif; ?>
        <button class="btn" type="button" data-modal-open="enquiry-modal"><svg class="icon"><use href="#i-mail"/></svg>Ask a question</button>
      </div>
    </div>
  </div>

  <section class="section-tight">
    <div class="tabs" role="tablist" aria-label="Lot information">
      <button class="tab" role="tab" aria-selected="true" aria-controls="tab-about" id="t-about">About this lot</button>
      <?php if ($chart): ?><button class="tab" role="tab" aria-selected="false" aria-controls="tab-weights" id="t-weights">Weight history</button><?php endif; ?>
      <button class="tab" role="tab" aria-selected="false" aria-controls="tab-health" id="t-health">Health records <span class="count">(<?= count($l['health']) ?>)</span></button>
      <?php if ($l['breed_description']): ?><button class="tab" role="tab" aria-selected="false" aria-controls="tab-breed" id="t-breed">The breed</button><?php endif; ?>
    </div>
    <div class="tab-panel prose" role="tabpanel" id="tab-about" aria-labelledby="t-about">
      <?= markdown($l['description_md']) ?: '<p class="muted">More information coming soon.</p>' ?>
      <?php if ($l['health_summary']): ?><p><strong>Health:</strong> <?= e($l['health_summary']) ?></p><?php endif; ?>
    </div>
    <?php if ($chart): ?><div class="tab-panel" role="tabpanel" id="tab-weights" aria-labelledby="t-weights" hidden><div style="max-width:720px"><?= $chart ?></div></div><?php endif; ?>
    <div class="tab-panel" role="tabpanel" id="tab-health" aria-labelledby="t-health" hidden>
      <?php if ($l['health']): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Date</th><th>Type</th><th>Product / detail</th><th>Notes</th><th>Withdrawal until</th></tr></thead>
          <tbody>
          <?php foreach ($l['health'] as $hr): ?>
            <tr><td class="nowrap"><?= e(fmt_date($hr['record_date'])) ?></td><td><span class="badge"><?= e(ucfirst($hr['type'])) ?></span></td>
              <td><?= e($hr['product_used'] ?? '—') ?></td><td class="muted"><?= e($hr['notes'] ?? '') ?></td>
              <td class="nowrap"><?= $hr['withdrawal_until'] ? e(fmt_date($hr['withdrawal_until'])) . (strtotime($hr['withdrawal_until']) < time() ? ' <span class="badge badge-success">Cleared</span>' : ' <span class="badge badge-warning">Active</span>') : '—' ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php else: ?><p class="muted">No health records captured yet.</p><?php endif; ?>
    </div>
    <?php if ($l['breed_description']): ?><div class="tab-panel prose" role="tabpanel" id="tab-breed" aria-labelledby="t-breed" hidden><?= markdown($l['breed_description']) ?></div><?php endif; ?>
  </section>
</main>

<dialog class="modal" id="enquiry-modal" aria-labelledby="enq-title">
  <form data-enquiry-form data-api="/ranch/api/enquiry.php" novalidate>
    <div class="modal-head"><h3 id="enq-title">Ask about <?= e($l['lot_no']) ?></h3><button class="nav-icon-btn" type="button" data-modal-close aria-label="Close"><svg class="icon"><use href="#i-close"/></svg></button></div>
    <div class="modal-body">
      <input type="hidden" name="lot_id" value="<?= (int) $l['id'] ?>">
      <div class="field"><label for="enq-name">Name</label><input class="input" id="enq-name" name="name" autocomplete="name" value="<?= e($customer ? $customer['first_name'] . ' ' . $customer['last_name'] : '') ?>" required></div>
      <div class="grid-2">
        <div class="field"><label for="enq-email">Email</label><input class="input" id="enq-email" name="email" type="email" autocomplete="email" value="<?= e($customer['email'] ?? '') ?>" required></div>
        <div class="field"><label for="enq-phone">Phone <span class="opt">(optional)</span></label><input class="input" id="enq-phone" name="phone" type="tel" autocomplete="tel" value="<?= e($customer['phone'] ?? '') ?>"></div>
      </div>
      <div class="field"><label for="enq-msg">Your question</label><textarea class="textarea" id="enq-msg" name="message" required>Hi, I'm interested in <?= e($l['title']) ?> (<?= e($l['lot_no']) ?>). </textarea></div>
      <div class="hidden-field" aria-hidden="true"><label>Leave empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Send question</button></div>
  </form>
</dialog>

<?php if ($canBuy): ?>
<div class="sticky-buy" data-sticky-buy aria-hidden="true" inert>
  <div class="sb-info"><div class="sb-name"><?= e($l['title']) ?></div><div class="price" style="font-size:17px"><?= e($price['main']) ?><span class="price-unit"> <?= e($price['unit']) ?></span></div></div>
  <button class="btn btn-primary" type="button" data-scroll-buy>Reserve</button>
</div>
<?php endif; ?>
<?= partial('cart-drawer', ['div' => 'ranch']) ?>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
