<?php
/**
 * Footer + closing tags. ECT Act s43 business details are shown here.
 * Set $page['hide_footer'] = true for minimal pages (checkout steps).
 */
defined('GX_ROOT') || exit;

$gxFooterMenu = [];
try {
    $gxFooterMenu = db_all("SELECT label, url FROM menu_items WHERE location = 'footer' AND is_active = 1 ORDER BY sort_order, id");
    // Pages follow their "Link in footer menu" switch: hidden/unpublished pages drop out, other shown pages are added
    $gxOff = $gxOn = [];
    foreach (db_all('SELECT slug, title, is_published, show_in_footer FROM pages ORDER BY sort_order, id') as $gxP) {
        $gxUrl = '/pages/page.php?slug=' . $gxP['slug'];
        if ((int) $gxP['is_published'] && (int) $gxP['show_in_footer']) {
            $gxOn[$gxUrl] = $gxP['title'];
        } else {
            $gxOff[$gxUrl] = true;
        }
    }
    $gxFooterMenu = array_values(array_filter($gxFooterMenu, static fn($m) => !isset($gxOff[$m['url']])));
    $gxLinked = array_flip(array_column($gxFooterMenu, 'url'));
    foreach ($gxOn as $gxUrl => $gxTitle) {
        if (!isset($gxLinked[$gxUrl])) {
            $gxFooterMenu[] = ['label' => $gxTitle, 'url' => $gxUrl];
        }
    }
} catch (Throwable $e) {
    gx_log('warning', 'Footer menu unavailable: ' . $e->getMessage());
}
$gxDivs = [];
try {
    $gxDivs = db_all('SELECT name, legal_name, registration_no, vat_registered, vat_no FROM divisions WHERE is_active = 1 ORDER BY id');
} catch (Throwable $e) {
    // ignore
}
$gxWa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
$gxPhone = setting('contact_phone');
$gxEmail = setting('contact_email');
$gxAddress = setting('physical_address');
$gxMapUrl = setting('map_url');
$gxFlashes = function_exists('flashes') && session_status() === PHP_SESSION_ACTIVE ? flashes() : [];
?>
<?php if (empty($page['hide_footer'])): ?>
<footer class="site-footer" role="contentinfo">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="brand" href="/"><span>Graze<b>X</b></span></a>
        <p class="muted small mt-2" style="max-width:360px"><?= e(content('gateway', 'hero_text', 'Ranch & Feeds')) ?></p>
        <div class="footer-logos">
          <a href="/feeds/" aria-label="GrazeX Feeds"><img src="/assets/img/brand/feeds-logo-480.webp" alt="GrazeX Feeds" width="120" height="60" loading="lazy"></a>
          <a href="/ranch/" aria-label="GrazeX Ranch"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX Ranch" width="120" height="60" loading="lazy"></a>
        </div>
        <div class="social">
          <?php foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_tiktok' => 'TikTok'] as $gxK => $gxLabel): ?>
            <?php if ($gxU = setting($gxK)): ?><a class="chip" href="<?= e($gxU) ?>" target="_blank" rel="noopener"><?= e($gxLabel) ?></a><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <h4><?= e(t('footer.shop')) ?></h4>
        <ul>
          <li><a href="/feeds/shop.php">Feed shop</a></li>
          <li><a href="/feeds/specials.php">Specials</a></li>
          <li><a href="/feeds/calculator.php">Feed calculator</a></li>
          <li><a href="/ranch/livestock.php">Livestock available</a></li>
          <li><a href="/account/">My account</a></li>
        </ul>
      </div>
      <div>
        <h4><?= e(t('footer.info')) ?></h4>
        <ul>
          <?php foreach ($gxFooterMenu as $gxM): ?>
            <li><a href="<?= e($gxM['url']) ?>"><?= e($gxM['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4><?= e(t('footer.visit')) ?></h4>
        <ul>
          <?php if ($gxAddress): ?><li class="muted small"><?= nl2br(e($gxAddress)) ?></li><?php endif; ?>
          <?php if ($gxH = setting('business_hours')): ?><li class="muted small"><?= e($gxH) ?></li><?php endif; ?>
          <?php if ($gxPhone): ?><li><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $gxPhone)) ?>"><?= e($gxPhone) ?></a></li><?php endif; ?>
          <?php if ($gxEmail): ?><li><a href="mailto:<?= e($gxEmail) ?>"><?= e($gxEmail) ?></a></li><?php endif; ?>
          <?php if ($gxMapUrl): ?><li><a href="<?= e($gxMapUrl) ?>" target="_blank" rel="noopener"><?= e(t('common.directions')) ?> ↗</a></li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>GrazeX © <?= date('Y') ?> · <?= e(t('footer.rights')) ?></span>
      <span>
        <?php foreach ($gxDivs as $gxI => $gxD): ?>
          <?= $gxI ? ' · ' : '' ?><?= e($gxD['legal_name'] ?: $gxD['name']) ?><?= $gxD['registration_no'] ? ' (Reg ' . e($gxD['registration_no']) . ')' : '' ?><?= $gxD['vat_registered'] && $gxD['vat_no'] ? ' · VAT ' . e($gxD['vat_no']) : '' ?>
        <?php endforeach; ?>
      </span>
      <span><?= e(t('footer.divisions')) ?></span>
    </div>
  </div>
</footer>
<?php endif; ?>

<?php if ($gxWa && empty($page['hide_fab'])): ?>
<a class="fab-whatsapp" href="<?= e($gxWa) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('common.whatsapp')) ?>"><svg aria-hidden="true"><use href="#i-whatsapp"/></svg></a>
<?php endif; ?>

<div class="toast-stack" role="status" aria-live="polite"></div>
<?php if ($gxFlashes): ?>
<script type="application/json" id="gx-flash"><?= json_encode($gxFlashes, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
</body>
</html>
