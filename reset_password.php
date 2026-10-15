<?php
require_once __DIR__ . '/config/app.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$error = '';
$member = null;

try {
    $db = getDatabaseConnection();

    if ($token !== '') {
        $q = $db->prepare(
            "SELECT id, prenom FROM membre
             WHERE reset_token_hash = ? AND reset_expires_at IS NOT NULL AND reset_expires_at > ? AND confirm = 1 LIMIT 1"
        );
        $q->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        $member = $q->fetch() ?: null;
    }

    if ($member && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = (string) ($_POST['passeword'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!csrfValid()) {
            $error = 'Votre session a expiré. Rechargez la page et réessayez.';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $db->prepare(
                "UPDATE membre SET passeword = ?, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ?"
            )->execute([password_hash($password, PASSWORD_DEFAULT), $member['id']]);

            flash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
            redirect('index.php');
        }
    }
} catch (Throwable $e) {
    error_log('Erreur reset_password : ' . $e->getMessage());
    $error = 'Une erreur est survenue. Veuillez réessayer.';
}

renderAuthHead('Nouveau mot de passe', 'Nouveau mot de passe', 'Réinitialisation');

if (!$member):
?>
    <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span>Ce lien est invalide ou a expiré. Refaites une demande de réinitialisation.</span></div>
    <a href="index.php" class="btn btn-primary btn-block">Retour à la connexion</a>
<?php else: ?>
    <p class="text-muted">Bonjour <?= e($member['prenom']) ?>, choisissez votre nouveau mot de passe.</p>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <form method="post" action="reset_password.php" data-loading="Enregistrement…">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
            <label for="passeword">Nouveau mot de passe</label>
            <div class="input-group">
                <input type="password" id="passeword" name="passeword" minlength="8" autocomplete="new-password" required data-autofocus>
                <button type="button" class="toggle-pass" data-toggle-pass="passeword" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
            </div>
            <div class="strength" data-strength-for="passeword" aria-hidden="true"><span></span></div>
            <span class="hint">8 caractères minimum.</span>
        </div>
        <div class="field">
            <label for="confirm">Confirmation</label>
            <div class="input-group">
                <input type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                <button type="button" class="toggle-pass" data-toggle-pass="confirm" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Enregistrer le mot de passe</button>
    </form>
<?php
endif;
renderAuthFoot();
