/* GrazeX setup wizard */
(function () {
  'use strict';
  const form = document.getElementById('setup-form');
  if (!form) return;
  window.GX.bindForm(form, {
    onSuccess(data) {
      window.GX.toast('Owner account created. Welcome to GrazeX!');
      setTimeout(() => { location.href = data.redirect || '/'; }, 900);
    },
  });
})();
