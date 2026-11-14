/* GrazeX — payment screens: JS redirect to Yoco (keeps the Referer) and status polling */
(function () {
  'use strict';
  const GX = window.GX;

  // pay.php: forward to Yoco from our own page
  const go = document.getElementById('pay-go');
  // Not when the customer came BACK to this page from Yoco: forwarding again would trap the Back button
  // (the page has its own "Pay now" button and a link back to the order)
  const nav = performance.getEntriesByType?.('navigation')[0];
  if (go && go.dataset.redirect && !(nav && nav.type === 'back_forward')) {
    setTimeout(() => window.location.assign(go.dataset.redirect), 900);
  }

  // return.php: wait for the webhook
  const box = document.getElementById('pay-status');
  if (!box || box.dataset.result !== 'success') return;
  box.setAttribute('role', 'status'); // "Confirming…" → "Payment received" is read out by screen readers
  const show = (state) => box.querySelectorAll('[data-state]').forEach((el) => { el.hidden = el.dataset.state !== state; });
  const started = Date.now();
  let tries = 0;
  const poll = async () => {
    tries++;
    const check = Date.now() - started > 9000 && tries % 3 === 0 ? '&check=1' : '';
    try {
      const d = await GX.api('/payments/api/status.php?t=' + encodeURIComponent(box.dataset.token) + check, { quiet: true });
      if (d.late) { show('late'); return; }
      if (d.paid) { show('paid'); return; }
      if (d.failed) { location.href = location.pathname + '?t=' + encodeURIComponent(box.dataset.token) + '&r=failed'; return; }
    } catch (_) { /* keep trying */ }
    if (Date.now() - started > 75000) { show('slow'); return; }
    setTimeout(poll, 2500);
  };
  poll();
})();
