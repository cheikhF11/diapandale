<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/securite/securisation.php';
require_once __DIR__ . '/sendMail/mail.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['number']) || !isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

function hasRole($roleValue, ...$allowedRoles) {
    $roleValue = strtolower(trim((string) ($roleValue ?? '')));
    if ($roleValue === '') {
        return false;
    }

    $roles = preg_split('/[\s,]+/', $roleValue, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($roles as $role) {
        if (in_array($role, $allowedRoles, true)) {
            return true;
        }
    }

    return false;
}

function isAdminDaara($connexion, $num) {
    $query = $connexion->prepare("SELECT role FROM membre WHERE num = ? LIMIT 1");
    $query->execute([$num]);
    $result = $query->fetch(PDO::FETCH_ASSOC);
    return $result && hasRole($result['role'] ?? '', 'admin_daara', 'admin_membre');
}

function getDaaraInfo($connexion) {
    $query = $connexion->query("SELECT * FROM daara_info ORDER BY id DESC LIMIT 1");
    return $query->fetch(PDO::FETCH_ASSOC);
}

function updateDaaraInfo($connexion, $data, $modifiePar) {
    $fields = [
        'theme',
        'conferencier',
        'date_seance',
        'lieu',
        'contact',
        'programme',
        'description',
        'modifie_par'
    ];

    $values = [];
    foreach ($fields as $field) {
        $values[] = $field === 'modifie_par' ? $modifiePar : ($data[$field] ?? null);
    }

    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO daara_info (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';

    $query = $connexion->prepare($sql);
    $query->execute($values);
    
    // Enregistrer dans l'historique
    $histoQuery = $connexion->prepare(
        "INSERT INTO daara_historique (action, details, modifie_par) 
         VALUES (?, ?, ?)"
    );
    
    $details = "Thème: {$data['theme']}, Conférencier: {$data['conferencier']}";
    $histoQuery->execute(['Modification des informations du Daara', $details, $modifiePar]);
    
    return true;
}

function getHistorique($connexion, $limit = 10) {
    $query = $connexion->prepare(
        "SELECT h.*, m.prenom, m.nom 
         FROM daara_historique h
         LEFT JOIN membre m ON h.modifie_par = m.num
         ORDER BY h.date_action DESC 
         LIMIT ?"
    );
    $query->execute([$limit]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function getActiveMemberEmails($connexion) {
    $query = $connexion->query(
        "SELECT email FROM membre WHERE confirm = 1 AND email IS NOT NULL AND email != '' ORDER BY email ASC"
    );
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function sendDaaraInfoNotification($connexion, $data) {
    $emails = getActiveMemberEmails($connexion);

    if (empty($emails)) {
        return false;
    }

    $theme = htmlspecialchars($data['theme'], ENT_QUOTES, 'UTF-8');
    $conferencier = htmlspecialchars($data['conferencier'], ENT_QUOTES, 'UTF-8');
    $dateSeance = htmlspecialchars($data['date_seance'], ENT_QUOTES, 'UTF-8');
    $lieu = htmlspecialchars($data['lieu'], ENT_QUOTES, 'UTF-8');
    $programme = nl2br(htmlspecialchars($data['programme'], ENT_QUOTES, 'UTF-8'));
    $description = !empty($data['description']) ? nl2br(htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8')) : 'Aucune description supplémentaire.';

    $subject = 'Nouvelle information du Daara - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE';
    $message = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #111; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #d0d0d0; border-radius: 12px;'>
                <h2 style='color: #111111; margin-bottom: 16px;'>Nouvelle information du Daara</h2>
                <p>Bonjour à tous,</p>
                <p>Une nouvelle information a été publiée sur le site de la DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE.</p>
                <p><strong>Thème :</strong> {$theme}<br>
                <strong>Conférencier :</strong> {$conferencier}<br>
                <strong>Date :</strong> {$dateSeance}<br>
                <strong>Lieu :</strong> {$lieu}</p>
                <p><strong>Programme :</strong><br>{$programme}</p>
                <p><strong>Description :</strong><br>{$description}</p>
                <p>Merci de rester connecté pour suivre les prochaines activités et les moments importants du Dahira.</p>
                
                <p>Avec respect,<br>DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</p>
                 <p style='margin-top: 18px; font-weight: 700; color: #111;'>Dieuredieufe Borom Touba Diarama Mame Cheikh Ibrahima Fall</p>
            </div>
        </body>
        </html>
    ";

    $sent = 0;
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && envoyerMail($email, $subject, $message)) {
            $sent++;
        }
    }

    return $sent > 0;
}

$error = '';
$success = '';

try {
    $connexion = getDatabaseConnection();
    
    // Vérifier si l'utilisateur est admin du Daara
    if (!isAdminDaara($connexion, $_SESSION['number'])) {
        header('Location: accueil.php');
        exit;
    }
    
    $daaraInfo = getDaaraInfo($connexion);
    $historique = getHistorique($connexion);
    
    // Traitement du formulaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $theme = securisation($_POST['theme'] ?? '');
        $conferencier = securisation($_POST['conferencier'] ?? '');
        $date_seance = securisation($_POST['date_seance'] ?? '');
        $lieu = securisation($_POST['lieu'] ?? '');
        $contact = securisation($_POST['contact'] ?? '');
        $programme = securisation($_POST['programme'] ?? '');
        $description = securisation($_POST['description'] ?? '');
        if (empty($theme) || empty($conferencier) || empty($date_seance) || 
            empty($lieu) || empty($contact) || empty($programme)) {
            $error = "Merci de remplir tous les champs requis.";
        } else {
            $data = [
                'theme' => $theme,
                'conferencier' => $conferencier,
                'date_seance' => $date_seance,
                'lieu' => $lieu,
                'contact' => $contact,
                'programme' => $programme,
                'description' => $description,
            ];
            
            if (updateDaaraInfo($connexion, $data, $_SESSION['number'])) {
                $mailSent = sendDaaraInfoNotification($connexion, $data);
                $success = $mailSent
                    ? "Les informations ont bien été mises à jour et un message a été envoyé à tous les membres."
                    : "Les informations ont bien été mises à jour.";
                $daaraInfo = getDaaraInfo($connexion);
                $historique = getHistorique($connexion);
            }
        }
    }
    
} catch (Throwable $e) {
    error_log("Erreur DB: " . $e->getMessage());
    $error = "Une petite erreur s’est produite. Merci de réessayer.";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #f8f4ee 0%, #f2f2f2 28%, #f7f7f7 100%);
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
            color: #333;
        }
        
        /* Animations de fond */
        .bg-animation {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        
        .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(201, 162, 77, 0.08);
            animation: float 25s infinite ease-in-out;
        }
        
        .circle:nth-child(1) {
            width: 400px;
            height: 400px;
            top: -200px;
            right: -200px;
            animation-delay: 0s;
        }
        
        .circle:nth-child(2) {
            width: 300px;
            height: 300px;
            bottom: -150px;
            left: -150px;
            animation-delay: 3s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.3;
            }
            50% {
                transform: translateY(-40px) rotate(180deg);
                opacity: 0.6;
            }
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid #D0D0D0;
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(17,17,17,0.08);
            overflow: hidden;
            position: relative;
            z-index: 1;
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #B58B45, #d6b57f, #B58B45);
            background-size: 200% 100%;
            animation: gradient-shift 3s ease infinite;
        }
        
        @keyframes gradient-shift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .header {
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 48%, #b58b45 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            box-shadow: inset 0 -1px 0 rgba(255,255,255,0.08);
        }
        
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(201, 162, 77, 0.3);
            color: white;
            border: 2px solid rgba(225, 199, 122, 0.5);
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: rgba(201, 162, 77, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(201, 162, 77, 0.3);
        }
        
        .content {
            padding: 30px;
            animation: fadeIn 1s ease-out 0.3s both;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .alert-error {
            background: #ffe6e6;
            color: #d32f2f;
            border-left: 4px solid #d32f2f;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        
        .form-section {
            background: #F2F2F2;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(201, 162, 77, 0.1);
            transition: transform 0.3s ease;
        }
        
        .form-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(201, 162, 77, 0.15);
        }
        
        .form-section h2 {
            color: #111111;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 45%, #b58b45 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
            box-shadow: 0 12px 20px rgba(17,17,17,0.12);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 24px rgba(181, 139, 69, 0.22);
        }
        
        .historique-section {
            margin-top: 30px;
        }
        
        .historique-section h2 {
            color: #667eea;
            margin-bottom: 20px;
        }
        
        .historique-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            animation: fadeIn 0.8s ease-out;
        }
        
        .historique-table thead {
            background: linear-gradient(135deg, #111111, #1d1d1d);
            color: white;
        }
        
        .historique-table th,
        .historique-table td {
            padding: 15px;
            text-align: left;
        }
        
        .historique-table tbody tr {
            border-bottom: 1px solid #d6b57f;
            transition: all 0.3s ease;
        }
        
        .historique-table tbody tr:hover {
            background: #F2F2F2;
            transform: scale(1.01);
        }
        
        .current-info {
            background: linear-gradient(135deg, #F2F2F2, #FFFFFF);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #B58B45;
            box-shadow: 0 5px 15px rgba(201, 162, 77, 0.1);
            animation: slideIn 0.6s ease-out;
        }
        
        .current-info h3 {
            color: #111111;
            margin-bottom: 10px;
        }
        
        .current-info p {
            margin: 5px 0;
            color: #333;
        }
        
        .current-info strong {
            color: #B58B45;
        }
        
        /* Footer */
        .footer {
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 100%);
            color: white;
            padding: 20px;
            text-align: center;
            margin-top: 30px;
            border-top: 4px solid #B58B45;
        }
        
        .footer-content {
            font-size: 14px;
            opacity: 0.9;
        }

        @media (max-width: 900px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .header {
                padding: 24px 16px 28px;
            }

            .back-btn {
                position: static;
                display: inline-block;
                margin-bottom: 14px;
            }

            .content {
                padding: 18px 14px;
            }

            .form-section,
            .current-info {
                padding: 16px 14px;
            }

            .historique-section {
                overflow-x: auto;
            }

            .historique-table {
                min-width: 560px;
            }

            .submit-btn {
                padding: 14px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
    </div>
    <div class="container">
        <div class="header">
            <a href="accueil.php" class="back-btn">← Retour</a>
            <h1>⚙️ Panneau d'Administration</h1>
            <p>Gestion des informations du Daara</p>
        </div>
        
        <div class="content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <!-- Informations actuelles -->
            <?php if ($daaraInfo): ?>
                <div class="current-info">
                    <h3>📋 Informations actuelles</h3>
                    <p><strong>Thème :</strong> <?php echo htmlspecialchars($daaraInfo['theme']); ?></p>
                    <p><strong>Conférencier :</strong> <?php echo htmlspecialchars($daaraInfo['conferencier']); ?></p>
                    <p><strong>Prochaine séance :</strong> <?php echo date('d/m/Y à H:i', strtotime($daaraInfo['date_seance'])); ?></p>
                    <p><strong>Lieu :</strong> <?php echo htmlspecialchars($daaraInfo['lieu']); ?></p>
                    <p><strong>Contact :</strong> <?php echo htmlspecialchars($daaraInfo['contact']); ?></p>
                    <p><strong>Programme :</strong> <?php echo htmlspecialchars($daaraInfo['programme']); ?></p>
                    <p><strong>Description :</strong> <?php echo htmlspecialchars($daaraInfo['description'] ?? ''); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Formulaire de modification -->
            <div class="form-section">
                <h2>✏️ Modifier les informations</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="theme">Thème du Dahira *</label>
                        <input 
                            type="text" 
                            id="theme" 
                            name="theme" 
                            value="<?php echo isset($_POST['theme']) ? htmlspecialchars($_POST['theme']) : ($daaraInfo ? htmlspecialchars($daaraInfo['theme']) : ''); ?>"
                            required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="conferencier">Conférencier *</label>
                            <input 
                                type="text" 
                                id="conferencier" 
                                name="conferencier" 
                                value="<?php echo isset($_POST['conferencier']) ? htmlspecialchars($_POST['conferencier']) : ($daaraInfo ? htmlspecialchars($daaraInfo['conferencier']) : ''); ?>"
                                required>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_seance">Date et heure de la séance *</label>
                            <input 
                                type="datetime-local" 
                                id="date_seance" 
                                name="date_seance" 
                                value="<?php echo isset($_POST['date_seance']) ? $_POST['date_seance'] : ($daaraInfo ? date('Y-m-d\TH:i', strtotime($daaraInfo['date_seance'])) : ''); ?>"
                                required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="lieu">Lieu *</label>
                            <input 
                                type="text" 
                                id="lieu" 
                                name="lieu" 
                                value="<?php echo isset($_POST['lieu']) ? htmlspecialchars($_POST['lieu']) : ($daaraInfo ? htmlspecialchars($daaraInfo['lieu']) : ''); ?>"
                                required>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact">Contact *</label>
                            <input 
                                type="tel" 
                                id="contact" 
                                name="contact" 
                                value="<?php echo isset($_POST['contact']) ? htmlspecialchars($_POST['contact']) : ($daaraInfo ? htmlspecialchars($daaraInfo['contact']) : ''); ?>"
                                required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="programme">Programme *</label>
                        <textarea 
                            id="programme" 
                            name="programme" 
                            required><?php echo isset($_POST['programme']) ? htmlspecialchars($_POST['programme']) : ($daaraInfo ? htmlspecialchars($daaraInfo['programme']) : ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="description">Description du Dahira</label>
                        <textarea id="description" name="description" rows="3"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ($daaraInfo ? htmlspecialchars($daaraInfo['description'] ?? '') : ''); ?></textarea>
                    </div>


                    
                    <button type="submit" class="submit-btn">💾 Enregistrer les modifications</button>
                </form>
            </div>
            
            <!-- Historique des modifications -->
            <div class="historique-section">
                <h2>📜 Historique des modifications</h2>
                <?php if (count($historique) > 0): ?>
                    <table class="historique-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Action</th>
                                <th>Détails</th>
                                <th>Modifié par</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historique as $entry): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($entry['date_action'])); ?></td>
                                    <td><?php echo htmlspecialchars($entry['action']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['details'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($entry['prenom'] . ' ' . $entry['nom']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #999; padding: 20px;">Aucun historique disponible</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Footer -->
    <div class="footer">
         Conçu par BICHRY_DEV © <?php echo date('Y'); ?>
    </div>
    </div>
    
    <script>
        // Animation des champs au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                group.style.opacity = '0';
                setTimeout(() => {
                    group.style.opacity = '1';
                    group.style.animation = `slideIn 0.5s ease-out ${index * 0.1}s both`;
                }, 100);
            });
            
            // Animation des inputs
            const inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.transform = 'scale(1.01)';
                });
                
                input.addEventListener('blur', function() {
                    this.style.transform = 'scale(1)';
                });
                
                // Animation de typing
                input.addEventListener('input', function() {
                    this.style.transition = 'transform 0.1s';
                    this.style.transform = 'scale(1.02)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 100);
                });
            });
            
            // Effet ripple sur le bouton submit
            const submitBtn = document.querySelector('.submit-btn');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        width: ${size}px;
                        height: ${size}px;
                        background: rgba(255,255,255,0.5);
                        border-radius: 50%;
                        pointer-events: none;
                        left: ${x}px;
                        top: ${y}px;
                        transform: scale(0);
                        animation: ripple-effect 0.6s ease-out;
                    `;
                    this.appendChild(ripple);
                    setTimeout(() => ripple.remove(), 600);
                });
            }
            
            // Animation des lignes du tableau
            const tableRows = document.querySelectorAll('.historique-table tbody tr');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                setTimeout(() => {
                    row.style.opacity = '1';
                    row.style.animation = `slideIn 0.5s ease-out ${index * 0.05}s both`;
                }, 500);
            });
        });
        
        // Animation CSS pour le ripple
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple-effect {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>