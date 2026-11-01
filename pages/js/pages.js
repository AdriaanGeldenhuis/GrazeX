/* GrazeX — contact form */
(function () {
  'use strict';
  const f = document.getElementById('contact-form');
  if (!f) return;
  window.GX.bindForm(f, { onSuccess(d) { window.GX.toast(d.message || 'Message sent'); f.reset(); } });
})();
