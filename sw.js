/* GrazeX service worker — caches static assets only. Pages and APIs always go to the network
   (prices, stock and carts must never be stale). */
const CACHE = 'gx-static-v1';
const PRECACHE = ['/assets/css/core.css', '/assets/js/core.js', '/assets/img/brand/favicon.svg', '/assets/fonts/inter-400-800-latin.woff2'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  const isStatic = url.pathname.startsWith('/assets/') || url.pathname.startsWith('/uploads/');
  if (!isStatic) return;
  e.respondWith(
    caches.open(CACHE).then(async (cache) => {
      const hit = await cache.match(e.request);
      const net = fetch(e.request).then((res) => { if (res.ok) cache.put(e.request, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    })
  );
});
