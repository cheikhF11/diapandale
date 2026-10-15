<?php
require_once __DIR__ . '/config/app.php';

$error = null;
$stats = ['total' => 0, 'en_ligne' => 0, 'nouveaux' => 0, 'en_attente' => 0];
$professions = ['Travailleurs' => 0, 'Étudiants' => 0, 'Élèves' => 0];
$genres = [];
$inscriptions = [];
$derniers = [];
$ages = null;

try {
    $db = getDatabaseConnection();
    $user = requireLogin($db);
    $admin = isAdmin($user);

    $stats['total'] = (int) $db->query("SELECT COUNT(*) FROM membre WHERE confirm = 1")->fetchColumn();
    $stats['en_ligne'] = (int) $db->query(
        "SELECT COUNT(*) FROM membre WHERE confirm = 1 AND derniere_connexion >= (NOW() - INTERVAL 5 MINUTE)"
    )->fetchColumn();
    $stats['nouveaux'] = (int) $db->query(
        "SELECT COUNT(*) FROM membre WHERE confirm = 1 AND date_inscription >= (NOW() - INTERVAL 30 DAY)"
    )->fetchColumn();

    $labels = ['travailleur' => 'Travailleurs', 'etudiant' => 'Étudiants', 'eleve' => 'Élèves'];
    foreach ($db->query("SELECT profession, COUNT(*) AS total FROM membre WHERE confirm = 1 GROUP BY profession")->fetchAll() as $r) {
        if (isset($labels[$r['profession']])) {
            $professions[$labels[$r['profession']]] = (int) $r['total'];
        }
    }

    foreach ($db->query("SELECT genre, COUNT(*) AS total FROM membre WHERE confirm = 1 GROUP BY genre")->fetchAll() as $r) {
        $genres[$r['genre']] = (int) $r['total'];
    }

    $inscriptions = $db->query(
        "SELECT DATE_FORMAT(date_inscription, '%Y-%m') AS mois, COUNT(*) AS total
         FROM membre WHERE confirm = 1 AND date_inscription >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY mois ORDER BY mois ASC"
    )->fetchAll();

    $derniers = $db->query(
        "SELECT id, prenom, nom, profession, profession_details, niveau_etude, classe, a_photo, photo_maj, date_inscription
         FROM membre WHERE confirm = 1 ORDER BY date_inscription DESC LIMIT 8"
    )->fetchAll();

    // Informations réservées à l'administration.
    if ($admin) {
        $stats['en_attente'] = (int) $db->query("SELECT COUNT(*) FROM membre WHERE confirm = 0")->fetchColumn();

        $ages = ['Moins de 13 ans' => 0, '13 – 17 ans' => 0, '18 – 25 ans' => 0, '26 – 40 ans' => 0, '41 – 60 ans' => 0, 'Plus de 60 ans' => 0];
        foreach ($db->query("SELECT date_naissance FROM membre WHERE confirm = 1 AND date_naissance IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $dn) {
            $a = calculateAge($dn);
            if ($a === null) {
                continue;
            }
            $bucket = $a < 13 ? 'Moins de 13 ans' : ($a < 18 ? '13 – 17 ans' : ($a < 26 ? '18 – 25 ans' : ($a < 41 ? '26 – 40 ans' : ($a <= 60 ? '41 – 60 ans' : 'Plus de 60 ans'))));
            $ages[$bucket]++;
        }
    }
} catch (Throwable $e) {
    error_log('Erreur dashboard : ' . $e->getMessage());
    if (!isset($user)) {
        redirect('index.php');
    }
    $error = 'Impossible de charger toutes les statistiques pour le moment.';
}

$moisLabels = array_map(fn($r) => formatMonthShort((string) $r['mois']), $inscriptions);
$moisValues = array_map(fn($r) => (int) $r['total'], $inscriptions);

renderHead('Tableau de bord', ['ping' => true]);
renderNav($user, 'dashboard');
?>
<main class="container" id="contenu">
    <div class="page-head">
        <h1>Tableau de bord</h1>
        <p>Vue d'ensemble de la communauté du <?= e(APP_NAME_SHORT) ?>.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <div class="stats <?= $admin ? 'stats--4' : 'stats--3' ?>">
        <div class="stat"><div class="stat__num"><?= $stats['total'] ?></div><div class="stat__label">Membres confirmés</div></div>
        <div class="stat"><div class="stat__num"><?= $stats['en_ligne'] ?></div><div class="stat__label">En ligne actuellement</div></div>
        <div class="stat"><div class="stat__num"><?= $stats['nouveaux'] ?></div><div class="stat__label">Nouveaux (30 jours)</div></div>
        <?php if ($admin): ?>
            <div class="stat"><div class="stat__num"><?= $stats['en_attente'] ?></div><div class="stat__label">Inscriptions en attente</div></div>
        <?php endif; ?>
    </div>

    <div class="grid grid--2">
        <div class="card">
            <h2 class="card-title"><?= icon('chart') ?> Inscriptions (12 derniers mois)</h2>
            <div class="chart-box"><canvas id="chartInscriptions" role="img" aria-label="Inscriptions par mois"></canvas></div>
        </div>
        <div class="card">
            <h2 class="card-title"><?= icon('users') ?> Répartition par genre</h2>
            <div class="chart-box"><canvas id="chartGenre" role="img" aria-label="Répartition par genre"></canvas></div>
        </div>
        <div class="card">
            <h2 class="card-title"><?= icon('briefcase') ?> Répartition par situation</h2>
            <div class="chart-box"><canvas id="chartProfession" role="img" aria-label="Répartition par situation"></canvas></div>
        </div>
        <?php if ($ages !== null): ?>
            <div class="card">
                <h2 class="card-title"><?= icon('lock') ?> Tranches d'âge <span class="badge badge--dark">Administration</span></h2>
                <div class="chart-box"><canvas id="chartAges" role="img" aria-label="Répartition par tranche d'âge"></canvas></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card mt-2">
        <h2 class="card-title"><?= icon('user-plus') ?> Derniers membres inscrits</h2>
        <?php if ($derniers): ?>
            <div class="members-grid">
                <?php foreach ($derniers as $m): ?>
                    <div class="member-item">
                        <button type="button" class="member-card" data-member-id="<?= (int) $m['id'] ?>" aria-label="Voir la fiche de <?= e(memberFullName($m)) ?>">
                            <?= avatarHtml($m, 'md') ?>
                            <span class="member-card__body">
                                <span class="member-card__name"><?= e(memberFullName($m)) ?></span>
                                <span class="member-card__meta"><?= e(memberSubtitle($m)) ?> · <?= e(formatDateFr($m['date_inscription'])) ?></span>
                            </span>
                            <?= icon('chevron-right', 'member-card__chev') ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty"><?= icon('users') ?><p>Aucun membre pour le moment.</p></div>
        <?php endif; ?>
    </div>
</main>

<?php renderMemberModal(); ?>
<?php
$js = 'window.addEventListener("load",function(){if(typeof Chart==="undefined"){return;}'
    . 'var NOIR="#141414",OR="#b58b45",CLAIR="#d9b774",GRIS="#e6e1d8",SABLE="#d9cdb4";'
    . 'Chart.defaults.font.family=getComputedStyle(document.body).fontFamily;Chart.defaults.color="#4a4a4a";'
    . 'var opt={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0},grid:{color:GRIS}},x:{grid:{display:false}}}};'
    . 'new Chart(document.getElementById("chartInscriptions"),{type:"line",data:{labels:' . json_encode($moisLabels, JSON_UNESCAPED_UNICODE) . ',datasets:[{label:"Nouveaux membres",data:' . json_encode($moisValues) . ',borderColor:NOIR,backgroundColor:"rgba(181,139,69,.18)",borderWidth:2,pointBackgroundColor:OR,pointRadius:4,fill:true,tension:.35}]},options:opt});'
    . 'new Chart(document.getElementById("chartGenre"),{type:"doughnut",data:{labels:' . json_encode(array_keys($genres), JSON_UNESCAPED_UNICODE) . ',datasets:[{data:' . json_encode(array_values($genres)) . ',backgroundColor:[NOIR,OR,CLAIR,SABLE],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:"62%",plugins:{legend:{position:"bottom",labels:{usePointStyle:true,boxWidth:10}}}}});'
    . 'new Chart(document.getElementById("chartProfession"),{type:"bar",data:{labels:' . json_encode(array_keys($professions), JSON_UNESCAPED_UNICODE) . ',datasets:[{label:"Membres",data:' . json_encode(array_values($professions)) . ',backgroundColor:[NOIR,OR,CLAIR],borderRadius:6,maxBarThickness:60}]},options:opt});';
if ($ages !== null) {
    $js .= 'new Chart(document.getElementById("chartAges"),{type:"bar",data:{labels:' . json_encode(array_keys($ages), JSON_UNESCAPED_UNICODE) . ',datasets:[{label:"Membres",data:' . json_encode(array_values($ages)) . ',backgroundColor:OR,borderRadius:6,maxBarThickness:48}]},options:opt});';
}
$js .= '});';

renderFooter([
    'scripts' => ['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js'],
    'inline' => $js,
]);
