/* Messagerie : création de l'envoi puis envoi par lots avec barre de progression. */
(function () {
  'use strict';

  var doc = document;
  var form = doc.getElementById('messageForm');
  if (!form) { return; }

  var modal = doc.getElementById('sendModal');
  var bar = doc.getElementById('sendBar');
  var fill = bar.querySelector('span');
  var title = doc.getElementById('sendTitle');
  var status = doc.getElementById('sendStatus');
  var errBox = doc.getElementById('sendError');
  var actions = doc.getElementById('sendActions');
  var btnCancel = doc.getElementById('sendCancel');
  var btnRetry = doc.getElementById('sendRetry');
  var btnClose = doc.getElementById('sendClose');
  var formError = doc.getElementById('formError');
  var audience = doc.getElementById('audience');

  var current = 0;      // identifiant de l'envoi en cours
  var running = false;

  function post(data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('admin_message.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': window.App.csrf(), 'Accept': 'application/json' },
      body: fd
    }).then(function (r) {
      return r.json().catch(function () { throw new Error('Réponse invalide du serveur.'); });
    });
  }

  function guard(e) { e.preventDefault(); e.returnValue = ''; }

  function setProgress(s) {
    var pct = s.total ? Math.min(100, Math.round(((s.envoyes + s.echecs) / s.total) * 100)) : 0;
    fill.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', String(pct));
    status.textContent = (s.envoyes + s.echecs) + ' / ' + s.total + ' destinataires traités (' + pct + ' %)';
  }

  function showButtons(retry, cancel, close) {
    actions.hidden = !(retry || cancel || close);
    btnRetry.hidden = !retry;
    btnCancel.hidden = !cancel;
    btnClose.hidden = !close;
  }

  function showError(msg, canRetry) {
    running = false;
    window.removeEventListener('beforeunload', guard);
    errBox.textContent = msg;
    errBox.hidden = false;
    title.textContent = 'Envoi interrompu';
    showButtons(canRetry, true, true);
  }

  function finish(s) {
    running = false;
    window.removeEventListener('beforeunload', guard);
    setProgress(s);
    errBox.hidden = true;
    if (s.statut === 'annule') {
      title.textContent = 'Envoi annulé';
    } else if (s.echecs > 0) {
      title.textContent = 'Envoi terminé avec des erreurs';
      status.textContent = s.envoyes + ' message(s) envoyé(s), ' + s.echecs + ' échec(s) sur ' + s.total + '.';
      if (s.erreur) { errBox.textContent = s.erreur; errBox.hidden = false; }
    } else {
      title.textContent = 'Message envoyé ✔';
      status.textContent = 'Le message a été envoyé à ' + s.envoyes + ' destinataire(s).';
    }
    showButtons(false, false, true);
    btnClose.onclick = function () { window.location.href = 'admin_message.php'; };
  }

  function loop() {
    if (!running) { return; }
    post({ action: 'batch', id: current }).then(function (s) {
      if (!s.ok) { showError(s.error || 'Une erreur est survenue.', true); return; }
      setProgress(s);
      if (s.done) { finish(s); } else { loop(); }
    }).catch(function (err) {
      showError((err && err.message) || 'Connexion interrompue.', true);
    });
  }

  function start(id) {
    current = id;
    running = true;
    title.textContent = 'Envoi en cours…';
    status.textContent = 'Préparation…';
    fill.style.width = '0%';
    errBox.hidden = true;
    showButtons(false, false, false);
    window.addEventListener('beforeunload', guard);
    window.App.openModal(modal);
    loop();
  }

  btnRetry.addEventListener('click', function () {
    errBox.hidden = true;
    title.textContent = 'Envoi en cours…';
    showButtons(false, false, false);
    running = true;
    window.addEventListener('beforeunload', guard);
    loop();
  });
  btnCancel.addEventListener('click', function () {
    post({ action: 'cancel', id: current }).then(function (s) {
      if (s.ok) { finish(s); } else { showError(s.error || 'Annulation impossible.', true); }
    }).catch(function () { showError('Connexion interrompue.', true); });
  });
  btnClose.addEventListener('click', function () { window.location.href = 'admin_message.php'; });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    formError.hidden = true;

    var sujet = form.elements.sujet.value.trim();
    var message = form.elements.message.value.trim();
    if (sujet.length < 3) { formError.textContent = "Veuillez saisir l'objet du message."; formError.hidden = false; form.elements.sujet.focus(); return; }
    if (message.length < 10) { formError.textContent = 'Votre message est trop court.'; formError.hidden = false; form.elements.message.focus(); return; }

    var opt = audience.options[audience.selectedIndex];
    var n = parseInt(opt.getAttribute('data-count') || '0', 10);
    if (n < 1) { formError.textContent = 'Aucun destinataire pour ce groupe.'; formError.hidden = false; return; }

    window.appConfirm({
      title: 'Envoyer ce message ?',
      message: 'Il sera envoyé à ' + n + ' membre' + (n > 1 ? 's' : '') + ' (' + opt.textContent.replace(/\s*\(\d+\)\s*$/, '').toLowerCase() + '). Cette action est irréversible.',
      okLabel: 'Envoyer'
    }, function () {
      post({ action: 'create', sujet: sujet, message: message, audience: audience.value })
        .then(function (s) {
          if (!s.ok) { formError.textContent = s.error || 'Une erreur est survenue.'; formError.hidden = false; return; }
          start(s.id);
        })
        .catch(function (err) { formError.textContent = (err && err.message) || 'Connexion interrompue.'; formError.hidden = false; });
    });
  });

  Array.prototype.forEach.call(doc.querySelectorAll('[data-resume]'), function (b) {
    b.addEventListener('click', function () { start(parseInt(b.getAttribute('data-resume'), 10)); });
  });

  var run = parseInt((doc.querySelector('[data-run]') || {}).getAttribute ? doc.querySelector('[data-run]').getAttribute('data-run') : '0', 10);
  if (run > 0) { start(run); }
})();
