<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/sendMail/mail.php';
require_once __DIR__ . '/securite/securisation.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Regénérer l'ID de session pour éviter la fixation de session
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

function generateOtp() {
    return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function getSmtpConfig() {
    return [
        'host' => trim((string) env('SMTP_HOST', '')),
        'username' => trim((string) env('SMTP_USERNAME', '')),
        'password' => (string) env('SMTP_PASSWORD', ''),
        'port' => (int) env('SMTP_PORT', 587),
        'secure' => trim((string) env('SMTP_SECURE', 'tls')),
        'from_name' => trim((string) env('SMTP_FROM_NAME', 'DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE')),
        'from_email' => trim((string) env('SMTP_FROM_EMAIL', '')),
    ];
}

function sendOtpEmail($email, $otp) {
    $subject = 'Code de confirmation - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE';
    $message = "Bonjour,\r\n\r\nVotre code de confirmation est : $otp\r\nCe code expire dans 10 minutes.\r\n\r\nMerci,\r\nDAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE";

    return envoyerMail($email, $subject, $message);
}

function registerUser($connexion, $data) {
    $checkQuery = $connexion->prepare("SELECT id FROM membre WHERE num = ? OR email = ? LIMIT 1");
    $checkQuery->execute([$data['num'], $data['email']]);

    if ($checkQuery->fetch()) {
        return ['error' => 'numero_ou_email_existe'];
    }

    $hashedPassword = password_hash($data['passeword'], PASSWORD_DEFAULT);
    $otp = generateOtp();
    $otpExpiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $professionDetails = null;
    $niveauEtude = null;
    $classe = null;
    $filiere = null;
    $photoProfil = trim((string) ($data['photo_profil'] ?? ''));

    if ($data['profession'] === 'travailleur') {
        $professionDetails = $data['profession_details'];
    } elseif ($data['profession'] === 'etudiant') {
        $niveauEtude = $data['niveau_etude'];
        $filiere = $data['filiere'];
    } elseif ($data['profession'] === 'eleve') {
        $classe = $data['classe'];
    }

    $insertQuery = $connexion->prepare(
        "INSERT INTO membre (num, prenom, nom, email, adresse, photo_profil, date_naissance, genre, profession, profession_details, niveau_etude, filiere, classe, passeword, confirm, otp_code, otp_expires_at, date_inscription)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())"
    );

    $insertQuery->execute([
        $data['num'],
        $data['prenom'],
        $data['nom'],
        $data['email'],
        $data['adresse'],
        $photoProfil !== '' ? $photoProfil : null,
        $data['date_naissance'],
        $data['genre'],
        $data['profession'],
        $professionDetails,
        $niveauEtude,
        $filiere,
        $classe,
        $hashedPassword,
        $otp,
        $otpExpiresAt
    ]);

    $emailSent = sendOtpEmail($data['email'], $otp);

    return ['success' => true, 'otp' => $otp, 'email_sent' => $emailSent];
}

$error = '';
$success = '';
$redirectToLogin = false;
$redirectToVerify = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $num = securisation($_POST['num'] ?? '');
    $prenom = securisation($_POST['prenom'] ?? '');
    $nom = securisation($_POST['nom'] ?? '');
    $email = securisation($_POST['email'] ?? '');
    $adresse = securisation($_POST['adresse'] ?? '');
    $photo_profil = securisation($_POST['photo_profil'] ?? '');
    $date_naissance = securisation($_POST['date_naissance'] ?? '');
    $genre = securisation($_POST['genre'] ?? '');
    $profession = securisation($_POST['profession'] ?? '');
    $profession_details = securisation($_POST['profession_details'] ?? '');
    $niveau_etude = securisation($_POST['niveau_etude'] ?? '');
    $filiere = securisation($_POST['filiere'] ?? '');
    $classe = securisation($_POST['classe'] ?? '');
    $passeword = securisation($_POST['passeword'] ?? '');
    $confirm = securisation($_POST['confirm'] ?? '');

    if (empty($num) || empty($prenom) || empty($nom) || empty($email) || empty($adresse) || empty($date_naissance) || empty($genre) || empty($profession) || empty($passeword) || empty($confirm)) {
        $error = "Veuillez remplir tous les champs obligatoires";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Veuillez saisir une adresse e-mail valide";
    } elseif ($profession === 'travailleur' && empty($profession_details)) {
        $error = "Veuillez indiquer votre profession";
    } elseif ($profession === 'etudiant' && (empty($niveau_etude) || empty($filiere))) {
        $error = "Veuillez indiquer votre niveau d'études et votre filière";
    } elseif ($profession === 'eleve' && empty($classe)) {
        $error = "Veuillez sélectionner votre classe";
    } elseif (!preg_match('/^[0-9+\-\s()]+$/', $num)) {
        $error = "Format de numéro invalide";
    } elseif ($passeword !== $confirm) {
        $error = "Les mots de passe ne correspondent pas";
    } elseif (strlen($passeword) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères";
    } else {
        try {
            $connexion = getDatabaseConnection();

            $userData = [
                'num' => $num,
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
                'adresse' => $adresse,
                'photo_profil' => $photo_profil,
                'date_naissance' => $date_naissance,
                'genre' => $genre,
                'profession' => $profession,
                'profession_details' => $profession_details,
                'niveau_etude' => $niveau_etude,
                'filiere' => $filiere,
                'classe' => $classe,
                'passeword' => $passeword
            ];

            $result = registerUser($connexion, $userData);

            if (isset($result['success']) && $result['success'] === true) {
                $_SESSION['pending_verification'] = [
                    'num' => $num,
                    'email' => $email
                ];
                header('Location: verify_otp.php');
                exit;
            } elseif (is_array($result) && isset($result['error'])) {
                if ($result['error'] === 'numero_ou_email_existe') {
                    $error = "Ce numéro ou cette adresse e-mail est déjà utilisé veillez vous connecter ou récupérer votre mot de passe";
                }
            }

        } catch (Throwable $e) {
            error_log("Erreur DB: " . $e->getMessage());
            $error = "Une erreur est survenue lors de l'inscription";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F2F2F2;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animations de fond */
        .bg-animation {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .wave {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 200%;
            height: 200px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120"><path d="M321.39,56.44c58-10.79,114.16-30.13,172-41.86,82.39-16.72,168.19-17.73,250.45-.39C823.78,31,906.67,72,985.66,92.83c70.05,18.48,146.53,26.09,214.34,3V0H0V27.35A600.21,600.21,0,0,0,321.39,56.44Z" fill="%23C9A24D" opacity="0.1"/></svg>') repeat-x;
            animation: wave 25s linear infinite;
        }
        
        .wave:nth-child(2) {
            bottom: 10px;
            animation: wave 20s linear infinite reverse;
            opacity: 0.5;
        }
        
        @keyframes wave {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        
        .particles {
            position: absolute;
            width: 100%;
            height: 100%;
        }
        
        .particle {
            position: absolute;
            background: rgba(225, 199, 122, 0.3);
            border-radius: 50%;
            animation: float-particle 15s infinite ease-in-out;
        }
        
        @keyframes float-particle {
            0%, 100% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 0.5;
            }
            90% {
                opacity: 0.5;
            }
            100% {
                transform: translateY(-100vh) translateX(50px);
                opacity: 0;
            }
        }
        
        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 650px;
            animation: slideUp 0.8s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form {
            background: #FFFFFF;
            border: 1px solid #D0D0D0;
            border-radius: 25px;
            padding: 45px;
            box-shadow: 0 20px 60px rgba(17, 17, 17, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .form::before {
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
        
        .logo {
            text-align: center;
            margin-bottom: 35px;
            animation: fadeIn 1s ease-out 0.3s both;
        }
        
        .logo-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #B58B45, #d6b57f);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 45px;
            box-shadow: 0 10px 30px rgba(201, 162, 77, 0.3);
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .title {
            text-align: center;
            color: #111111;
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .subtitle {
            text-align: center;
            color: #4A4A4A;
            font-size: 15px;
            margin-bottom: 35px;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
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
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
            animation: shake 0.5s;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .form-message {
            display: none;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .form-message.error {
            display: block;
            background: #fff1f0;
            color: #b71c1c;
            border-left: 4px solid #d32f2f;
        }

        .form-message.success {
            display: block;
            background: #e8f5e9;
            color: #1b5e20;
            border-left: 4px solid #2e7d32;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .flex {
            margin-bottom: 20px;
            animation: fadeInUp 0.6s ease-out both;
        }
        
        .flex:nth-child(1) { animation-delay: 0.1s; }
        .flex:nth-child(2) { animation-delay: 0.15s; }
        .flex:nth-child(3) { animation-delay: 0.2s; }
        .flex:nth-child(4) { animation-delay: 0.25s; }
        .flex:nth-child(5) { animation-delay: 0.3s; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        label {
            display: block;
            color: #111111;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input, select, textarea {
            width: 100%;
            padding: 14px 15px;
            border: 2px solid #d6b57f;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #F2F2F2;
            font-family: inherit;
        }
        
        .input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #B58B45;
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(201, 162, 77, 0.2);
        }
        
        .flex2 {
            margin-bottom: 20px;
            padding: 15px;
            background: #F2F2F2;
            border-radius: 12px;
            border: 2px solid #d6b57f;
            animation: fadeInUp 0.6s ease-out 0.35s both;
        }
        
        .flex2 label {
            display: inline-block;
            margin-right: 20px;
        }
        
        .flex2 input[type="radio"] {
            margin-right: 5px;
            cursor: pointer;
            width: auto;
        }
        
        .flex2 label[for="Sm"],
        .flex2 label[for="Sf"] {
            font-weight: normal;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .flex2 input[type="radio"]:checked + label {
            color: #B58B45;
            font-weight: bold;
        }
        
        .button-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 35px;
        }
        
        #submit {
            background: linear-gradient(135deg, #111111, #1d1d1d);
            color: #FFFFFF;
            border: none;
            padding: 16px;
            border-radius: 12px;
            font-size: 17px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }
        
        #submit::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.8s, height 0.8s;
        }
        
        #submit:hover::before {
            width: 400px;
            height: 400px;
        }
        
        #submit:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(31, 94, 67, 0.4);
        }
        
        #submit:active {
            transform: translateY(-2px);
        }
        
        #reset {
            background: #F2F2F2;
            color: #111111;
            border: 2px solid #d6b57f;
            padding: 16px;
            border-radius: 12px;
            font-size: 17px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        #reset:hover {
            background: #d6b57f;
            color: #FFFFFF;
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(225, 199, 122, 0.4);
        }
        
        .login-link {
            text-align: center;
            margin-top: 30px;
            color: #1d1d1d;
            font-size: 15px;
        }
        
        .login-link a {
            color: #B58B45;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .login-link a::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 0;
            height: 2px;
            background: #B58B45;
            transition: width 0.3s ease;
        }
        
        .login-link a:hover::after {
            width: 100%;
        }
        
        .login-link a:hover {
            color: #111111;
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

        @media (max-width: 768px) {
            body {
                padding: 16px 12px 60px;
                overflow-x: hidden;
            }

            .container {
                max-width: 100%;
            }

            .form {
                padding: 28px 18px 22px;
                border-radius: 18px;
            }

            .logo {
                margin-bottom: 24px;
            }

            .logo-icon {
                width: 72px;
                height: 72px;
                font-size: 36px;
            }

            .title {
                font-size: 26px;
            }

            .subtitle {
                font-size: 13px;
                margin-bottom: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .button-group {
                grid-template-columns: 1fr;
                gap: 12px;
                margin-top: 24px;
            }

            .input, select, textarea {
                font-size: 16px;
                padding: 13px 14px;
            }

            .flex2 {
                padding: 12px 10px;
            }

            .flex2 label {
                display: inline-block;
                margin-right: 10px;
                margin-bottom: 8px;
            }
        }

        @media (max-width: 480px) {
            .title {
                font-size: 24px;
            }

            .subtitle {
                letter-spacing: 0.06em;
            }

            .login-link {
                font-size: 13px;
                line-height: 1.5;
            }

            .footer {
                font-size: 11px;
                padding: 9px 8px;
            }
        }
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #F2F2F2;
            border-radius: 10px;
            margin-bottom: 30px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #B58B45, #d6b57f);
            width: 0%;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="wave"></div>
        <div class="wave"></div>
        <div class="particles" id="particles"></div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
         Conçu par BICHRY_DEV © <?php echo date('Y'); ?>
    </div>
    
    <div class="container">
        <form method="post" class="form" action="" id="registerForm">
            <div class="logo">
                <div class="logo-icon"></div>
                <p class="title">Inscription</p>
                <p class="subtitle">DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</p>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            
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

            <div id="formMessage" class="form-message" aria-live="polite"></div>

            <div class="form-row">
                <div class="flex">
                    <label for="prenom">Prénom *</label>
                    <input 
                        type="text" 
                        name="prenom" 
                        id="prenom" 
                        class="input" 
                        placeholder="Saisissez votre Prénom" 
                        value="<?php echo isset($_POST['prenom']) ? htmlspecialchars($_POST['prenom']) : ''; ?>" 
                        required>
                </div>
                
                <div class="flex">
                    <label for="nom">Nom *</label>
                    <input 
                        type="text" 
                        name="nom" 
                        id="nom" 
                        class="input" 
                        placeholder="Saisissez votre Nom" 
                        value="<?php echo isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : ''; ?>" 
                        required>
                </div>
            </div>

            <div class="flex">
                <label for="num">Numéro de téléphone *</label>
                <div class="input-wrapper">
                    <input 
                        type="tel" 
                        name="num" 
                        id="num" 
                        class="input" 
                        placeholder="Saisissez votre Numéro WhatsApp" 
                        value="<?php echo isset($_POST['num']) ? htmlspecialchars($_POST['num']) : ''; ?>" 
                        required 
                        autocomplete="tel">
                </div>
            </div>

            <div class="flex">
                <label for="email">Adresse e-mail *</label>
                <input 
                    type="email" 
                    name="email" 
                    id="email" 
                    class="input" 
                    placeholder="exemple@email.com" 
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                    required 
                    autocomplete="email">
            </div>

            <div class="flex">
                <label for="date_naissance">Date de naissance *</label>
                <input 
                    type="date" 
                    name="date_naissance" 
                    id="date_naissance" 
                    class="input" 
                    value="<?php echo isset($_POST['date_naissance']) ? htmlspecialchars($_POST['date_naissance']) : ''; ?>" 
                    required>
            </div>
            
            <div class="flex2">
                  <label>Genre *</label>
                <input type="radio" value="Homme" name="genre" id="Sm" 
                        <?php echo (isset($_POST['genre']) && $_POST['genre'] === 'Homme') ? 'checked' : ''; ?> required>
                <label for="Sm">Homme</label>
                <input type="radio" value="Femme" name="genre" id="Sf" 
                        <?php echo (isset($_POST['genre']) && $_POST['genre'] === 'Femme') ? 'checked' : ''; ?>>
                <label for="Sf">Femme</label>
            </div>
 
            <div class="form-row">
                <div class="flex">
                    <label for="profession">Profession *</label>
                    <select name="profession" id="profession" required onchange="showProfessionFields()">
                        <option value="">Veuillez sélectionner</option>
                        <option value="travailleur" <?php echo (isset($_POST['profession']) && $_POST['profession'] === 'travailleur') ? 'selected' : ''; ?>>Travailleur</option>
                        <option value="etudiant" <?php echo (isset($_POST['profession']) && $_POST['profession'] === 'etudiant') ? 'selected' : ''; ?>>Étudiant</option>
                        <option value="eleve" <?php echo (isset($_POST['profession']) && $_POST['profession'] === 'eleve') ? 'selected' : ''; ?>>Élève</option>
                    </select>
                </div>
                
                <!-- Champ pour travailleur -->
                <div class="flex" id="profession_details_field" style="display: none;">
                    <label for="profession_details">Votre profession *</label>
                    <input 
                        type="text" 
                        name="profession_details" 
                        id="profession_details" 
                        class="input" 
                        placeholder="Ex: Enseignant, Commerçant, etc." 
                        value="<?php echo isset($_POST['profession_details']) ? htmlspecialchars($_POST['profession_details']) : ''; ?>">
                </div>
                
                <!-- Champs pour étudiant -->
                <div class="flex" id="niveau_etude_field" style="display: none;">
                    <label for="niveau_etude">Niveau d'études *</label>
                    <select name="niveau_etude" id="niveau_etude" class="input">
                        <option value="">Sélectionner</option>
                        <option value="Licence 1" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Licence 1') ? 'selected' : ''; ?>>Licence 1</option>
                        <option value="Licence 2" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Licence 2') ? 'selected' : ''; ?>>Licence 2</option>
                        <option value="Licence 3" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Licence 3') ? 'selected' : ''; ?>>Licence 3</option>
                        <option value="Master 1" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Master 1') ? 'selected' : ''; ?>>Master 1</option>
                        <option value="Master 2" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Master 2') ? 'selected' : ''; ?>>Master 2</option>
                        <option value="Doctorat" <?php echo (isset($_POST['niveau_etude']) && $_POST['niveau_etude'] === 'Doctorat') ? 'selected' : ''; ?>>Doctorat</option>
                    </select>
                </div>
            </div>
            
            <!-- Champ filière pour étudiant (nouvelle ligne) -->
            <div class="flex" id="filiere_field" style="display: none;">
                <label for="filiere">Filière *</label>
                <input 
                    type="text" 
                    name="filiere" 
                    id="filiere" 
                    class="input" 
                    placeholder="Ex: Informatique, Médecine, Économie, etc." 
                    value="<?php echo isset($_POST['filiere']) ? htmlspecialchars($_POST['filiere']) : ''; ?>">
            </div>
            
            <!-- Champ pour élève -->
            <div class="flex" id="classe_field" style="display: none;">
                <label for="classe">Classe *</label>
                <select name="classe" id="classe" class="input">
                    <option value="">Sélectionner votre classe</option>
                    <optgroup label="Collège">
                        <option value="6ème" <?php echo (isset($_POST['classe']) && $_POST['classe'] === '6ème') ? 'selected' : ''; ?>>6ème</option>
                        <option value="5ème" <?php echo (isset($_POST['classe']) && $_POST['classe'] === '5ème') ? 'selected' : ''; ?>>5ème</option>
                        <option value="4ème" <?php echo (isset($_POST['classe']) && $_POST['classe'] === '4ème') ? 'selected' : ''; ?>>4ème</option>
                        <option value="3ème" <?php echo (isset($_POST['classe']) && $_POST['classe'] === '3ème') ? 'selected' : ''; ?>>3ème</option>
                    </optgroup>
                    <optgroup label="Lycée - Série L">
                        <option value="Seconde L" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Seconde L') ? 'selected' : ''; ?>>Seconde L</option>
                        <option value="Première L" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Première L') ? 'selected' : ''; ?>>Première L</option>
                        <option value="Terminale L" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Terminale L') ? 'selected' : ''; ?>>Terminale L</option>
                    </optgroup>
                    <optgroup label="Lycée - Série S">
                        <option value="Seconde S" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Seconde S') ? 'selected' : ''; ?>>Seconde S</option>
                        <option value="Première S" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Première S') ? 'selected' : ''; ?>>Première S</option>
                        <option value="Terminale S" <?php echo (isset($_POST['classe']) && $_POST['classe'] === 'Terminale S') ? 'selected' : ''; ?>>Terminale S</option>
                    </optgroup>
                </select>
            </div>
            
            <div class="form-row">
                <div class="flex">
                    <label for="passeword">Mot de passe *</label>
                    <input 
                        type="password" 
                        name="passeword" 
                        id="passeword" 
                        class="input" 
                        placeholder="Minimum 8 caractères" 
                        required>
                </div>
                
                <div class="flex">
                    <label for="confirm">Confirmation *</label>
                    <input 
                        type="password" 
                        name="confirm" 
                        id="confirm" 
                        class="input" 
                        placeholder="Confirmer le mot de passe" 
                        required>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" id="submit">S'inscrire</button>
                <button type="reset" id="reset">Annuler</button>
            </div>
            
            <p class="login-link">
                Vous avez déjà un compte ? <a href="index.php">Se connecter</a>
            </p>
        </form>
    </div>

    <script>
        // Redirection automatique après succès
        <?php if ($redirectToLogin): ?>
        setTimeout(function() {
            window.location.href = 'index.php';
        }, 2000); // Redirection après 2 secondes
        <?php endif; ?>
        
        // Fonction pour afficher les champs selon la profession
        function showProfessionFields() {
            const profession = document.getElementById('profession').value;
            const professionDetailsField = document.getElementById('profession_details_field');
            const niveauEtudeField = document.getElementById('niveau_etude_field');
            const filiereField = document.getElementById('filiere_field');
            const classeField = document.getElementById('classe_field');
            
            // Masquer tous les champs
            professionDetailsField.style.display = 'none';
            niveauEtudeField.style.display = 'none';
            filiereField.style.display = 'none';
            classeField.style.display = 'none';
            
            // Retirer l'attribut required
            document.getElementById('profession_details').removeAttribute('required');
            document.getElementById('niveau_etude').removeAttribute('required');
            document.getElementById('filiere').removeAttribute('required');
            document.getElementById('classe').removeAttribute('required');
            
            // Afficher le champ approprié
            if (profession === 'travailleur') {
                professionDetailsField.style.display = 'block';
                professionDetailsField.style.animation = 'slideIn 0.5s ease-out';
                document.getElementById('profession_details').setAttribute('required', 'required');
            } else if (profession === 'etudiant') {
                niveauEtudeField.style.display = 'block';
                filiereField.style.display = 'block';
                niveauEtudeField.style.animation = 'slideIn 0.5s ease-out';
                filiereField.style.animation = 'slideIn 0.5s ease-out 0.1s';
                document.getElementById('niveau_etude').setAttribute('required', 'required');
                document.getElementById('filiere').setAttribute('required', 'required');
            } else if (profession === 'eleve') {
                classeField.style.display = 'block';
                classeField.style.animation = 'slideIn 0.5s ease-out';
                document.getElementById('classe').setAttribute('required', 'required');
            }
            
            updateProgress();
        }
        
        // Appeler au chargement si une profession est déjà sélectionnée
        document.addEventListener('DOMContentLoaded', function() {
            const professionSelect = document.getElementById('profession');
            if (professionSelect.value) {
                showProfessionFields();
            }
        });
        
        const formMessage = document.getElementById('formMessage');

        function showFormMessage(type, message) {
            formMessage.className = 'form-message ' + type;
            formMessage.textContent = message;
        }

        function validateRegistrationForm() {
            const profession = document.getElementById('profession').value;
            const phone = document.getElementById('num');
            const email = document.getElementById('email');
            const dateNaissance = document.getElementById('date_naissance');
            const password = document.getElementById('passeword');
            const confirmPassword = document.getElementById('confirm');
            const requiredMessage = 'Veuillez remplir correctement tous les champs obligatoires.';

            if (!phone.value.trim() || !/^[0-9+\-\s()]+$/.test(phone.value.trim())) {
                phone.focus();
                phone.setCustomValidity('Le numéro est invalide.');
                return { valid: false, message: 'Le numéro de téléphone est invalide.' };
            }
            phone.setCustomValidity('');

            if (!email.value.trim() || !email.checkValidity()) {
                email.focus();
                return { valid: false, message: 'Veuillez saisir une adresse e-mail valide.' };
            }

            if (!dateNaissance.value || new Date(dateNaissance.value) > new Date()) {
                dateNaissance.focus();
                return { valid: false, message: 'Veuillez indiquer une date de naissance valide.' };
            }

            if (profession === 'travailleur' && !document.getElementById('profession_details').value.trim()) {
                document.getElementById('profession_details').focus();
                return { valid: false, message: 'Veuillez préciser votre profession.' };
            }

            if (profession === 'etudiant' && (!document.getElementById('niveau_etude').value || !document.getElementById('filiere').value.trim())) {
                document.getElementById('filiere').focus();
                return { valid: false, message: 'Veuillez renseigner le niveau d’études et la filière.' };
            }

            if (profession === 'eleve' && !document.getElementById('classe').value) {
                document.getElementById('classe').focus();
                return { valid: false, message: 'Veuillez choisir votre classe.' };
            }

            if (!password.value || password.value.length < 6) {
                password.focus();
                return { valid: false, message: 'Le mot de passe doit contenir au moins 6 caractères.' };
            }

            if (password.value !== confirmPassword.value) {
                confirmPassword.focus();
                return { valid: false, message: 'Les mots de passe ne correspondent pas.' };
            }

            return { valid: true, message: 'Vérification OK.' };
        }

        // Générer des particules
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            for (let i = 0; i < 15; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                particle.style.width = Math.random() * 10 + 5 + 'px';
                particle.style.height = particle.style.width;
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 15 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 10) + 's';
                particlesContainer.appendChild(particle);
            }
        }
        
        createParticles();
        
        // Barre de progression du formulaire
        const form = document.getElementById('registerForm');
        const inputs = form.querySelectorAll('input[required], select[required]');
        const progressFill = document.getElementById('progressFill');
        
        function updateProgress() {
            let filledInputs = 0;
            const visibleInputs = Array.from(inputs).filter(input => {
                return input.offsetParent !== null; // Vérifie si l'input est visible
            });
            
            visibleInputs.forEach(input => {
                if (input.type === 'radio') {
                    const radioGroup = form.querySelectorAll(`input[name="${input.name}"]`);
                    if (Array.from(radioGroup).some(radio => radio.checked)) {
                        filledInputs++;
                    }
                } else if (input.value.trim() !== '') {
                    filledInputs++;
                }
            });
            
            // Éviter de compter les radios plusieurs fois
            const radioNames = new Set();
            visibleInputs.forEach(input => {
                if (input.type === 'radio') radioNames.add(input.name);
            });
            const totalFields = visibleInputs.length - radioNames.size + radioNames.size;
            
            const progress = totalFields > 0 ? (filledInputs / totalFields) * 100 : 0;
            progressFill.style.width = progress + '%';
        }
        
        inputs.forEach(input => {
            input.addEventListener('input', updateProgress);
            input.addEventListener('change', updateProgress);
        });
        
        updateProgress();
        
        form.addEventListener('submit', function(e) {
            const validation = validateRegistrationForm();
            if (!validation.valid) {
                e.preventDefault();
                showFormMessage('error', validation.message);
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const submitBtn = document.getElementById('submit');
            submitBtn.classList.add('loading');
            submitBtn.textContent = 'Inscription en cours...';
            showFormMessage('success', 'Vérification des informations en cours...');
        });
        
        // Validation des mots de passe en temps réel
        const password = document.getElementById('passeword');
        const confirmPassword = document.getElementById('confirm');
        
        function validatePasswords() {
            if (confirmPassword.value !== '' && password.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Les mots de passe ne correspondent pas');
                confirmPassword.style.borderColor = '#c62828';
            } else {
                confirmPassword.setCustomValidity('');
                confirmPassword.style.borderColor = '#d6b57f';
            }
        }
        
        password.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
        
        // Animation des inputs au focus
        const allInputs = document.querySelectorAll('.input, select');
        allInputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.style.transform = 'scale(1.01)';
            });
            
            input.addEventListener('blur', function() {
                this.style.transform = 'scale(1)';
            });
        });
        
        // Effet de typing
        allInputs.forEach(input => {
            input.addEventListener('input', function() {
                this.style.transition = 'transform 0.1s';
                this.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 100);
            });
        });
        
        // Animation des radios
        const radios = document.querySelectorAll('input[type="radio"]');
        radios.forEach(radio => {
            radio.addEventListener('change', function() {
                const parent = this.closest('.flex2');
                parent.style.transform = 'scale(1.02)';
                parent.style.borderColor = '#B58B45';
                setTimeout(() => {
                    parent.style.transform = 'scale(1)';
                    parent.style.borderColor = '#d6b57f';
                }, 300);
            });
        });
        
        // Effet ripple sur le bouton submit
        const submitBtn = document.getElementById('submit');
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