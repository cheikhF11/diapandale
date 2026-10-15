<?php
require_once __DIR__ . '/config/app.php';

$error = '';
$members = [];
$daara = null;
$stats = ['total' => 0, 'travailleur' => 0, 'etudiant' => 0, 'eleve' => 0];

try {
    $db = getDatabaseConnection();
    $user = requireLogin($db);

    $daara = $db->query("SELECT * FROM daara_info ORDER BY id DESC LIMIT 1")->fetch() ?: null;

    // Liste des membres : aucune donnée sensible (ni âge, ni date de naissance).
    $members = $db->query(
        "SELECT id, num, prenom, nom, profession, profession_details, niveau_etude, classe, a_photo, photo_maj
         FROM membre WHERE confirm = 1 ORDER BY nom ASC, prenom ASC"
    )->fetchAll();

    foreach ($members as $m) {
        $stats['total']++;
        if (isset($stats[$m['profession']])) {
            $stats[$m['profession']]++;
        }
    }
} catch (Throwable $e) {
    error_log('Erreur accueil : ' . $e->getMessage());
    if (!isset($user)) {
        redirect('index.php');
    }
    $error = 'Impossible de charger toutes les informations pour le moment.';
}

$socials = [];
if ($daara) {
    foreach (['facebook_url' => ['Facebook', 'link'], 'instagram_url' => ['Instagram', 'link'], 'youtube_url' => ['YouTube', 'link'],
              'whatsapp_url' => ['WhatsApp', 'message'], 'telegram_url' => ['Telegram', 'send'], 'website_url' => ['Site web', 'link']] as $key => [$label, $ico]) {
        $url = safeUrl($daara[$key] ?? '');
        if ($url) {
            $socials[] = ['label' => $label, 'url' => $url, 'icon' => $ico];
        }
    }
}

