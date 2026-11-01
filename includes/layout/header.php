<?php
/**
 * Site navigation. Uses $page['division'] to pick links and the cart.
 * Optional: $page['nav_current'] = 'shop' | 'specials' | ... to mark the active link.
 */
defined('GX_ROOT') || exit;

$div = $page['division'] ?? 'gateway';
$current = $page['nav_current'] ?? '';
$cartDiv = in_array($div, ['feeds', 'ranch'], true) ? $div : null;
$cartCount = ($cartDiv && class_exists('CartService')) ? CartService::count($cartDiv) : 0;
$customer = current_customer();

$links = match ($div) {
    'feeds' => [
        ['shop', '/feeds/shop.php', t('nav.shop')],
        ['specials', '/feeds/specials.php', t('nav.specials')],
        ['calculator', '/feeds/calculator.php', t('nav.calculator')],
        ['ranch', '/ranch/', t('nav.ranch') . ' ↗'],
    ],
    'ranch' => [
        ['livestock', '/ranch/livestock.php', t('nav.livestock')],
        ['model', '/ranch/model.php', t('nav.model')],
        ['faq', '/ranch/#faq', 'FAQ'],
        ['feeds', '/feeds/', t('nav.feeds') . ' ↗'],
    ],
    default => [
        ['ranch', '/ranch/', t('nav.ranch')],
        ['feeds', '/feeds/', t('nav.feeds')],
        ['model', '/#model', t('nav.model')],
        ['contact', '/pages/contact.php', t('nav.contact')],
    ],
};
$homeHref = $cartDiv ? '/' . $cartDiv . '/' : '/';
$waLink = whatsapp_link(setting('whatsapp_number'), 'Hi GrazeX, ');
?>
<header class="site-nav glass" role="banner">
  <a class="brand" href="<?= e($homeHref) ?>" aria-label="GrazeX <?= $cartDiv ? e(ucfirst($cartDiv)) : '' ?> home">
    <span>Graze<b>X</b></span>
    <?php if ($cartDiv): ?><span class="brand-div"><?= e(strtoupper($cartDiv)) ?></span><?php endif; ?>
  </a>

  <nav class="nav-links" aria-label="Main">
    <?php foreach ($links as [$key, $href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="nav-actions">
    <?php if ($div === 'feeds'): ?>
      <a class="nav-icon-btn" href="/feeds/shop.php#search" aria-label="<?= e(t('common.search')) ?>"><svg class="icon"><use href="#i-search"/></svg></a>
    <?php endif; ?>
    <a class="nav-icon-btn" href="/account/" aria-label="<?= e($customer ? t('nav.account') : t('nav.login')) ?>">
      <svg class="icon"><use href="#i-user"/></svg>
    </a>
    <?php if ($cartDiv): ?>
      <a class="nav-icon-btn" href="/<?= e($cartDiv) ?>/cart.php" data-drawer-open="cart-drawer" aria-label="<?= e(t('nav.cart')) ?>">
        <svg class="icon"><use href="#i-cart"/></svg>
        <span class="cart-count<?= $cartCount ? ' has-items' : '' ?>" data-cart-count><?= (int) $cartCount ?></span>
      </a>
    <?php else: ?>
      <a class="btn btn-primary btn-sm hide-mobile" href="/feeds/shop.php" data-magnetic><?= e(t('gateway.shop_feeds')) ?></a>
    <?php endif; ?>
    <button class="nav-icon-btn nav-toggle" type="button" data-menu-toggle aria-controls="menu-sheet" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
      <svg class="icon"><use href="#i-menu"/></svg>
    </button>
  </div>
</header>

<div class="menu-sheet" id="menu-sheet" aria-hidden="true">
  <div class="sheet-head">
    <a class="brand" href="/"><span>Graze<b>X</b></span></a>
    <button class="nav-icon-btn" type="button" data-menu-close aria-label="<?= e(t('nav.close')) ?>"><svg class="icon"><use href="#i-close"/></svg></button>
  </div>
  <nav aria-label="Mobile">
    <?php if ($div !== 'gateway'): ?><a href="/"><?= e(t('nav.home')) ?></a><?php endif; ?>
    <?php foreach ($links as [$key, $href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="/account/"><?= e($customer ? t('nav.account') : t('nav.login')) ?></a>
  </nav>
  <div class="sheet-foot">
    <?php if ($waLink): ?>
      <a class="btn btn-whatsapp btn-block" href="<?= e($waLink) ?>" target="_blank" rel="noopener"><svg class="icon icon-fill"><use href="#i-whatsapp"/></svg><?= e(t('common.whatsapp')) ?></a>
    <?php endif; ?>
    <?php if ($p = setting('contact_phone')): ?>
      <a class="btn btn-block" href="tel:<?= e(preg_replace('/[^\d+]/', '', $p)) ?>"><svg class="icon"><use href="#i-phone"/></svg><?= e($p) ?></a>
    <?php endif; ?>
  </div>
</div>
