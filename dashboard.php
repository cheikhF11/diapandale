<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/securite/securisation.php';

if (!isset($_SESSION['number']) || !isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

function getUserInfo($connexion, $num) {
    $query = $connexion->prepare("SELECT prenom, nom, role FROM membre WHERE num = ? LIMIT 1");
    $query->execute([$num]);
    return $query->fetch(PDO::FETCH_ASSOC);
}

function getGlobalStats($connexion) {
    $stats = [];

    $stats['total'] = (int) $connexion->query(
        "SELECT COUNT(*) AS c FROM membre WHERE confirm = 1"
    )->fetch()['c'];

    $stats['en_attente'] = (int) $connexion->query(
        "SELECT COUNT(*) AS c FROM membre WHERE confirm = 0"
    )->fetch()['c'];

    $stats['en_ligne'] = (int) $connexion->query(
        "SELECT COUNT(*) AS c FROM membre WHERE statut_en_ligne = 1 AND confirm = 1"
    )->fetch()['c'];

    return $stats;
}

function getProfessionBreakdown($connexion) {
    $rows = $connexion->query(
        "SELECT profession, COUNT(*) AS total FROM membre
         WHERE confirm = 1 GROUP BY profession"
    )->fetchAll();

    $labels = ['travailleur' => 'Travailleurs', 'etudiant' => 'Étudiants', 'eleve' => 'Élèves'];
    $result = ['Travailleurs' => 0, 'Étudiants' => 0, 'Élèves' => 0];
    foreach ($rows as $r) {
        if (isset($labels[$r['profession']])) {
            $result[$labels[$r['profession']]] = (int) $r['total'];
        }
    }
    return $result;
}

function getGenreBreakdown($connexion) {
    $rows = $connexion->query(
        "SELECT genre, COUNT(*) AS total FROM membre WHERE confirm = 1 GROUP BY genre"
    )->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[$r['genre']] = (int) $r['total'];
    }
    return $result;
}

function getInscriptionsParMois($connexion) {
    $rows = $connexion->query(
        "SELECT DATE_FORMAT(date_inscription, '%Y-%m') AS mois, COUNT(*) AS total
         FROM membre
         WHERE date_inscription >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
         GROUP BY mois ORDER BY mois ASC"
    )->fetchAll();

    return $rows;
}

