<?php
require_once __DIR__ . '/config/app.php';

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['number'])) {
    redirect('accueil.php');
}

$error = '';
$v = [ // valeurs du formulaire (conservées en cas d'erreur)
    'prenom' => '', 'nom' => '', 'num' => '', 'email' => '', 'adresse' => '', 'date_naissance' => '',
    'genre' => '', 'profession' => '', 'profession_details' => '', 'niveau_etude' => '', 'filiere' => '', 'classe' => '',
    'photo_data' => '', 'photo_thumb' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $key => $_) {
        if ($key === 'photo_data' || $key === 'photo_thumb') {
            $v[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        } else {
            $v[$key] = securisation($_POST[$key] ?? '');
        }
    }
    $v['email'] = strtolower($v['email']);
    $v['num'] = normalizePhone($v['num']);
    $password = (string) ($_POST['passeword'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    $photoError = null;
    $photo = photoFromRequest($_POST, $_FILES, $photoError);
    $age = calculateAge($v['date_naissance']);
    $digits = strlen(preg_replace('/\D/', '', $v['num']));

    if (!csrfValid()) {
        $error = 'Votre session a expiré. Veuillez réessayer.';
    } elseif ($v['prenom'] === '' || $v['nom'] === '' || $v['num'] === '' || $v['email'] === '' || $v['adresse'] === ''
        || $v['date_naissance'] === '' || $v['genre'] === '' || $v['profession'] === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!in_array($v['genre'], ['Homme', 'Femme'], true)) {
        $error = 'Veuillez indiquer votre genre.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez saisir une adresse e-mail valide.';
    } elseif ($digits < 8 || $digits > 15) {
        $error = 'Le numéro de téléphone est invalide.';
    } elseif ($age === null || $age > 110 || $age < 3) {
        $error = 'Veuillez indiquer une date de naissance valide.';
    } elseif (strlen($v['adresse']) < 5 || strlen($v['adresse']) > 255) {
        $error = 'Veuillez saisir une adresse complète (5 à 255 caractères).';
    } elseif (!in_array($v['profession'], ['travailleur', 'etudiant', 'eleve'], true)) {
        $error = 'Veuillez choisir votre situation.';
    } elseif ($v['profession'] === 'travailleur' && $v['profession_details'] === '') {
        $error = 'Veuillez indiquer votre profession.';
    } elseif ($v['profession'] === 'etudiant' && (!in_array($v['niveau_etude'], NIVEAUX_ETUDE, true) || $v['filiere'] === '')) {
        $error = "Veuillez indiquer votre niveau d'études et votre filière.";
    } elseif ($v['profession'] === 'eleve' && !classeValide($v['classe'])) {
        $error = 'Veuillez sélectionner votre classe.';
    } elseif ($photoError !== null) {
        $error = $photoError;
    } elseif ($photo === null) {
        $error = 'Veuillez ajouter votre photo de profil.';
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        try {
            $db = getDatabaseConnection();

            $q = $db->prepare("SELECT id, confirm FROM membre WHERE num = ? OR email = ?");
            $q->execute([$v['num'], $v['email']]);
            $conflicts = $q->fetchAll();

            $confirmed = array_filter($conflicts, fn($r) => (int) $r['confirm'] === 1);

            if ($confirmed) {
                $error = 'Ce numéro ou cette adresse e-mail est déjà utilisé. Connectez-vous ou réinitialisez votre mot de passe.';
            } else {
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                $db->beginTransaction();
                try {
                    // Une inscription jamais confirmée ne doit pas bloquer une nouvelle tentative.
                    foreach ($conflicts as $old) {
                        $db->prepare("DELETE FROM membre_photo WHERE membre_id = ?")->execute([$old['id']]);
                        $db->prepare("DELETE FROM membre WHERE id = ? AND confirm = 0")->execute([$old['id']]);
                    }

                    $prof = $v['profession'];
                    $db->prepare(
                        "INSERT INTO membre (num, prenom, nom, email, adresse, date_naissance, genre, profession,
                                             profession_details, niveau_etude, filiere, classe, passeword, confirm,
                                             otp_code, otp_expires_at, otp_attempts, otp_sent_at, date_inscription)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0, NOW(), NOW())"
                    )->execute([
                        $v['num'], $v['prenom'], $v['nom'], $v['email'], $v['adresse'], $v['date_naissance'],
                        $v['genre'], $prof,
                        $prof === 'travailleur' ? $v['profession_details'] : null,
                        $prof === 'etudiant' ? $v['niveau_etude'] : null,
                        $prof === 'etudiant' ? $v['filiere'] : null,
                        $prof === 'eleve' ? $v['classe'] : null,
                        password_hash($password, PASSWORD_DEFAULT),
                        $otp,
                        date('Y-m-d H:i:s', time() + 600),
                    ]);

                    photoSave($db, (int) $db->lastInsertId(), $photo);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                $_SESSION['pending_verification'] = ['num' => $v['num'], 'email' => $v['email']];

                if (!envoyerCodeOtp($v['email'], $v['prenom'], $otp)) {
                    flash('warning', "Votre compte est créé, mais l'e-mail contenant le code n'a pas pu être envoyé. Utilisez « Renvoyer le code » ci-dessous.");
                }
                redirect('verify_otp.php');
            }
        } catch (Throwable $e) {
            error_log('Erreur inscription : ' . $e->getMessage());
            $error = "Une erreur est survenue lors de l'inscription. Veuillez réessayer.";
        }
    }
}

renderAuthHead('Inscription', 'Inscription', '', true);
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
<?php endif; ?>

<form method="post" action="form.php" id="registerForm" enctype="multipart/form-data" data-loading="Inscription en cours…">
    <?= csrfField() ?>
    <div class="progress-steps" aria-hidden="true"><span data-progress-bar></span></div>

    <div class="section-label">Photo de profil</div>
    <div class="field">
        <div class="photo-picker" data-photo-picker data-required>
            <div class="photo-picker__preview" data-photo-preview><?= icon('camera') ?></div>
            <div class="photo-picker__body">
                <div class="photo-picker__actions">
                    <label class="btn btn-outline btn-sm" for="photo">
                        <?= icon('image') ?> <span data-photo-label>Choisir une photo</span>
                    </label>
                    <input class="photo-picker__file" data-photo-file type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                </div>
                <span class="hint">Une photo nette de votre visage (JPG, PNG ou WebP). Vous pourrez la recadrer.</span>
            </div>
            <input type="hidden" name="photo_data" data-photo-data value="<?= e($v['photo_data']) ?>">
            <input type="hidden" name="photo_thumb" data-photo-thumb value="<?= e($v['photo_thumb']) ?>">
        </div>
        <div class="alert alert-error mt-1 mb-0" data-photo-error hidden role="alert"></div>
    </div>

    <div class="section-label">Identité</div>
    <div class="form-grid form-grid--2">
        <div class="field">
            <label for="prenom">Prénom <span class="req">*</span></label>
            <input type="text" id="prenom" name="prenom" value="<?= e($v['prenom']) ?>" autocomplete="given-name" required>
        </div>
        <div class="field">
            <label for="nom">Nom <span class="req">*</span></label>
            <input type="text" id="nom" name="nom" value="<?= e($v['nom']) ?>" autocomplete="family-name" required>
        </div>
    </div>

    <div class="form-grid form-grid--2">
        <div class="field">
            <label for="date_naissance">Date de naissance <span class="req">*</span></label>
            <input type="date" id="date_naissance" name="date_naissance" value="<?= e($v['date_naissance']) ?>"
                   max="<?= e(date('Y-m-d')) ?>" min="1910-01-01" autocomplete="bday" required>
            <span class="hint"><?= icon('lock') ?> Visible uniquement par l'administration.</span>
        </div>
        <div class="field">
            <span class="label" id="genre-label">Genre <span class="req">*</span></span>
            <div class="segmented" role="radiogroup" aria-labelledby="genre-label">
                <input type="radio" id="genre-h" name="genre" value="Homme" <?= $v['genre'] === 'Homme' ? 'checked' : '' ?> required>
                <label for="genre-h">Homme</label>
                <input type="radio" id="genre-f" name="genre" value="Femme" <?= $v['genre'] === 'Femme' ? 'checked' : '' ?>>
                <label for="genre-f">Femme</label>
            </div>
        </div>
    </div>

    <div class="section-label">Coordonnées</div>
    <div class="form-grid form-grid--2">
        <div class="field">
            <label for="num">Téléphone / WhatsApp <span class="req">*</span></label>
            <input type="tel" id="num" name="num" value="<?= e($v['num']) ?>" placeholder="+221 77 123 45 67"
                   autocomplete="tel" inputmode="tel" required>
        </div>
        <div class="field">
            <label for="email">Adresse e-mail <span class="req">*</span></label>
            <input type="email" id="email" name="email" value="<?= e($v['email']) ?>" placeholder="exemple@email.com"
                   autocomplete="email" inputmode="email" autocapitalize="none" required>
        </div>
    </div>
    <div class="field">
        <label for="adresse">Adresse <span class="req">*</span></label>
        <input type="text" id="adresse" name="adresse" value="<?= e($v['adresse']) ?>" maxlength="255"
               placeholder="Quartier, rue, ville" autocomplete="street-address" required>
    </div>

    <div class="section-label">Situation</div>
    <div class="field">
        <label for="profession">Vous êtes <span class="req">*</span></label>
        <select id="profession" name="profession" data-profession-select required>
            <option value="">Veuillez sélectionner</option>
            <option value="travailleur" <?= $v['profession'] === 'travailleur' ? 'selected' : '' ?>>Travailleur</option>
            <option value="etudiant" <?= $v['profession'] === 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
            <option value="eleve" <?= $v['profession'] === 'eleve' ? 'selected' : '' ?>>Élève</option>
        </select>
    </div>

    <div class="field" data-prof-field="travailleur" hidden>
        <label for="profession_details">Votre profession <span class="req">*</span></label>
        <input type="text" id="profession_details" name="profession_details" value="<?= e($v['profession_details']) ?>"
               placeholder="Ex : Enseignant, Commerçant, Infirmier…">
    </div>

    <div data-prof-field="etudiant" hidden>
        <div class="form-grid form-grid--2">
            <div class="field">
                <label for="niveau_etude">Niveau d'études <span class="req">*</span></label>
                <select id="niveau_etude" name="niveau_etude">
                    <option value="">Sélectionner</option>
                    <?php foreach (NIVEAUX_ETUDE as $n): ?>
                        <option value="<?= e($n) ?>" <?= $v['niveau_etude'] === $n ? 'selected' : '' ?>><?= e($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="filiere">Filière <span class="req">*</span></label>
                <input type="text" id="filiere" name="filiere" value="<?= e($v['filiere']) ?>" placeholder="Ex : Informatique, Médecine…">
            </div>
        </div>
    </div>

    <div class="field" data-prof-field="eleve" hidden>
        <label for="classe">Classe <span class="req">*</span></label>
        <select id="classe" name="classe">
            <option value="">Sélectionner votre classe</option>
            <?php foreach (CLASSES as $groupe => $liste): ?>
                <optgroup label="<?= e($groupe) ?>">
                    <?php foreach ($liste as $c): ?>
                        <option value="<?= e($c) ?>" <?= $v['classe'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="section-label">Sécurité</div>
    <div class="form-grid form-grid--2">
        <div class="field">
            <label for="passeword">Mot de passe <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" id="passeword" name="passeword" minlength="8" autocomplete="new-password" required>
                <button type="button" class="toggle-pass" data-toggle-pass="passeword" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
            </div>
            <div class="strength" data-strength-for="passeword" aria-hidden="true"><span></span></div>
            <span class="hint">8 caractères minimum.</span>
        </div>
        <div class="field">
            <label for="confirm">Confirmation <span class="req">*</span></label>
            <div class="input-group">
                <input type="password" id="confirm" name="confirm" minlength="8" autocomplete="new-password" required>
                <button type="button" class="toggle-pass" data-toggle-pass="confirm" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-block mt-2">Créer mon compte</button>
</form>

<p class="auth-links">Déjà inscrit ? <a href="index.php">Se connecter</a></p>
<?php
renderAuthFoot();
