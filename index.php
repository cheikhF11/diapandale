<?php
require_once __DIR__ . '/config/app.php';

// Déjà connecté : direction l'accueil.
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['number'])) {
    redirect('accueil.php');
}

$error = '';
$info = '';
$email = '';
$showRecovery = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');

    if (!csrfValid()) {
        $error = 'Votre session a expiré. Veuillez réessayer.';
    } else {
        try {
            $db = getDatabaseConnection();

            /* ── Demande de réinitialisation du mot de passe ─────────── */
            if ($action === 'recover_password') {
                $showRecovery = true;
                $recoveryEmail = strtolower(securisation($_POST['recovery_email'] ?? ''));
                $genericInfo = "Si un compte correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé.";

                if (!filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Veuillez saisir une adresse e-mail valide.';
                } elseif (attemptsBlocked($db, 'reset:' . $recoveryEmail)) {
                    $info = $genericInfo; // même réponse : on ne révèle rien
                } else {
                    recordAttempt($db, 'reset:' . $recoveryEmail);

                    $q = $db->prepare("SELECT id, prenom, email FROM membre WHERE email = ? AND confirm = 1 LIMIT 1");
                    $q->execute([$recoveryEmail]);
                    $member = $q->fetch();

                    if ($member) {
                        $token = bin2hex(random_bytes(32));
                        $db->prepare(
                            "UPDATE membre SET reset_token_hash = ?, reset_expires_at = ? WHERE id = ?"
                        )->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600), $member['id']]);

                        $url = appBaseUrl() . '/reset_password.php?token=' . $token;
                        if (!envoyerLienReinitialisation($member['email'], (string) $member['prenom'], $url)) {
                            $info = '';
                            $error = "L'e-mail n'a pas pu être envoyé pour le moment. Veuillez réessayer dans quelques instants.";
                        }
                    }
                    if ($error === '') {
                        $info = $genericInfo;
                    }
                }

            /* ── Connexion ───────────────────────────────────────────── */
            } else {
                $email = strtolower(securisation($_POST['email'] ?? ''));
                $password = (string) ($_POST['passeword'] ?? '');

                if ($email === '' || $password === '') {
                    $error = 'Veuillez renseigner votre e-mail et votre mot de passe.';
                } elseif (attemptsBlocked($db, $email)) {
                    $error = 'Trop de tentatives échouées. Réessayez dans quelques minutes ou réinitialisez votre mot de passe.';
                } else {
                    $q = $db->prepare("SELECT id, num, email, passeword, confirm FROM membre WHERE email = ? LIMIT 1");
                    $q->execute([$email]);
                    $user = $q->fetch();

                    if (!$user || !password_verify($password, (string) $user['passeword'])) {
                        recordAttempt($db, $email);
                        $error = 'E-mail ou mot de passe incorrect.';
                    } elseif ((int) $user['confirm'] !== 1) {
                        // Mot de passe correct mais compte non confirmé : on propose de saisir le code.
                        $_SESSION['pending_verification'] = ['num' => $user['num'], 'email' => $user['email']];
                        flash('info', "Votre compte n'est pas encore confirmé. Saisissez le code reçu par e-mail ou demandez-en un nouveau.");
                        redirect('verify_otp.php');
                    } else {
                        clearAttempts($db, $email);
                        session_regenerate_id(true); // protège contre la fixation de session
                        $_SESSION['logged_in'] = true;
                        $_SESSION['number'] = $user['num'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['user_id'] = (int) $user['id'];

                        $db->prepare("UPDATE membre SET statut_en_ligne = 1, derniere_connexion = NOW() WHERE id = ?")
                           ->execute([$user['id']]);

                        redirect('accueil.php');
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Erreur index.php : ' . $e->getMessage());
            $error = 'Une erreur est survenue. Veuillez réessayer.';
        }
    }
}

renderAuthHead('Connexion', 'Connexion');
?>
<?php renderFlashes(); ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
<?php endif; ?>
<?php if ($info !== ''): ?>
    <div class="alert alert-success" role="status"><?= icon('check-circle') ?><span><?= e($info) ?></span></div>
<?php endif; ?>

<form method="post" action="index.php" id="loginForm" data-loading="Connexion…">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="login">

    <div class="field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="exemple@email.com"
               autocomplete="email" inputmode="email" autocapitalize="none" required>
    </div>

    <div class="field">
        <label for="passeword">Mot de passe</label>
        <div class="input-group">
            <input type="password" id="passeword" name="passeword" placeholder="Votre mot de passe"
                   autocomplete="current-password" required>
            <button type="button" class="toggle-pass" data-toggle-pass="passeword" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
</form>

<p class="auth-links">
    <button type="button" class="btn btn-ghost btn-sm" data-modal-open="recoveryModal">Mot de passe oublié ?</button>
</p>
<p class="auth-links mt-0">Pas encore de compte ? <a href="form.php">Créer un compte</a></p>

<div class="modal<?= $showRecovery ? ' is-open' : '' ?>" id="recoveryModal" role="dialog" aria-modal="true" aria-labelledby="recoveryTitle" aria-hidden="<?= $showRecovery ? 'false' : 'true' ?>">
    <div class="modal__backdrop" data-modal-close></div>
    <div class="modal__dialog modal__dialog--sm">
        <div class="modal__body">
            <h2 class="modal__title" id="recoveryTitle">Mot de passe oublié</h2>
            <p class="text-muted">Saisissez l'adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.</p>
            <form method="post" action="index.php" data-loading="Envoi…">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="recover_password">
                <div class="field">
                    <label for="recovery_email">Adresse e-mail</label>
                    <input type="email" id="recovery_email" name="recovery_email" placeholder="exemple@email.com"
                           autocomplete="email" autocapitalize="none" required data-autofocus>
                </div>
                <div class="modal__actions">
                    <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                    <button type="submit" class="btn btn-primary">Envoyer le lien</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
renderAuthFoot();