renderHead('Accueil', ['ping' => true]);
renderNav($user, 'accueil');
?>
<main class="container" id="contenu">
    <?php renderFlashes(); ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <div class="tabs" role="tablist" aria-label="Sections">
        <button type="button" class="tab is-active" role="tab" data-tab="accueil" aria-selected="true"><?= icon('home') ?> Accueil</button>
        <button type="button" class="tab" role="tab" data-tab="membres" aria-selected="false">
            <?= icon('users') ?> Membres <span class="count"><?= (int) $stats['total'] ?></span>
        </button>
    </div>

    <!-- ═══ ACCUEIL ═══ -->
    <section class="tab-panel is-active" id="tab-accueil" role="tabpanel">
        <div class="stats stats--4">
            <button type="button" class="stat" data-switch-tab="membres"><div class="stat__num"><?= (int) $stats['total'] ?></div><div class="stat__label">Membres</div></button>
            <button type="button" class="stat" data-switch-tab="membres"><div class="stat__num"><?= (int) $stats['travailleur'] ?></div><div class="stat__label">Travailleurs</div></button>
            <button type="button" class="stat" data-switch-tab="membres"><div class="stat__num"><?= (int) $stats['etudiant'] ?></div><div class="stat__label">Étudiants</div></button>
            <button type="button" class="stat" data-switch-tab="membres"><div class="stat__num"><?= (int) $stats['eleve'] ?></div><div class="stat__label">Élèves</div></button>
        </div>

        <div class="grid grid--main-side">
            <div class="card card--accent">
                <h2 class="card-title"><?= icon('book') ?> Prochaine séance du Daara</h2>
                <?php if ($daara): ?>
                    <dl class="info-grid m-0">
                        <div class="info-item info-item--wide"><dt>Thème</dt><dd><?= e($daara['theme']) ?></dd></div>
                        <div class="info-item"><dt>Conférencier</dt><dd><?= e($daara['conferencier']) ?></dd></div>
                        <div class="info-item"><dt>Date</dt><dd><?= e(formatDateFr($daara['date_seance'], true, true)) ?></dd></div>
                        <div class="info-item"><dt>Lieu</dt><dd><?= e($daara['lieu']) ?></dd></div>
                        <div class="info-item">
                            <dt>Contact</dt>
                            <dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $daara['contact'])) ?>"><?= e($daara['contact']) ?></a></dd>
                        </div>
                        <div class="info-item info-item--wide"><dt>Programme</dt><dd><?= nl2br(e($daara['programme'])) ?></dd></div>
                        <?php if (!empty($daara['description'])): ?>
                            <div class="info-item info-item--wide"><dt>Description</dt><dd><?= nl2br(e($daara['description'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?php if ($socials): ?>
                        <div class="social-links mt-2">
                            <?php foreach ($socials as $s): ?>
                                <a class="btn btn-outline btn-sm" href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon($s['icon']) ?> <?= e($s['label']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty"><?= icon('calendar') ?><p>Aucune information n'a encore été publiée.</p></div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title"><?= icon('chart') ?> Répartition</h2>
                <div class="chart-box chart-box--sm"><canvas id="membersChart" aria-label="Répartition des membres par situation" role="img"></canvas></div>
            </div>
        </div>
    </section>

    <!-- ═══ MEMBRES ═══ -->
    <section class="tab-panel" id="tab-membres" role="tabpanel" data-members>
        <div class="toolbar">
            <div class="search">
                <?= icon('search') ?>
                <input type="search" data-member-search placeholder="Rechercher un membre…" aria-label="Rechercher un membre" autocomplete="off">
            </div>
            <div class="chips" role="group" aria-label="Filtrer par situation">
                <button type="button" class="chip" data-member-cat="all" aria-pressed="true">Tous <span class="count"><?= (int) $stats['total'] ?></span></button>
                <button type="button" class="chip" data-member-cat="travailleur" aria-pressed="false">Travailleurs <span class="count"><?= (int) $stats['travailleur'] ?></span></button>
                <button type="button" class="chip" data-member-cat="etudiant" aria-pressed="false">Étudiants <span class="count"><?= (int) $stats['etudiant'] ?></span></button>
                <button type="button" class="chip" data-member-cat="eleve" aria-pressed="false">Élèves <span class="count"><?= (int) $stats['eleve'] ?></span></button>
            </div>
        </div>
        <p class="text-muted mb-1" style="font-size:.88rem" data-member-count aria-live="polite"></p>

        <div class="members-grid">
            <?php foreach ($members as $m): ?>
                <div class="member-item" data-member-item data-cat="<?= e($m['profession']) ?>"
                     data-search="<?= e(memberFullName($m) . ' ' . memberSubtitle($m) . ' ' . preg_replace('/\D/', '', (string) $m['num'])) ?>">
                    <button type="button" class="member-card" data-member-id="<?= (int) $m['id'] ?>"
                            aria-label="Voir la fiche de <?= e(memberFullName($m)) ?>">
                        <?= avatarHtml($m, 'md') ?>
                        <span class="member-card__body">
                            <span class="member-card__name"><?= e(memberFullName($m)) ?></span>
                            <span class="member-card__meta"><?= e(memberSubtitle($m)) ?></span>
                        </span>
                        <?= icon('chevron-right', 'member-card__chev') ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="empty" data-member-empty hidden><?= icon('search') ?><p>Aucun membre ne correspond à votre recherche.</p></div>
        <?php if (!$members): ?>
            <div class="empty"><?= icon('users') ?><p>Aucun membre pour le moment.</p></div>
        <?php endif; ?>
    </section>
</main>

<?php renderMemberModal(); ?>
<?php
renderFooter([
    'scripts' => ['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js'],
    'inline' => 'window.addEventListener("load",function(){var c=document.getElementById("membersChart");if(!c||typeof Chart==="undefined"){return;}'
        . 'new Chart(c,{type:"doughnut",data:{labels:["Travailleurs","Étudiants","Élèves"],datasets:[{data:['
        . (int) $stats['travailleur'] . ',' . (int) $stats['etudiant'] . ',' . (int) $stats['eleve']
        . '],backgroundColor:["#141414","#b58b45","#d9cdb4"],borderWidth:0,hoverOffset:6}]},'
        . 'options:{responsive:true,maintainAspectRatio:false,cutout:"62%",plugins:{legend:{position:"bottom",labels:{usePointStyle:true,boxWidth:10}}}}});});',
]);
