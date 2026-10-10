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

function isAdminDaara($connexion, $num) {
    $query = $connexion->prepare("SELECT role FROM membre WHERE num = ? LIMIT 1");
    $query->execute([$num]);
    $result = $query->fetch(PDO::FETCH_ASSOC);
    return $result && $result['role'] === 'admin_daara';
}

function getGroupLinks($connexion) {
    $query = $connexion->query("SELECT * FROM group_links ORDER BY id DESC LIMIT 1");
    return $query->fetch(PDO::FETCH_ASSOC);
}

function saveGroupLinks($connexion, $telegramUrl, $whatsappUrl, $adminNum) {
    $query = $connexion->prepare(
        "INSERT INTO group_links (telegram_url, whatsapp_url, modifie_par) VALUES (?, ?, ?)"
    );
    return $query->execute([$telegramUrl, $whatsappUrl, $adminNum]);
}

$error = '';
$success = '';

try {
    $connexion = getDatabaseConnection();
    $userInfo = getUserInfo($connexion, $_SESSION['number']);
    $isAdmin = isAdminDaara($connexion, $_SESSION['number']);
    $groupLinks = getGroupLinks($connexion);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isAdmin) {
            $error = 'Accès refusé.';
        } else {
            $telegramUrl = trim((string) securisation($_POST['telegram_url'] ?? ''));
            $whatsappUrl = trim((string) securisation($_POST['whatsapp_url'] ?? ''));

            if ($telegramUrl === '' && $whatsappUrl === '') {
                $error = 'Veuillez renseigner au moins un lien de groupe.';
            } else {
                $saved = saveGroupLinks($connexion, $telegramUrl, $whatsappUrl, $_SESSION['number']);
                if ($saved) {
                    $success = 'Les liens des groupes ont été enregistrés.';
                    $groupLinks = getGroupLinks($connexion);
                } else {
                    $error = 'Une erreur est survenue lors de l\'enregistrement.';
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Erreur group links: ' . $e->getMessage());
    $error = 'Une erreur est survenue.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Groupes – DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f2f2f2;
            color: #1f1f1f;
        }
        .container {
            max-width: 960px;
            margin: 32px auto;
            padding: 0 16px 32px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            background: #111;
            color: white;
            padding: 18px 22px;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.12);
            margin-bottom: 20px;
        }
        .topbar h1 {
            margin: 0;
            font-size: 1.35rem;
        }
        .nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .nav a {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.08);
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.15);
            font-size: 13px;
            font-weight: 600;
        }
        .card {
            background: white;
            border: 1px solid #d7d7d7;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .alert.error { background: #ffe9e9; color: #a12626; border: 1px solid #f0b3b3; }
        .alert.success { background: #eaf9ee; color: #176d38; border: 1px solid #bfe5c9; }
        .group-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-top: 18px;
        }
        .group-box {
            border: 1px solid #e0d7c3;
            background: linear-gradient(135deg, #fffdf8, #f7f2e9);
            padding: 18px;
            border-radius: 16px;
        }
        .group-box h3 {
            margin: 0 0 12px;
            color: #111;
        }
        .group-btn {
            display: inline-block;
            width: 100%;
            text-align: center;
            padding: 12px 16px;
            border-radius: 12px;
            color: white;
            text-decoration: none;
            font-weight: 700;
            margin-top: 8px;
        }
        .telegram { background: linear-gradient(135deg, #2aabee, #1b8bd7); }
        .whatsapp { background: linear-gradient(135deg, #25d366, #1fa957); }
        form {
            display: grid;
            gap: 14px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d0d0d0;
            font-size: 15px;
        }
        button {
            background: #111;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
        }
        .muted {
            color: #6a6a6a;
            margin: 0;
        }
        @media (max-width: 600px) {
            .container {
                margin: 20px auto;
                padding: 0 12px 24px;
            }
            .topbar {
                flex-direction: column;
                align-items: flex-start;
                padding: 16px 14px;
            }
            .nav {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .nav a {
                flex: 1;
                text-align: center;
                padding: 10px 12px;
            }
            .card {
                padding: 16px 14px;
            }
            .group-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <h1>💬 Groupes du Dahira</h1>
            <div class="nav">
                <a href="accueil.php">Accueil</a>
                <?php if ($isAdmin): ?>
                    <a href="admin_daara.php">Administration</a>
                <?php endif; ?>
                <a href="logout.php">Déconnexion</a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="card">
                <h2 style="margin-top:0;">✏️ Gérer les liens des groupes</h2>
                <form method="POST">
                    <div>
                        <label for="telegram_url">Lien Telegram</label>
                        <input type="url" id="telegram_url" name="telegram_url" value="<?php echo htmlspecialchars($groupLinks['telegram_url'] ?? ''); ?>" placeholder="https://t.me/...">
                    </div>
                    <div>
                        <label for="whatsapp_url">Lien WhatsApp</label>
                        <input type="url" id="whatsapp_url" name="whatsapp_url" value="<?php echo htmlspecialchars($groupLinks['whatsapp_url'] ?? ''); ?>" placeholder="https://chat.whatsapp.com/...">
                    </div>
                    <button type="submit">Enregistrer</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0;">🔗 Rejoindre les groupes</h2>
            <div class="group-grid">
                <?php if (!empty($groupLinks['telegram_url'])): ?>
                    <div class="group-box">
                        <h3>Telegram</h3>
                        <a class="group-btn telegram" href="<?php echo htmlspecialchars($groupLinks['telegram_url']); ?>" target="_blank" rel="noopener noreferrer">Rejoindre Telegram</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($groupLinks['whatsapp_url'])): ?>
                    <div class="group-box">
                        <h3>WhatsApp</h3>
                        <a class="group-btn whatsapp" href="<?php echo htmlspecialchars($groupLinks['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer">Rejoindre WhatsApp</a>
                    </div>
                <?php endif; ?>

                <?php if (empty($groupLinks['telegram_url']) && empty($groupLinks['whatsapp_url'])): ?>
                    <div class="group-box" style="grid-column: 1 / -1;">
                        <p class="muted">Aucun lien de groupe n’a été ajouté pour le moment.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
