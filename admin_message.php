<?php
require_once __DIR__ . '/config/app.php';

$db = getDatabaseConnection();
$user = requireLogin($db);

if (!isAdmin($user)) {
    if (($_POST['action'] ?? '') !== '') {
        jsonResponse(['ok' => false, 'error' => 'Accès refusé.'], 403);
    }
    redirect('accueil.php');
}

/* ═══ Actions AJAX (création, envoi par lots, annulation) ═══════════════ */
$action = (string) ($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create', 'batch', 'cancel'], true)) {
    if (!csrfValid()) {
        jsonResponse(['ok' => false, 'error' => 'Session expirée : rechargez la page.'], 403);
    }
    session_write_close(); // l'envoi peut durer plusieurs secondes : on libère la session

    try {
        if ($action === 'create') {
            $sujet = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($_POST['sujet'] ?? ''))));
            $contenu = trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')));
            $audience = (string) ($_POST['audience'] ?? 'tous');

            if (strlen($sujet) < 3 || strlen($sujet) > 150) {
                jsonResponse(['ok' => false, 'error' => "L'objet doit contenir entre 3 et 150 caractères."], 422);
            }
            if (strlen($contenu) < 10 || strlen($contenu) > 10000) {
                jsonResponse(['ok' => false, 'error' => 'Le message doit contenir entre 10 et 10 000 caractères.'], 422);
            }
            if (mailConfig() === null) {
                jsonResponse(['ok' => false, 'error' => "L'envoi d'e-mails n'est pas configuré sur le serveur (variables SMTP_*)."], 500);
            }

            $id = campagneCreate($db, $sujet, $contenu, $audience, (string) $user['num']);
            if ($id === 0) {
                jsonResponse(['ok' => false, 'error' => 'Aucun destinataire pour ce groupe.'], 422);
            }
            jsonResponse(campagneEtat(campagneLoad($db, $id)));
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'cancel') {
            campagneCancel($db, $id);
            $c = campagneLoad($db, $id);
            jsonResponse($c ? campagneEtat($c) : ['ok' => false, 'error' => 'Envoi introuvable.'], $c ? 200 : 404);
        }

        // batch
        $etat = campagneBatch($db, $id);
        jsonResponse($etat, $etat['ok'] ? 200 : 500);
    } catch (Throwable $e) {
        error_log('Messagerie : ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Une erreur est survenue. Réessayez.'], 500);
    }
}

/* ═══ Page ═══════════════════════════════════════════════════════════════ */
$counts = [];
$history = [];
$error = '';
try {
    $counts = campagneCounts($db);
    $history = $db->query(
        "SELECT c.*, m.prenom, m.nom FROM message_campagne c
         LEFT JOIN membre m ON m.num = c.cree_par
         ORDER BY c.date_creation DESC, c.id DESC LIMIT 15"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Messagerie (page) : ' . $e->getMessage());
    $error = 'Impossible de charger les informations de la messagerie.';
}

$run = (int) ($_GET['run'] ?? 0);

renderHead('Messagerie', ['ping' => true]);
renderNav($user, 'messagerie');
?>
<main class="container" id="contenu" data-run="<?= $run ?>">
    <div class="page-head">
        <h1>Messagerie</h1>
        <p>Envoyez un e-mail à tous les membres ou à un groupe. Les adresses restent confidentielles (copie cachée).</p>
    </div>

    <?php renderFlashes(); ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <?php if (mailConfig() === null): ?>
        <div class="alert alert-warning" role="alert"><?= icon('alert-circle') ?><span>L'envoi d'e-mails n'est pas encore configuré : renseignez les variables <b>SMTP_*</b> (voir le guide de déploiement).</span></div>
    <?php endif; ?>

    <div class="grid grid--main-side">
        <form class="card card--accent" id="messageForm" autocomplete="off">
            <h2 class="card-title"><?= icon('send') ?> Nouveau message</h2>

            <div class="field">
                <label for="audience">Destinataires</label>
                <select id="audience" name="audience">
                    <?php foreach (CAMPAGNE_AUDIENCES as $key => $label): ?>
                        <option value="<?= e($key) ?>" data-count="<?= (int) ($counts[$key] ?? 0) ?>"><?= e($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Seuls les membres confirmés ayant une adresse e-mail reçoivent le message.</span>
            </div>

            <div class="field">
                <label for="sujet">Objet</label>
                <input type="text" id="sujet" name="sujet" maxlength="150" placeholder="Ex : Rappel de la séance de vendredi" required>
            </div>

            <div class="field">
                <label for="message">Message</label>
                <textarea id="message" name="message" maxlength="10000" rows="10" placeholder="Rédigez votre message… Les liens (https://…) deviennent cliquables." required style="min-height:220px"></textarea>
                <div class="counter" data-counter-for="message"></div>
            </div>

            <div class="alert alert-error" id="formError" hidden role="alert"></div>
            <button type="submit" class="btn btn-primary btn-block" id="sendBtn"><?= icon('send') ?> Envoyer le message</button>
        </form>

        <aside class="card">
            <h2 class="card-title"><?= icon('clock') ?> Historique</h2>
            <?php if ($history): ?>
                <?php foreach ($history as $h): [$statutLabel, $statutClass] = campagneStatutLabel((string) $h['statut']); ?>
                    <div class="campaign">
                        <div class="campaign__top">
                            <b><?= e($h['sujet']) ?></b>
                            <span class="badge <?= e($statutClass) ?>"><?= e($statutLabel) ?></span>
                        </div>
                        <small>
                            <?= e(formatDateFr($h['date_creation'], true)) ?> · <?= e(CAMPAGNE_AUDIENCES[$h['audience']] ?? 'Tous les membres') ?>
                            <?php if (!empty($h['prenom'])): ?> · par <?= e(trim($h['prenom'] . ' ' . $h['nom'])) ?><?php endif; ?>
                        </small>
                        <small><?= (int) $h['envoyes'] ?> envoyé(s) sur <?= (int) $h['total'] ?><?= (int) $h['echecs'] > 0 ? ' · ' . (int) $h['echecs'] . ' échec(s)' : '' ?></small>
                        <?php if ($h['statut'] === 'en_cours'): ?>
                            <div><button type="button" class="btn btn-outline btn-sm" data-resume="<?= (int) $h['id'] ?>">Reprendre l'envoi</button></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty"><?= icon('mail') ?><p>Aucun message envoyé pour le moment.</p></div>
            <?php endif; ?>
        </aside>
    </div>
</main>

<!-- Progression de l'envoi -->
<div class="modal" id="sendModal" data-static role="dialog" aria-modal="true" aria-labelledby="sendTitle" aria-hidden="true">
    <div class="modal__backdrop"></div>
    <div class="modal__dialog modal__dialog--sm">
        <div class="modal__body">
            <h2 class="modal__title" id="sendTitle">Envoi en cours…</h2>
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="sendBar"><span></span></div>
            <p class="send-status" id="sendStatus" aria-live="polite">Préparation…</p>
            <div class="alert alert-error" id="sendError" hidden role="alert"></div>
            <div class="modal__actions" id="sendActions" hidden>
                <button type="button" class="btn btn-outline" id="sendCancel">Annuler l'envoi</button>
                <button type="button" class="btn btn-primary" id="sendRetry">Reprendre</button>
                <button type="button" class="btn btn-primary" id="sendClose">Fermer</button>
            </div>
        </div>
    </div>
</div>
<?php
renderFooter(['pageScript' => 'js/message.js']);
