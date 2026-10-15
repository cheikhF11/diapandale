/* ═══════════════════════════════════════════════════════════════════════
   DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE — Script commun
   Aucune dépendance. Fonctionne sur tous les navigateurs récents
   (Chrome, Edge, Firefox, Safari iOS 13+, Samsung Internet).
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var doc = document;
  var NS = 'http://www.w3.org/2000/svg';
  var XLINK = 'http://www.w3.org/1999/xlink';

  function $(sel, root) { return (root || doc).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }
  function csrfToken() {
    var m = $('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }
  function el(tag, attrs, children) {
    var n = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; }
      else if (k === 'class') { n.className = attrs[k]; }
      else { n.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { if (c) { n.appendChild(c); } });
    return n;
  }
  function icon(name) {
    var s = doc.createElementNS(NS, 'svg');
    s.setAttribute('class', 'icon');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    var u = doc.createElementNS(NS, 'use');
    u.setAttribute('href', '#i-' + name);
    u.setAttributeNS(XLINK, 'xlink:href', '#i-' + name);
    s.appendChild(u);
    return s;
  }
  function normalize(s) {
    s = (s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s;
  }

  /* ── Gestionnaire de fenêtres modales ─────────────────────────────── */
  var openStack = [];

  function focusables(root) {
    return $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', root)
      .filter(function (n) { return n.offsetParent !== null; });
  }

  function openModal(modal, opener) {
    if (!modal || modal.classList.contains('is-open')) { return; }
    modal._opener = opener || doc.activeElement;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    openStack.push(modal);
    doc.documentElement.classList.add('modal-open');
    var target = $('[data-autofocus]', modal) || $('.modal__dialog', modal);
    if (target) {
      if (!target.hasAttribute('tabindex')) { target.setAttribute('tabindex', '-1'); }
      setTimeout(function () { try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); } }, 30);
    }
    modal.dispatchEvent(new CustomEvent('modal:open'));
  }

  function closeModal(modal) {
    if (!modal || !modal.classList.contains('is-open')) { return; }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    openStack = openStack.filter(function (m) { return m !== modal; });
    if (!openStack.length) { doc.documentElement.classList.remove('modal-open'); }
    var opener = modal._opener;
    if (opener && typeof opener.focus === 'function' && doc.body.contains(opener)) {
      try { opener.focus({ preventScroll: true }); } catch (e) { /* ignore */ }
    }
    modal.dispatchEvent(new CustomEvent('modal:close'));
  }

  doc.addEventListener('click', function (e) {
    var closer = e.target.closest ? e.target.closest('[data-modal-close]') : null;
    if (closer) {
      var m = closer.closest('.modal');
      if (m) { e.preventDefault(); closeModal(m); }
      return;
    }
    var opener = e.target.closest ? e.target.closest('[data-modal-open]') : null;
    if (opener) {
      e.preventDefault();
      openModal(doc.getElementById(opener.getAttribute('data-modal-open')), opener);
    }
  });

  doc.addEventListener('keydown', function (e) {
    var top = openStack[openStack.length - 1];
    if (!top) { return; }
    if (e.key === 'Escape' || e.key === 'Esc') {
      if (top.hasAttribute('data-static')) { return; }
      e.preventDefault();
      closeModal(top);
      return;
    }
    if (e.key === 'Tab') {
      var items = focusables(top);
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && (doc.activeElement === first || doc.activeElement === top.querySelector('.modal__dialog'))) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && doc.activeElement === last) {
        e.preventDefault(); first.focus();
      }
    }
  });

  /* ── Navigation mobile ────────────────────────────────────────────── */
  function initNav() {
    var toggle = $('#navToggle');
    var panel = $('#navPanel');
    if (!toggle || !panel) { return; }

    function setOpen(open) {
      panel.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    }
    toggle.addEventListener('click', function () { setOpen(!panel.classList.contains('is-open')); });
    doc.addEventListener('click', function (e) {
      if (panel.classList.contains('is-open') && !panel.contains(e.target) && !toggle.contains(e.target)) { setOpen(false); }
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel.classList.contains('is-open') && !openStack.length) { setOpen(false); toggle.focus(); }
    });
    window.addEventListener('resize', function () { if (window.innerWidth >= 1280) { setOpen(false); } });
    $$('a', panel).forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
  }

  /* ── Onglets ──────────────────────────────────────────────────────── */
  function switchTab(id, updateUrl) {
    var found = false;
    $$('[data-tab]').forEach(function (b) {
      var on = b.getAttribute('data-tab') === id;
      if (on) { found = true; }
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.classList.toggle('is-active', on);
    });
    if (!found) { return; }
    $$('.tab-panel').forEach(function (p) { p.classList.toggle('is-active', p.id === 'tab-' + id); });
    if (updateUrl && window.history && history.replaceState) {
      var u = new URL(window.location.href);
      u.searchParams.set('tab', id);
      history.replaceState(null, '', u.toString());
    }
  }
  window.appSwitchTab = switchTab;

  function initTabs() {
    var tabs = $$('[data-tab]');
    if (!tabs.length) { return; }
    tabs.forEach(function (b) {
      b.addEventListener('click', function () { switchTab(b.getAttribute('data-tab'), true); });
    });
    $$('[data-switch-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        switchTab(b.getAttribute('data-switch-tab'), true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
    var wanted = new URLSearchParams(window.location.search).get('tab');
    if (wanted) { switchTab(wanted, false); }
  }

  /* ── Filtre de la liste des membres ───────────────────────────────── */
  function initMemberFilter() {
    var root = $('[data-members]');
    if (!root) { return; }
    var input = $('[data-member-search]', root);
    var chips = $$('[data-member-cat]', root);
    var items = $$('[data-member-item]', root);
    var empty = $('[data-member-empty]', root);
    var label = $('[data-member-count]', root);
    var cat = 'all';
    var q = '';

    items.forEach(function (it) { it._s = normalize(it.getAttribute('data-search')); });

    function apply() {
      var n = 0;
      items.forEach(function (it) {
        var ok = (cat === 'all' || it.getAttribute('data-cat') === cat) && (!q || it._s.indexOf(q) !== -1);
        it.hidden = !ok;
        if (ok) { n++; }
      });
      if (empty) { empty.hidden = n > 0; }
      if (label) { label.textContent = n + (n > 1 ? ' membres' : ' membre'); }
    }

    if (input) {
      input.addEventListener('input', function () { q = normalize(input.value.trim()); apply(); });
    }
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        cat = c.getAttribute('data-member-cat');
        chips.forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
        apply();
      });
    });
    apply();
  }

  /* ── Fiche membre (modal) ─────────────────────────────────────────── */
  var memberCache = {};

  function safeHref(h) {
    return /^(tel:|mailto:|https:\/\/wa\.me\/)/.test(h || '') ? h : null;
  }

  function buildAvatar(m) {
    var a = el('span', { class: 'avatar avatar--xl', 'aria-hidden': 'true' });
    a.appendChild(doc.createTextNode(m.initiales || ''));
    if (m.photo) {
      a.appendChild(el('img', { src: m.photo, alt: '', decoding: 'async' }));
    }
    return a;
  }

  function renderMember(box, m) {
    box.textContent = '';

    var hero = el('div', { class: 'profile-hero' });
    hero.appendChild(buildAvatar(m));
    hero.appendChild(el('h2', { id: 'memberModalTitle', text: m.nom_complet }));
    if (m.sous_titre) { hero.appendChild(el('p', { text: m.sous_titre })); }
    if (m.categorie) { hero.appendChild(el('span', { class: 'badge', text: m.categorie })); }
    box.appendChild(hero);

    var list = el('ul', { class: 'profile-list' });
    (m.lignes || []).forEach(function (row) {
      var li = el('li');
      li.appendChild(el('span', { class: 'ico' }, [icon(row.icone || 'user')]));
      var wrap = el('div', { style: 'min-width:0' });
      wrap.appendChild(el('span', { class: 'k', text: row.label }));
      var href = safeHref(row.href);
      wrap.appendChild(href
        ? el('a', { class: 'v', href: href, text: row.valeur })
        : el('span', { class: 'v', text: row.valeur }));
      li.appendChild(wrap);
      list.appendChild(li);
    });
    box.appendChild(list);

    var actions = el('div', { class: 'profile-actions' });
    (m.actions || []).forEach(function (a) {
      var href = safeHref(a.href);
      if (!href) { return; }
      var link = el('a', { class: 'btn btn-outline btn-sm', href: href });
      if (href.indexOf('http') === 0) { link.setAttribute('target', '_blank'); link.setAttribute('rel', 'noopener'); }
      link.appendChild(icon(a.icone || 'phone'));
      link.appendChild(doc.createTextNode(' ' + a.label));
      actions.appendChild(link);
    });
    if (actions.childNodes.length) { box.appendChild(actions); }
  }

  function renderState(box, message, loading) {
    box.textContent = '';
    var s = el('div', { class: 'profile-hero profile-state' });
    if (loading) { s.appendChild(el('div', { class: 'spinner' })); }
    s.appendChild(el('p', { text: message }));
    box.appendChild(s);
  }

  function initMemberModal() {
    var modal = $('#memberModal');
    var box = $('#memberModalContent');
    if (!modal || !box) { return; }
    var current = 0;

    function show(id, opener) {
      current = id;
      openModal(modal, opener);
      if (memberCache[id]) { renderMember(box, memberCache[id]); return; }
      renderState(box, 'Chargement…', true);
      fetch('membre_info.php?id=' + encodeURIComponent(id), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) {
          if (r.status === 401) { window.location.href = 'index.php'; throw new Error('auth'); }
          return r.json();
        })
        .then(function (j) {
          if (!j || !j.ok) { throw new Error((j && j.error) || 'Erreur'); }
          memberCache[id] = j.member;
          if (current === id) { renderMember(box, j.member); }
        })
        .catch(function (err) {
          if (err && err.message === 'auth') { return; }
          if (current === id) { renderState(box, 'Impossible de charger la fiche de ce membre. Veuillez réessayer.', false); }
        });
    }

    doc.addEventListener('click', function (e) {
      var card = e.target.closest ? e.target.closest('[data-member-id]') : null;
      if (!card) { return; }
      e.preventDefault();
      show(card.getAttribute('data-member-id'), card);
    });
  }

  /* ── Boîte de confirmation ────────────────────────────────────────── */
  var confirmModal = null;
  function askConfirm(opts, onOk) {
    if (!confirmModal) {
      confirmModal = el('div', { class: 'modal', role: 'alertdialog', 'aria-modal': 'true', 'aria-hidden': 'true', 'aria-labelledby': 'confirmTitle' });
      confirmModal.appendChild(el('div', { class: 'modal__backdrop', 'data-modal-close': '' }));
      var dlg = el('div', { class: 'modal__dialog modal__dialog--sm' });
      var body = el('div', { class: 'modal__body' });
      body.appendChild(el('h3', { class: 'modal__title', id: 'confirmTitle' }));
      body.appendChild(el('p', { class: 'text-muted mb-0', 'data-confirm-text': '' }));
      var actions = el('div', { class: 'modal__actions' });
      actions.appendChild(el('button', { type: 'button', class: 'btn btn-outline', 'data-modal-close': '', text: 'Annuler' }));
      actions.appendChild(el('button', { type: 'button', class: 'btn btn-danger', 'data-confirm-ok': '', 'data-autofocus': '' }));
      body.appendChild(actions);
      dlg.appendChild(body);
      confirmModal.appendChild(dlg);
      doc.body.appendChild(confirmModal);
    }
    $('#confirmTitle', confirmModal).textContent = opts.title || 'Confirmation';
    $('[data-confirm-text]', confirmModal).textContent = opts.message || '';
    var ok = $('[data-confirm-ok]', confirmModal);
    ok.textContent = opts.okLabel || 'Confirmer';
    ok.onclick = function () { closeModal(confirmModal); onOk(); };
    openModal(confirmModal);
  }
  window.appConfirm = askConfirm;

  function initConfirmForms() {
    $$('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (f._confirmed) { return; }
        e.preventDefault();
        askConfirm({
          title: f.getAttribute('data-confirm-title') || 'Confirmation',
          message: f.getAttribute('data-confirm'),
          okLabel: f.getAttribute('data-confirm-ok') || 'Confirmer'
        }, function () { f._confirmed = true; f.submit(); });
      });
    });
  }

  /* ── États de chargement des formulaires ──────────────────────────── */
  function initLoadingForms() {
    $$('form[data-loading]').forEach(function (f) {
      f.addEventListener('submit', function () {
        setTimeout(function () {
          var b = $('button[type="submit"]', f);
          if (!b || f._noLoading) { return; }
          b._label = b.textContent;
          b.classList.add('is-loading');
          var txt = f.getAttribute('data-loading');
          if (txt) { b.textContent = txt; }
        }, 0);
      });
    });
    window.addEventListener('pageshow', function (e) {
      if (!e.persisted) { return; }
      $$('form[data-loading] button.is-loading').forEach(function (b) {
        b.classList.remove('is-loading');
        if (b._label) { b.textContent = b._label; }
      });
    });
  }

  /* ── Champ mot de passe : afficher / masquer + robustesse ─────────── */
  function initPasswordFields() {
    $$('[data-toggle-pass]').forEach(function (btn) {
      var input = doc.getElementById(btn.getAttribute('data-toggle-pass'));
      if (!input) { return; }
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        var use = $('use', btn);
        if (use) {
          use.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
          use.setAttributeNS(XLINK, 'xlink:href', show ? '#i-eye-off' : '#i-eye');
        }
      });
    });

    $$('[data-strength-for]').forEach(function (bar) {
      var input = doc.getElementById(bar.getAttribute('data-strength-for'));
      var fill = $('span', bar);
      if (!input || !fill) { return; }
      input.addEventListener('input', function () {
        var v = input.value;
        var score = 0;
        if (v.length >= 8) { score++; }
        if (v.length >= 12) { score++; }
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) { score++; }
        if (/\d/.test(v)) { score++; }
        if (/[^A-Za-z0-9]/.test(v)) { score++; }
        var pct = v ? Math.max(12, score * 20) : 0;
        fill.style.width = pct + '%';
        fill.style.background = score <= 2 ? '#c62828' : (score === 3 ? '#ef9a1a' : '#2e7d32');
      });
    });
  }

  /* ── Compteur de caractères ───────────────────────────────────────── */
  function initCounters() {
    $$('[data-counter-for]').forEach(function (c) {
      var t = doc.getElementById(c.getAttribute('data-counter-for'));
      if (!t) { return; }
      var max = parseInt(t.getAttribute('maxlength') || '0', 10);
      function upd() { c.textContent = t.value.length + (max ? ' / ' + max : '') + ' caractères'; }
      t.addEventListener('input', upd);
      upd();
    });
  }

  /* ── Champs conditionnels selon la profession + progression ───────── */
  function initProfessionFields() {
    var select = $('[data-profession-select]');
    if (!select) { return; }
    var fields = $$('[data-prof-field]');
    function apply() {
      fields.forEach(function (f) {
        var on = f.getAttribute('data-prof-field') === select.value;
        f.hidden = !on;
        $$('input, select', f).forEach(function (i) {
          i.required = on;
          i.disabled = !on;
        });
      });
    }
    select.addEventListener('change', apply);
    apply();
  }

  function initFormProgress() {
    var bar = $('[data-progress-bar]');
    var form = bar ? bar.closest('form') : null;
    if (!bar || !form) { return; }
    function update() {
      var names = {};
      var total = 0;
      var filled = 0;
      $$('input[required], select[required], textarea[required]', form).forEach(function (i) {
        if (i.disabled || i.closest('[hidden]')) { return; }
        if (i.type === 'radio') {
          if (names[i.name]) { return; }
          names[i.name] = true;
          total++;
          if (form.querySelector('input[name="' + i.name + '"]:checked')) { filled++; }
        } else {
          total++;
          if (i.value.trim() !== '') { filled++; }
        }
      });
      var pd = $('[data-photo-data]', form);
      if (pd && pd.closest('[data-required]')) { total++; if (pd.value) { filled++; } }
      bar.style.width = (total ? Math.round(filled / total * 100) : 0) + '%';
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('photo:change', update);
    update();
  }

  /* ── Sélecteur de photo avec recadrage ────────────────────────────── */
  var cropperModal = null;

  function buildCropper() {
    if (cropperModal) { return cropperModal; }
    var m = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-hidden': 'true', 'aria-label': 'Recadrer la photo' });
    m.appendChild(el('div', { class: 'modal__backdrop' }));
    var dlg = el('div', { class: 'modal__dialog modal__dialog--sm' });
    var wrap = el('div', { class: 'cropper' });
    wrap.appendChild(el('h3', { class: 'modal__title', text: 'Recadrer votre photo' }));
    wrap.appendChild(el('p', { class: 'text-muted mb-0', style: 'font-size:.9rem', text: 'Faites glisser l’image pour la cadrer, puis ajustez le zoom.' }));
    var stage = el('div', { class: 'cropper__stage', 'data-stage': '' });
    stage.appendChild(el('canvas', { width: '360', height: '360' }));
    stage.appendChild(el('div', { class: 'cropper__mask' }));
    wrap.appendChild(stage);
    var zoom = el('div', { class: 'cropper__zoom' });
    zoom.appendChild(icon('minus'));
    zoom.appendChild(el('input', { type: 'range', min: '1', max: '3', step: '0.01', value: '1', 'aria-label': 'Zoom', 'data-autofocus': '' }));
    zoom.appendChild(icon('plus'));
    wrap.appendChild(zoom);
    var actions = el('div', { class: 'modal__actions' });
    actions.appendChild(el('button', { type: 'button', class: 'btn btn-outline', 'data-crop-cancel': '', text: 'Annuler' }));
    actions.appendChild(el('button', { type: 'button', class: 'btn btn-primary', 'data-crop-ok': '', text: 'Valider la photo' }));
    wrap.appendChild(actions);
    dlg.appendChild(wrap);
    m.appendChild(dlg);
    doc.body.appendChild(m);
    cropperModal = m;
    return m;
  }

  function openCropper(img, done) {
    var m = buildCropper();
    var stage = $('[data-stage]', m);
    var cv = $('canvas', m);
    var ctx = cv.getContext('2d');
    var zoomEl = $('input[type="range"]', m);
    var V = cv.width;
    var iw = img.naturalWidth || img.width;
    var ih = img.naturalHeight || img.height;
    var base = V / Math.min(iw, ih);
    var zoom = 1;
    var s = base;
    var ox = (V - iw * s) / 2;
    var oy = (V - ih * s) / 2;
    var pts = {};
    var pinch = null;

    function clamp() {
      ox = Math.min(0, Math.max(V - iw * s, ox));
      oy = Math.min(0, Math.max(V - ih * s, oy));
    }
    function draw() {
      ctx.fillStyle = '#111';
      ctx.fillRect(0, 0, V, V);
      ctx.drawImage(img, ox, oy, iw * s, ih * s);
    }
    function setZoom(z) {
      z = Math.min(3, Math.max(1, z));
      var cx = (V / 2 - ox) / s;
      var cy = (V / 2 - oy) / s;
      zoom = z;
      s = base * zoom;
      ox = V / 2 - cx * s;
      oy = V / 2 - cy * s;
      clamp();
      draw();
    }
    function exportJpeg(size, quality) {
      var c = doc.createElement('canvas');
      c.width = size; c.height = size;
      var x = c.getContext('2d');
      x.fillStyle = '#fff';
      x.fillRect(0, 0, size, size);
      x.imageSmoothingEnabled = true;
      x.imageSmoothingQuality = 'high';
      x.drawImage(img, -ox / s, -oy / s, V / s, V / s, 0, 0, size, size);
      return c.toDataURL('image/jpeg', quality);
    }
    function count() { return Object.keys(pts).length; }
    function dist() {
      var k = Object.keys(pts);
      var a = pts[k[0]];
      var b = pts[k[1]];
      return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2));
    }

    zoomEl.value = '1';
    clamp();
    draw();

    stage.onpointerdown = function (e) {
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      try { stage.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
      if (count() === 2) { pinch = { d: dist(), z: zoom }; }
    };
    stage.onpointermove = function (e) {
      var p = pts[e.pointerId];
      if (!p) { return; }
      var k = V / stage.clientWidth;
      if (count() === 2 && pinch) {
        pts[e.pointerId] = { x: e.clientX, y: e.clientY };
        var d = dist();
        if (pinch.d > 0) { zoomEl.value = String(Math.min(3, Math.max(1, pinch.z * d / pinch.d))); setZoom(parseFloat(zoomEl.value)); }
        return;
      }
      ox += (e.clientX - p.x) * k;
      oy += (e.clientY - p.y) * k;
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      clamp();
      draw();
    };
    stage.onpointerup = stage.onpointercancel = function (e) {
      delete pts[e.pointerId];
      pinch = null;
    };
    stage.onwheel = function (e) {
      e.preventDefault();
      var z = Math.min(3, Math.max(1, zoom + (e.deltaY < 0 ? 0.12 : -0.12)));
      zoomEl.value = String(z);
      setZoom(z);
    };
    zoomEl.oninput = function () { setZoom(parseFloat(zoomEl.value)); };

    $('[data-crop-cancel]', m).onclick = function () { closeModal(m); };
    $('[data-crop-ok]', m).onclick = function () {
      var result = { main: exportJpeg(480, 0.86), thumb: exportJpeg(160, 0.82) };
      closeModal(m);
      done(result);
    };
    openModal(m);
  }

  function readImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image')); };
      img.src = url;
    });
  }

  function initPhotoPickers() {
    $$('[data-photo-picker]').forEach(function (root) {
      var file = $('[data-photo-file]', root);
      var preview = $('[data-photo-preview]', root);
      var dataInput = $('[data-photo-data]', root);
      var thumbInput = $('[data-photo-thumb]', root);
      var label = $('[data-photo-label]', root);
      var errBox = $('[data-photo-error]', root);
      var form = root.closest('form');
      if (!file || !preview || !dataInput || !thumbInput) { return; }

      function setError(msg) {
        if (!errBox) { return; }
        errBox.textContent = msg || '';
        errBox.hidden = !msg;
      }
      function showPreview(src) {
        var old = $('img', preview);
        if (old) { old.remove(); }
        preview.appendChild(el('img', { src: src, alt: 'Aperçu de la photo de profil' }));
        var ph = $('.icon', preview);
        if (ph) { ph.style.display = 'none'; }
        if (label) { label.textContent = 'Changer la photo'; }
      }

      if (dataInput.value) { showPreview(dataInput.value); }

      file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) { return; }
        if (f.type && !/^image\/(jpeg|png|webp|gif|heic|heif)$/i.test(f.type)) {
          setError('Format non pris en charge. Choisissez une image JPG, PNG ou WebP.');
          file.value = '';
          return;
        }
        if (f.size > 20 * 1024 * 1024) {
          setError('Cette image est trop volumineuse (20 Mo maximum).');
          file.value = '';
          return;
        }
        readImage(f).then(function (img) {
          file.value = ''; // seule la version recadrée (légère) sera envoyée
          setError('');
          openCropper(img, function (res) {
            dataInput.value = res.main;
            thumbInput.value = res.thumb;
            showPreview(res.main);
            if (form) { form.dispatchEvent(new CustomEvent('photo:change')); }
          });
        }).catch(function () {
          file.value = '';
          setError('Impossible de lire cette image. Essayez une autre photo (JPG, PNG ou WebP).');
        });
      });

      if (form && root.hasAttribute('data-required')) {
        form.addEventListener('submit', function (e) {
          if (!dataInput.value) {
            e.preventDefault();
            e.stopImmediatePropagation();
            setError('Veuillez ajouter votre photo de profil.');
            root.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }, true);
      }
    });
  }

  /* ── Divers ───────────────────────────────────────────────────────── */
  function initOnlinePing() {
    if (!doc.body.hasAttribute('data-ping')) { return; }
    setInterval(function () {
      if (doc.hidden) { return; }
      fetch('update_online_status.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken() }
      }).catch(function () { /* silencieux */ });
    }, 120000);
  }

  // Une photo qui ne se charge pas laisse apparaître les initiales.
  doc.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && t.closest && t.closest('.avatar')) { t.remove(); }
  }, true);

  function ready() {
    initNav();
    initTabs();
    initMemberFilter();
    initMemberModal();
    initConfirmForms();
    initLoadingForms();
    initPasswordFields();
    initCounters();
    initProfessionFields();
    initPhotoPickers();
    initFormProgress();
    initOnlinePing();
  }

  window.App = { openModal: openModal, closeModal: closeModal, csrf: csrfToken, icon: icon, el: el };

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', ready); }
  else { ready(); }
})();
