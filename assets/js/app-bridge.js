/* =====================================================================
   GrazeX — app-bridge.js
   Loaded only inside the GrazeX Android app (head.php: user agent "GrazeXApp/…").
   Talks to the app through window.GXNative, which the app injects into pages of this
   site only (WebViewCompat.addWebMessageListener). Messages are JSON strings:
     page → app   {type:'state', overlay, refresh}        what the back button / pull-to-refresh may do
                  {type:'push:token', id}                 ask for this phone's push token (asks permission first)
                  {type:'file:save', id, name, mime, data} save a file (base64) to the phone's Downloads
                  {type:'back:result', handled}           answer to the app's back button
     app → page   {type:'push:token', id, token, appVersion} or {type:'push:token', id, error}
                  {type:'file:saved', id, name} or {type:'file:error', id, message}
                  {type:'back'}                           close the open dialog / drawer / menu, if any
   Without the bridge (an old WebView) the site simply behaves like the website.
   ===================================================================== */
(function () {
  'use strict';

  const native = window.GXNative;
  const GX = window.GX;
  if (!native || typeof native.postMessage !== 'function' || !GX) return;

  const send = (msg) => { try { native.postMessage(JSON.stringify(msg)); } catch (_) { /* the app went away */ } };
  const pending = new Map();
  let seq = 0;
  const request = (msg, timeout = 30000) => new Promise((resolve, reject) => {
    const id = String(++seq);
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('The app did not answer.')); }, timeout);
    pending.set(id, (reply) => { clearTimeout(timer); pending.delete(id); resolve(reply); });
    send({ ...msg, id });
  });

  native.onmessage = (event) => {
    let msg;
    try { msg = JSON.parse(event.data); } catch (_) { return; }
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'back') {
      send({ type: 'back:result', handled: closeOverlay() });
      return;
    }
    const done = msg.id !== undefined && pending.get(String(msg.id));
    if (done) done(msg);
  };

  // ---------- Back button and pull-to-refresh ----------
  const overlayOpen = () => !!document.querySelector('dialog[open], .drawer.is-open, #menu-sheet.is-open, .adm.menu-open');
  function closeOverlay() {
    const dialogs = document.querySelectorAll('dialog[open]');
    if (dialogs.length) { dialogs[dialogs.length - 1].close(); return true; }
    if (document.querySelector('.drawer.is-open')) { GX.drawer.close(); return true; }
    if (document.querySelector('#menu-sheet.is-open')) { GX.closeMenu?.(); return true; }
    if (document.querySelector('.adm.menu-open')) {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      return true;
    }
    return false;
  }
  // Pulling down must never reload a checkout or payment page (the payment may be on its way)
  const noRefreshPage = location.pathname.startsWith('/payments/') || /\/checkout(\.php)?$/.test(location.pathname);
  let lastState = '';
  let queued = false;
  const reportState = () => {
    queued = false;
    const overlay = overlayOpen();
    const state = { type: 'state', overlay, refresh: !overlay && !noRefreshPage };
    const key = JSON.stringify(state);
    if (key !== lastState) { lastState = key; send(state); }
  };
  reportState();
  new MutationObserver(() => { if (!queued) { queued = true; requestAnimationFrame(reportState); } })
    .observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'open'] });
  window.addEventListener('pageshow', () => { lastState = ''; reportState(); });

  // ---------- Push notifications: link this phone to whoever is logged in ----------
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (_) { /* private mode */ } },
  };
  async function linkPush() {
    const who = (document.body.dataset.push || '').split(' ').filter(Boolean);
    if (!who.length) return;
    const linked = (document.body.dataset.pushLinked || '').split(' ');
    let reply;
    try { reply = await request({ type: 'push:token' }, 120000); } catch (_) { return; }
    if (!reply.token) return; // notifications switched off, or this build has no Firebase
    for (const w of who) {
      const key = 'gx-push-' + w;
      if (linked.includes(w) && store.get(key) === reply.token) continue;
      try {
        await GX.api(w === 'admin' ? '/admin/api/push.php' : '/account/api/push.php', {
          body: { action: 'register', token: reply.token, app_version: reply.appVersion || '', platform: 'android' }, quiet: true,
        });
        store.set(key, reply.token);
      } catch (_) { /* tried again on the next page */ }
    }
  }
  linkPush();

  // ---------- Files: PDFs and uploads are saved to the phone instead of opening a blank page ----------
  const toBase64 = (blob) => new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).replace(/^data:[^,]*,/, ''));
    r.onerror = () => reject(r.error);
    r.readAsDataURL(blob);
  });
  const fileName = (disposition) => {
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition || '');
    try { return m ? decodeURIComponent(m[1]) : ''; } catch (_) { return m[1]; }
  };
  async function saveBlob(blob, name) {
    const reply = await request({ type: 'file:save', name, mime: blob.type || 'application/octet-stream', data: await toBase64(blob) }, 60000);
    if (reply.type !== 'file:saved') throw new Error(reply.message || 'Could not save the file.');
    return reply.name || name;
  }
  window.GXApp = { saveBlob };

  GX.on(document, 'click', 'a[data-download]', async (e, a) => {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button) return;
    e.preventDefault();
    if (a.getAttribute('aria-busy') === 'true') return;
    a.setAttribute('aria-busy', 'true');
    const url = new URL(a.href, location.href);
    url.searchParams.set('dl', '1');
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      const type = (res.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
      // Logged out (login page), not found or still being prepared: show that page as the website would
      if (!res.ok || type === 'text/html' || type === 'text/plain') { location.href = a.href; return; }
      const blob = await res.blob();
      if (blob.size > 20 * 1024 * 1024) { location.href = url.href; return; } // too big for the bridge: the app downloads it
      const saved = await saveBlob(new Blob([blob], { type: type || 'application/octet-stream' }), fileName(res.headers.get('Content-Disposition')) || 'GrazeX-document');
      GX.toast('Saved to Downloads: ' + saved);
    } catch (err) {
      GX.toast(err.message && err.message !== 'Failed to fetch' ? err.message : 'Could not download the file. Please try again.', 'error');
    } finally {
      a.removeAttribute('aria-busy');
    }
  });
})();
