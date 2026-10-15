<?php
require_once __DIR__ . '/config/app.php';

$db = getDatabaseConnection();
$user = requireLogin($db);

if (!hasRole($user, 'admin_daara')) {
    redirect('accueil.php');
}

$error = '';
$daara = null;
$history = [];

try {
    $daara = $db->query("SELECT * FROM daara_info ORDER BY id DESC LIMIT 1")->fetch() ?: null;
} catch (Throwable $e) {
    error_log('admin_daara (lecture) : ' . $e->getMessage());
    $error = 'Impossible de charger les informations actuelles.';
}

$fields = ['theme', 'conferencier', 'date_seance', 'lieu', 'contact', 'programme', 'description',
           'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url', 'telegram_url', 'website_url'];
$v = array_fill_keys($fields, '');
if ($daara) {
    foreach ($fields as $f) {
        $v[$f] = (string) ($daara[$f] ?? '');
    }
    $v['date_seance'] = $daara['date_seance'] ? date('Y-m-d\TH:i', strtotime((string) $daara['date_seance'])) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $f) {
        $v[$f] = $f === 'programme' || $f === 'description'
            ? trim(strip_tags((string) ($_POST[$f] ?? '')))
            : securisation($_POST[$f] ?? '');
    }
    $notify = !empty($_POST['notify']);
    $ts = $v['date_seance'] !== '' ? strtotime(str_replace('T', ' ', $v['date_seance'])) : false;

    $badUrl = null;
    foreach (['facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url', 'telegram_url', 'website_url'] as $f) {
        if ($v[$f] !== '' && safeUrl($v[$f]) === null) {
            $badUrl = $f;
            break;
        }
    }

    if (!csrfValid()) {
        $error = 'Votre session a expiré. Veuillez réessayer.';
    } elseif ($v['theme'] === '' || $v['conferencier'] === '' || $v['lieu'] === '' || $v['contact'] === '' || $v['programme'] === '' || $ts === false) {
        $error = 'Veuillez remplir tous les champs obligatoires (thème, conférencier, date, lieu, contact, programme).';
    } elseif ($badUrl !== null) {
        $error = 'Un des liens saisis est invalide : il doit commencer par http:// ou https://.';
    } else {
        try {
            $db->beginTransaction();
            $db->prepare(
                "INSERT INTO daara_info (theme, conferencier, date_seance, lieu, contact, programme, description,
                                         facebook_url, instagram_url, youtube_url, whatsapp_url, telegram_url, website_url, modifie_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $v['theme'], $v['conferencier'], date('Y-m-d H:i:s', $ts), $v['lieu'], $v['contact'], $v['programme'],
                $v['description'] !== '' ? $v['description'] : null,
                $v['facebook_url'] ?: null, $v['instagram_url'] ?: null, $v['youtube_url'] ?: null,
                $v['whatsapp_url'] ?: null, $v['telegram_url'] ?: null, $v['website_url'] ?: null,
                $user['num'],
            ]);
            $db->prepare("INSERT INTO daara_historique (action, details, modifie_par) VALUES (?, ?, ?)")
               ->execute(['Mise à jour des informations du Daara', 'Thème : ' . $v['theme'] . ' · Conférencier : ' . $v['conferencier'], $user['num']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('admin_daara (écriture) : ' . $e->getMessage());
            $error = "Une erreur est survenue lors de l'enregistrement.";
        }

        if ($error === '') {
            if ($notify) {
                $corps = "Une nouvelle séance du Daara a été programmée.\n\n"
                    . 'Thème : ' . $v['theme'] . "\n"
                    . 'Conférencier : ' . $v['conferencier'] . "\n"
                    . 'Date : ' . formatDateFr($ts, true, true) . "\n"
                    . 'Lieu : ' . $v['lieu'] . "\n"
                    . 'Contact : ' . $v['contact'] . "\n\n"
                    . "Programme :\n" . $v['programme']
                    . ($v['description'] !== '' ? "\n\n" . $v['description'] : '');

                try {
                    $id = campagneCreate($db, 'Daara : ' . $v['theme'], $corps, 'tous', (string) $user['num']);
                    if ($id > 0) {
                        flash('success', 'Informations enregistrées. Envoi du message aux membres en cours…');
                        redirect('admin_message.php?run=' . $id);
                    }
                } catch (Throwable $e) {
                    error_log('admin_daara (notification) : ' . $e->getMessage());
                }
                flash('warning', "Informations enregistrées, mais l'e-mail aux membres n'a pas pu être préparé. Utilisez la Messagerie.");
            } else {
                flash('success', 'Les informations du Daara ont été mises à jour.');
            }
            redirect('admin_daara.php');
        }
    }
}

