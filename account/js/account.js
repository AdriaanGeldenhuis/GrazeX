/* =====================================================================
   GrazeX — customer portal behaviour
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const $ = GX.$, $$ = GX.$$;

  // Auth forms (login/register/forgot/reset) + simple account forms: <form data-api data-action>
  $$('form[data-api]').forEach((form) => {
    GX.bindForm(form, {
      body: (f) => {
        const o = {};
        for (const [k, v] of new FormData(f).entries()) o[k] = v;
        if (f.dataset.action) o.action = f.dataset.action;
        return o;
      },
      onSuccess(data, f) {
        if (data.message) GX.toast(data.message);
        if (data.redirect) setTimeout(() => { location.href = data.redirect; }, 350);
        else if (f.dataset.reload !== undefined) setTimeout(() => location.reload(), 500);
        else if (f.dataset.reset !== undefined) f.reset();
      },
    });
  });

  // Logout
  GX.on(document, 'click', '[data-logout]', async (e) => {
    e.preventDefault();
    const d = await GX.api('/account/api/auth.php', { body: { action: 'logout' } });
    location.href = d.redirect || '/';
  });

  // Generic action buttons: <button data-act="/account/api/orders.php" data-payload='{"action":"cancel"}' data-confirm="...">
  GX.on(document, 'click', '[data-act]', async (e, b) => {
    e.preventDefault();
    if (b.dataset.confirm && !(await GX.confirm(b.dataset.confirm, { ok: b.dataset.ok || 'Yes', danger: b.dataset.danger !== undefined }))) return;
    GX.setLoading(b, true);
    try {
      const d = await GX.api(b.dataset.act, { body: JSON.parse(b.dataset.payload || '{}') });
      if (d.message) GX.toast(d.message);
      if (d.redirect) location.href = d.redirect;
      else if (d.download) { downloadJson(d.download, d.filename || 'data.json'); }
      else setTimeout(() => location.reload(), 500);
    } catch (_) { /* toast shown */ }
    GX.setLoading(b, false);
  });

  function downloadJson(obj, name) {
    const blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
    // In the GrazeX app the file goes to the phone's Downloads folder (a WebView ignores <a download>)
    if (window.GXApp?.saveBlob) {
      window.GXApp.saveBlob(blob, name).then((msg) => GX.toast(msg), (err) => GX.toast(err.message, 'error'));
      return;
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }

  // Copy to clipboard
  // (navigator.clipboard needs https and permission; some WebViews and older phones don't have it)
  const copyText = async (text) => {
    try { await navigator.clipboard.writeText(text); return true; } catch (_) { /* try the old way */ }
    const back = document.activeElement;
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, text.length); // iOS
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
    ta.remove();
    back?.focus?.(); // keyboard and screen-reader users stay where they were
    return ok;
  };
  GX.on(document, 'click', '[data-copy]', async (e, b) => {
    if (await copyText(b.dataset.copy)) GX.toast('Copied');
    else GX.toast('Could not copy — press and hold the text to copy it: ' + b.dataset.copy, 'error', { timeout: 9000 });
  });

  // POP upload with drag & drop
  const pop = $('#pop-form');
  if (pop) {
    const drop = $('.drop', pop);
    const input = $('input[type="file"]', pop);
    const label = $('[data-file-label]', pop);
    const pick = () => { label.textContent = input.files[0] ? `${input.files[0].name} (${Math.round(input.files[0].size / 1024)} KB)` : 'Tap to choose a photo or PDF'; };
    input.addEventListener('change', pick);
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; pick(); } });
    pop.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!input.files.length) return GX.toast('Choose your proof of payment first.', 'error');
      const btn = pop.querySelector('[type="submit"]');
      GX.setLoading(btn, true);
      try {
        const d = await GX.api('/account/api/pop-upload.php', { form: new FormData(pop) });
        GX.toast(d.message || 'Uploaded');
        setTimeout(() => location.reload(), 700);
      } catch (_) { GX.setLoading(btn, false); }
    });
  }

  // VAT farmer certificate upload
  const vat = $('#vat-form');
  if (vat) {
    vat.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = vat.querySelector('[type="submit"]');
      GX.setLoading(btn, true);
      try {
        const d = await GX.api('/account/api/profile.php', { form: new FormData(vat) });
        GX.toast(d.message || 'Uploaded');
        setTimeout(() => location.reload(), 700);
      } catch (_) { GX.setLoading(btn, false); }
    });
  }

  // Confetti-free celebration: just focus the success heading for screen readers
  $('.placed-hero h1')?.focus?.();
})();
