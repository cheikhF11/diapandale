<?php
require_once __DIR__ . '/config/app.php';

if (empty($_SESSION['pending_verification']['num']) || empty($_SESSION['pending_verification']['email'])) {
    redirect('index.php');
}

$pending = $_SESSION['pending_verification'];
$error = '';
$info = '';
$retryIn = 0;

try {
    $db = getDatabaseConnection();

    $load = function () use ($db, $pending) {
        $q = $db->prepare(
            "SELECT id, prenom, otp_code, otp_expires_at, otp_attempts, confirm,
                    GREATEST(0, 60 - TIMESTAMPDIFF(SECOND, COALESCE(otp_sent_at, '2000-01-01'), NOW())) AS retry_in
             FROM membre WHERE num = ? AND email = ? LIMIT 1"
        );
        $q->execute([$pending['num'], $pending['email']]);
        return $q->fetch();
    };

    $member = $load();

    if (!$member) {
        unset($_SESSION['pending_verification']);
        flash('error', "Aucune inscription en attente n'a été trouvée. Veuillez vous inscrire à nouveau.");
        redirect('form.php');
    }
    if ((int) $member['confirm'] === 1) {
        unset($_SESSION['pending_verification']);
        flash('success', 'Votre compte est déjà confirmé. Vous pouvez vous connecter.');
        redirect('index.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? 'verify');

        if (!csrfValid()) {
            $error = 'Votre session a expiré. Veuillez réessayer.';

        } elseif ($action === 'resend') {
            if ((int) $member['retry_in'] > 0) {
                $error = 'Veuillez patienter ' . (int) $member['retry_in'] . ' seconde(s) avant de demander un nouveau code.';
            } else {
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $db->prepare(
                    "UPDATE membre SET otp_code = ?, otp_expires_at = ?, otp_attempts = 0, otp_sent_at = NOW() WHERE id = ?"
                )->execute([$otp, date('Y-m-d H:i:s', time() + 600), $member['id']]);

                if (envoyerCodeOtp($pending['email'], (string) $member['prenom'], $otp)) {
                    $info = 'Un nouveau code vient de vous être envoyé par e-mail.';
                } else {
                    $error = "L'e-mail n'a pas pu être envoyé pour le moment. Veuillez réessayer dans quelques instants.";
                }
            }

        } else {
            $otp = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));

            if (strlen((string) $otp) !== 6) {
                $error = 'Saisissez le code à 6 chiffres reçu par e-mail.';
            } elseif ((int) $member['otp_attempts'] >= 5) {
                $error = 'Trop de tentatives incorrectes. Demandez un nouveau code.';
            } elseif (empty($member['otp_code']) || strtotime((string) $member['otp_expires_at']) < time()) {
                $error = 'Ce code a expiré. Demandez-en un nouveau.';
            } elseif (!hash_equals((string) $member['otp_code'], (string) $otp)) {
                $db->prepare("UPDATE membre SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$member['id']]);
                $left = max(0, 4 - (int) $member['otp_attempts']);
                $error = 'Code incorrect. ' . ($left > 0 ? "Il vous reste {$left} essai(s)." : 'Demandez un nouveau code.');
            } else {
                $db->prepare(
                    "UPDATE membre SET confirm = 1, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?"
                )->execute([$member['id']]);
                unset($_SESSION['pending_verification']);
                flash('success', 'Votre compte est confirmé. Vous pouvez maintenant vous connecter.');
                redirect('index.php');
            }
        }

        $member = $load() ?: $member;
    }

    $retryIn = (int) $member['retry_in'];
} catch (Throwable $e) {
    error_log('Erreur OTP : ' . $e->getMessage());
    $error = 'Une erreur est survenue. Veuillez réessayer.';
}

renderAuthHead('Vérification', 'Vérification', 'Confirmation du compte');
?>
<?php renderFlashes(); ?>
<p class="text-center text-muted">Saisissez le code à 6 chiffres envoyé à l'adresse :</p>
<span class="email-pill"><?= e($pending['email']) ?></span>

<?php if ($error !== ''): ?>
    <div class="alert alert-error mt-2" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($info !== ''): ?>
    <div class="alert alert-success mt-2" role="status"><?= icon('check-circle') ?><span><?= e($info) ?></span></div>
<?php endif; ?>

<form method="post" action="verify_otp.php" id="otpForm" data-loading="Vérification…">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="otp" id="otp" value="">
    <div class="otp-grid" role="group" aria-label="Code de vérification à 6 chiffres">
        <?php for ($i = 1; $i <= 6; $i++): ?>
            <input class="otp-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1"
                   autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" aria-label="Chiffre <?= $i ?>" <?= $i === 1 ? 'data-autofocus' : '' ?>>
        <?php endfor; ?>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Confirmer mon compte</button>
</form>

<form method="post" action="verify_otp.php" class="mt-2 text-center" data-loading="Envoi…">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="resend">
    <p class="text-muted mb-1" style="font-size:.9rem">Le code est valable 10 minutes. Vous ne l'avez pas reçu ? Pensez à vérifier vos courriers indésirables.</p>
    <button type="submit" class="btn btn-outline btn-sm" id="resendBtn" <?= $retryIn > 0 ? 'disabled' : '' ?>
            data-retry="<?= $retryIn ?>">Renvoyer le code</button>
</form>
<p class="auth-links"><a href="index.php">Retour à la connexion</a></p>
<?php
renderAuthFoot(['inline' => <<<'JS'
(function () {
  var form = document.getElementById('otpForm');
  var hidden = document.getElementById('otp');
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-input'));
  function combine() {
    var code = boxes.map(function (b) { return b.value.replace(/\D/g, ''); }).join('');
    hidden.value = code;
    return code;
  }
  boxes[0].focus();
  boxes.forEach(function (box, i) {
    box.addEventListener('input', function () {
      var digits = box.value.replace(/\D/g, '');
      if (digits.length > 1) {
        digits.split('').slice(0, 6 - i).forEach(function (d, k) { boxes[i + k].value = d; });
        boxes[Math.min(5, i + digits.length - 1)].focus();
      } else {
        box.value = digits;
        if (digits && boxes[i + 1]) { boxes[i + 1].focus(); }
      }
      if (combine().length === 6) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !box.value && boxes[i - 1]) { boxes[i - 1].focus(); }
      if (e.key === 'ArrowLeft' && boxes[i - 1]) { boxes[i - 1].focus(); }
      if (e.key === 'ArrowRight' && boxes[i + 1]) { boxes[i + 1].focus(); }
    });
    box.addEventListener('focus', function () { box.select(); });
    box.addEventListener('paste', function (e) {
      var text = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '');
      if (!text) { return; }
      e.preventDefault();
      text.split('').slice(0, 6).forEach(function (d, k) { boxes[k].value = d; });
      boxes[Math.min(5, text.length - 1)].focus();
      if (combine().length === 6) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
    });
  });
  form.addEventListener('submit', function (e) {
    if (combine().length !== 6) { e.preventDefault(); (boxes.filter(function (b) { return !b.value; })[0] || boxes[0]).focus(); }
  });
  var btn = document.getElementById('resendBtn');
  var left = parseInt(btn.getAttribute('data-retry'), 10) || 0;
  if (left > 0) {
    var label = btn.textContent;
    var t = setInterval(function () {
      left--;
      if (left <= 0) { clearInterval(t); btn.disabled = false; btn.textContent = label; }
      else { btn.textContent = label + ' (' + left + ' s)'; }
    }, 1000);
    btn.textContent = label + ' (' + left + ' s)';
  }
})();
JS
]);
