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
const STATIC = 'gx-static-<?= $version ?>'; // the precache: versioned CSS/JS, fonts, offline page
const RUNTIME = 'gx-run-<?= $version ?>';   // other site files as they are used (at most RUNTIME_MAX)
const MEDIA = 'gx-media-v1';                 // uploaded photos (at most MEDIA_MAX)
const RUNTIME_MAX = 120;
const MEDIA_MAX = 80;
const PRECACHE = <?= json_encode($precache, JSON_UNESCAPED_SLASHES) ?>;

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  // Older releases' caches go (including the first service worker's "gx-static-v1")
  e.waitUntil(Promise.all([
    caches.keys().then((keys) => Promise.all(keys.filter((k) => ![STATIC, RUNTIME, MEDIA].includes(k)).map((k) => caches.delete(k)))),
    // Pages start loading while the worker wakes up
    self.registration.navigationPreload ? self.registration.navigationPreload.enable() : null,
  ]).then(() => self.clients.claim()));
});

async function trim(cache, max) {
  const keys = await cache.keys();
  if (keys.length > max) await Promise.all(keys.slice(0, keys.length - max).map((k) => cache.delete(k)));
}

/** Answer from the cache at once when we have it; refresh the copy in the background. */
function staleWhileRevalidate(e, cacheName, max) {
  const req = e.request;
  e.respondWith(caches.open(cacheName).then(async (cache) => {
    const hit = await cache.match(req);
    const net = fetch(req).then((res) => {
      if (res.ok && res.status === 200) {
        // The page never waits for (or breaks on) the cache write
        e.waitUntil(cache.put(req, res.clone()).then(() => trim(cache, max)).catch(() => {}));
      }
      return res;
    });
    if (hit) {
      e.waitUntil(net.catch(() => {}));
      return hit;
    }
    return net;
  }));
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  // Pages: always the network; the offline page only when there is no connection at all
  if (req.mode === 'navigate') {
    e.respondWith((async () => {
      try {
        return (await e.preloadResponse) || (await fetch(req));
      } catch (_) {
        return (await caches.match('/offline.html')) || Response.error();
      }
    })());
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    // A versioned URL (?v=…) never changes: from the cache when we have it
    if (url.searchParams.has('v')) {
      e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok && res.status === 200) {
          e.waitUntil(caches.open(RUNTIME).then((c) => c.put(req, res.clone()).then(() => trim(c, RUNTIME_MAX))).catch(() => {}));
        }
        return res;
      })));
      return;
    }
    // Unversioned files (brand images, fonts): kept, but refreshed so a replaced file shows up
    staleWhileRevalidate(e, RUNTIME, RUNTIME_MAX);
    return;
  }
  // Uploaded photos
  if (url.pathname.startsWith('/uploads/')) {
    staleWhileRevalidate(e, MEDIA, MEDIA_MAX);
  }
});
