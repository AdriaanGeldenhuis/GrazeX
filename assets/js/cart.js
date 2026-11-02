/* =====================================================================
   GrazeX — shared cart behaviour (Feeds + Ranch)
   Add-to-cart buttons, drawer refresh, qty updates, remove, coupons.
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const division = document.body.dataset.division;
  if (division !== 'feeds' && division !== 'ranch') return;
  const API = `/${division}/api/cart.php`;

  function render(data) {
    GX.setCartCount(data.count || 0);
    GX.$$('[data-cart-body]').forEach((el) => {
      // Full cart page keeps the non-compact layout
      if (el.dataset.full === '1') return;
      el.innerHTML = data.body;
    });
    GX.$$('[data-cart-foot]').forEach((el) => { if (el.dataset.full !== '1') el.innerHTML = data.foot; });
    document.dispatchEvent(new CustomEvent('cart:updated', { detail: data }));
  }

  GX.cart = {
    async add(targetId, qty = 1, sourceEl = null, openDrawer = true) {
      const data = await GX.api(API, { body: { action: 'add', target_id: Number(targetId), qty: Number(qty) } });
      render(data);
      if (sourceEl) GX.flyToCart(sourceEl);
      GX.toast(data.message || 'Added to cart', 'success', { action: division === 'ranch' ? 'Reserve' : 'Checkout', href: `/${division}/checkout.php` });
      if (openDrawer && window.innerWidth >= 1024) setTimeout(() => GX.drawer.open('cart-drawer'), 350);
      return data;
    },
    async update(itemId, qty) {
      const full = GX.$('[data-cart-body][data-full="1"]');
      const data = await GX.api(API, { body: { action: 'update', item_id: Number(itemId), qty: Number(qty), compact: !full } });
      render(data);
      if (full) location.reload();
      return data;
    },
    async remove(itemId) {
      const full = GX.$('[data-cart-body][data-full="1"]');
      const data = await GX.api(API, { body: { action: 'remove', item_id: Number(itemId) } });
      render(data);
      if (full) location.reload();
      return data;
    },
    async refresh() { render(await GX.api(API, { quiet: true })); },
  };

  // Add-to-cart buttons: <button data-add-to-cart data-target="ID" [data-qty-from="#qty"]>
  GX.on(document, 'click', '[data-add-to-cart]', async (e, btn) => {
    e.preventDefault();
    const qtyInput = btn.dataset.qtyFrom ? GX.$(btn.dataset.qtyFrom) : null;
    // Correct a half-typed quantity first (the page clamps it on "change")
    if (qtyInput) qtyInput.dispatchEvent(new Event('change', { bubbles: true }));
    const target = btn.dataset.target;
    if (!target) return GX.toast('Please choose an option first.', 'error');
    GX.setLoading(btn, true);
    try { await GX.cart.add(target, qtyInput ? qtyInput.value : 1, btn); } catch (_) { /* toast shown */ }
    GX.setLoading(btn, false);
  });

  // Qty changes inside cart lines (debounced)
  const pending = {};
  document.addEventListener('change', (e) => {
    const input = e.target.closest('[data-cart-qty]');
    if (!input) return;
    const id = input.dataset.cartQty;
    // An emptied box is not "remove this line": put the previous number back
    if (input.value.trim() === '' || Number(input.value) < 1) { input.value = input.defaultValue || '1'; return; }
    clearTimeout(pending[id]);
    pending[id] = setTimeout(() => GX.cart.update(id, input.value).catch(() => {}), 350);
  });

  GX.on(document, 'click', '[data-cart-remove]', async (e, btn) => {
    e.preventDefault();
    btn.closest('.cart-line')?.classList.add('is-busy');
    try { await GX.cart.remove(btn.dataset.cartRemove); } catch (_) { btn.closest('.cart-line')?.classList.remove('is-busy'); }
  });

  // Coupon form on cart page
  const couponForm = GX.$('#coupon-form');
  if (couponForm) {
    couponForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = couponForm.querySelector('button');
      GX.setLoading(btn, true);
      try {
        await GX.api(API, { body: { action: 'coupon', code: couponForm.code.value } });
        location.reload();
      } catch (_) { /* toast */ }
      GX.setLoading(btn, false);
    });
  }
  GX.on(document, 'click', '[data-coupon-remove]', async (e) => {
    e.preventDefault();
    await GX.api(API, { body: { action: 'remove_coupon' } });
    location.reload();
  });
})();
