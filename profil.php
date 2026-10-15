<?php
require_once __DIR__ . '/config/app.php';

$db = getDatabaseConnection();
$user = requireLogin($db);
$error = '';
$errorPwd = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrfValid()) {
        $error = 'Votre session a expiré. Veuillez réessayer.';
    } elseif ($action === 'profile') {
        $adresse = securisation($_POST['adresse'] ?? '');
        $photoError = null;
        $photo = photoFromRequest($_POST, $_FILES, $photoError);

        if ($photoError !== null) {
            $error = $photoError;
        } elseif (strlen($adresse) < 5 || strlen($adresse) > 255) {
            $error = 'Veuillez saisir une adresse complète (5 à 255 caractères).';
        } else {
            try {
                $db->prepare("UPDATE membre SET adresse = ? WHERE id = ?")->execute([$adresse, $user['id']]);
                if ($photo !== null) {
                    photoSave($db, (int) $user['id'], $photo);
                }
                flash('success', 'Votre profil a été mis à jour.');
                redirect('profil.php');
            } catch (Throwable $e) {
                error_log('Erreur profil : ' . $e->getMessage());
                $error = 'Une erreur est survenue. Veuillez réessayer.';
            }
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['passeword'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        try {
            $q = $db->prepare("SELECT passeword FROM membre WHERE id = ?");
            $q->execute([$user['id']]);
            $hash = (string) $q->fetchColumn();

            if (!password_verify($current, $hash)) {
                $errorPwd = 'Votre mot de passe actuel est incorrect.';
            } elseif (strlen($new) < 8) {
                $errorPwd = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
            } elseif ($new !== $confirm) {
                $errorPwd = 'Les mots de passe ne correspondent pas.';
            } else {
                $db->prepare("UPDATE membre SET passeword = ? WHERE id = ?")
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                flash('success', 'Votre mot de passe a été modifié.');
                redirect('profil.php');
            }
        } catch (Throwable $e) {
            error_log('Erreur mot de passe : ' . $e->getMessage());
            $errorPwd = 'Une erreur est survenue. Veuillez réessayer.';
        }
    }
}

$adresseValue = isset($_POST['adresse']) ? securisation($_POST['adresse']) : (string) ($user['adresse'] ?? '');

renderHead('Mon profil', ['ping' => true]);
renderNav($user, 'profil');
?>
<main class="container container--narrow" id="contenu">
    <div class="page-head">
        <h1>Mon profil</h1>
        <p>Gérez votre photo, votre adresse et votre mot de passe.</p>
    </div>

    <?php renderFlashes(); ?>

    <div class="card">
        <div class="flex items-center gap-2 wrap">
            <?= avatarHtml($user, 'lg', true) ?>
            <div style="min-width:0">
                <h2 class="mb-0"><?= e(memberFullName($user)) ?></h2>
                <p class="text-muted mb-0"><?= e(memberSubtitle($user)) ?></p>
            </div>
        </div>
        <dl class="info-grid mt-2 mb-0">
            <div class="info-item"><dt>E-mail</dt><dd><?= e($user['email']) ?></dd></div>
            <div class="info-item"><dt>Téléphone</dt><dd><?= e($user['num']) ?></dd></div>
        </dl>
        <p class="hint mt-1"><?= icon('lock') ?> Votre date de naissance n'est visible que par l'administration. Pour modifier votre e-mail ou votre numéro, contactez l'administration.</p>
    </div>

    <form method="post" action="profil.php" enctype="multipart/form-data" class="card mt-2" data-loading="Enregistrement…">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="profile">
        <h2 class="card-title"><?= icon('camera') ?> Photo et adresse</h2>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
        <?php endif; ?>

        <div class="field">
            <div class="photo-picker" data-photo-picker>
                <div class="photo-picker__preview" data-photo-preview>
                    <?php if (memberPhotoUrl($user, true)): ?>
                        <img src="<?= e(memberPhotoUrl($user, true)) ?>" alt="Votre photo actuelle">
                    <?php else: ?>
                        <?= icon('camera') ?>
                    <?php endif; ?>
                </div>
                <div class="photo-picker__body">
                    <div class="photo-picker__actions">
                        <label class="btn btn-outline btn-sm" for="photo"><?= icon('image') ?> <span data-photo-label><?= !empty($user['a_photo']) ? 'Changer la photo' : 'Ajouter une photo' ?></span></label>
                        <input class="photo-picker__file" data-photo-file type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <span class="hint">JPG, PNG ou WebP. Vous pourrez recadrer l'image.</span>
                </div>
                <input type="hidden" name="photo_data" data-photo-data value="">
                <input type="hidden" name="photo_thumb" data-photo-thumb value="">
            </div>
            <div class="alert alert-error mt-1 mb-0" data-photo-error hidden role="alert"></div>
        </div>

        <div class="field">
            <label for="adresse">Adresse <span class="req">*</span></label>
            <input type="text" id="adresse" name="adresse" value="<?= e($adresseValue) ?>" maxlength="255" placeholder="Quartier, rue, ville" autocomplete="street-address" required>
        </div>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>

    <form method="post" action="profil.php" class="card mt-2" data-loading="Enregistrement…">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="password">
        <h2 class="card-title"><?= icon('lock') ?> Changer mon mot de passe</h2>

        <?php if ($errorPwd !== ''): ?>
            <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($errorPwd) ?></span></div>
        <?php endif; ?>

        <div class="field">
            <label for="current">Mot de passe actuel</label>
            <input type="password" id="current" name="current" autocomplete="current-password" required>
        </div>
        <div class="form-grid form-grid--2">
            <div class="field">
                <label for="passeword">Nouveau mot de passe</label>
                <div class="input-group">
                    <input type="password" id="passeword" name="passeword" minlength="8" autocomplete="new-password" required>
                    <button type="button" class="toggle-pass" data-toggle-pass="passeword" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
                </div>
                <div class="strength" data-strength-for="passeword" aria-hidden="true"><span></span></div>
            </div>
            <div class="field">
                <label for="confirm">Confirmation</label>
                <input type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Modifier le mot de passe</button>
    </form>
</main>
<?php
renderFooter();
