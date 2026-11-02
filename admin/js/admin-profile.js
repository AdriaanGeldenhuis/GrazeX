/* Admin profile: 2FA setup (password first, so a hijacked session cannot move 2FA to another phone) */
(function () {
  'use strict';
  const GX = window.GX;
  const form = document.querySelector('form[data-2fa-start]');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type="submit"]');
    GX.clearErrors(form);
    GX.setLoading(btn, true);
    try {
      const d = await GX.api('/admin/api/auth.php', { body: { action: '2fa_setup', password: form.querySelector('[name="password"]').value }, quiet: true });
      document.querySelector('[data-2fa-secret]').textContent = d.secret.replace(/(.{4})/g, '$1 ').trim();
      document.querySelector('[data-2fa-uri]').href = d.uri;
      document.querySelector('[data-2fa-box]').hidden = false;
      form.hidden = true;
    } catch (err) {
      GX.showErrors(form, err.errors);
      GX.toast(err.message, 'error');
      GX.setLoading(btn, false);
    }
  });
})();
