/* GrazeX — payment screens: JS redirect to Yoco (keeps the Referer) and status polling */
(function () {
  'use strict';
  const GX = window.GX;

  // pay.php: forward to Yoco from our own page
  const go = document.getElementById('pay-go');
  if (go && go.dataset.redirect) {
    setTimeout(() => window.location.assign(go.dataset.redirect), 900);
  }

  // return.php: wait for the webhook
  const box = document.getElementById('pay-status');
  if (!box || box.dataset.result !== 'success') return;
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
