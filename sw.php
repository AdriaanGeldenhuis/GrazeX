<?php
/**
 * Service worker, served as /sw.js (see .htaccess). PHP writes in the current asset versions, so the precache holds
 * exactly the files the pages ask for (?v=…), and every deploy gets a fresh cache.
 * Pages and APIs always go to the network (prices, stock and carts must never be stale); only when the network is
 * gone does a page request get the small offline page.
 */
declare(strict_types=1);

define('GX_NO_SESSION', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');

$precache = [
    asset('/assets/css/core.css'), asset('/assets/js/core.js'), asset('/assets/css/store.css'), asset('/assets/js/cart.js'),
    '/assets/img/brand/favicon.svg', '/assets/fonts/inter-400-800-latin.woff2', '/assets/fonts/barlow-condensed-800-latin.woff2', '/offline.html',
];
$version = substr(sha1(implode('|', $precache) . '|' . (string) @filemtime(__FILE__) . '|' . (string) @filemtime(__DIR__ . '/offline.html')), 0, 12);
?>
/* GrazeX service worker <?= $version ?> */
const STATIC = 'gx-static-<?= $version ?>'; // versioned CSS/JS/fonts: a URL never changes its content
const MEDIA = 'gx-media-v1';                 // uploaded photos: kept, but at most MEDIA_MAX of them
const MEDIA_MAX = 80;
const PRECACHE = <?= json_encode($precache, JSON_UNESCAPED_SLASHES) ?>;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  // Older releases' caches go (including the first service worker's "gx-static-v1")
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== STATIC && k !== MEDIA).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

async function trim(cache, max) {
  const keys = await cache.keys();
  if (keys.length > max) await Promise.all(keys.slice(0, keys.length - max).map((k) => cache.delete(k)));
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  // Pages: always the network; the offline page only when there is no connection at all
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }
  // Site assets: from the cache when we have this exact (versioned) URL, otherwise the network
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(caches.open(STATIC).then(async (cache) => {
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) cache.put(req, res.clone());
      return res;
    }));
    return;
  }
  // Uploaded photos: show the saved copy at once, refresh it in the background
  if (url.pathname.startsWith('/uploads/')) {
    e.respondWith(caches.open(MEDIA).then(async (cache) => {
      const hit = await cache.match(req);
      const net = fetch(req).then(async (res) => {
        if (res.ok) { await cache.put(req, res.clone()); trim(cache, MEDIA_MAX); }
        return res;
      }).catch(() => hit);
      return hit || net;
    }));
  }
});
