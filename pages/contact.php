<?php
/** Contact page with form, details, hours and directions. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$c = current_customer();
$wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
$page = ['title' => 'Contact', 'description' => 'Contact GrazeX Ranch & Feeds — WhatsApp, phone, email and directions.', 'division' => 'pages',
         'css' => ['/pages/css/pages.css'], 'js' => ['/pages/js/pages.js']];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <span class="kicker">Talk to us</span>
  <h1 class="display-2 mt-1 mb-3">Contact <span class="text-fire italic">GrazeX.</span></h1>
  <div class="contact-grid">
    <section class="card card-pad">
      <h2 class="h3 mb-2">Send us a message</h2>
      <form id="contact-form" data-api="/pages/api/contact.php" novalidate>
        <div class="grid-2">
          <div class="field"><label for="name">Name</label><input class="input" id="name" name="name" autocomplete="name" value="<?= e($c ? $c['first_name'] . ' ' . $c['last_name'] : '') ?>" required></div>
          <div class="field"><label for="phone">Phone <span class="opt">(optional)</span></label><input class="input" id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e($c['phone'] ?? '') ?>"></div>
        </div>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="email" value="<?= e($c['email'] ?? '') ?>" required></div>
        <div class="field"><label for="division">About</label><select class="select" id="division" name="division"><option value="">General</option><option value="feeds">Feeds</option><option value="ranch">Livestock</option></select></div>
        <div class="field"><label for="message">Message</label><textarea class="textarea" id="message" name="message" rows="5" required></textarea></div>
        <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn btn-primary btn-lg" type="submit">Send message</button>
      </form>
    </section>
    <aside class="contact-side">
      <?php if ($wa): ?><a class="btn btn-whatsapp btn-lg btn-block" href="<?= e($wa) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg>WhatsApp us</a><?php endif; ?>
      <div class="card card-pad">
        <ul class="trust-list">
          <?php if ($p = setting('contact_phone')): ?><li><svg class="icon icon-sm"><use href="#i-phone"/></svg><a class="link" href="tel:<?= e(preg_replace('/[^\d+]/', '', $p)) ?>"><?= e($p) ?></a></li><?php endif; ?>
          <?php if ($m = setting('contact_email')): ?><li><svg class="icon icon-sm"><use href="#i-mail"/></svg><a class="link" href="mailto:<?= e($m) ?>"><?= e($m) ?></a></li><?php endif; ?>
          <?php if ($h = setting('business_hours')): ?><li><svg class="icon icon-sm"><use href="#i-clock"/></svg><?= e($h) ?></li><?php endif; ?>
          <?php if ($a = setting('physical_address')): ?><li><svg class="icon icon-sm"><use href="#i-pin"/></svg><span><?= nl2br(e($a)) ?></span></li><?php endif; ?>
        </ul>
        <?php if ($map = setting('map_url')): ?><a class="btn btn-block mt-2" href="<?= e($map) ?>" target="_blank" rel="noopener"><svg class="icon"><use href="#i-pin"/></svg>Get directions</a><?php endif; ?>
      </div>
      <div class="card card-pad center">
        <div class="row" style="justify-content:center"><img src="/assets/img/brand/feeds-logo-480.webp" alt="GrazeX Feeds" width="140" height="70"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX Ranch" width="140" height="70"></div>
      </div>
    </aside>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