try {
    $history = $db->query(
        "SELECT h.action, h.details, h.date_action, m.prenom, m.nom
         FROM daara_historique h LEFT JOIN membre m ON m.num = h.modifie_par
         ORDER BY h.date_action DESC, h.id DESC LIMIT 12"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('admin_daara (historique) : ' . $e->getMessage());
}

renderHead('Administration du Daara', ['ping' => true]);
renderNav($user, 'daara');
?>
<main class="container container--narrow" id="contenu">
    <div class="page-head">
        <h1>Informations du Daara</h1>
        <p>Ces informations sont affichées sur la page d'accueil de tous les membres.</p>
    </div>

    <?php renderFlashes(); ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" action="admin_daara.php" class="card card--accent" data-loading="Enregistrement…">
        <?= csrfField() ?>
        <h2 class="card-title"><?= icon('edit') ?> Modifier les informations</h2>

        <div class="field">
            <label for="theme">Thème <span class="req">*</span></label>
            <input type="text" id="theme" name="theme" value="<?= e($v['theme']) ?>" required>
        </div>
        <div class="form-grid form-grid--2">
            <div class="field">
                <label for="conferencier">Conférencier <span class="req">*</span></label>
                <input type="text" id="conferencier" name="conferencier" value="<?= e($v['conferencier']) ?>" required>
            </div>
            <div class="field">
                <label for="date_seance">Date et heure <span class="req">*</span></label>
                <input type="datetime-local" id="date_seance" name="date_seance" value="<?= e($v['date_seance']) ?>" required>
            </div>
            <div class="field">
                <label for="lieu">Lieu <span class="req">*</span></label>
                <input type="text" id="lieu" name="lieu" value="<?= e($v['lieu']) ?>" required>
            </div>
            <div class="field">
                <label for="contact">Contact <span class="req">*</span></label>
                <input type="tel" id="contact" name="contact" value="<?= e($v['contact']) ?>" inputmode="tel" required>
            </div>
        </div>
        <div class="field">
            <label for="programme">Programme <span class="req">*</span></label>
            <textarea id="programme" name="programme" required><?= e($v['programme']) ?></textarea>
        </div>
        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= e($v['description']) ?></textarea>
        </div>

        <div class="section-label">Liens et réseaux</div>
        <div class="form-grid form-grid--2">
            <?php foreach (['facebook_url' => 'Facebook', 'instagram_url' => 'Instagram', 'youtube_url' => 'YouTube',
                            'whatsapp_url' => 'WhatsApp (groupe)', 'telegram_url' => 'Telegram', 'website_url' => 'Site web'] as $name => $label): ?>
                <div class="field">
                    <label for="<?= e($name) ?>"><?= e($label) ?></label>
                    <input type="url" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($v[$name]) ?>" placeholder="https://…" inputmode="url" autocapitalize="none">
                </div>
            <?php endforeach; ?>
        </div>

        <label class="check mt-1">
            <input type="checkbox" name="notify" value="1" checked>
            <div><strong>Informer tous les membres par e-mail</strong><span>Un message récapitulatif est envoyé à chaque membre confirmé.</span></div>
        </label>

        <button type="submit" class="btn btn-primary btn-block mt-2">Enregistrer</button>
    </form>

    <div class="card mt-2">
        <h2 class="card-title"><?= icon('clock') ?> Historique des modifications</h2>
        <?php if ($history): ?>
            <ul class="timeline">
                <?php foreach ($history as $h): ?>
                    <li>
                        <time><?= e(formatDateFr($h['date_action'], true)) ?></time>
                        <b><?= e($h['action']) ?></b>
                        <p><?= e((string) $h['details']) ?><?= !empty($h['prenom']) ? ' — par ' . e(trim($h['prenom'] . ' ' . $h['nom'])) : '' ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty"><?= icon('clock') ?><p>Aucune modification pour le moment.</p></div>
        <?php endif; ?>
    </div>
</main>
<?php
renderFooter();
