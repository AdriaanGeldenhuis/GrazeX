<?php
/** Customer portal sub-navigation. Set $accountTab before including. */
defined('GX_ROOT') || exit;
$gxTabs = [
    'dashboard' => ['/account/', 'Overview', 'grid'],
    'orders' => ['/account/orders.php', 'Orders', 'bag'],
    'documents' => ['/account/documents.php', 'Invoices', 'file'],
    'addresses' => ['/account/addresses.php', 'Addresses', 'pin'],
    'alerts' => ['/account/alerts.php', 'Alerts', 'bell'],
    'profile' => ['/account/profile.php', 'Profile', 'user'],
];
$gxActive = $accountTab ?? 'dashboard';
?>
<nav class="acc-nav" aria-label="My account">
  <?php foreach ($gxTabs as $gxKey => [$gxHref, $gxLabel, $gxIcon]): ?>
    <a href="<?= e($gxHref) ?>"<?= $gxActive === $gxKey ? ' aria-current="page"' : '' ?>><svg class="icon icon-sm"><use href="#i-<?= e($gxIcon) ?>"/></svg><?= e($gxLabel) ?></a>
  <?php endforeach; ?>
  <button type="button" class="acc-logout" data-logout><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>Log out</button>
</nav>
