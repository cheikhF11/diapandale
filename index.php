<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/sendMail/mail.php';
require_once __DIR__ . '/securite/securisation.php';

// Regénérer l'ID de session pour éviter la fixation de session
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

function verifyUser($connexion, $email, $password) {
    // Récupérer l'utilisateur correspondant à l'email
    $requete = $connexion->prepare(
        "SELECT num, email, passeword, confirm FROM membre WHERE email = ? LIMIT 1"
    );
    $requete->execute([$email]);

    $user = $requete->fetch(PDO::FETCH_ASSOC);

    // Vérifier si l'email existe
    if (!$user) {
        return false;
    }

    // Vérifier si le compte est confirmé (si vous utilisez cette colonne)
    if (isset($user['confirm']) && $user['confirm'] != 1) {
        return ['error' => 'compte_non_confirme'];
    }

    // Vérifier le mot de passe
    if (password_verify($password, $user['passeword'])) {
        return $user;
    }

    return false;
}

$error = '';
$recoveryMessage = '';
$recoveryError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'recover_password') {
        $identifier = securisation($_POST['recovery_identifier'] ?? '');

        if ($identifier === '') {
            $recoveryError = 'Veuillez renseigner votre numéro ou votre email.';
        } else {
            try {
                $connexion = getDatabaseConnection();
                $requete = $connexion->prepare(
                    "SELECT id, num, email, prenom, nom FROM membre WHERE num = ? OR email = ? LIMIT 1"
                );
                $requete->execute([$identifier, $identifier]);
                $user = $requete->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $recoveryError = 'Aucun compte n’a été trouvé avec ces informations.';
                } elseif (empty($user['email'])) {
                    $recoveryError = 'Aucune adresse e-mail n’est enregistrée pour ce compte. Contactez l’administration.';
                } else {
                    $temporaryPassword = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) . '@' . random_int(100, 999);
                    $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

                    $updateQuery = $connexion->prepare(
                        "UPDATE membre SET passeword = ? WHERE id = ?"
                    );
                    $updateQuery->execute([$hashedPassword, $user['id']]);

                    $subject = 'Récupération de votre mot de passe - DAHIRA';
                    $message = "
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <h2>Récupération de mot de passe</h2>
                            <p>Bonjour {$user['prenom']} {$user['nom']},</p>
                            <p>Votre mot de passe a été réinitialisé avec succès.</p>
                            <p>Votre nouveau mot de passe temporaire est : <strong>{$temporaryPassword}</strong></p>
                            <p>Veuillez vous connecter puis changer votre mot de passe dès votre prochaine connexion.</p>
                            <p>Numéro de compte : <strong>{$user['num']}</strong></p>
                            <p>Cordialement,<br>DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</p>
                        </body>
                        </html>
                    ";

                    $emailSent = envoyerMail($user['email'], $subject, $message);

                    if ($emailSent) {
                        $recoveryMessage = 'Un nouveau mot de passe temporaire a été envoyé à votre adresse e-mail.';
                    } else {
                        $recoveryMessage = 'Votre compte a bien été retrouvé, mais l’e-mail de récupération n’a pas pu être envoyé. Contactez l’administration pour finaliser la récupération.';
                    }
                }
            } catch (Throwable $e) {
                error_log('Erreur récupération mot de passe: ' . $e->getMessage());
                $recoveryError = 'Une erreur est survenue. Veuillez réessayer.';
            }
        }
    } else {
        $email = securisation($_POST['email'] ?? '');
        $passeword = securisation($_POST['passeword'] ?? '');

        try {
            $connexion = getDatabaseConnection();
            $user = verifyUser($connexion, $email, $passeword);

            // Gérer les erreurs spécifiques
            if (is_array($user) && isset($user['error'])) {
                if ($user['error'] === 'compte_non_confirme') {
                    $error = "Votre compte n'a pas encore été confirmé. Veuillez vérifier votre email.";
                } else {
                    $error = "Une erreur est survenue";
                }
            } elseif ($user) {
                // Connexion réussie - régénérer l'ID de session
                session_regenerate_id(true);
                
                // Stocker les informations en session
                $_SESSION['number'] = $user['num'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['logged_in'] = true;

                header('Location: accueil.php');
                exit;
            } else {
                $error = "Email ou mot de passe incorrect";
            }

        } catch (Throwable $e) {
            error_log("Erreur de connexion: " . $e->getMessage());
            $error = "Une erreur est survenue. Veuillez réessayer.";
        }
    }
} else {
    // Si l'utilisateur est déjà connecté, le rediriger vers la page d'accueil
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        header('Location: accueil.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
     <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(181, 139, 69, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(22, 54, 79, 0.15), transparent 30%),
                linear-gradient(135deg, #111111 0%, #181818 28%, #f3efe7 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Animations de fond */
        .bg-animation {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(21, 45, 62, 0.05);
            animation: float 20s infinite ease-in-out;
        }
        
        .circle:nth-child(1) {
            width: 300px;
            height: 300px;
            top: -150px;
            left: -150px;
            animation-delay: 0s;
        }
        
        .circle:nth-child(2) {
            width: 200px;
            height: 200px;
            bottom: -100px;
            right: -100px;
            animation-delay: 2s;
        }
        
        .circle:nth-child(3) {
            width: 150px;
            height: 150px;
            top: 50%;
            right: 10%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.3;
            }
            50% {
                transform: translateY(-30px) rotate(180deg);
                opacity: 0.6;
            }
        }
        
        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 470px;
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
        
        .form {
            background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(249,247,243,0.96) 100%);
            border: 1px solid rgba(181, 139, 69, 0.2);
            border-radius: 26px;
            padding: 34px 30px 26px;
            box-shadow: 0 28px 70px rgba(17,17,17,0.2), 0 10px 25px rgba(22, 54, 79, 0.08);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(4px);
        }
        
        .form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #111111, #b58b45, #d6b57f, #111111);
            background-size: 200% 100%;
            animation: shimmer 4s linear infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
            animation: fadeIn 1s ease-out 0.3s both;
        }
        
        .logo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 88px;
            height: 88px;
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 100%);
            border: 2px solid rgba(181, 139, 69, 0.7);
            border-radius: 50%;
            box-shadow: 0 14px 25px rgba(181, 139, 69, 0.2);
            margin: 0 auto 18px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #B58B45, #d6b57f);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 40px;
            animation: rotate 10s linear infinite;
            overflow: hidden;
        }
        .logo-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover; /* adapte l’image sans déformation */
}
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .title {
            text-align: center;
            color: #111111;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin-bottom: 10px;
        }
        
        .subtitle {
            text-align: center;
            color: #5d5d5d;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 28px;
            font-weight: 700;
        }
        
        .error-message {
            background: #ffebee;
            color: #c62828;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
            animation: shake 0.5s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        .flex {
            margin-bottom: 20px;
            animation: fadeIn 1s ease-out both;
        }
        
        .flex:nth-child(2) { animation-delay: 0.1s; }
        .flex:nth-child(3) { animation-delay: 0.2s; }
        .flex:nth-child(4) { animation-delay: 0.3s; }
        
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
        
        .input {
            width: 100%;
            height: 52px;
            padding: 14px 45px 14px 15px;
            border: 1.5px solid rgba(39, 74, 92, 0.22);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f7f7f5;
            color: #111111;
        }
        
        .input:focus {
            outline: none;
            border-color: #16364f;
            background: #FFFFFF;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(22, 54, 79, 0.10);
        }
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #B58B45;
            font-size: 20px;
        }
        
        .button-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 30px;
        }
        
        #submit,
        #reset,
        .recovery-submit {
            min-height: 52px;
            width: 100%;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        #submit {
            background: linear-gradient(135deg, #111111, #2a2a2a 60%, #b58b45);
            color: #FFFFFF;
            border: none;
            padding: 14px 18px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 22px rgba(17,17,17,0.12);
        }
        
        #submit::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        #submit:hover::before {
            width: 300px;
            height: 300px;
        }
        
        #submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(31, 94, 67, 0.3);
        }
        
        #submit:active {
            transform: translateY(-1px);
        }
        
        #reset {
            background: #f0ede8;
            color: #1b2d38;
            border: 1.5px solid rgba(22, 54, 79, 0.2);
            padding: 14px 18px;
        }
        
        #reset:hover {
            background: #d8d1c8;
            color: #1b2d38;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(22, 54, 79, 0.10);
        }
        
        .register-link {
            text-align: center;
            margin-top: 25px;
            color: #1d1d1d;
            font-size: 14px;
        }
        
        .register-link a {
            color: #B58B45;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .register-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #B58B45;
            transition: width 0.3s ease;
        }
        
        .register-link a:hover::after {
            width: 100%;
        }
        
        .register-link a:hover {
            color: #111111;
        }

        .recovery-actions {
            width: 100%;
            max-width: 450px;
            margin: 18px auto 0;
            text-align: center;
        }

        .recovery-note {
            text-align: center;
            margin-top: 18px;
            color: #4A4A4A;
            font-size: 13px;
            line-height: 1.5;
        }

        .forgot-password-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(22, 54, 79, 0.04);
            border: 1px solid rgba(22, 54, 79, 0.15);
            color: #1d4961;
            font-weight: 700;
            cursor: pointer;
            padding: 10px 18px;
            margin-top: 12px;
            border-radius: 999px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .forgot-password-btn:hover {
            color: #16364f;
            background: rgba(22, 54, 79, 0.08);
            transform: translateY(-1px);
        }

        .recovery-container {
            display: none;
            margin-top: 20px;
            padding: 18px 16px 8px;
            border: 1px solid rgba(22, 54, 79, 0.12);
            background: rgba(245, 242, 237, 0.8);
            border-radius: 16px;
            text-align: left;
        }

        .recovery-container.visible {
            display: block;
        }

        .recovery-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        .recovery-form .input {
            margin-bottom: 0;
        }

        .recovery-submit {
            background: linear-gradient(135deg, #1d4961, #2a5e7d);
            color: #ffffff;
            border: none;
            padding: 12px 18px;
            margin-top: 4px;
            box-shadow: 0 10px 20px rgba(29, 73, 97, 0.16);
        }

        .recovery-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(29, 73, 97, 0.22);
        }

        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
            padding: 12px 15px;
            border-radius: 10px;
            margin-top: 15px;
            font-size: 14px;
        }

        .warning-message {
            background: #fff3e0;
            color: #ef6c00;
            border-left: 4px solid #ef6c00;
            padding: 12px 15px;
            border-radius: 10px;
            margin-top: 15px;
            font-size: 14px;
        }
        
        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(17, 17, 17, 0.9);
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 12px;
            opacity: 0.9;
            z-index: 10;
            border-top: 2px solid rgba(181, 139, 69, 0.75);
        }
        
        /* Animation du bouton de soumission en chargement */
        .loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid #F2F2F2;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

    </style>
   
