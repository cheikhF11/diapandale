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

function getDaaraInfo($connexion) {
    $query = $connexion->query("SELECT * FROM daara_info ORDER BY id DESC LIMIT 1");
    return $query->fetch(PDO::FETCH_ASSOC);
}

function getGroupLinks($connexion) {
    $query = $connexion->query("SELECT * FROM group_links ORDER BY id DESC LIMIT 1");
    return $query->fetch(PDO::FETCH_ASSOC);
}

function getMembersByProfession($connexion, $profession) {
    $query = $connexion->prepare(
        "SELECT id, num, prenom, nom, email, adresse, photo_profil, genre, profession, profession_details, niveau_etude, filiere, classe, date_inscription
         FROM membre WHERE profession = ? AND confirm = 1 ORDER BY nom ASC"
    );
    $query->execute([$profession]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function getStatistics($connexion) {
    $stats = [];
    $query = $connexion->query("SELECT COUNT(*) as total FROM membre WHERE confirm = 1");
    $stats['total'] = $query->fetch(PDO::FETCH_ASSOC)['total'];
    foreach (['travailleur', 'etudiant', 'eleve'] as $prof) {
        $query = $connexion->prepare("SELECT COUNT(*) as total FROM membre WHERE profession = ? AND confirm = 1");
        $query->execute([$prof]);
        $stats[$prof] = $query->fetch(PDO::FETCH_ASSOC)['total'];
    }
    return $stats;
}

function updateOnlineStatus($connexion, $num) {
    $query = $connexion->prepare("UPDATE membre SET statut_en_ligne = 1, derniere_connexion = NOW() WHERE num = ?");
    $query->execute([$num]);
}

try {
    $connexion = getDatabaseConnection();

    $userInfo       = getUserInfo($connexion, $_SESSION['number']);
    $daaraInfo      = getDaaraInfo($connexion);
    $groupLinks     = getGroupLinks($connexion);
    $stats          = getStatistics($connexion);
    updateOnlineStatus($connexion, $_SESSION['number']);

    $searchResults  = [];
    $searchTerm     = '';

    if (isset($_GET['search']) && !empty(securisation($_GET['search'] ?? ''))) {
        $searchTerm = securisation($_GET['search'] ?? '');
        $searchQuery = $connexion->prepare(
            "SELECT num, prenom, nom, email, adresse, photo_profil, genre, profession, profession_details, niveau_etude, filiere, classe
             FROM membre WHERE (prenom LIKE ? OR nom LIKE ? OR num LIKE ?) AND confirm = 1
             ORDER BY nom ASC LIMIT 50"
        );
        $like = "%{$searchTerm}%";
        $searchQuery->execute([$like, $like, $like]);
        $searchResults = $searchQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    $travailleurs = getMembersByProfession($connexion, 'travailleur');
    $etudiants    = getMembersByProfession($connexion, 'etudiant');
    $eleves       = getMembersByProfession($connexion, 'eleve');

} catch (Throwable $e) {
    error_log("Erreur DB: " . $e->getMessage());
    $error = "Une erreur est survenue";
}

// ── PHP helper to render a members table ──
function renderMembersTable($members, $showProfession = false, $detailField = null, $showFiliere = false) {
    if (empty($members)) {
        return '';
    }

    $html = '<div class="member-grid">';

    foreach ($members as $i => $m) {
        $fullName = trim((string) (($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')));
        $profession = (string) ($m['profession'] ?? '');
        $detailValue = $m[$detailField] ?? null;
        $filiereValue = $m['filiere'] ?? null;
        $niveauValue = $m['niveau_etude'] ?? null;
        $classeValue = $m['classe'] ?? null;

        $roleLabel = 'Membre';
        if ($profession === 'travailleur') {
            $roleLabel = $detailValue ?: 'Travailleur';
        } elseif ($profession === 'etudiant') {
            $roleLabel = trim((string) ($niveauValue . ' ' . $filiereValue));
            if ($roleLabel === '') {
                $roleLabel = 'Étudiant';
            }
        } elseif ($profession === 'eleve') {
            $roleLabel = $classeValue ?: 'Élève';
        }

        $photo = !empty($m['photo_profil'])
            ? htmlspecialchars((string) $m['photo_profil'], ENT_QUOTES, 'UTF-8')
            : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111111&color=F2F2F2&size=200';

        $memberData = [
            'name' => $fullName,
            'num' => $m['num'] ?? '',
            'email' => $m['email'] ?? '',
            'genre' => $m['genre'] ?? '',
            'profession' => $roleLabel,
            'adresse' => $m['adresse'] ?? '',
            'niveau' => $niveauValue ?? '',
            'filiere' => $filiereValue ?? '',
            'classe' => $classeValue ?? '',
            'photo' => $photo,
        ];

        $memberJson = htmlspecialchars(json_encode($memberData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        $genderClass = strtolower((string) ($m['genre'] ?? '')) === 'femme' ? 'badge-femme' : 'badge-homme';

        $html .= '<button type="button" class="member-card" data-member="' . $memberJson . '">';
        $html .= '<div class="member-avatar-wrap"><img class="member-avatar" src="' . $photo . '" alt="Photo de ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '"></div>';
        $html .= '<div class="member-name">' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div class="member-meta"><span class="badge ' . $genderClass . '">' . htmlspecialchars((string) ($m['genre'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</span>';
        if ($showProfession) {
            $html .= '<span class="badge badge-prof">' . htmlspecialchars(ucfirst((string) (($m['profession'] ?? ''))), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '</div>';
        $html .= '<div class="member-role">' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '</button>';
    }

    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accueil – DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        :root {
            --primary: #111111;
            --primary-soft: #1d1d1d;
            --secondary: #B58B45;
            --secondary-soft: #d6b57f;
            --bg: #F2F2F2;
            --card: #FFFFFF;
            --title: #111111;
            --text: #4A4A4A;
            --muted: #6f6f6f;
            --border: #D0D0D0;
            --nav-text: #FFFFFF;
            --rad: 16px;
            --shad: 0 10px 28px rgba(17,17,17,.08);
            --shad-h: 0 16px 36px rgba(181,139,69,.18);

            /* Alias de compatibilité (anciens noms de variables encore utilisés
               dans certaines règles ci-dessous) — évite tout style "cassé" */
            --green: var(--primary);
            --green2: var(--primary-soft);
            --gold: var(--secondary);
            --gold-l: var(--secondary-soft);
            --white: var(--card);
            --text-light: var(--muted);
        }

        body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:linear-gradient(180deg,#f8f5ef 0%, #f2f2f2 30%, #f8f8f8 100%); color:var(--text); min-height:100vh; }

        /* ══ TOP NAV ══ */
        .topnav {
            background:linear-gradient(135deg, #111111 0%, #1d1d1d 58%, #b58b45 100%);
            height:72px; padding:0 20px;
            display:flex; align-items:center; justify-content:space-between;
            position:sticky; top:0; z-index:200;
            box-shadow:0 12px 28px rgba(17,17,17,.18);
            border-bottom:1px solid rgba(255,255,255,.12);
        }

        .brand { display:flex; align-items:center; gap:12px; }
        .brand img { width:42px; height:42px; border-radius:50%; border:2px solid var(--secondary); object-fit:cover; }
        .brand-text { color:var(--nav-text); }
        .brand-text b { display:block; font-size:14px; letter-spacing:.5px; }
        .brand-text small { font-size:11px; opacity:.85; }

        .nav-links { display:flex; gap:8px; align-items:center; }
        .nav-btn {
            padding:8px 14px; border-radius:10px;
            border:1px solid rgba(255,255,255,.18);
            background:rgba(255,255,255,.06);
            color:var(--nav-text); font-size:12px; font-weight:600;
            text-decoration:none; cursor:pointer;
            transition:all .22s ease;
        }
        .nav-btn:hover { background:var(--secondary); color:#111; transform:translateY(-1px); }
        .nav-btn.red { border-color:rgba(255,255,255,.12); background:rgba(255,255,255,.03); }
        .nav-btn.red:hover { background:#fff; color:var(--primary); }

        /* ══ TABS BAR ══ */
        .tabs-bar {
            background:var(--white);
            border-bottom:2px solid #dde8de;
            display:flex; overflow-x:auto; scrollbar-width:none;
            position:sticky; top:60px; z-index:100;
            box-shadow:0 2px 8px rgba(0,0,0,.06);
        }
        .tabs-bar::-webkit-scrollbar { display:none; }

        .tab-btn {
            padding:13px 20px; border:none; background:none;
            font-size:13px; font-weight:600; color:var(--muted);
            cursor:pointer; white-space:nowrap;
            border-bottom:3px solid transparent; margin-bottom:-2px;
            display:flex; align-items:center; gap:6px;
            transition:color .2s, border-color .2s;
        }
        .tab-btn .cnt {
            background:#ececec; color:var(--muted);
            font-size:11px; padding:2px 7px; border-radius:20px;
            transition:all .2s;
        }
        .tab-btn:hover { color:var(--primary); }
        .tab-btn.active { color:var(--primary); border-bottom-color:var(--secondary); }
        .tab-btn.active .cnt { background:var(--secondary); color:var(--primary); }

        /* ══ LAYOUT ══ */
        .wrap { max-width:1280px; margin:0 auto; padding:22px 14px; }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; animation:fadeUp .38s ease; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

        /* ══ CARD ══ */
        .card {
            background:linear-gradient(180deg, #ffffff 0%, #fffdf9 100%);
            border:1px solid rgba(181,139,69,.22); border-radius:var(--rad);
            box-shadow:var(--shad); padding:22px; margin-bottom:18px;
            transition:box-shadow .25s, border-color .25s;
            position:relative;
        }
        .card::before {
            content:''; position:absolute; inset:0 auto auto 0; width:100%; height:4px;
            background:linear-gradient(90deg, var(--secondary), transparent);
            border-radius:var(--rad) var(--rad) 0 0;
        }
        .card:hover { box-shadow:var(--shad-h); border-color:rgba(181,139,69,.38); }
        .card-title { font-size:15px; font-weight:700; color:var(--title); margin-bottom:16px; display:flex; align-items:center; gap:8px; }

        .dashboard-grid {
            display:grid; grid-template-columns:1.5fr .9fr; gap:18px; align-items:stretch;
        }
        .dashboard-panel {
            background:#fafafa; border:1px solid var(--border); border-radius:14px; padding:16px;
        }
        .dashboard-metrics { display:grid; grid-template-columns:repeat(2,minmax(110px,1fr)); gap:12px; }
        .metric-pill {
            padding:12px 14px; border-radius:12px; background:var(--card); border:1px solid var(--border);
            display:flex; flex-direction:column; gap:6px; min-height:90px; justify-content:center;
        }
        .metric-pill strong { font-size:22px; color:var(--title); }
        .metric-pill span { color:var(--text); font-size:12px; }

        /* ══ INFO CHIPS ══ */
        .info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:12px; }
        .info-chip {
            background:#fafafa; border:1px solid var(--border);
            border-radius:12px; padding:12px 14px;
            transition:all .22s;
        }
        .info-chip:hover { border-color:var(--secondary); transform:translateY(-2px); box-shadow:0 4px 14px rgba(181,139,69,.12); }
        .info-chip strong { display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.5px; color:var(--secondary); margin-bottom:3px; }
        .info-chip span { font-size:13.5px; }

        /* ══ STATS ROW ══ */
        .stats-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:12px; margin-bottom:18px; }

        .stat-card {
            background:linear-gradient(135deg,#111111 0%, #232323 55%, #b58b45 100%);
            color:var(--nav-text); border-radius:var(--rad);
            padding:16px 12px; text-align:center; cursor:pointer;
            box-shadow:0 10px 22px rgba(17,17,17,.12);
            transition:all .28s; position:relative; overflow:hidden;
            border:1px solid rgba(255,255,255,.06);
        }
        .stat-card::after {
            content:''; position:absolute; top:-25px; right:-25px;
            width:70px; height:70px; border-radius:50%;
            background:rgba(181,139,69,.18);
        }
        .stat-card:hover { transform:translateY(-4px) scale(1.04); box-shadow:0 16px 28px rgba(17,17,17,.18); }
        .stat-card.msg { background:linear-gradient(135deg,#2a2a2a,#812a2a); }
        .stat-num { font-size:26px; font-weight:800; position:relative; z-index:1; }
        .stat-lbl { font-size:11px; opacity:.9; margin-top:2px; position:relative; z-index:1; }

        /* ══ SEARCH ══ */
        .search-form { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
        .s-input, .s-select {
            padding:10px 13px; border:2px solid #D0D0D0;
            border-radius:10px; font-size:14px; background:#f5faf6;
            color:var(--text); transition:all .22s;
        }
        .s-input { flex:1; min-width:200px; }
        .s-input:focus, .s-select:focus {
            outline:none; border-color:var(--gold); background:#fff;
            box-shadow:0 0 0 3px rgba(201,162,77,.1);
        }
        .s-btn {
            padding:10px 24px; background:linear-gradient(135deg,var(--gold),var(--gold-l));
            color:#fff; border:none; border-radius:10px;
            font-size:14px; font-weight:700; cursor:pointer;
            transition:all .22s;
        }
        .s-btn:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(201,162,77,.32); }

        .results-note {
            font-size:13px; color:var(--muted); padding:9px 13px;
            background:#f5faf6; border-radius:8px; border-left:3px solid var(--gold);
            margin-bottom:14px;
        }
         /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(31, 94, 67, 0.9);
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 12px;
            opacity: 0.8;
            z-index: 10;
        }

        /* ══ TABLE ══ */
        .table-wrap { width:100%; overflow-x:auto; border-radius:var(--rad); box-shadow:var(--shad); }

        table.dtable { width:100%; border-collapse:collapse; background:#fff; font-size:13.5px; min-width:480px; }
        table.dtable thead { background:linear-gradient(135deg,var(--green),var(--green2)); color:#fff; }
        table.dtable th { padding:12px 13px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
        table.dtable td { padding:11px 13px; border-bottom:1px solid #eef3ef; white-space:nowrap; }
        table.dtable tbody tr { transition:background .18s; }
        table.dtable tbody tr:hover { background:#f4fbf5; }
        table.dtable tbody tr:last-child td { border-bottom:none; }
        @keyframes rowFade { from{opacity:0;transform:translateX(-6px)} to{opacity:1;transform:translateX(0)} }
        table.dtable tbody tr.row-in { animation:rowFade .3s ease both; }

        /* ══ BADGES ══ */
        .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; transition:transform .18s; cursor:default; }
        .badge:hover { transform:scale(1.07); }
        .badge-homme  { background:#dbeafe; color:#1d4ed8; }
        .badge-femme  { background:#fce7f3; color:#be185d; }
        .badge-filiere{ background:#d1fae5; color:#065f46; }
        .badge-prof   { background:#ede9fe; color:#5b21b6; }
        .badge-pub    { display:inline-flex; align-items:center; padding:5px 10px; border-radius:20px; font-size:11px; font-weight:700; background:#fff5df; color:#8b5e0f; }

        .member-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
            gap:16px;
        }

        .member-card {
            background:linear-gradient(180deg, #fff, #faf7f2);
            border:1px solid #e6d7b2;
            border-radius:18px;
            padding:16px;
            text-align:left;
            cursor:pointer;
            transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
            box-shadow:0 10px 24px rgba(17,17,17,.05);
            color:inherit;
            width:100%;
        }

        .member-card:hover {
            transform:translateY(-4px);
            border-color:var(--secondary);
            box-shadow:0 18px 28px rgba(181,139,69,.14);
        }

        .member-avatar-wrap {
            display:flex;
            align-items:center;
            justify-content:center;
            margin-bottom:14px;
        }

        .member-avatar {
            width:90px;
            height:90px;
            border-radius:50%;
            object-fit:cover;
            border:3px solid var(--secondary-soft);
            background:#f3f3f3;
        }

        .member-name {
            font-size:18px;
            font-weight:700;
            color:var(--title);
            margin-bottom:8px;
        }

        .member-meta {
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-bottom:10px;
        }

        .member-role {
            font-size:13px;
            color:var(--text);
            line-height:1.5;
        }

        .member-modal {
            position:fixed;
            inset:0;
            display:flex;
            align-items:center;
            justify-content:center;
            z-index:999;
            opacity:0;
            pointer-events:none;
            transition:opacity .2s ease;
        }

        .member-modal.open {
            opacity:1;
            pointer-events:auto;
        }

        .member-modal-backdrop {
            position:absolute;
            inset:0;
            background:rgba(17,17,17,.6);
        }

        .member-modal-dialog {
            position:relative;
            width:min(520px, calc(100vw - 24px));
            max-height:90vh;
            overflow:auto;
            background:#fff;
            border-radius:20px;
            border:1px solid var(--border);
            box-shadow:0 25px 60px rgba(17,17,17,.2);
            z-index:1;
        }

        .member-modal-close {
            position:absolute;
            top:12px;
            right:12px;
            border:none;
            background:#f3f3f3;
            width:34px;
            height:34px;
            border-radius:50%;
            font-size:24px;
            cursor:pointer;
            color:var(--primary);
        }

        .member-modal-body {
            padding:22px 20px 18px;
        }

        .modal-profile {
            display:flex;
            gap:16px;
            align-items:center;
            margin-bottom:18px;
            padding-bottom:16px;
            border-bottom:1px solid #f0f0f0;
        }

        .modal-profile img {
            width:82px;
            height:82px;
            object-fit:cover;
            border-radius:50%;
            border:3px solid var(--secondary-soft);
        }

        .modal-profile h3 {
            font-size:22px;
            color:var(--title);
            margin-bottom:6px;
        }

        .modal-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:12px;
        }

        .modal-item {
            background:#fafafa;
            border:1px solid var(--border);
            border-radius:12px;
            padding:10px 12px;
        }

        .modal-item strong {
            display:block;
            font-size:11px;
            letter-spacing:.05em;
            text-transform:uppercase;
            color:var(--secondary);
            margin-bottom:4px;
        }

        .modal-item span {
            color:var(--text);
            font-size:13px;
            line-height:1.5;
        }

        /* ══ EMPTY ══ */
        .empty { text-align:center; padding:44px 20px; color:var(--muted); }
        .empty-icon { font-size:38px; margin-bottom:10px; }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 900px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .brand { max-width: 60%; }
            .brand-text small { display: none; }
        }

        @media(max-width:600px){
            .topnav {
                height: auto;
                padding: 12px 14px;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .brand {
                max-width: 100%;
                width: 100%;
            }
            .nav-links {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .nav-links .nav-btn {
                text-align: center;
                padding: 9px 8px;
                font-size: 11px;
            }
            .brand-text b { font-size:12px; }
            .stats-row { grid-template-columns:1fr 1fr; }
            .tab-btn { padding:11px 13px; font-size:12px; }
            .wrap { padding:14px 10px; }
            .search-form { flex-direction: column; }
            .s-input, .s-select, .s-btn { width: 100%; min-width: 0; }
            .card { padding: 16px 14px; }
            .footer {
                position: static;
                padding: 10px 12px;
            }
        }

        @media (max-width: 420px) {
            .stats-row { grid-template-columns: 1fr; }
            .dashboard-metrics { grid-template-columns: 1fr; }
            .nav-links { grid-template-columns: 1fr 1fr; }
            .nav-btn.red { grid-column: 1 / -1; }
            .tab-btn { padding: 10px 12px; }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <div class="brand">
        <img src="bmh1.jpeg" alt="logo">
        <div class="brand-text">
            <b>DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</b>
            <small>Bonjour, <?php echo htmlspecialchars($userInfo['prenom'] . ' ' . $userInfo['nom']); ?></small>
        </div>
    </div>
    <div class="nav-links">
        <a href="dashboard.php" class="nav-btn">📊 Dashboard</a>
        <a href="groupes.php" class="nav-btn">💬 Groupes</a>
        <?php if (isset($userInfo['role']) && $userInfo['role'] === 'admin_daara'): ?>
            <a href="admin_daara.php" class="nav-btn">⚙️ Daara</a>
        <?php endif; ?>
        <?php if (isset($userInfo['role']) && $userInfo['role'] === 'admin_membre'): ?>
            <a href="admin_membres.php" class="nav-btn">👥 Membres</a>
        <?php endif; ?>
        <a href="logout.php" class="nav-btn red">Déconnexion</a>
    </div>
</nav>

<!-- TABS BAR -->
<div class="tabs-bar">
    <button class="tab-btn active" data-tab="accueil">🏠 Accueil</button>
    <button class="tab-btn" data-tab="recherche">🔍 Recherche</button>
    <button class="tab-btn" data-tab="travailleurs">👔 Travailleurs <span class="cnt"><?php echo count($travailleurs); ?></span></button>
    <button class="tab-btn" data-tab="etudiants">🎓 Étudiants <span class="cnt"><?php echo count($etudiants); ?></span></button>
    <button class="tab-btn" data-tab="eleves">📚 Élèves <span class="cnt"><?php echo count($eleves); ?></span></button>
</div>

<div class="wrap">

    <!-- ═══ ACCUEIL ═══ -->
    <div class="tab-panel active" id="tab-accueil">

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card" onclick="switchTab('travailleurs')" title="Voir les travailleurs">
                <div class="stat-num"><?php echo $stats['total']; ?></div>
                <div class="stat-lbl">Total membres</div>
            </div>
            <div class="stat-card" onclick="switchTab('travailleurs')">
                <div class="stat-num"><?php echo $stats['travailleur']; ?></div>
                <div class="stat-lbl">👔 Travailleurs</div>
            </div>
            <div class="stat-card" onclick="switchTab('etudiants')">
                <div class="stat-num"><?php echo $stats['etudiant']; ?></div>
                <div class="stat-lbl">🎓 Étudiants</div>
            </div>
            <div class="stat-card" onclick="switchTab('eleves')">
                <div class="stat-num"><?php echo $stats['eleve']; ?></div>
                <div class="stat-lbl">📚 Élèves</div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">📊 Vue d’ensemble</div>
            <div class="dashboard-grid">
                <div class="dashboard-panel">
                    <canvas id="membersChart" height="180"></canvas>
                </div>
                <div class="dashboard-panel">
                    <div class="dashboard-metrics">
                        <div class="metric-pill">
                            <strong><?php echo $stats['total']; ?></strong>
                            <span>Membres actifs</span>
                        </div>
                        <div class="metric-pill">
                            <strong><?php echo $stats['travailleur']; ?></strong>
                            <span>Travailleurs</span>
                        </div>
                        <div class="metric-pill">
                            <strong><?php echo $stats['etudiant']; ?></strong>
                            <span>Étudiants</span>
                        </div>
                        <div class="metric-pill">
                            <strong><?php echo $stats['eleve']; ?></strong>
                            <span>Élèves</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daara Info -->
        <div class="card">
            <div class="card-title">📖 Informations du Daara</div>
            <?php if ($daaraInfo): ?>
            <div class="info-grid">
                <div class="info-chip"><strong>Thème</strong><span><?php echo htmlspecialchars($daaraInfo['theme']); ?></span></div>
                <div class="info-chip"><strong>Conférencier</strong><span><?php echo htmlspecialchars($daaraInfo['conferencier']); ?></span></div>
                <div class="info-chip"><strong>Prochaine séance</strong><span><?php echo date('l d F Y à H\hi', strtotime($daaraInfo['date_seance'])); ?></span></div>
                <div class="info-chip"><strong>Lieu</strong><span><?php echo htmlspecialchars($daaraInfo['lieu']); ?></span></div>
                <div class="info-chip"><strong>Contact</strong><span><?php echo htmlspecialchars($daaraInfo['contact']); ?></span></div>
                <div class="info-chip"><strong>Programme</strong><span><?php echo htmlspecialchars($daaraInfo['programme']); ?></span></div>
            </div>
            <?php else: ?>
            <p style="color:var(--muted)">Aucune information disponible.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title">💬 Groupes du Dahira</div>
            <?php if (!empty($groupLinks['telegram_url']) || !empty($groupLinks['whatsapp_url'])): ?>
                <div class="info-grid">
                    <?php if (!empty($groupLinks['telegram_url'])): ?>
                        <div class="info-chip">
                            <strong>Telegram</strong>
                            <span><a href="<?php echo htmlspecialchars($groupLinks['telegram_url']); ?>" target="_blank" rel="noopener noreferrer">Rejoindre le groupe</a></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($groupLinks['whatsapp_url'])): ?>
                        <div class="info-chip">
                            <strong>WhatsApp</strong>
                            <span><a href="<?php echo htmlspecialchars($groupLinks['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer">Rejoindre le groupe</a></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p style="color:var(--muted)">Aucun groupe disponible pour le moment.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ RECHERCHE ═══ -->
    <div class="tab-panel" id="tab-recherche">
        <div class="card">
            <div class="card-title">🔍 Rechercher un membre</div>
            <form method="GET" class="search-form">
                <input type="hidden" name="tab" value="recherche">
                <input type="text" name="search" class="s-input"
                       placeholder="Nom, prénom ou numéro..."
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
                <button type="submit" class="s-btn">Rechercher</button>
            </form>

            <?php if (!empty($searchTerm)): ?>
                <div class="results-note">
                    <?php echo count($searchResults); ?> résultat(s) pour
                    "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                </div>
                <?php if (count($searchResults) > 0): ?>
                    <div class="table-wrap"><?php echo renderMembersTable($searchResults, true); ?></div>
                <?php else: ?>
                    <div class="empty"><div class="empty-icon">🔎</div>Aucun résultat.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ TRAVAILLEURS ═══ -->
    <div class="tab-panel" id="tab-travailleurs">
        <div class="card">
            <div class="card-title">👔 Travailleurs <span class="badge badge-filiere"><?php echo count($travailleurs); ?></span></div>
            <?php if (count($travailleurs) > 0): ?>
                <div class="table-wrap"><?php echo renderMembersTable($travailleurs, false, 'profession_details'); ?></div>
            <?php else: ?><div class="empty"><div class="empty-icon">👔</div>Aucun travailleur.</div><?php endif; ?>
        </div>
    </div>

    <!-- ═══ ETUDIANTS ═══ -->
    <div class="tab-panel" id="tab-etudiants">
        <div class="card">
            <div class="card-title">🎓 Étudiants <span class="badge badge-filiere"><?php echo count($etudiants); ?></span></div>
            <?php if (count($etudiants) > 0): ?>
                <div class="table-wrap"><?php echo renderMembersTable($etudiants, false, 'niveau_etude', true); ?></div>
            <?php else: ?><div class="empty"><div class="empty-icon">🎓</div>Aucun étudiant.</div><?php endif; ?>
        </div>
    </div>

    <!-- ═══ ELEVES ═══ -->
    <div class="tab-panel" id="tab-eleves">
        <div class="card">
            <div class="card-title">📚 Élèves <span class="badge badge-filiere"><?php echo count($eleves); ?></span></div>
            <?php if (count($eleves) > 0): ?>
                <div class="table-wrap"><?php echo renderMembersTable($eleves, false, 'classe'); ?></div>
            <?php else: ?><div class="empty"><div class="empty-icon">📚</div>Aucun élève.</div><?php endif; ?>
        </div>
    </div>

</div><!-- wrap -->

 <!-- Footer -->
    <div class="footer">
         Conçu par BICHRY_DEV © <?php echo date('Y'); ?>
    </div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── TABS ──
const tabBtns = document.querySelectorAll('.tab-btn[data-tab]');

tabBtns.forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));

function switchTab(id) {
    document.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === id));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + id));
}

// Restore tab from URL
const uTab = new URLSearchParams(location.search).get('tab');
if (uTab) switchTab(uTab);

function createMemberModal() {
    const existing = document.getElementById('memberModal');
    if (existing) return;

    document.body.insertAdjacentHTML('beforeend', `
        <div class="member-modal" id="memberModal" aria-hidden="true">
            <div class="member-modal-backdrop" data-close="true"></div>
            <div class="member-modal-dialog" role="dialog" aria-modal="true">
                <button type="button" class="member-modal-close" aria-label="Fermer">×</button>
                <div class="member-modal-body" id="memberModalBody"></div>
            </div>
        </div>
    `);

    const modal = document.getElementById('memberModal');
    const closeBtn = modal.querySelector('.member-modal-close');
    const backdrop = modal.querySelector('.member-modal-backdrop');

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    };

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            closeModal();
        }
    });

    document.addEventListener('click', (event) => {
        const card = event.target.closest('.member-card');
        if (!card) return;

        const member = JSON.parse(card.dataset.member);
        const modalBody = document.getElementById('memberModalBody');

        modalBody.innerHTML = `
            <div class="modal-profile">
                <img src="${member.photo}" alt="${member.name}">
                <div>
                    <h3>${member.name}</h3>
                    <span class="badge-pub">${member.genre || 'Membre'}</span>
                </div>
            </div>
            <div class="modal-grid">
                <div class="modal-item"><strong>Numéro</strong><span>${member.num || 'Non renseigné'}</span></div>
                <div class="modal-item"><strong>Email</strong><span>${member.email || 'Non renseigné'}</span></div>
                <div class="modal-item"><strong>Profession</strong><span>${member.profession || 'Non renseignée'}</span></div>
                <div class="modal-item"><strong>Genre</strong><span>${member.genre || 'Non renseigné'}</span></div>
                ${member.niveau ? `<div class="modal-item"><strong>Niveau</strong><span>${member.niveau}</span></div>` : ''}
                ${member.filiere ? `<div class="modal-item"><strong>Filière</strong><span>${member.filiere}</span></div>` : ''}
                ${member.classe ? `<div class="modal-item"><strong>Classe</strong><span>${member.classe}</span></div>` : ''}
                <div class="modal-item" style="grid-column:1 / -1;"><strong>Adresse</strong><span>${member.adresse || 'Non renseignée'}</span></div>
            </div>
        `;

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    });
}

createMemberModal();

// ── CHARTS ──
const chartNode = document.getElementById('membersChart');
if (chartNode) {
    new Chart(chartNode, {
        type: 'doughnut',
        data: {
            labels: ['Travailleurs', 'Étudiants', 'Élèves'],
            datasets: [{
                data: [
                    <?php echo (int)($stats['travailleur'] ?? 0); ?>,
                    <?php echo (int)($stats['etudiant'] ?? 0); ?>,
                    <?php echo (int)($stats['eleve'] ?? 0); ?>
                ],
                backgroundColor: ['#111111', '#B58B45', '#D0D0D0'],
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#4A4A4A',
                        usePointStyle: true,
                        boxWidth: 10,
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (context) => `${context.label}: ${context.parsed} membre(s)`
                    }
                }
            }
        }
    });
}

// ── ONLINE STATUS ──
setInterval(() => fetch('update_online_status.php', { method:'POST' }), 120000);
</script>
</body>
</html>