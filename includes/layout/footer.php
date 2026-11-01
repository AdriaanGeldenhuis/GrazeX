<?php
/**
 * Footer + closing tags. ECT Act s43 business details are shown here.
 * Set $page['hide_footer'] = true for minimal pages (checkout steps).
 */
defined('GX_ROOT') || exit;

$footerMenu = [];
try {
    $footerMenu = db_all("SELECT label, url FROM menu_items WHERE location = 'footer' AND is_active = 1 ORDER BY sort_order, id");
} catch (Throwable $e) {
    gx_log('warning', 'Footer menu unavailable: ' . $e->getMessage());
}
$divs = [];
try {
    $divs = db_all('SELECT name, legal_name, registration_no, vat_registered, vat_no FROM divisions WHERE is_active = 1 ORDER BY id');
} catch (Throwable $e) {
    // ignore
}
$wa = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
$phone = setting('contact_phone');
$email = setting('contact_email');
$address = setting('physical_address');
$mapUrl = setting('map_url');
$flashes = function_exists('flashes') && session_status() === PHP_SESSION_ACTIVE ? flashes() : [];
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
          <?php foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_tiktok' => 'TikTok'] as $k => $label): ?>
            <?php if ($u = setting($k)): ?><a class="chip" href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><?php endif; ?>
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
          <?php foreach ($footerMenu as $m): ?>
            <li><a href="<?= e($m['url']) ?>"><?= e($m['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4><?= e(t('footer.visit')) ?></h4>
        <ul>
          <?php if ($address): ?><li class="muted small"><?= nl2br(e($address)) ?></li><?php endif; ?>
          <?php if ($h = setting('business_hours')): ?><li class="muted small"><?= e($h) ?></li><?php endif; ?>
          <?php if ($phone): ?><li><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
          <?php if ($email): ?><li><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
          <?php if ($mapUrl): ?><li><a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener"><?= e(t('common.directions')) ?> ↗</a></li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>GrazeX © <?= date('Y') ?> · <?= e(t('footer.rights')) ?></span>
      <span>
        <?php foreach ($divs as $i => $d): ?>
          <?= $i ? ' · ' : '' ?><?= e($d['legal_name'] ?: $d['name']) ?><?= $d['registration_no'] ? ' (Reg ' . e($d['registration_no']) . ')' : '' ?><?= $d['vat_registered'] && $d['vat_no'] ? ' · VAT ' . e($d['vat_no']) : '' ?>
        <?php endforeach; ?>
      </span>
      <span><?= e(t('footer.divisions')) ?></span>
    </div>
  </div>
</footer>
<?php endif; ?>

<?php if ($wa && empty($page['hide_fab'])): ?>
<a class="fab-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('common.whatsapp')) ?>"><svg aria-hidden="true"><use href="#i-whatsapp"/></svg></a>
<?php endif; ?>

<div class="toast-stack" role="status" aria-live="polite"></div>
<?php if ($flashes): ?>
<script type="application/json" id="gx-flash"><?= json_encode($flashes, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
</body>
</html>
