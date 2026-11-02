/* =====================================================================
   GrazeX Ranch — area behaviour
   Board filters, reservation calculator, alerts, enquiries, gallery.
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const $ = GX.$, $$ = GX.$$;

  /* ---------- Board filters ---------- */
  const form = $('#lot-filters');
  const results = $('[data-results]');
  if (form && results) {
    let ctrl = null;
    const load = async (page = 1, scroll = false) => {
      const p = new URLSearchParams();
      for (const [k, v] of new FormData(form).entries()) if (v) p.set(k, v);
      if (p.get('status') === 'now') p.delete('status');
      if (p.get('sort') === 'featured') p.delete('sort');
      if (page > 1) p.set('page', page);
      ctrl?.abort();
      ctrl = new AbortController();
      results.classList.add('is-busy');
      try {
        const data = await GX.api('/ranch/api/lots.php?' + p.toString(), { signal: ctrl.signal });
        results.innerHTML = data.html;
        $('[data-result-count]').textContent = `${data.total} lot${data.total === 1 ? '' : 's'}`;
        history.replaceState(null, '', '/ranch/livestock.php' + (p.toString() ? '?' + p : ''));
        $$('.reveal', results).forEach((el) => el.classList.add('is-visible'));
        if (scroll) results.scrollIntoView({ behavior: GX.reduceMotion ? 'auto' : 'smooth' });
      } catch (e) { if (e.name !== 'AbortError') console.error(e); }
      results.classList.remove('is-busy');
    };
    GX.on(form, 'click', '[data-filter]', (e, chip) => {
      const name = chip.dataset.filter;
      form.elements[name].value = chip.dataset.value;
      $$(`[data-filter="${name}"]`, form).forEach((c) => c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'));
      load();
    });
    form.addEventListener('change', () => load());
    form.addEventListener('submit', (e) => { e.preventDefault(); load(); });
    GX.on(results, 'click', '.pagination a', (e, a) => { e.preventDefault(); load(Number(a.dataset.page) || 1, true); });
  }

  /* ---------- Lot reservation calculator ---------- */
  const box = $('#lot-box');
  if (box) {
    const lot = JSON.parse(box.dataset.lot || '{}');
    const qty = $('#lot-qty');
    // Totals follow while typing; the box is corrected when you leave it or press Reserve
    const update = (write = true) => {
      if (!qty) return;
      const q = Math.max(1, Math.min(lot.max, Number(qty.value) || 1));
      if (write && String(q) !== qty.value) qty.value = q;
      const perHead = lot.mode === 'per_kg' ? Math.round((lot.avg || 0) * lot.price) : lot.price;
      const total = perHead * q;
      $('[data-lot-total]') && ($('[data-lot-total]').textContent = (lot.mode === 'per_kg' ? '≈ ' : '') + GX.money(total));
      $('[data-lot-weight]') && ($('[data-lot-weight]').textContent = lot.avg ? `≈ ${(lot.avg * q).toFixed(1)} kg` : '—');
      $('[data-lot-deposit]') && ($('[data-lot-deposit]').textContent = GX.money(Math.round(total * lot.deposit / 100)));
    };
    qty?.addEventListener('change', () => update(true));
    qty?.addEventListener('blur', () => update(true));
    qty?.addEventListener('input', () => update(false));
    GX.on(box, 'click', '[data-take-all]', (e, b) => { qty.value = b.dataset.takeAll; update(); });
    update();

    const sticky = $('[data-sticky-buy]');
    const btn = $('#reserve-btn');
    if (sticky && btn && 'IntersectionObserver' in window) {
      new IntersectionObserver(([en]) => {
        const show = !en.isIntersecting && en.boundingClientRect.top < 0;
        sticky.classList.toggle('is-visible', show);
        sticky.setAttribute('aria-hidden', show ? 'false' : 'true');
        sticky.inert = !show; // hidden bar: out of the tab order too
      }).observe(btn);
      $('[data-scroll-buy]').addEventListener('click', () => btn.click());
    }
  }

  /* ---------- Gallery thumbs ---------- */
  GX.on(document, 'click', '[data-gallery-src]', (e, b) => {
    const main = $('.gallery-main img');
    if (main) { main.src = b.dataset.gallerySrc; main.removeAttribute('srcset'); }
    $$('[data-gallery-src]').forEach((x) => x.setAttribute('aria-current', x === b ? 'true' : 'false'));
  });

  /* ---------- Livestock alerts ---------- */
  $$('[data-alert-form]').forEach((f) => {
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = f.querySelector('[type="submit"]');
      GX.clearErrors(f);
      GX.setLoading(btn, true);
      try {
        const body = Object.fromEntries(new FormData(f).entries());
        if (f.dataset.breed) body.breed = f.dataset.breed;
        if (f.dataset.category) body.category = f.dataset.category;
        const data = await GX.api('/ranch/api/alerts.php', { body, quiet: true });
        GX.toast(data.message || 'Alert saved.');
        f.reset();
      } catch (err) { GX.showErrors(f, err.errors); GX.toast(err.message, 'error'); }
      GX.setLoading(btn, false);
    });
  });

  /* ---------- Enquiry modal ---------- */
  const enq = $('[data-enquiry-form]');
  if (enq) {
    GX.bindForm(enq, {
      body: (f) => Object.fromEntries(new FormData(f).entries()),
      onSuccess(data) {
        GX.toast(data.message || 'Question sent.');
        enq.closest('dialog')?.close();
      },
    });
  }
})();
