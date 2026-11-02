/* =====================================================================
   GrazeX — checkout behaviour (Feeds + Ranch)
   Inline register/login, fulfilment panels, collection slots, delivery
   zone lookup, live totals, GPS pin and order placement.
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const $ = GX.$, $$ = GX.$$;
  const root = $('.checkout');
  if (!root) return;
  const division = root.dataset.division;

  /* ---------- Inline account (register / login) ---------- */
  $$('[data-auth]').forEach((form) => {
    GX.bindForm(form, {
      body: (f) => ({ action: f.dataset.auth, ...Object.fromEntries(new FormData(f).entries()) }),
      onSuccess() { GX.toast('Welcome! Continue with your order.'); setTimeout(() => location.reload(), 400); },
    });
    form.dataset.api = '/account/api/auth.php';
  });

  const form = $('#checkout-form');
  if (!form) return;
  const api = form.dataset.api;

  /* ---------- Fulfilment panels ---------- */
  const fulfilment = () => form.querySelector('[name="fulfilment"]:checked')?.value || form.querySelector('[name="fulfilment"]')?.value || 'collection';
  const syncPanels = () => {
    const f = fulfilment();
    $$('[data-panel]', form).forEach((p) => { p.hidden = p.dataset.panel !== f; });
    quote();
  };
  $$('[name="fulfilment"]', form).forEach((r) => r.addEventListener('change', syncPanels));

  /* ---------- Collection day → slots ---------- */
  form.addEventListener('change', (e) => {
    if (e.target.name !== 'collection_date') return;
    const date = e.target.value;
    $$('[data-slots-for]', form).forEach((l) => { l.hidden = l.dataset.slotsFor !== date; });
    const list = $(`[data-slots-for="${date}"]`, form);
    const first = list?.querySelector('input:not(:disabled)');
    $$('[name="collection_slot_id"]', form).forEach((r) => { r.checked = false; });
    if (first) first.checked = true;
  });

  /* ---------- Saved address vs new ---------- */
  const addrSel = $('#address_id');
  const newAddr = $('.new-address', form);
  addrSel?.addEventListener('change', () => { newAddr.hidden = addrSel.value !== 'new'; quote(); });

  /* ---------- GPS pin ---------- */
  $('[data-gps]', form)?.addEventListener('click', () => {
    const label = $('[data-gps-label]', form);
    if (!navigator.geolocation) { label.textContent = 'Location not available on this device.'; return; }
    label.textContent = 'Finding you…';
    navigator.geolocation.getCurrentPosition((pos) => {
      form.elements['address[gps_lat]'].value = pos.coords.latitude.toFixed(7);
      form.elements['address[gps_lng]'].value = pos.coords.longitude.toFixed(7);
      label.textContent = `📍 Saved (${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)})`;
    }, () => { label.textContent = 'Could not get your location — directions are fine too.'; }, { enableHighAccuracy: true, timeout: 12000 });
  });

  /* ---------- Live totals ---------- */
  let ctrl = null;
  const setT = (k, v) => { const el = $(`[data-t="${k}"]`); if (el) el.textContent = v; };
  const quote = GX.debounce(async () => {
    ctrl?.abort();
    ctrl = new AbortController();
    const body = {
      action: 'quote', fulfilment: fulfilment(), payment_method: form.querySelector('[name="payment_method"]:checked')?.value || '',
      town: form.elements.town?.value || '', address_id: addrSel ? addrSel.value : 'new',
    };
    try {
      const q = await GX.api(api, { body, quiet: true, signal: ctrl.signal });
      if (q.notices && q.notices.length) {
        const key = q.notices.join('|');
        if (key !== form.dataset.noticeShown) { form.dataset.noticeShown = key; GX.toast(q.notices.join(' '), 'error'); }
      }
      setT('subtotal', GX.money(q.subtotal));
      setT('discount', '−' + GX.money(q.discount));
      $('[data-row="discount"]') && ($('[data-row="discount"]').hidden = !q.discount);
      setT('delivery', fulfilment() === 'delivery' ? (q.zone ? (q.delivery ? GX.money(q.delivery) : 'Free') : '—') : 'Free');
      setT('total', GX.money(q.total));
      setT('vat', GX.money(q.vat));
      setT('due_now', GX.money(q.due_now));
      setT('balance', GX.money(q.balance));
      const now = division === 'ranch' ? q.deposit : (body.payment_method === 'cash_on_collection' ? q.total : q.total);
      $('[data-total-now]').textContent = GX.money(now);
      $('[data-submit-label]').textContent = division === 'ranch' ? (q.deposit ? 'Reserve & pay deposit' : 'Reserve')
        : body.payment_method === 'yoco' ? 'Pay now' : 'Place order';

      // Payment methods allowed for this fulfilment
      $$('[data-method]', form).forEach((card) => {
        const ok = q.methods.includes(card.dataset.method);
        card.hidden = !ok;
        if (!ok) card.querySelector('input').checked = false;
      });
      if (!form.querySelector('[name="payment_method"]:checked')) {
        const firstOk = form.querySelector('[data-method]:not([hidden]) input');
        if (firstOk) firstOk.checked = true;
      }
      // No way to pay this order online: say so and keep the button off (instead of a silent validation error)
      const noMethods = q.methods.length === 0;
      const noBox = $('[data-no-methods]', form);
      if (noBox) noBox.hidden = !noMethods;
      const submitBtn = form.querySelector('.co-submit');
      if (submitBtn && !submitBtn.classList.contains('is-loading')) submitBtn.disabled = noMethods;

      // Delivery zone + dates
      const info = $('[data-zone-info]', form);
      if (info && fulfilment() === 'delivery') {
        if (q.zone) {
          info.innerHTML = `<div class="dc-card ok"><strong>${GX.esc(q.zone.name)}</strong> · delivery ${q.delivery ? GX.esc(GX.money(q.delivery)) : 'free'}${q.zone.free_over ? ` (free over ${GX.esc(q.zone.free_over_label)})` : ''}${q.zone.min_order ? ` · minimum order ${GX.esc(q.zone.min_order_label)}` : ''}${q.errors.fulfilment ? `<br><span class="text-accent">${GX.esc(q.errors.fulfilment)}</span>` : ''}</div>`;
        } else if (body.town || (addrSel && addrSel.value !== 'new')) {
          info.innerHTML = `<div class="dc-card no">${GX.esc(q.errors.town || 'We do not deliver there yet.')}</div>`;
        } else info.innerHTML = '';
        const sel = $('#delivery_date');
        const cur = sel.value;
        sel.innerHTML = q.delivery_dates.length
          ? q.delivery_dates.map((d) => `<option value="${d.value}"${d.value === cur ? ' selected' : ''}>${GX.esc(d.label)}</option>`).join('')
          : '<option value="">Choose your town first</option>';
      }
    } catch (e) { if (e.name !== 'AbortError') console.warn(e); }
  }, 250);

  form.addEventListener('change', (e) => { if (['payment_method', 'town', 'delivery_date'].includes(e.target.name)) quote(); });
  form.elements.town?.addEventListener('input', quote);

  /* ---------- Place order ---------- */
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    GX.clearErrors(form);
    const btn = form.querySelector('.co-submit');
    const fd = new FormData(form);
    const body = { action: 'place', address: {} };
    for (const [k, v] of fd.entries()) {
      const m = k.match(/^address\[(\w+)\]$/);
      if (m) body.address[m[1]] = v; else body[k] = v;
    }
    GX.setLoading(btn, true);
    try {
      const data = await GX.api(api, { body, quiet: true });
      GX.toast(data.message || 'Order placed!');
      location.href = data.redirect;
    } catch (err) {
      GX.setLoading(btn, false);
      GX.showErrors(form, err.errors);
      GX.toast(err.message, 'error');
      const first = form.querySelector('.has-error');
      first?.scrollIntoView({ behavior: GX.reduceMotion ? 'auto' : 'smooth', block: 'center' });
    }
  });

  syncPanels();
})();
