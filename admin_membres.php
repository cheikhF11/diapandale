<?php
require_once __DIR__ . '/config/app.php';

$db = getDatabaseConnection();
$user = requireLogin($db);

if (!hasRole($user, 'admin_membre')) {
    redirect('accueil.php');
}

$error = '';
$v = [
    'prenom' => '', 'nom' => '', 'num' => '', 'email' => '', 'adresse' => '', 'date_naissance' => '',
    'genre' => '', 'profession' => '', 'profession_details' => '', 'niveau_etude' => '', 'filiere' => '', 'classe' => '',
    'photo_data' => '', 'photo_thumb' => '',
];
$openTab = 'liste';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrfValid()) {
        $error = 'Votre session a expiré. Veuillez réessayer.';

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['member_id'] ?? 0);
        try {
            if ($id === (int) $user['id']) {
                flash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            } else {
                $db->prepare("DELETE FROM membre_photo WHERE membre_id = ?")->execute([$id]);
                $stmt = $db->prepare("DELETE FROM membre WHERE id = ?");
                $stmt->execute([$id]);
                flash($stmt->rowCount() > 0 ? 'success' : 'error', $stmt->rowCount() > 0 ? 'Le membre a été supprimé.' : 'Membre introuvable.');
            }
        } catch (Throwable $e) {
            error_log('Suppression membre : ' . $e->getMessage());
            flash('error', 'Erreur lors de la suppression.');
        }
        redirect('admin_membres.php');

    } elseif ($action === 'add') {
        $openTab = 'ajouter';
        foreach ($v as $key => $_) {
            $v[$key] = ($key === 'photo_data' || $key === 'photo_thumb')
                ? (is_string($_POST[$key] ?? null) ? $_POST[$key] : '')
                : securisation($_POST[$key] ?? '');
        }
        $v['email'] = strtolower($v['email']);
        $v['num'] = normalizePhone($v['num']);
        $password = (string) ($_POST['passeword'] ?? '');

        $photoError = null;
        $photo = photoFromRequest($_POST, $_FILES, $photoError);
        $age = calculateAge($v['date_naissance']);
        $digits = strlen(preg_replace('/\D/', '', $v['num']));

        if ($v['prenom'] === '' || $v['nom'] === '' || $v['num'] === '' || $v['email'] === '' || $v['adresse'] === ''
            || $v['date_naissance'] === '' || $v['genre'] === '' || $v['profession'] === '' || $password === '') {
            $error = 'Veuillez remplir tous les champs obligatoires.';
        } elseif (!in_array($v['genre'], ['Homme', 'Femme'], true)) {
            $error = 'Genre invalide.';
        } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse e-mail invalide.';
        } elseif ($digits < 8 || $digits > 15) {
            $error = 'Numéro de téléphone invalide.';
        } elseif ($age === null || $age > 110 || $age < 3) {
            $error = 'Date de naissance invalide.';
        } elseif (strlen($v['adresse']) < 5 || strlen($v['adresse']) > 255) {
            $error = 'Adresse invalide (5 à 255 caractères).';
        } elseif (!in_array($v['profession'], ['travailleur', 'etudiant', 'eleve'], true)) {
            $error = 'Situation invalide.';
        } elseif ($v['profession'] === 'travailleur' && $v['profession_details'] === '') {
            $error = 'Veuillez indiquer la profession.';
        } elseif ($v['profession'] === 'etudiant' && (!in_array($v['niveau_etude'], NIVEAUX_ETUDE, true) || $v['filiere'] === '')) {
            $error = "Veuillez indiquer le niveau d'études et la filière.";
        } elseif ($v['profession'] === 'eleve' && !classeValide($v['classe'])) {
            $error = 'Veuillez sélectionner la classe.';
        } elseif ($photoError !== null) {
            $error = $photoError;
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } else {
            try {
                $q = $db->prepare("SELECT id, confirm FROM membre WHERE num = ? OR email = ?");
                $q->execute([$v['num'], $v['email']]);
                $conflicts = $q->fetchAll();

                if (array_filter($conflicts, fn($r) => (int) $r['confirm'] === 1)) {
                    $error = 'Ce numéro ou cette adresse e-mail est déjà utilisé.';
                } else {
                    $db->beginTransaction();
                    try {
                        foreach ($conflicts as $old) {
                            $db->prepare("DELETE FROM membre_photo WHERE membre_id = ?")->execute([$old['id']]);
                            $db->prepare("DELETE FROM membre WHERE id = ? AND confirm = 0")->execute([$old['id']]);
                        }
                        $p = $v['profession'];
                        $db->prepare(
                            "INSERT INTO membre (num, prenom, nom, email, adresse, date_naissance, genre, profession,
                                                 profession_details, niveau_etude, filiere, classe, passeword, confirm, date_inscription)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
                        )->execute([
                            $v['num'], $v['prenom'], $v['nom'], $v['email'], $v['adresse'], $v['date_naissance'], $v['genre'], $p,
                            $p === 'travailleur' ? $v['profession_details'] : null,
                            $p === 'etudiant' ? $v['niveau_etude'] : null,
                            $p === 'etudiant' ? $v['filiere'] : null,
                            $p === 'eleve' ? $v['classe'] : null,
                            password_hash($password, PASSWORD_DEFAULT),
                        ]);
                        if ($photo !== null) {
                            photoSave($db, (int) $db->lastInsertId(), $photo);
                        }
                        $db->commit();
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        throw $e;
                    }
                    flash('success', 'Le membre a été ajouté avec succès.');
                    redirect('admin_membres.php');
                }
            } catch (Throwable $e) {
                error_log('Ajout membre : ' . $e->getMessage());
                $error = "Une erreur est survenue lors de l'ajout.";
            }
        }
    }
}

