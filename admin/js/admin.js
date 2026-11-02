/* =====================================================================
   GrazeX Admin — shared behaviour
   Menu, search shortcut, notifications, forms (nested names), action
   buttons, repeatable rows, markdown editor, media manager + picker,
   focal-point editor.
   ===================================================================== */
(function () {
  'use strict';
  const GX = window.GX;
  const $ = GX.$, $$ = GX.$$;
  const shell = $('.adm');

  /* ---------- Menu / search / bell ---------- */
  GX.on(document, 'click', '[data-adm-menu]', () => shell?.classList.toggle('menu-open'));
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); $('[data-adm-search]')?.focus(); }
  });
  const bellPanel = $('[data-adm-bell-panel]');
  GX.on(document, 'click', '[data-adm-bell]', async () => {
    if (!bellPanel) return;
    if (!bellPanel.hidden) { bellPanel.hidden = true; return; }
    bellPanel.innerHTML = '<p class="muted small" style="padding:12px">Loading…</p>';
    bellPanel.hidden = false;
    try {
      const d = await GX.api('/admin/api/notifications.php');
      bellPanel.innerHTML = (d.items.length ? d.items.map((n) => `<a href="${GX.esc(n.link || '#')}" class="${n.is_read ? '' : 'unread'}">
          <strong>${GX.esc(n.title)}</strong><br><span class="small muted">${GX.esc(n.body || '')}</span><br><span class="tiny dim">${GX.esc(n.when)}</span></a>`).join('')
        : '<p class="muted small" style="padding:12px">No notifications yet.</p>')
        + '<button class="btn btn-sm btn-block mt-1" type="button" data-bell-read>Mark all as read</button>';
    } catch (_) { bellPanel.hidden = true; }
  });
  GX.on(document, 'click', '[data-bell-read]', async () => {
    await GX.api('/admin/api/notifications.php', { body: { action: 'read_all' } });
    bellPanel.hidden = true;
    $('[data-adm-bell] .cart-count')?.remove();
  });
  document.addEventListener('click', (e) => { if (bellPanel && !bellPanel.hidden && !e.target.closest('[data-adm-bell-panel], [data-adm-bell]')) bellPanel.hidden = true; });

  /* ---------- FormData → nested object (supports a[b][0][c] names) ----------
     Only "name[]" builds a list. Numeric keys such as weights[<order_item_id>] stay keyed by that id
     (an object), because the APIs look rows up by id. Covered by tests/js/formToObject.test.mjs. */
  GX.formToObject = function (form) {
    const out = {};
    const set = (obj, path, val) => {
      let cur = obj;
      path.forEach((k, i) => {
        const last = i === path.length - 1;
        const nextIsPush = !last && path[i + 1] === '';
        if (k === '') { // push
          if (last) cur.push(val); else { const n = nextIsPush ? [] : {}; cur.push(n); cur = n; }
          return;
        }
        if (last) { cur[k] = val; return; }
        if (cur[k] === undefined) cur[k] = nextIsPush ? [] : {};
        cur = cur[k];
      });
    };
    for (const [name, value] of new FormData(form).entries()) {
      if (value instanceof File) continue;
      const parts = name.replace(/\]/g, '').split('[');
      set(out, parts, value);
    }
    // normalise sparse arrays → arrays
    const fix = (v) => Array.isArray(v) ? v.filter((x) => x !== undefined).map(fix) : (v && typeof v === 'object' ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, fix(x)])) : v);
    return fix(out);
  };

  /* ---------- Admin forms: <form data-admin-form data-api="..." [data-action] [data-redirect] [data-reload]> ---------- */
  $$('form[data-admin-form]').forEach((form) => {
    GX.bindForm(form, {
      body: (f) => Object.assign(GX.formToObject(f), f.dataset.action ? { action: f.dataset.action } : {}),
      onSuccess(data, f) {
        GX.toast(data.message || 'Saved');
        if (data.redirect) setTimeout(() => { location.href = data.redirect; }, 400);
        else if (f.dataset.reload !== undefined) setTimeout(() => location.reload(), 500);
        f.dataset.dirty = '';
      },
    });
    form.addEventListener('input', () => { form.dataset.dirty = '1'; });
  });
  window.addEventListener('beforeunload', (e) => {
    if ($$('form[data-admin-form][data-dirty="1"]').length) { e.preventDefault(); e.returnValue = ''; }
  });
  document.addEventListener('submit', (e) => { if (e.target.matches('form[data-admin-form]')) e.target.dataset.dirty = ''; }, true);

  /* ---------- Action buttons: <button data-admin-act="/admin/api/x.php" data-payload='{}' [data-confirm] [data-prompt]> ---------- */
  GX.on(document, 'click', '[data-admin-act]', async (e, b) => {
    e.preventDefault();
    if (b.dataset.confirm && !(await GX.confirm(b.dataset.confirm, { ok: b.dataset.ok || 'Yes', danger: b.dataset.danger !== undefined }))) return;
    const payload = JSON.parse(b.dataset.payload || '{}');
    if (b.dataset.prompt) {
      const v = window.prompt(b.dataset.prompt, b.dataset.promptDefault || '');
      if (v === null) return;
      payload[b.dataset.promptField || 'note'] = v;
    }
    GX.setLoading(b, true);
    const run = async (body) => {
      const d = await GX.api(b.dataset.adminAct, { body, quiet: true });
      GX.toast(d.message || 'Done');
      if (d.redirect) location.href = d.redirect;
      else if (b.dataset.noReload === undefined) setTimeout(() => location.reload(), 450);
    };
    try {
      await run(payload);
    } catch (err) {
      // The API asks for an explicit second confirmation (e.g. approving more than is due)
      if (err.status === 409 && err.errors && err.errors.override) {
        if (await GX.confirm(err.errors.override, { ok: 'Yes, continue', danger: true })) {
          try { await run(Object.assign({}, payload, { override: 1 })); } catch (err2) { GX.toast(err2.message, 'error'); }
        }
      } else if (err.name !== 'AbortError') {
        GX.toast(err.message, 'error');
      }
    }
    GX.setLoading(b, false);
  });

  /* ---------- Modal forms: <form data-modal-form data-api> inside <dialog> ---------- */
  $$('dialog form[data-modal-form]').forEach((form) => {
    GX.bindForm(form, {
      body: (f) => Object.assign(GX.formToObject(f), f.dataset.action ? { action: f.dataset.action } : {}),
      onSuccess(data, f) {
        GX.toast(data.message || 'Saved');
        f.closest('dialog')?.close();
        if (data.redirect) location.href = data.redirect; else setTimeout(() => location.reload(), 400);
      },
    });
  });

  /* ---------- Repeatable rows ---------- */
  // <div data-repeat="variants"> rows… <template>row html with __i__</template> <button data-repeat-add="variants">
  const counters = {};
  GX.on(document, 'click', '[data-repeat-add]', (e, b) => {
    e.preventDefault();
    const name = b.dataset.repeatAdd;
    const wrap = $(`[data-repeat="${name}"]`);
    const tpl = $(`template[data-repeat-template="${name}"]`);
    if (!wrap || !tpl) return;
    counters[name] = (counters[name] ?? wrap.querySelectorAll(':scope > [data-repeat-row]').length + 1000) + 1;
    const html = tpl.innerHTML.replace(/__i__/g, String(counters[name])).replace(/__parent__/g, b.dataset.parent || '');
    wrap.insertAdjacentHTML('beforeend', html);
    wrap.lastElementChild?.querySelector('input, select')?.focus();
    wrap.closest('form')?.setAttribute('data-dirty', '1');
  });
  GX.on(document, 'click', '[data-repeat-remove]', (e, b) => {
    e.preventDefault();
    const row = b.closest('[data-repeat-row]');
    row?.closest('form')?.setAttribute('data-dirty', '1');
    row?.remove();
  });

  /* ---------- Markdown editor ---------- */
  $$('[data-md]').forEach((wrap) => {
    const ta = $('textarea', wrap);
    const prev = $('.md-preview', wrap);
    const wrapSel = (before, after = before) => {
      const s = ta.selectionStart, en = ta.selectionEnd, txt = ta.value.slice(s, en) || 'text';
      ta.setRangeText(before + txt + after, s, en, 'end');
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.focus();
    };
    GX.on(wrap, 'click', '[data-md-cmd]', (e, b) => {
      e.preventDefault();
      const cmd = b.dataset.mdCmd;
      if (cmd === 'b') wrapSel('**');
      if (cmd === 'i') wrapSel('_');
      if (cmd === 'h') wrapSel('\n## ', '\n');
      if (cmd === 'ul') wrapSel('\n- ', '\n');
      if (cmd === 'link') { const url = prompt('Link address (https://…)'); if (url) wrapSel('[', `](${url})`); }
      if (cmd === 'preview') {
        prev.hidden = !prev.hidden;
        if (!prev.hidden) GX.api('/admin/api/content.php', { body: { action: 'preview', md: ta.value }, quiet: true }).then((d) => { prev.innerHTML = d.html || '<p class="muted">Nothing to preview.</p>'; });
      }
    });
  });

  /* ---------- Media manager (product / lot galleries) ---------- */
  // <div data-media-manager data-name="media_ids"> .media-grid with .media-item[data-id] + label.media-add > input[type=file multiple]
  $$('[data-media-manager]').forEach((mgr) => {
    const grid = $('.media-grid', mgr);
    const name = mgr.dataset.name || 'media_ids';
    const sync = () => {
      $$('input[data-media-hidden]', mgr).forEach((i) => i.remove());
      $$('.media-item', grid).forEach((it, i) => {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = `${name}[]`; h.value = it.dataset.id; h.dataset.mediaHidden = '1';
        mgr.appendChild(h);
        it.querySelector('.mi-first')?.toggleAttribute('hidden', i !== 0);
      });
      mgr.closest('form')?.setAttribute('data-dirty', '1');
    };
    const itemHtml = (m) => `<div class="media-item" draggable="true" data-id="${m.id}"><img src="${GX.esc(m.thumb)}" alt="">
      <span class="badge badge-solid mi-first" hidden>Main</span>
      <div class="mi-actions"><button type="button" data-focal="${m.id}" title="Set focal point">◎</button><button type="button" data-media-remove title="Remove">✕</button></div></div>`;
    const input = $('input[type="file"]', mgr);
    input?.addEventListener('change', async () => {
      const files = Array.from(input.files);
      for (const f of files) {
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('file', f);
        try {
          const m = await GX.api('/admin/api/media.php', { form: fd });
          $('.media-add', grid).insertAdjacentHTML('beforebegin', itemHtml(m));
          sync();
        } catch (_) { /* toast */ }
      }
      input.value = '';
    });
    GX.on(grid, 'click', '[data-media-remove]', (e, b) => { b.closest('.media-item').remove(); sync(); });
    // drag to reorder
    let drag = null;
    grid.addEventListener('dragstart', (e) => { drag = e.target.closest('.media-item'); e.dataTransfer.effectAllowed = 'move'; });
    grid.addEventListener('dragover', (e) => {
      e.preventDefault();
      const over = e.target.closest('.media-item');
      if (drag && over && over !== drag) {
        const r = over.getBoundingClientRect();
        over.parentNode.insertBefore(drag, (e.clientX - r.left) > r.width / 2 ? over.nextSibling : over);
      }
    });
    grid.addEventListener('drop', (e) => { e.preventDefault(); sync(); drag = null; });
    sync();
    mgr.closest('form')?.removeAttribute('data-dirty');
  });

  /* ---------- Focal point editor (16:9 crops) ---------- */
  GX.on(document, 'click', '[data-focal]', async (e, b) => {
    e.preventDefault();
    const id = b.dataset.focal;
    const m = await GX.api('/admin/api/media.php?action=get&id=' + encodeURIComponent(id));
    const d = document.createElement('dialog');
    d.className = 'modal';
    d.style.width = 'min(760px, calc(100% - 24px))';
    d.innerHTML = `<div class="modal-head"><h3>Focal point — tap the most important part</h3><button class="nav-icon-btn" value="x">✕</button></div>
      <div class="modal-body"><div class="focal"><img src="${GX.esc(m.large)}" alt=""><span class="dot" style="left:${m.focal_x * 100}%;top:${m.focal_y * 100}%"></span></div>
      <div class="field mt-2"><label>Alt text (describes the photo)</label><input class="input" data-alt value="${GX.esc(m.alt_text || '')}"></div>
      <div class="focal-preview"><span class="label">16:9 preview</span><div class="media-16x9"><img data-prev src="${GX.esc(m.wide)}" alt="" style="object-position:${m.focal_x * 100}% ${m.focal_y * 100}%"></div></div></div>
      <div class="modal-foot"><button class="btn btn-ghost" value="x">Cancel</button><button class="btn btn-primary" data-save type="button">Save crop</button></div>`;
    document.body.appendChild(d);
    let fx = Number(m.focal_x), fy = Number(m.focal_y);
    const focal = $('.focal', d), dot = $('.dot', d), prevImg = $('[data-prev]', d);
    prevImg.src = m.large;
    prevImg.style.objectFit = 'cover';
    focal.addEventListener('click', (ev) => {
      const r = focal.querySelector('img').getBoundingClientRect();
      fx = Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width));
      fy = Math.min(1, Math.max(0, (ev.clientY - r.top) / r.height));
      dot.style.left = fx * 100 + '%'; dot.style.top = fy * 100 + '%';
      prevImg.style.objectPosition = `${fx * 100}% ${fy * 100}%`;
    });
    d.addEventListener('click', (ev) => { if (ev.target.closest('[value="x"]')) d.close(); });
    $('[data-save]', d).addEventListener('click', async (ev) => {
      GX.setLoading(ev.target, true);
      try {
        await GX.api('/admin/api/media.php', { body: { action: 'update', id: Number(id), focal_x: fx, focal_y: fy, alt_text: $('[data-alt]', d).value } });
        GX.toast('Crop saved');
        d.close();
      } catch (_) { GX.setLoading(ev.target, false); }
    });
    d.addEventListener('close', () => d.remove());
    d.showModal();
  });

  /* ---------- Single image picker (content blocks, categories…) ---------- */
  // <div data-image-field> <input type=hidden name=...> <div class="ip-preview"></div> <button data-pick> <button data-clear>
  GX.on(document, 'click', '[data-image-field] [data-pick]', async (e, b) => {
    e.preventDefault();
    const field = b.closest('[data-image-field]');
    const d = document.createElement('dialog');
    d.className = 'modal';
    d.style.width = 'min(900px, calc(100% - 24px))';
    d.innerHTML = `<div class="modal-head"><h3>Choose an image</h3><button class="nav-icon-btn" value="x">✕</button></div>
      <div class="modal-body"><div class="media-grid" data-pick-grid><label class="media-add"><input type="file" accept="image/*" hidden>＋<br>Upload new</label></div></div>`;
    document.body.appendChild(d);
    const grid = $('[data-pick-grid]', d);
    const add = (m) => grid.insertAdjacentHTML('beforeend', `<button type="button" class="media-item" data-choose="${m.id}" data-thumb="${GX.esc(m.thumb)}"><img src="${GX.esc(m.thumb)}" alt=""></button>`);
    try { (await GX.api('/admin/api/media.php?action=list')).items.forEach(add); } catch (_) { /* toast */ }
    $('input[type=file]', d).addEventListener('change', async (ev) => {
      const fd = new FormData(); fd.append('action', 'upload'); fd.append('file', ev.target.files[0]);
      try { const m = await GX.api('/admin/api/media.php', { form: fd }); choose(m.id, m.thumb); } catch (_) { /* toast */ }
    });
    const choose = (id, thumb) => {
      $('input[type=hidden]', field).value = id;
      $('.ip-preview', field).innerHTML = `<img src="${GX.esc(thumb)}" alt="">`;
      field.closest('form')?.setAttribute('data-dirty', '1');
      d.close();
    };
    GX.on(grid, 'click', '[data-choose]', (ev, it) => choose(it.dataset.choose, it.dataset.thumb));
    d.addEventListener('click', (ev) => { if (ev.target.closest('[value="x"]')) d.close(); });
    d.addEventListener('close', () => d.remove());
    d.showModal();
  });
  GX.on(document, 'click', '[data-image-field] [data-clear]', (e, b) => {
    e.preventDefault();
    const field = b.closest('[data-image-field]');
    $('input[type=hidden]', field).value = '';
    $('.ip-preview', field).innerHTML = '<span class="muted small">No image</span>';
  });

  /* ---------- Auto-slug ---------- */
  $$('[data-slug-from]').forEach((slug) => {
    const src = document.getElementById(slug.dataset.slugFrom);
    if (!src) return;
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = true; });
    src.addEventListener('input', () => {
      if (touched) return;
      slug.value = src.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    });
  });

  /* ---------- Filters auto-submit ---------- */
  $$('form[data-autosubmit]').forEach((f) => f.addEventListener('change', () => f.submit()));
})();
