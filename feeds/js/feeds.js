/* =====================================================================
   GrazeX Feeds — area behaviour
   Instant search, shop filters, product page pricing, calculator,
   delivery checker. Cart actions live in /assets/js/cart.js.
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const $ = GX.$, $$ = GX.$$;

  /* ---------- Instant search suggestions ---------- */
  $$('[data-suggest]').forEach((box) => {
    const input = $('[data-suggest-input]', box);
    const list = $('[data-suggest-list]', box);
    let ctrl = null, active = -1;
    const close = () => { list.hidden = true; active = -1; };
    const run = GX.debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) return close();
      ctrl?.abort();
      ctrl = new AbortController();
      try {
        const data = await GX.api('/feeds/api/search.php?q=' + encodeURIComponent(q), { quiet: true, signal: ctrl.signal });
        if (!data.items.length) {
          list.innerHTML = `<a href="${GX.esc(data.all_url || '/feeds/shop.php')}"><span class="s-name">No matches — search all products</span></a>`;
        } else {
          list.innerHTML = data.items.map((it) => `
            <a href="${GX.esc(it.url)}">
              <span class="s-img">${it.image ? `<img src="${GX.esc(it.image)}" alt="" loading="lazy">` : ''}</span>
              <span><span class="s-name">${GX.esc(it.name)}</span><br><span class="s-meta">${GX.esc(it.category)}${it.in_stock ? '' : ' · out of stock'}</span></span>
              <span class="s-price">${GX.esc(it.price)}</span>
            </a>`).join('') + `<a href="${GX.esc(data.all_url)}"><span class="s-name text-accent">See all results →</span></a>`;
        }
        list.hidden = false;
      } catch (_) { /* ignore aborted */ }
    }, 220);
    input.addEventListener('input', run);
    input.addEventListener('keydown', (e) => {
      const links = $$('a', list);
      if (list.hidden || !links.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        links.forEach((l, i) => l.classList.toggle('is-active', i === active));
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        location.href = links[active].href;
      } else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) close(); });
    // Hero search (not inside the shop filter form): Enter → shop results
    if (!box.closest('form')) {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && active < 0 && input.value.trim()) location.href = '/feeds/shop.php?q=' + encodeURIComponent(input.value.trim());
      });
    }
  });

  /* ---------- Shop filters (fetch + history) ---------- */
  const form = $('#shop-filters');
  const results = $('[data-results]');
  if (form && results) {
    const params = () => {
      const fd = new FormData(form);
      const p = new URLSearchParams();
      for (const [k, v] of fd.entries()) if (v !== '' && v !== null) p.set(k, v);
      if (p.get('sort') === 'featured') p.delete('sort');
      return p;
    };
    let ctrl = null;
    const load = async (page = 1, scroll = false) => {
      const p = params();
      if (page > 1) p.set('page', page);
      ctrl?.abort();
      ctrl = new AbortController();
      results.classList.add('is-busy');
      try {
        const data = await GX.api('/feeds/api/products.php?' + p.toString(), { signal: ctrl.signal });
        results.innerHTML = data.html;
        $('[data-result-count]').textContent = `${data.total} product${data.total === 1 ? '' : 's'}`;
        // The address bar shows the category's own address (/feeds/category/x), with any other filters after it
        const cat = p.get('category');
        p.delete('category');
        history.replaceState(null, '', (cat ? '/feeds/category/' + encodeURIComponent(cat) : '/feeds/shop.php') + (p.toString() ? '?' + p.toString() : ''));
        $$('.reveal', results).forEach((el) => el.classList.add('is-visible'));
        if (scroll) results.scrollIntoView({ behavior: GX.reduceMotion ? 'auto' : 'smooth', block: 'start' });
      } catch (e) { if (e.name !== 'AbortError') console.error(e); }
      results.classList.remove('is-busy');
    };
    GX.on(form, 'click', '[data-filter]', (e, chip) => {
      const name = chip.dataset.filter;
      form.elements[name].value = chip.dataset.value;
      $$(`[data-filter="${name}"]`, form).forEach((c) => c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'));
      load();
    });
    form.addEventListener('change', (e) => { if (e.target.name !== 'q') load(); });
    form.addEventListener('submit', (e) => { e.preventDefault(); $('[data-suggest-list]', form).hidden = true; load(); });
    $('#q', form)?.addEventListener('input', GX.debounce(() => load(), 450));
    GX.on(results, 'click', '.pagination a', (e, a) => { e.preventDefault(); load(Number(a.dataset.page) || 1, true); });
  }

  /* ---------- Product page: variant, tiers, qty, sticky bar, gallery ---------- */
  const box = $('#buy-box');
  if (box) {
    const variants = JSON.parse(box.dataset.variants || '[]');
    const qty = $('#qty');
    const addBtn = $('#add-btn');
    let current = variants.find((v) => v.id === Number(box.dataset.default)) || variants[0];

    const unitFor = (v, q) => {
      let unit = v.price;
      v.tiers.forEach((t) => { if (q >= t.min && t.price < unit) unit = t.price; });
      return unit;
    };
    const stockBadge = (v) => v.stock <= 0
      ? '<span class="badge badge-danger">Out of stock</span>'
      : v.stock <= v.low ? `<span class="badge badge-warning">Only ${v.stock} left</span>`
        : '<span class="badge badge-success"><span class="dot"></span>In stock</span>';

    // While typing, only the totals follow (an empty or half-typed box is left alone); the box itself is
    // corrected when you leave it or press Add, so "12" never turns into "1" halfway through typing
    const update = (write = true) => {
      const q = Math.max(1, Math.min(current.stock || 1, Number(qty.value) || 1));
      if (write && String(q) !== qty.value) qty.value = q;
      qty.max = Math.max(1, current.stock);
      const unit = unitFor(current, q);
      $('[data-price]').textContent = GX.money(unit);
      $('[data-price-sticky]').textContent = GX.money(unit);
      const old = $('[data-price-old]');
      old.hidden = !(current.special || unit < current.regular);
      old.textContent = GX.money(current.regular);
      $('[data-special-badge]').hidden = !current.special;
      $('[data-price-unit]').textContent = current.kg ? `· ${GX.money(Math.round(unit / current.kg))}/kg` : '';
      $('[data-stock]').innerHTML = stockBadge(current);
      $('[data-line-total]').textContent = GX.money(unit * q);
      addBtn.dataset.target = current.id;
      addBtn.disabled = current.stock <= 0;

      const tiersEl = $('[data-tiers]');
      if (current.tiers.length) {
        const rows = [{ min: 1, price: current.price }, ...current.tiers];
        tiersEl.innerHTML = '<span class="label">Bulk pricing</span>' + rows.map((t, i) => {
          const next = rows[i + 1];
          const on = q >= t.min && (!next || q < next.min);
          return `<div class="tier-row${on ? ' is-active' : ''}"><span>${t.min}${next ? '–' + (next.min - 1) : '+'} ${current.kg ? 'bags' : 'units'}</span><strong>${GX.money(t.price)} each</strong></div>`;
        }).join('');
        tiersEl.hidden = false;
        const nextTier = current.tiers.find((t) => q < t.min && t.price < unit);
        $('[data-tier-hint]').textContent = nextTier ? `Add ${nextTier.min - q} more to pay ${GX.money(nextTier.price)} each (save ${GX.money((unit - nextTier.price) * nextTier.min)}).` : '';
      } else {
        tiersEl.hidden = true;
        $('[data-tier-hint]').textContent = current.special_to ? `Special ends ${current.special_to}.` : '';
      }
    };

    $$('input[name="variant"]', box).forEach((r) => r.addEventListener('change', () => {
      current = variants.find((v) => v.id === Number(r.value)) || current;
      update();
    }));
    qty.addEventListener('change', () => update(true));
    qty.addEventListener('blur', () => update(true));
    qty.addEventListener('input', () => update(false));
    update();

    // Sticky mobile buy bar once the main button scrolls away
    const sticky = $('[data-sticky-buy]');
    if (sticky && 'IntersectionObserver' in window) {
      new IntersectionObserver(([en]) => {
        const show = !en.isIntersecting && en.boundingClientRect.top < 0;
        sticky.classList.toggle('is-visible', show);
        sticky.setAttribute('aria-hidden', show ? 'false' : 'true');
        sticky.inert = !show; // hidden bar: out of the tab order too
      }).observe(addBtn);
      $('[data-scroll-buy]').addEventListener('click', () => addBtn.click());
    }

    // Gallery thumbs
    const main = $('.gallery-main img');
    GX.on(document, 'click', '[data-gallery-src]', (e, b) => {
      if (main) { main.src = b.dataset.gallerySrc; main.removeAttribute('srcset'); }
      $$('[data-gallery-src]').forEach((x) => x.setAttribute('aria-current', x === b ? 'true' : 'false'));
    });
  }

  /* ---------- Feed calculator ---------- */
  const calc = $('#calc');
  if (calc) {
    const rules = JSON.parse(calc.dataset.rules || '[]');
    const sel = $('#calc-rule'), head = $('#calc-head'), days = $('#calc-days');
    let species = $('[data-calc-species][aria-pressed="true"]')?.dataset.calcSpecies;

    const fillRules = () => {
      const list = rules.filter((r) => r.species === species);
      sel.innerHTML = list.map((r) => `<option value="${r.id}">${GX.esc(r.product)} — ${GX.esc(r.animal)}</option>`).join('');
    };
    const unitFor = (v, q) => v.tiers.reduce((u, t) => (q >= t.min && t.price < u ? t.price : u), v.price);
    const compute = () => {
      const rule = rules.find((r) => r.id === Number(sel.value));
      const out = $('[data-calc-options]');
      if (!rule) { out.innerHTML = ''; return; }
      const n = Math.max(0, Number(head.value) || 0), d = Math.max(0, Number(days.value) || 0);
      const grams = rule.grams * n * d;
      $('[data-calc-kg]').textContent = Math.round(grams / 1000).toLocaleString('en-ZA');
      $('[data-calc-note]').textContent = `${rule.grams.toLocaleString('en-ZA')} g per animal per day${rule.notes ? ' · ' + rule.notes : ''}`;
      const options = rule.variants.map((v) => {
        const bags = Math.max(1, Math.ceil(grams / v.grams));
        const unit = unitFor(v, bags);
        return { v, bags, unit, total: unit * bags };
      });
      const best = options.reduce((a, b) => (b.total < a.total ? b : a), options[0]);
      out.innerHTML = options.map((o) => `
        <div class="calc-option${o === best && options.length > 1 ? ' is-best' : ''}">
          <div><div class="co-title">${o.bags} × ${GX.esc(o.v.name)}${o === best && options.length > 1 ? ' <span class="badge badge-solid">Best value</span>' : ''}</div>
          <div class="co-meta">${GX.esc(rule.product)} · ${GX.money(o.unit)} each${o.v.stock < o.bags ? ` · <span class="text-accent">only ${o.v.stock} in stock</span>` : ''}</div></div>
          <div class="co-price">${GX.money(o.total)}</div>
          ${o.v.stock > 0 ? `<button class="btn btn-primary btn-block" type="button" data-calc-add="${o.v.id}" data-qty="${Math.min(o.bags, o.v.stock)}">Add ${Math.min(o.bags, o.v.stock)} to cart</button>` : '<button class="btn btn-block" disabled>Out of stock</button>'}
        </div>`).join('') + `<a class="link small" href="${GX.esc(rule.url)}">View ${GX.esc(rule.product)} →</a>`;
    };
    GX.on(calc, 'click', '[data-calc-species]', (e, chip) => {
      species = chip.dataset.calcSpecies;
      $$('[data-calc-species]', calc).forEach((c) => c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'));
      fillRules(); compute();
    });
    GX.on(calc, 'click', '[data-days]', (e, chip) => { days.value = chip.dataset.days; compute(); });
    GX.on(calc, 'click', '[data-calc-add]', async (e, btn) => {
      GX.setLoading(btn, true);
      try { await GX.cart.add(btn.dataset.calcAdd, btn.dataset.qty, btn); } catch (_) { /* toast */ }
      GX.setLoading(btn, false);
    });
    [sel, head, days].forEach((el) => el.addEventListener('input', compute));
    fillRules(); compute();
  }

  /* ---------- Delivery checker ---------- */
  const dc = $('[data-delivery-check]');
  if (dc) {
    const out = $('[data-delivery-result]');
    const list = $('#dc-towns');
    dc.town.addEventListener('input', GX.debounce(async () => {
      const q = dc.town.value.trim();
      if (q.length < 2) return;
      try {
        const data = await GX.api('/feeds/api/delivery-check.php?suggest=' + encodeURIComponent(q), { quiet: true });
        list.innerHTML = data.towns.map((t) => `<option value="${GX.esc(t)}">`).join('');
      } catch (_) { /* ignore */ }
    }, 250));
    dc.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = dc.querySelector('button');
      GX.setLoading(btn, true);
      try {
        const d = await GX.api('/feeds/api/delivery-check.php?town=' + encodeURIComponent(dc.town.value.trim()));
        if (d.delivers) {
          const z = d.zone;
          out.innerHTML = `<div class="dc-card ok"><strong>Yes! We deliver to ${GX.esc(d.town)}.</strong>
            <ul><li>Zone: ${GX.esc(z.name)}</li><li>Delivery fee: ${GX.esc(z.fee_label)}${z.free_over_label ? ` — free over ${GX.esc(z.free_over_label)}` : ''}</li>
            ${z.min_order ? `<li>Minimum order: ${GX.esc(z.min_order_label)}</li>` : ''}
            <li>Delivery days: ${GX.esc(z.days.join(', '))}${d.next_dates.length ? ` · next: ${GX.esc(d.next_dates.join(', '))}` : ''}</li></ul></div>`;
        } else {
          out.innerHTML = `<div class="dc-card no"><strong>We don't deliver to ${GX.esc(d.town)} yet.</strong>
            <ul><li>You can collect at ${GX.esc(d.collection?.name || 'our depot')}${d.collection?.hours ? ' (' + GX.esc(d.collection.hours) + ')' : ''}.</li></ul></div>`;
        }
      } catch (_) { /* toast */ }
      GX.setLoading(btn, false);
    });
  }
})();