$members = [];
try {
    $members = $db->query(
        "SELECT id, num, prenom, nom, role, profession, profession_details, niveau_etude, classe, a_photo, photo_maj
         FROM membre WHERE confirm = 1 ORDER BY date_inscription DESC, id DESC"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Liste membres : ' . $e->getMessage());
    $error = $error ?: 'Impossible de charger la liste des membres.';
}

renderHead('Gestion des membres', ['ping' => true]);
renderNav($user, 'gestion');
?>
<main class="container" id="contenu">
    <div class="page-head">
        <h1>Gestion des membres</h1>
        <p>Consultez, ajoutez et supprimez des membres. L'âge n'apparaît que sur cet espace d'administration.</p>
    </div>

    <?php renderFlashes(); ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <div class="tabs" role="tablist">
        <button type="button" class="tab<?= $openTab === 'liste' ? ' is-active' : '' ?>" role="tab" data-tab="liste" aria-selected="<?= $openTab === 'liste' ? 'true' : 'false' ?>"><?= icon('users') ?> Liste <span class="count"><?= count($members) ?></span></button>
        <button type="button" class="tab<?= $openTab === 'ajouter' ? ' is-active' : '' ?>" role="tab" data-tab="ajouter" aria-selected="<?= $openTab === 'ajouter' ? 'true' : 'false' ?>"><?= icon('user-plus') ?> Ajouter un membre</button>
    </div>

    <section class="tab-panel<?= $openTab === 'liste' ? ' is-active' : '' ?>" id="tab-liste" role="tabpanel" data-members>
        <div class="toolbar">
            <div class="search">
                <?= icon('search') ?>
                <input type="search" data-member-search placeholder="Rechercher un membre…" aria-label="Rechercher un membre" autocomplete="off">
            </div>
            <p class="text-muted mb-0" style="font-size:.9rem" data-member-count aria-live="polite"></p>
        </div>
        <div class="members-grid">
            <?php foreach ($members as $m): ?>
                <div class="member-item" data-member-item data-cat="<?= e($m['profession']) ?>"
                     data-search="<?= e(memberFullName($m) . ' ' . memberSubtitle($m) . ' ' . preg_replace('/\D/', '', (string) $m['num'])) ?>">
                    <button type="button" class="member-card" data-member-id="<?= (int) $m['id'] ?>" aria-label="Voir la fiche de <?= e(memberFullName($m)) ?>">
                        <?= avatarHtml($m, 'md') ?>
                        <span class="member-card__body">
                            <span class="member-card__name"><?= e(memberFullName($m)) ?></span>
                            <span class="member-card__meta"><?= e(memberSubtitle($m)) ?><?= isAdmin($m) ? ' · Administration' : '' ?></span>
                        </span>
                        <?= icon('chevron-right', 'member-card__chev') ?>
                    </button>
                    <?php if ((int) $m['id'] !== (int) $user['id']): ?>
                        <form method="post" action="admin_membres.php" class="m-0" style="display:flex"
                              data-confirm="Cette action est définitive : <?= e(memberFullName($m)) ?> sera supprimé(e) ainsi que sa photo."
                              data-confirm-title="Supprimer ce membre ?" data-confirm-ok="Supprimer">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="btn btn-danger-soft btn-icon" aria-label="Supprimer <?= e(memberFullName($m)) ?>"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="empty" data-member-empty hidden><?= icon('search') ?><p>Aucun membre ne correspond à votre recherche.</p></div>
        <?php if (!$members): ?><div class="empty"><?= icon('users') ?><p>Aucun membre pour le moment.</p></div><?php endif; ?>
    </section>

    <section class="tab-panel<?= $openTab === 'ajouter' ? ' is-active' : '' ?>" id="tab-ajouter" role="tabpanel">
        <form method="post" action="admin_membres.php" enctype="multipart/form-data" class="card" data-loading="Ajout en cours…">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <h2 class="card-title"><?= icon('user-plus') ?> Nouveau membre</h2>

            <div class="field">
                <span class="label">Photo de profil <span class="text-muted">(facultative)</span></span>
                <div class="photo-picker" data-photo-picker>
                    <div class="photo-picker__preview" data-photo-preview><?= icon('camera') ?></div>
                    <div class="photo-picker__body">
                        <div class="photo-picker__actions">
                            <label class="btn btn-outline btn-sm" for="photo"><?= icon('image') ?> <span data-photo-label>Choisir une photo</span></label>
                            <input class="photo-picker__file" data-photo-file type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
                        </div>
                        <span class="hint">JPG, PNG ou WebP.</span>
                    </div>
                    <input type="hidden" name="photo_data" data-photo-data value="<?= e($v['photo_data']) ?>">
                    <input type="hidden" name="photo_thumb" data-photo-thumb value="<?= e($v['photo_thumb']) ?>">
                </div>
                <div class="alert alert-error mt-1 mb-0" data-photo-error hidden role="alert"></div>
            </div>

            <div class="form-grid form-grid--2">
                <div class="field"><label for="prenom">Prénom <span class="req">*</span></label><input type="text" id="prenom" name="prenom" value="<?= e($v['prenom']) ?>" required></div>
                <div class="field"><label for="nom">Nom <span class="req">*</span></label><input type="text" id="nom" name="nom" value="<?= e($v['nom']) ?>" required></div>
                <div class="field"><label for="num">Téléphone / WhatsApp <span class="req">*</span></label><input type="tel" id="num" name="num" value="<?= e($v['num']) ?>" inputmode="tel" required></div>
                <div class="field"><label for="email">E-mail <span class="req">*</span></label><input type="email" id="email" name="email" value="<?= e($v['email']) ?>" required></div>
                <div class="field"><label for="date_naissance">Date de naissance <span class="req">*</span></label><input type="date" id="date_naissance" name="date_naissance" value="<?= e($v['date_naissance']) ?>" max="<?= e(date('Y-m-d')) ?>" min="1910-01-01" required></div>
                <div class="field">
                    <label for="genre">Genre <span class="req">*</span></label>
                    <select id="genre" name="genre" required>
                        <option value="">Sélectionner</option>
                        <option value="Homme" <?= $v['genre'] === 'Homme' ? 'selected' : '' ?>>Homme</option>
                        <option value="Femme" <?= $v['genre'] === 'Femme' ? 'selected' : '' ?>>Femme</option>
                    </select>
                </div>
            </div>
            <div class="field"><label for="adresse">Adresse <span class="req">*</span></label><input type="text" id="adresse" name="adresse" value="<?= e($v['adresse']) ?>" maxlength="255" required></div>

            <div class="field">
                <label for="profession">Situation <span class="req">*</span></label>
                <select id="profession" name="profession" data-profession-select required>
                    <option value="">Sélectionner</option>
                    <option value="travailleur" <?= $v['profession'] === 'travailleur' ? 'selected' : '' ?>>Travailleur</option>
                    <option value="etudiant" <?= $v['profession'] === 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                    <option value="eleve" <?= $v['profession'] === 'eleve' ? 'selected' : '' ?>>Élève</option>
                </select>
            </div>
            <div class="field" data-prof-field="travailleur" hidden>
                <label for="profession_details">Profession <span class="req">*</span></label>
                <input type="text" id="profession_details" name="profession_details" value="<?= e($v['profession_details']) ?>" placeholder="Ex : Enseignant, Commerçant">
            </div>
            <div data-prof-field="etudiant" hidden>
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label for="niveau_etude">Niveau d'études <span class="req">*</span></label>
                        <select id="niveau_etude" name="niveau_etude">
                            <option value="">Sélectionner</option>
                            <?php foreach (NIVEAUX_ETUDE as $n): ?><option value="<?= e($n) ?>" <?= $v['niveau_etude'] === $n ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="filiere">Filière <span class="req">*</span></label><input type="text" id="filiere" name="filiere" value="<?= e($v['filiere']) ?>"></div>
                </div>
            </div>
            <div class="field" data-prof-field="eleve" hidden>
                <label for="classe">Classe <span class="req">*</span></label>
                <select id="classe" name="classe">
                    <option value="">Sélectionner</option>
                    <?php foreach (CLASSES as $groupe => $liste): ?>
                        <optgroup label="<?= e($groupe) ?>"><?php foreach ($liste as $c): ?><option value="<?= e($c) ?>" <?= $v['classe'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="passeword">Mot de passe provisoire <span class="req">*</span></label>
                <div class="input-group">
                    <input type="password" id="passeword" name="passeword" minlength="8" autocomplete="new-password" required>
                    <button type="button" class="toggle-pass" data-toggle-pass="passeword" aria-label="Afficher le mot de passe"><?= icon('eye') ?></button>
                </div>
                <span class="hint">8 caractères minimum. Le membre pourra le changer depuis « Mon profil ».</span>
            </div>
            <button type="submit" class="btn btn-primary">Ajouter le membre</button>
        </form>
    </section>
</main>

<?php renderMemberModal(); ?>
<?php
renderFooter();
