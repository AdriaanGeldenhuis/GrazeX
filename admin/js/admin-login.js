/* GrazeX admin login */
(function () {
  'use strict';
  const GX = window.GX;
  // "Forgot password" and "choose a new password" forms
  document.querySelectorAll('form[data-admin-public]').forEach((form) => {
    GX.bindForm(form, {
      body: (x) => Object.assign(Object.fromEntries(new FormData(x).entries()), { action: x.dataset.action }),
      onSuccess(d, x) {
        if (d.redirect) { GX.toast(d.message || 'Done'); setTimeout(() => { location.href = d.redirect; }, 900); return; }
        x.hidden = true;
        const done = document.querySelector('[data-admin-public-done]');
        if (done) { done.textContent = d.message || 'Done'; done.hidden = false; done.focus(); }
      },
    });
  });
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