function getDerniersMembres($connexion, $limit = 8) {
    $query = $connexion->prepare(
        "SELECT prenom, nom, profession, date_inscription
         FROM membre WHERE confirm = 1
         ORDER BY date_inscription DESC LIMIT ?"
    );
    $query->bindValue(1, $limit, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
}

try {
    $connexion   = getDatabaseConnection();
    $userInfo    = getUserInfo($connexion, $_SESSION['number']);
    $stats       = getGlobalStats($connexion);
    $professions = getProfessionBreakdown($connexion);
    $genres      = getGenreBreakdown($connexion);
    $inscriptions = getInscriptionsParMois($connexion);
    $derniers    = getDerniersMembres($connexion);
    $error       = null;
} catch (Throwable $e) {
    error_log('Erreur dashboard: ' . $e->getMessage());
    $error = "Impossible de charger les statistiques pour le moment.";
    $stats = ['total' => 0, 'en_attente' => 0, 'en_ligne' => 0];
    $professions = ['Travailleurs' => 0, 'Étudiants' => 0, 'Élèves' => 0];
    $genres = [];
    $inscriptions = [];
    $derniers = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de bord - Dahira Ndiapandal Bou Serigne Saliou Mbacke</title>
<link rel="stylesheet" href="assets/css/daara-theme.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#F2F2F2;color:#4A4A4A;min-height:100vh;}

    .topnav{
        display:flex;align-items:center;justify-content:space-between;
        background:#111111;color:#FFFFFF;padding:14px 24px;
        position:sticky;top:0;z-index:50;box-shadow:0 3px 12px rgba(0,0,0,.15);
    }
    .brand{display:flex;align-items:center;gap:12px;}
    .brand img{width:38px;height:38px;border-radius:50%;border:2px solid #B58B45;object-fit:cover;}
    .brand-text b{font-size:15px;}
    .brand-text small{display:block;font-size:11.5px;opacity:.75;}
    .nav-links{display:flex;gap:10px;flex-wrap:wrap;}
    .nav-btn{
        color:#FFFFFF;text-decoration:none;font-size:13px;font-weight:600;
        padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.25);
        transition:all .25s ease;
    }
    .nav-btn:hover{background:#B58B45;border-color:#B58B45;}
    .nav-btn.red{border-color:#c62828;background:rgba(198,40,40,.15);}
    .nav-btn.red:hover{background:#c62828;}
    .nav-btn.active{background:#B58B45;border-color:#B58B45;}

    .wrap{max-width:1200px;margin:0 auto;padding:26px 20px 60px;}

    .page-title{font-size:22px;font-weight:700;color:#111111;margin-bottom:4px;}
    .page-sub{color:#6f6f6f;font-size:13.5px;margin-bottom:24px;}

    .error-banner{
        background:#fdecea;color:#c62828;padding:12px 16px;border-radius:10px;
        border-left:4px solid #c62828;margin-bottom:20px;font-size:14px;
    }

    .kpi-grid{
        display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:26px;
    }
    .kpi-card{
        background:#FFFFFF;border:1px solid #D0D0D0;border-radius:14px;
        padding:20px;box-shadow:0 3px 14px rgba(17,17,17,.06);
        transition:transform .2s ease, box-shadow .2s ease;
    }
    .kpi-card:hover{transform:translateY(-3px);box-shadow:0 10px 26px rgba(17,17,17,.12);}
    .kpi-num{font-size:30px;font-weight:800;color:#111111;}
    .kpi-lbl{font-size:12.5px;color:#6f6f6f;margin-top:4px;text-transform:uppercase;letter-spacing:.4px;}
    .kpi-accent{width:34px;height:4px;background:#B58B45;border-radius:4px;margin-top:10px;}

    .charts-grid{
        display:grid;grid-template-columns:1.4fr 1fr;gap:18px;margin-bottom:22px;
    }
    .chart-card, .list-card{
        background:#FFFFFF;border:1px solid #D0D0D0;border-radius:14px;
        padding:22px;box-shadow:0 3px 14px rgba(17,17,17,.06);
    }
    .chart-card h3, .list-card h3{
        font-size:14.5px;color:#111111;margin-bottom:16px;font-weight:700;
    }
    .charts-row-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px;}

    table.dtable{width:100%;border-collapse:collapse;font-size:13.5px;}
    table.dtable th{
        text-align:left;padding:9px 10px;background:#111111;color:#FFFFFF;
        font-size:11.5px;text-transform:uppercase;letter-spacing:.3px;
    }
    table.dtable td{padding:9px 10px;border-bottom:1px solid #D0D0D0;}
    table.dtable tr:hover td{background:#F2F2F2;}

    .badge{
        display:inline-block;padding:3px 9px;border-radius:20px;font-size:11.5px;
        font-weight:600;background:#B58B45;color:#FFFFFF;
    }

    .empty-msg{color:#6f6f6f;font-size:13.5px;text-align:center;padding:20px 0;}

    @media(max-width:900px){
        .kpi-grid{grid-template-columns:1fr 1fr;}
        .charts-grid, .charts-row-2{grid-template-columns:1fr;}
    }
    @media(max-width:600px){
        .kpi-grid{grid-template-columns:1fr;}
        .nav-links .nav-btn:not(.red){display:none;}
    }
</style>
</head>
<body>

<nav class="topnav">
    <div class="brand">
        <img src="https://i.pinimg.com/736x/66/f7/7f/66f77f7c9b829475172d96bd12679f8e.jpg" alt="logo">
        <div class="brand-text">
           <b>DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</b>
            <small>Tableau de bord</small>
        </div>
    </div>
    <div class="nav-links">
        <a href="accueil.php" class="nav-btn">🏠 Accueil</a>
        <a href="dashboard.php" class="nav-btn active">📊 Dashboard</a>
        <?php if (isset($userInfo['role']) && $userInfo['role'] === 'admin_daara'): ?>
            <a href="admin_daara.php" class="nav-btn">⚙️ Daara</a>
        <?php endif; ?>
        <?php if (isset($userInfo['role']) && $userInfo['role'] === 'admin_membre'): ?>
            <a href="admin_membres.php" class="nav-btn">👥 Membres</a>
        <?php endif; ?>
        <a href="logout.php" class="nav-btn red">Déconnexion</a>
    </div>
</nav>

<div class="wrap">
    <div class="page-title">📊 Tableau de bord</div>
    <div class="page-sub">Vue d'ensemble en temps réel du Dahira Ndiapandal Bou Serigne Saliou Mbacke</div>

    <?php if ($error): ?>
        <div class="error-banner"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- KPI -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-num"><?php echo $stats['total']; ?></div>
            <div class="kpi-lbl">Membres confirmés</div>
            <div class="kpi-accent"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-num"><?php echo $stats['en_ligne']; ?></div>
            <div class="kpi-lbl">En ligne actuellement</div>
            <div class="kpi-accent"></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-num"><?php echo $stats['en_attente']; ?></div>
            <div class="kpi-lbl">Inscriptions en attente</div>
            <div class="kpi-accent"></div>
        </div>
    </div>

    <!-- Charts row 1 -->
    <div class="charts-grid">
        <div class="chart-card">
            <h3>📈 Inscriptions des 12 derniers mois</h3>
            <canvas id="chartInscriptions" height="230"></canvas>
        </div>
        <div class="chart-card">
            <h3>🧑‍🤝‍🧑 Répartition par genre</h3>
            <canvas id="chartGenre" height="230"></canvas>
        </div>
    </div>

    <!-- Charts row 2 -->
    <div class="charts-row-2">
        <div class="chart-card">
            <h3>👔 Répartition par profession</h3>
            <canvas id="chartProfession" height="220"></canvas>
        </div>
    </div>

    <!-- Derniers membres -->
    <div class="list-card">
        <h3>🆕 Derniers membres inscrits</h3>
        <?php if (count($derniers) > 0): ?>
        <table class="dtable">
            <thead><tr><th>Nom complet</th><th>Profession</th><th>Inscription</th></tr></thead>
            <tbody>
            <?php foreach ($derniers as $m): ?>
                <tr>
                    <td><?php echo htmlspecialchars($m['prenom'] . ' ' . $m['nom']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($m['profession'] ?? '')); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($m['date_inscription'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-msg">Aucun membre pour le moment.</div>
        <?php endif; ?>
    </div>
</div>

<script>
const NOIR = '#111111';
const OR   = '#B58B45';
const OR_CLAIR = '#ddba73';
const GRIS = '#D0D0D0';

Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
Chart.defaults.color = '#4A4A4A';

// ── Inscriptions par mois (ligne) ──
new Chart(document.getElementById('chartInscriptions'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(fn($r) => $r['mois'], $inscriptions)); ?>,
        datasets: [{
            label: 'Nouveaux membres',
            data: <?php echo json_encode(array_map(fn($r) => (int) $r['total'], $inscriptions)); ?>,
            borderColor: NOIR,
            backgroundColor: 'rgba(181,139,69,0.15)',
            borderWidth: 2,
            pointBackgroundColor: OR,
            pointRadius: 4,
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: GRIS } },
            x: { grid: { display: false } }
        }
    }
});

// ── Genre (doughnut) ──
new Chart(document.getElementById('chartGenre'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($genres)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($genres)); ?>,
            backgroundColor: [NOIR, OR, OR_CLAIR, GRIS],
            borderWidth: 0
        }]
    },
    options: {
        plugins: { legend: { position: 'bottom' } },
        cutout: '65%'
    }
});

// ── Profession (barres) ──
new Chart(document.getElementById('chartProfession'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($professions)); ?>,
        datasets: [{
            label: 'Membres',
            data: <?php echo json_encode(array_values($professions)); ?>,
            backgroundColor: [NOIR, OR, OR_CLAIR],
            borderRadius: 6,
            maxBarThickness: 60
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: GRIS } },
            x: { grid: { display: false } }
        }
    }
});

</script>
</body>
</html>
