<?php
/**
 * Site navigation. Uses $page['division'] to pick links and the cart.
 * Optional: $page['nav_current'] = 'shop' | 'specials' | ... to mark the active link.
 * The logo always goes to the main home page. The Feeds (orange) / Ranch (green) switch goes to each division.
 */
defined('GX_ROOT') || exit;

$gxDiv = $page['division'] ?? 'gateway';
$gxCurrent = $page['nav_current'] ?? '';
$gxCartDiv = in_array($gxDiv, ['feeds', 'ranch'], true) ? $gxDiv : null;
$gxCartCount = ($gxCartDiv && class_exists('CartService')) ? CartService::count($gxCartDiv) : 0;
$gxCustomer = current_customer();

$gxLinks = match ($gxDiv) {
    'feeds' => [
        ['shop', '/feeds/shop.php', t('nav.shop')],
        ['specials', '/feeds/specials.php', t('nav.specials')],
        ['calculator', '/feeds/calculator.php', t('nav.calculator')],
    ],
    'ranch' => [
        ['livestock', '/ranch/livestock.php', t('nav.livestock')],
        ['model', '/ranch/model.php', t('nav.model')],
        // Only when there are ranch FAQs (the #faq section exists only then)
        ...(division_has_faqs('ranch') ? [['faq', '/ranch/#faq', t('nav.faq')]] : []),
    ],
    default => [
        ['model', '/#model', t('nav.model')],
        ['contact', '/pages/contact.php', t('nav.contact')],
    ],
};
$gxWaLink = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
$gxLogo = '<img class="brand-logo" src="/assets/img/brand/wordmark-640.webp" srcset="/assets/img/brand/wordmark-640.webp 640w, /assets/img/brand/wordmark-1200.webp 1258w" sizes="260px" width="1258" height="138" alt="GrazeX">';
?>
<header class="site-nav glass" role="banner">
  <a class="brand" href="/" aria-label="GrazeX home"><?= $gxLogo ?></a>

  <nav class="div-switch<?= $gxCartDiv ? ' has-current' : '' ?>" aria-label="GrazeX divisions">
    <a class="ds ds-feeds" href="/feeds/"<?= $gxDiv === 'feeds' ? ' aria-current="true"' : '' ?>><span class="ds-dot" aria-hidden="true"></span><?= e(t('nav.feeds')) ?></a>
    <a class="ds ds-ranch" href="/ranch/"<?= $gxDiv === 'ranch' ? ' aria-current="true"' : '' ?>><span class="ds-dot" aria-hidden="true"></span><?= e(t('nav.ranch')) ?></a>
  </nav>

  <nav class="nav-links" aria-label="Main">
    <?php foreach ($gxLinks as [$gxKey, $gxHref, $gxLabel]): ?>
      <a href="<?= e($gxHref) ?>"<?= $gxCurrent === $gxKey ? ' aria-current="page"' : '' ?>><?= e($gxLabel) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="nav-actions">
    <?php if ($gxDiv === 'feeds'): ?>
      <a class="nav-icon-btn nav-secondary" href="/feeds/shop.php#search" aria-label="<?= e(t('common.search')) ?>"><svg class="icon"><use href="#i-search"/></svg></a>
    <?php endif; ?>
    <a class="nav-icon-btn nav-secondary" href="/account/" aria-label="<?= e($gxCustomer ? t('nav.account') : t('nav.login')) ?>">
      <svg class="icon"><use href="#i-user"/></svg>
    </a>
    <?php if ($gxCartDiv): ?>
      <a class="nav-icon-btn" href="/<?= e($gxCartDiv) ?>/cart.php" data-drawer-open="cart-drawer" data-cart-label="<?= e(t('nav.cart')) ?>"
         aria-label="<?= e(t('nav.cart') . ', ' . (int) $gxCartCount . ' item' . ((int) $gxCartCount === 1 ? '' : 's')) ?>">
        <svg class="icon"><use href="#i-cart"/></svg>
        <span class="cart-count<?= $gxCartCount ? ' has-items' : '' ?>" data-cart-count><?= (int) $gxCartCount ?></span>
      </a>
    <?php else: ?>
      <a class="btn btn-orange btn-sm hide-mobile nav-cta" href="/feeds/shop.php" data-magnetic><?= e(t('gateway.shop_feeds')) ?></a>
    <?php endif; ?>
    <button class="nav-icon-btn nav-toggle" type="button" data-menu-toggle aria-controls="menu-sheet" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
      <svg class="icon"><use href="#i-menu"/></svg>
    </button>
  </div>
</header>

<div class="menu-sheet" id="menu-sheet" role="dialog" aria-modal="true" aria-label="<?= e(t('nav.menu')) ?>" aria-hidden="true" inert>
  <div class="sheet-head">
    <a class="brand" href="/" aria-label="GrazeX home"><?= $gxLogo ?></a>
    <button class="nav-icon-btn" type="button" data-menu-close aria-label="<?= e(t('nav.close')) ?>"><svg class="icon"><use href="#i-close"/></svg></button>
  </div>
  <div class="sheet-divs">
    <a class="sheet-div sd-feeds" href="/feeds/"<?= $gxDiv === 'feeds' ? ' aria-current="true"' : '' ?>>
      <img src="<?= e(asset('/assets/img/bg/feeds-480.webp')) ?>" alt="" width="480" height="270" loading="lazy">
      <span><small>GrazeX</small><?= e(t('nav.feeds')) ?></span>
    </a>
    <a class="sheet-div sd-ranch" href="/ranch/"<?= $gxDiv === 'ranch' ? ' aria-current="true"' : '' ?>>
      <img src="<?= e(asset('/assets/img/bg/ranch-480.webp')) ?>" alt="" width="480" height="270" loading="lazy">
      <span><small>GrazeX</small><?= e(t('nav.ranch')) ?></span>
    </a>
  </div>
  <nav aria-label="Mobile">
    <?php if ($gxDiv !== 'gateway'): ?><a href="/"><?= e(t('nav.home')) ?></a><?php endif; ?>
    <?php foreach ($gxLinks as [$gxKey, $gxHref, $gxLabel]): ?>
      <a href="<?= e($gxHref) ?>"<?= $gxCurrent === $gxKey ? ' aria-current="page"' : '' ?>><?= e($gxLabel) ?></a>
    <?php endforeach; ?>
    <a href="/account/"><?= e($gxCustomer ? t('nav.account') : t('nav.login')) ?></a>
  </nav>
  <div class="sheet-foot">
    <?php if ($gxWaLink): ?>
      <a class="btn btn-whatsapp btn-block" href="<?= e($gxWaLink) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg><?= e(t('common.whatsapp')) ?></a>
    <?php endif; ?>
    <?php if ($gxP = setting('contact_phone')): ?>
      <a class="btn btn-block" href="tel:<?= e(preg_replace('/[^\d+]/', '', $gxP)) ?>"><svg class="icon"><use href="#i-phone"/></svg><?= e($gxP) ?></a>
    <?php endif; ?>
  </div>
</div>
