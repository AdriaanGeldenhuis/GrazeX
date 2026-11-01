<?php
/**
 * <head> + opening <body>. Set $page before including:
 *   $page = ['title' => '', 'description' => '', 'division' => 'gateway|feeds|ranch|account|pages|admin',
 *            'css' => ['/feeds/css/feeds.css'], 'js' => ['/feeds/js/feeds.js'], 'og_image' => '', 'body_class' => '',
 *            'noindex' => false, 'jsonld' => []];
 */
defined('GX_ROOT') || exit;

$page = ($page ?? []) + [
    'title' => '', 'description' => '', 'division' => 'gateway', 'css' => [], 'js' => [],
    'og_image' => '', 'body_class' => '', 'noindex' => false, 'jsonld' => [],
];
$gxSiteName   = setting('site_name', 'GrazeX');
$gxFullTitle  = $page['title'] !== '' ? $page['title'] . ' — ' . $gxSiteName : $gxSiteName . ' — ' . setting('site_tagline', 'Ranch & Feeds');
$gxMetaDesc   = $page['description'] ?: (string) setting('meta_description', '');
$gxOgImage    = $page['og_image'] ?: ($page['division'] === 'ranch' ? '/assets/img/brand/og-ranch.jpg' : '/assets/img/brand/og-feeds.jpg');
$gxCanonical  = app_url(strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/');
$gxHasAnnounce = setting_bool('announcement_enabled') && setting('announcement_text') && $page['division'] !== 'admin';
$gxBodyClass  = trim($page['body_class'] . ($gxHasAnnounce ? ' has-announce' : ''));
?><!doctype html>
<html lang="en" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($gxFullTitle) ?></title>
<meta name="description" content="<?= e(str_limit($gxMetaDesc, 300)) ?>">
<?php if ($page['noindex']): ?><meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?= csrf_meta() ?>
<meta name="theme-color" content="#050606">
<meta name="color-scheme" content="dark">
<link rel="canonical" href="<?= e($gxCanonical) ?>">
<link rel="icon" href="/assets/img/brand/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/img/brand/icon-180.png">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="preload" href="/assets/fonts/inter-400-800-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/barlow-condensed-800-latin.woff2" as="font" type="font/woff2" crossorigin>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($gxSiteName) ?>">
<meta property="og:title" content="<?= e($gxFullTitle) ?>">
<meta property="og:description" content="<?= e(str_limit($gxMetaDesc, 200)) ?>">
<meta property="og:url" content="<?= e($gxCanonical) ?>">
<meta property="og:image" content="<?= e(app_url($gxOgImage)) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="stylesheet" href="<?= e(asset('/assets/css/core.css')) ?>">
<?php foreach ($page['css'] as $gxCss): ?>
<link rel="stylesheet" href="<?= e(asset($gxCss)) ?>">
<?php endforeach; ?>
<script src="<?= e(asset('/assets/js/core.js')) ?>" defer></script>
<?php foreach ($page['js'] as $gxJs): ?>
<script src="<?= e(asset($gxJs)) ?>" defer></script>
<?php endforeach; ?>
<?php foreach ((array) $page['jsonld'] as $gxLd): ?>
<script type="application/ld+json"><?= json_encode($gxLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
</head>
<body data-division="<?= e($page['division']) ?>" class="<?= e($gxBodyClass) ?>">
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<?php require GX_ROOT . '/includes/layout/icons.php'; ?>
<div class="noise" aria-hidden="true"></div>
<div class="cursor-glow" aria-hidden="true"></div>
<?php if ($gxHasAnnounce): ?>
<div class="announce" role="note">
  <?php $gxAl = setting('announcement_link'); ?>
  <?php if ($gxAl): ?><a href="<?= e($gxAl) ?>"><?= e(setting('announcement_text')) ?></a><?php else: ?><?= e(setting('announcement_text')) ?><?php endif; ?>
</div>
<?php endif; ?>
