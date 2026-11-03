/* =====================================================================
   GrazeX — core.js
   Shared behaviour for every area: fetch API wrapper (CSRF), toasts,
   drawers, modals, forms, reveal-on-scroll, nav, steppers, count-up.
   No frameworks. Loaded with `defer` on every page.
   ===================================================================== */
(function () {
  'use strict';

  document.documentElement.classList.remove('no-js');

  const GX = (window.GX = window.GX || {});
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------- DOM helpers ----------
  GX.$ = (sel, root = document) => root.querySelector(sel);
  GX.$$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  GX.on = (el, ev, sel, fn) => el.addEventListener(ev, (e) => {
    const t = e.target.closest(sel);
    if (t && el.contains(t)) fn(e, t);
  });
  GX.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  GX.debounce = (fn, ms = 250) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  GX.money = (cents) => {
    const n = Math.abs(Number(cents || 0)) / 100;
    const s = n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (cents < 0 ? '-' : '') + 'R ' + s;
  };
  GX.icon = (name, cls = 'icon') => `<svg class="${cls}" aria-hidden="true"><use href="#i-${name}"></use></svg>`;
  GX.reduceMotion = reduceMotion;

  // ---------- API (fetch + CSRF + JSON envelope) ----------
  GX.csrf = () => GX.$('meta[name="csrf-token"]')?.content || '';

  GX.api = async function (url, opts = {}) {
    const { method = opts.body || opts.form ? 'POST' : 'GET', body, form, quiet = false, signal } = opts;
    const headers = { Accept: 'application/json', 'X-Requested-With': 'fetch' };
    let payload;
    if (form) {
      payload = form instanceof FormData ? form : new FormData(form);
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    if (method !== 'GET') headers['X-CSRF-Token'] = GX.csrf();

    let res, data;
    try {
      res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', signal });
      // Keep the page's CSRF token current (it changes after login / password change)
      const fresh = res.headers.get('X-CSRF-Token');
      const meta = GX.$('meta[name="csrf-token"]');
      if (fresh && meta && meta.content !== fresh) meta.content = fresh;
      data = await res.json().catch(() => ({ ok: false, error: 'Unexpected response from the server.' }));
    } catch (err) {
      if (err.name === 'AbortError') throw err;
      data = { ok: false, error: navigator.onLine ? 'Network error. Please try again.' : 'You appear to be offline.' };
      res = { status: 0 };
    }
    if (!data.ok) {
      const e = new Error(data.error || 'Something went wrong.');
      e.status = res.status;
      e.errors = data.errors || {};
      e.data = data;
      if (!quiet) GX.toast(e.message, 'error');
      if (res.status === 419) setTimeout(() => location.reload(), 1800);
      throw e;
    }
    const out = data.data || {};
    if (data.message && out.message === undefined) out.message = data.message;
    return out;
  };

  // ---------- Toasts ----------
  GX.toast = function (message, type = 'success', opts = {}) {
    let stack = GX.$('.toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    const sym = type === 'error' ? '!' : type === 'info' ? 'i' : '✓';
    el.innerHTML = `<span class="t-icon">${sym}</span><span class="t-body">${GX.esc(message)}</span>` +
      (opts.action ? `<a class="t-action" href="${GX.esc(opts.href || '#')}">${GX.esc(opts.action)}</a>` : '');
    stack.appendChild(el);
    const close = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 320); };
    // Time to read it: longer with a button (e.g. "Checkout"), and paused while the pointer or keyboard focus is on it
    let left = opts.timeout || (type === 'error' ? 6000 : opts.action ? 7000 : 3800);
    let timer = 0, started = 0, hovered = false, focused = false;
    const run = () => {
      clearTimeout(timer);
      timer = 0;
      if (hovered || focused) return;
      started = Date.now();
      timer = setTimeout(close, left);
    };
    const pause = () => {
      if (!timer) return;
      clearTimeout(timer);
      timer = 0;
      left = Math.max(1200, left - (Date.now() - started));
    };
    el.addEventListener('mouseenter', () => { pause(); hovered = true; });
    el.addEventListener('mouseleave', () => { hovered = false; run(); });
    el.addEventListener('focusin', () => { pause(); focused = true; });
    el.addEventListener('focusout', () => { focused = false; run(); });
    run();
    el.addEventListener('click', (e) => { if (!e.target.closest('.t-action')) close(); });
    return el;
  };

  // ---------- Focus helpers for drawers and sheets ----------
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  /** Keep Tab / Shift+Tab inside `box` (a modal panel). Returns a function that releases it. */
  GX.trapFocus = function (box) {
    const onKey = (e) => {
      if (e.key !== 'Tab') return;
      const els = GX.$$(FOCUSABLE, box).filter((el) => el.getClientRects().length > 0);
      if (!els.length) return;
      const first = els[0], last = els[els.length - 1];
      if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  };

  // ---------- Drawers ----------
  // A closed drawer is inert (no focus, hidden from screen readers); an open one is a modal dialog with focus kept inside
  GX.drawer = {
    open(id) {
      const d = document.getElementById(id);
      if (!d) return;
      d.inert = false;
      d.classList.add('is-open');
      d.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      GX.drawer._last = document.activeElement;
      GX.drawer._release?.();
      GX.drawer._release = GX.trapFocus(d);
      setTimeout(() => d.querySelector('[data-autofocus], button, a')?.focus(), 50);
      d.dispatchEvent(new CustomEvent('drawer:open'));
    },
    close(id) {
      const d = id ? document.getElementById(id) : GX.$('.drawer.is-open');
      if (!d) return;
      d.classList.remove('is-open');
      d.setAttribute('aria-hidden', 'true');
      d.inert = true;
      document.body.style.overflow = '';
      GX.drawer._release?.();
      GX.drawer._release = null;
      GX.drawer._last?.focus?.();
    },
  };
  // No drawer on this page (e.g. checkout): let the link go to the cart page instead
  GX.on(document, 'click', '[data-drawer-open]', (e, t) => { if (!document.getElementById(t.dataset.drawerOpen)) return; e.preventDefault(); GX.drawer.open(t.dataset.drawerOpen); });
  GX.on(document, 'click', '[data-drawer-close], .drawer-backdrop', (e) => { e.preventDefault(); GX.drawer.close(); });

  // ---------- Modals (<dialog>) ----------
  GX.modal = {
    open(id) { const m = document.getElementById(id); if (m && !m.open) m.showModal(); return m; },
    close(id) { const m = id ? document.getElementById(id) : GX.$('dialog[open]'); m?.close(); },
  };
  GX.on(document, 'click', '[data-modal-open]', (e, t) => { e.preventDefault(); GX.modal.open(t.dataset.modalOpen); });
  GX.on(document, 'click', '[data-modal-close]', (e, t) => { e.preventDefault(); t.closest('dialog')?.close(); });
  document.addEventListener('click', (e) => { if (e.target.tagName === 'DIALOG') e.target.close(); });

  /** Promise-based confirm dialog. */
  GX.confirm = function (message, { title = 'Are you sure?', ok = 'Yes, continue', danger = false } = {}) {
    return new Promise((resolve) => {
      const d = document.createElement('dialog');
      d.className = 'modal';
      d.innerHTML = `<div class="modal-head"><h3>${GX.esc(title)}</h3></div>
        <div class="modal-body"><p class="muted mb-0">${GX.esc(message)}</p></div>
        <div class="modal-foot"><button class="btn btn-ghost" value="no">Cancel</button>
        <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" value="yes">${GX.esc(ok)}</button></div>`;
      document.body.appendChild(d);
      d.addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) d.close(b.value); });
      d.addEventListener('close', () => { resolve(d.returnValue === 'yes'); d.remove(); });
      d.showModal();
    });
  };

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { GX.drawer.close(); GX.closeMenu?.(); } });

  // ---------- Terms / privacy in a dialog: the form being filled in (checkout, registration) stays as it is ----------
  GX.on(document, 'click', 'a[data-page-dialog]', async (e, a) => {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button) return; // "open in new tab" still works
    e.preventDefault();
    let d;
    try {
      d = await GX.api('/pages/api/page.php?slug=' + encodeURIComponent(a.dataset.pageDialog), { quiet: true });
    } catch (_) {
      window.open(a.href, '_blank', 'noopener'); // offline or not found: the page itself, in a new tab as before
      return;
    }
    const dlg = document.createElement('dialog');
    dlg.className = 'modal modal-page';
    dlg.setAttribute('aria-labelledby', 'page-dialog-title');
    dlg.innerHTML = `<div class="modal-head"><h3 id="page-dialog-title">${GX.esc(d.title)}</h3>
        <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="Close">${GX.icon('close')}</button></div>
      <div class="modal-body prose" tabindex="0">${d.html}<p class="tiny dim mt-3">Last updated ${GX.esc(d.updated)}</p></div>
      <div class="modal-foot"><a class="link small" href="${GX.esc(d.url)}" target="_blank" rel="noopener">Open as a page</a>
        <button class="btn btn-primary" type="button" data-modal-close>Close</button></div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('close', () => { dlg.remove(); a.focus(); });
    dlg.showModal();
  });

  // ---------- Forms: AJAX submit with inline errors ----------
  GX.clearErrors = (form) => {
    GX.$$('.field.has-error', form).forEach((f) => f.classList.remove('has-error'));
    GX.$$('.field-error', form).forEach((el) => el.remove());
  };
  GX.showErrors = (form, errors) => {
    let first = null;
    Object.entries(errors || {}).forEach(([name, msg]) => {
      const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
      const field = input?.closest('.field') || input?.parentElement;
      if (!field) return;
      field.classList.add('has-error');
      const p = document.createElement('div');
      p.className = 'field-error';
      p.textContent = msg;
      field.appendChild(p);
      input.setAttribute('aria-invalid', 'true');
      first = first || input;
    });
    first?.focus();
  };
  GX.setLoading = (btn, on) => { if (!btn) return; btn.classList.toggle('is-loading', on); btn.disabled = on; };

  /**
   * Bind a <form data-api="/area/api/x.php"> for fetch submission.
   * Options: onSuccess(data, form), body(form) → custom JSON body.
   */
  GX.bindForm = function (form, opts = {}) {
    if (!form || form._gxBound) return;
    form._gxBound = true;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      GX.clearErrors(form);
      const btn = form.querySelector('[type="submit"]');
      GX.setLoading(btn, true);
      try {
        const url = form.dataset.api || form.action;
        const body = opts.body ? opts.body(form) : Object.fromEntries(new FormData(form).entries());
        const data = await GX.api(url, { method: 'POST', body, quiet: true });
        if (opts.onSuccess) opts.onSuccess(data, form);
        else {
          if (data.message) GX.toast(data.message);
          if (data.redirect) location.href = data.redirect;
        }
      } catch (err) {
        if (err.name === 'AbortError') return;
        GX.showErrors(form, err.errors);
        GX.toast(err.message, 'error');
      } finally {
        GX.setLoading(btn, false);
      }
    });
    form.addEventListener('input', (e) => {
      const f = e.target.closest('.field.has-error');
      if (f) { f.classList.remove('has-error'); f.querySelector('.field-error')?.remove(); }
    });
  };

  // ---------- Quantity steppers ----------
  GX.on(document, 'click', '.stepper button', (e, b) => {
    const input = b.parentElement.querySelector('input');
    const min = Number(input.min || 1), max = Number(input.max || 9999);
    const step = b.dataset.step === 'down' ? -1 : 1;
    const v = Math.min(max, Math.max(min, (Number(input.value) || min) + step));
    input.value = v;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  // ---------- Cart badge + fly-to-cart ----------
  GX.setCartCount = function (count) {
    // The cart button says how many items it holds ("Cart, 3 items")
    GX.$$('[data-cart-label]').forEach((a) => a.setAttribute('aria-label', `${a.dataset.cartLabel}, ${count} item${count === 1 ? '' : 's'}`));
    GX.$$('[data-cart-count]').forEach((el) => {
      el.textContent = count > 99 ? '99+' : String(count);
      el.classList.toggle('has-items', count > 0);
      el.classList.remove('bump');
      void el.offsetWidth;
      if (count > 0) el.classList.add('bump');
    });
  };
  GX.flyToCart = function (fromEl) {
    const target = GX.$('[data-cart-count]')?.closest('a,button');
    if (!fromEl || !target || reduceMotion) return;
    const a = fromEl.getBoundingClientRect(), b = target.getBoundingClientRect();
    const dot = document.createElement('div');
    dot.className = 'fly-dot';
    dot.style.left = a.left + a.width / 2 - 9 + 'px';
    dot.style.top = a.top + a.height / 2 - 9 + 'px';
    document.body.appendChild(dot);
    requestAnimationFrame(() => {
      dot.style.transform = `translate(${b.left + b.width / 2 - (a.left + a.width / 2)}px, ${b.top + b.height / 2 - (a.top + a.height / 2)}px) scale(.4)`;
      dot.style.opacity = '.2';
    });
    setTimeout(() => dot.remove(), 750);
  };

  // ---------- Tabs ----------
  GX.on(document, 'click', '[role="tab"]', (e, tab) => {
    const list = tab.closest('[role="tablist"]');
    GX.$$('[role="tab"]', list).forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      const p = document.getElementById(t.getAttribute('aria-controls'));
      if (p) p.hidden = !on;
    });
  });

  // ---------- Init on DOM ready ----------
  function init() {
    // Reveal on scroll
    const reveals = GX.$$('.reveal');
    if ('IntersectionObserver' in window && !reduceMotion) {
      const io = new IntersectionObserver((entries) => entries.forEach((en) => {
        if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); }
      }), { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      reveals.forEach((el) => io.observe(el));
    } else {
      reveals.forEach((el) => el.classList.add('is-visible'));
    }

    // Count-up numbers: <span data-count="12">0</span>
    const counters = GX.$$('[data-count]');
    if (counters.length && 'IntersectionObserver' in window) {
      const co = new IntersectionObserver((entries) => entries.forEach((en) => {
        if (!en.isIntersecting) return;
        co.unobserve(en.target);
        const el = en.target, end = Number(el.dataset.count) || 0;
        if (reduceMotion || end === 0) { el.textContent = end; return; }
        // The page already shows the real number; the count-up starts from 0 only now that it is on screen
        el.textContent = '0';
        const t0 = performance.now(), dur = 1200;
        const tick = (t) => {
          const p = Math.min(1, (t - t0) / dur), eased = 1 - Math.pow(1 - p, 3);
          el.textContent = Math.round(end * eased);
          if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      }), { threshold: 0.4 });
      counters.forEach((c) => co.observe(c));
    }

    // Nav scrolled state
    const onScroll = () => document.body.classList.toggle('is-scrolled', window.scrollY > 30);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Background scene: slow parallax, and it dims as you scroll so long pages stay readable.
    // Scroll progress bar. Styles are set on the few elements involved only (no page-wide restyle per frame).
    const bgPic = GX.$('.page-bg picture');
    const bar = GX.$('.scroll-progress');
    let frame = 0;
    const paint = () => {
      frame = 0;
      const y = window.scrollY, vh = window.innerHeight || 1;
      if (bar) bar.style.transform = `scaleX(${Math.min(1, y / Math.max(1, document.documentElement.scrollHeight - vh)).toFixed(4)})`;
      if (bgPic) {
        bgPic.style.opacity = (1 - Math.min(1, y / (vh * 1.2)) * 0.5).toFixed(3);
        if (!reduceMotion) bgPic.style.transform = `translate3d(0, ${(-Math.min(y, vh) * 0.05).toFixed(1)}px, 0)`;
      }
    };
    const schedulePaint = () => { if (!frame) frame = requestAnimationFrame(paint); };
    window.addEventListener('scroll', schedulePaint, { passive: true });
    window.addEventListener('resize', schedulePaint, { passive: true });
    paint();

    // Glass cards light up where the pointer is (desktop)
    if (window.matchMedia('(hover: hover)').matches && !reduceMotion) {
      const SPOT = '.card, .p-card, .l-card, .cat-tile, .feature-band, .portal, .quick a, .order-row, .soon-item, .stat, .step';
      document.addEventListener('pointermove', (e) => {
        const el = e.target instanceof Element ? e.target.closest(SPOT) : null;
        if (!el) return;
        const r = el.getBoundingClientRect();
        el.style.setProperty('--mx', `${Math.round(e.clientX - r.left)}px`);
        el.style.setProperty('--my', `${Math.round(e.clientY - r.top)}px`);
      }, { passive: true });
    }

    // Mobile menu sheet
    const sheet = GX.$('#menu-sheet');
    let releaseMenu = null;
    GX.closeMenu = (returnFocus = true) => {
      if (!sheet?.classList.contains('is-open')) return;
      sheet.classList.remove('is-open');
      sheet.setAttribute('aria-hidden', 'true');
      sheet.inert = true;
      document.body.style.overflow = '';
      releaseMenu?.();
      releaseMenu = null;
      const toggle = GX.$('[data-menu-toggle]');
      toggle?.setAttribute('aria-expanded', 'false');
      if (returnFocus) toggle?.focus();
    };
    GX.on(document, 'click', '[data-menu-toggle]', (e, b) => {
      if (!sheet) return;
      const open = !sheet.classList.contains('is-open');
      if (!open) return GX.closeMenu();
      sheet.inert = false;
      sheet.classList.add('is-open');
      sheet.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      b.setAttribute('aria-expanded', 'true');
      releaseMenu = GX.trapFocus(sheet);
      // After the fade-in has begun (a still-hidden element cannot take focus)
      setTimeout(() => sheet.querySelector('a, button')?.focus(), 60);
    });
    GX.on(document, 'click', '[data-menu-close]', () => GX.closeMenu());
    GX.on(document, 'click', '#menu-sheet nav a', () => GX.closeMenu(false)); // following a link: no need to refocus the button

    // Cursor glow (desktop only)
    const glow = GX.$('.cursor-glow');
    if (glow && window.matchMedia('(hover: hover)').matches && !reduceMotion) {
      let raf = 0;
      window.addEventListener('mousemove', (e) => {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => { glow.style.left = e.clientX + 'px'; glow.style.top = e.clientY + 'px'; });
      }, { passive: true });
    }

    // Magnetic buttons (desktop)
    if (window.matchMedia('(hover: hover)').matches && !reduceMotion) {
      GX.$$('[data-magnetic]').forEach((el) => {
        el.addEventListener('mousemove', (e) => {
          const r = el.getBoundingClientRect();
          el.style.transform = `translate(${(e.clientX - r.left - r.width / 2) * 0.18}px, ${(e.clientY - r.top - r.height / 2) * 0.25}px)`;
        });
        el.addEventListener('mouseleave', () => { el.style.transform = ''; });
      });
    }

    // Server flash messages
    const flash = GX.$('#gx-flash');
    if (flash) {
      try { JSON.parse(flash.textContent).forEach((f) => GX.toast(f.message, f.type)); } catch (_) { /* ignore */ }
    }

    // Auto-bind data-api forms that don't need custom handling
    GX.$$('form[data-api][data-auto]').forEach((f) => GX.bindForm(f));

    // Register service worker (PWA) — skip on localhost dev
    if ('serviceWorker' in navigator && location.protocol === 'https:') {
      navigator.serviceWorker.register('/sw.js').catch(() => {});
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
