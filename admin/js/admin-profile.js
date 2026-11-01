/* Admin profile: 2FA setup */
(function () {
  'use strict';
  const GX = window.GX;
  const btn = document.querySelector('[data-2fa-start]');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    GX.setLoading(btn, true);
    try {
      const d = await GX.api('/admin/api/auth.php', { body: { action: '2fa_setup' } });
      document.querySelector('[data-2fa-secret]').textContent = d.secret.replace(/(.{4})/g, '$1 ').trim();
      document.querySelector('[data-2fa-uri]').href = d.uri;
      document.querySelector('[data-2fa-box]').hidden = false;
      btn.hidden = true;
    } catch (_) { GX.setLoading(btn, false); }
  });
})();