</head>
<body>
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
         Conçu par BICHRY_DEV © <?php echo date('Y'); ?>
    </div>
    
    <div class="container">
        <form method="post" class="form" action="" id="loginForm">
            <div class="logo">
                <div class="logo-badge">
                    <div class="logo-icon">✦</div>
                </div>
                <p class="title">Connexion</p>
                <p class="subtitle">DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error-message" id="errorMessage">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($recoveryMessage)): ?>
                <div class="success-message" id="recoveryMessageBox">
                    <?php echo htmlspecialchars($recoveryMessage); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($recoveryError)): ?>
                <div class="warning-message" id="recoveryErrorBox">
                    <?php echo htmlspecialchars($recoveryError); ?>
                </div>
            <?php endif; ?>
            
            <div class="flex">
                <label for="email">Adresse e-mail</label>
                <div class="input-wrapper">
                    <input 
                        type="email" 
                        name="email" 
                        id="email" 
                        class="input" 
                        placeholder="Votre adresse e-mail" 
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                        required
                        autocomplete="email">
                    <span class="input-icon"></span>
                </div>
            </div>
            
            <div class="flex">
                <label for="password">Mot de passe</label>
                <div class="input-wrapper">
                    <input 
                        type="password" 
                        name="passeword" 
                        id="password" 
                        class="input" 
                        placeholder="Votre mot de passe" 
                        required
                        autocomplete="current-password">
                    <span class="input-icon"></span>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" id="submit">Se connecter</button>
                <button type="reset" id="reset">Annuler</button>
            </div>
            
            <p class="register-link">
                Si vous n'avez pas de compte, veuillez <a href="form.php">créer un compte</a>
            </p>
        </form>

        <div class="recovery-actions">
            <button type="button" class="forgot-password-btn" id="forgotPasswordBtn">
                Mot de passe oublié
            </button>

            <div class="recovery-container" id="recoveryContainer">
                <form method="post" class="recovery-form" id="recoveryForm">
                    <input type="hidden" name="action" value="recover_password">
                    <label for="recovery_identifier">Email</label>
                    <input 
                        type="email" 
                        name="recovery_identifier" 
                        id="recovery_identifier" 
                        class="input" 
                        placeholder="Entrez votre email" 
                        required>
                    <button type="submit" class="recovery-submit">Récupérer mes informations</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        const forgotPasswordBtn = document.getElementById('forgotPasswordBtn');
        const recoveryContainer = document.getElementById('recoveryContainer');

        if (forgotPasswordBtn && recoveryContainer) {
            forgotPasswordBtn.addEventListener('click', function() {
                recoveryContainer.classList.toggle('visible');
                if (recoveryContainer.classList.contains('visible')) {
                    document.getElementById('recovery_identifier').focus();
                }
            });
        }

        // Animation de chargement lors de la soumission
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit');
            submitBtn.classList.add('loading');
            submitBtn.textContent = 'Connexion...';
        });

        const recoveryForm = document.getElementById('recoveryForm');
        if (recoveryForm) {
            recoveryForm.addEventListener('submit', function() {
                const button = this.querySelector('button[type="submit"]');
                if (button) {
                    button.textContent = 'Vérification...';
                }
            });
        }
        
        // Animation des inputs au focus
        const inputs = document.querySelectorAll('.input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
            
            // Animation de typing
            input.addEventListener('input', function() {
                this.style.transform = 'scale(1.01)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 100);
            });
        });
        
        // Masquer le message d'erreur après 5 secondes
        const errorMessage = document.getElementById('errorMessage');
        if (errorMessage) {
            setTimeout(() => {
                errorMessage.style.animation = 'fadeOut 0.5s ease-out';
                setTimeout(() => {
                    errorMessage.style.display = 'none';
                }, 500);
            }, 5000);
        }
        
        // Animation fadeOut
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-10px); }
            }
        `;
        document.head.appendChild(style);
        
        // Effet de particules au survol du bouton
        const submitBtn = document.getElementById('submit');
        submitBtn.addEventListener('mouseenter', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const ripple = document.createElement('span');
            ripple.style.cssText = `
                position: absolute;
                width: 10px;
                height: 10px;
                background: rgba(255,255,255,0.5);
                border-radius: 50%;
                pointer-events: none;
                left: ${x}px;
                top: ${y}px;
                transform: translate(-50%, -50%) scale(0);
                animation: ripple 0.6s ease-out;
            `;
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
        
        // Animation ripple
        const rippleStyle = document.createElement('style');
        rippleStyle.textContent = `
            @keyframes ripple {
                to {
                    transform: translate(-50%, -50%) scale(4);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(rippleStyle);
    </script>
</body>
</html>