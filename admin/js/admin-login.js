/* GrazeX admin login */
(function () {
  'use strict';
  const GX = window.GX;
  const f = document.getElementById('admin-login');
  if (!f) return;
  GX.bindForm(f, {
    body: (form) => Object.assign(Object.fromEntries(new FormData(form).entries()), { action: form.dataset.step }),
    onSuccess(d) { location.href = d.redirect || '/admin/'; },
  });
  f.dataset.api = '/admin/api/auth.php';
  document.querySelector('[data-cancel-2fa]')?.addEventListener('click', async () => {
    await GX.api('/admin/api/auth.php', { body: { action: 'logout' } });
    location.reload();
  });
})();
