<?php
session_start();

require_once __DIR__ . '/dataBase/bd.php';
require_once __DIR__ . '/securite/securisation.php';

if (!isset($_SESSION['pending_verification']) || empty($_SESSION['pending_verification']['num'])) {
    header('Location: index.php');
    exit;
}

$pending = $_SESSION['pending_verification'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = securisation(trim($_POST['otp'] ?? ''));

    if (!preg_match('/^\d{6}$/', $otp)) {
        $error = 'Le code OTP est invalide. Saisissez 6 chiffres.';
    } else {
        try {
            $connexion = getDatabaseConnection();
            $query = $connexion->prepare(
                "SELECT id, otp_code, otp_expires_at, confirm FROM membre WHERE num = ? AND email = ? LIMIT 1"
            );
            $query->execute([$pending['num'], $pending['email']]);
            $user = $query->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Aucun compte correspondant n’a été trouvé. Vérifiez votre inscription.';
            } elseif ((int) $user['confirm'] === 1) {
                $success = 'Votre compte est déjà vérifié. Vous pouvez maintenant vous connecter.';
                unset($_SESSION['pending_verification']);
                header('Refresh: 2; url=index.php');
                exit;
            } elseif ($user['otp_code'] !== $otp) {
                $error = 'Code OTP incorrect. Vérifiez le code reçu par e-mail.';
            } elseif (strtotime($user['otp_expires_at']) < time()) {
                $error = 'Le code OTP a expiré. Refaire votre inscription pour obtenir un nouveau code.';
            } else {
                $update = $connexion->prepare(
                    "UPDATE membre SET confirm = 1, otp_code = NULL, otp_expires_at = NULL WHERE id = ?"
                );
                $update->execute([$user['id']]);

                $success = 'Votre inscription est confirmée avec succès. Redirection vers la connexion...';
                unset($_SESSION['pending_verification']);
                header('Refresh: 2; url=index.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Erreur OTP: ' . $e->getMessage());
            $error = 'Une erreur est survenue lors de la vérification du code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification OTP</title>
    <link rel="stylesheet" href="assets/css/daara-theme.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #111111 0%, #1d1d1d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(201,162,77,0.18), transparent 25%), radial-gradient(circle at bottom right, rgba(255,255,255,0.12), transparent 20%);
        }

        .card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 26px;
            padding: 32px 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
        }

        .top-badge {
            width: 90px;
            height: 90px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #B58B45, #d6b57f);
            color: #fff;
            font-size: 36px;
            box-shadow: 0 12px 24px rgba(201,162,77,0.3);
        }

        h1 {
            margin: 0 0 8px;
            text-align: center;
            color: #111111;
            font-size: clamp(1.8rem, 3vw, 2.5rem);
        }

        .intro {
            margin: 0 0 24px;
            text-align: center;
            color: #1d1d1d;
            font-size: 0.98rem;
            line-height: 1.6;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.94rem;
            line-height: 1.5;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .email-box {
            background: #F2F2F2;
            border: 1px solid #d6b57f;
            border-radius: 12px;
            text-align: center;
            padding: 12px 14px;
            font-weight: 600;
            color: #111111;
            margin-bottom: 22px;
        }

        .otp-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin: 20px 0 24px;
        }

        .otp-input {
            width: 100%;
            height: 58px;
            border-radius: 14px;
            border: 2px solid #d6b57f;
            background: #F2F2F2;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #111111;
            transition: all 0.2s ease;
        }

        .otp-input:focus {
            outline: none;
            border-color: #B58B45;
            box-shadow: 0 0 0 4px rgba(201,162,77,0.18);
            background: #fff;
        }

        button {
            width: 100%;
            padding: 15px 16px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #111111, #1d1d1d);
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 20px rgba(31,94,67,0.22);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 26px rgba(31,94,67,0.28);
        }

        .helper {
            margin-top: 18px;
            color: #1d1d1d;
            font-size: 0.88rem;
            text-align: center;
        }

        .debug {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d6b57f;
            background: #F2F2F2;
            color: #111111;
            font-size: 0.88rem;
            text-align: center;
        }

        .debug strong {
            color: #111111;
        }

        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 540px) {
            .card {
                padding: 24px 16px;
            }

            .otp-grid {
                gap: 8px;
            }

            .otp-input {
                height: 50px;
                font-size: 1.35rem;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="top-badge">✉️</div>
        <h1>Vérification OTP</h1>
        <p class="intro">Entrez le code à 6 chiffres envoyé par e-mail pour valider votre compte.</p>

        <div class="email-box"><?php echo htmlspecialchars($pending['email']); ?></div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
            <form method="POST" id="otpForm" novalidate>
                <div class="otp-grid" aria-label="Code de vérification">
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 1" />
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 2" />
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 3" />
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 4" />
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 5" />
                    <input class="otp-input" type="text" inputmode="numeric" maxlength="1" aria-label="Chiffre 6" />
                </div>
                <input type="hidden" name="otp" id="otp" value="" />
                <button type="submit">Vérifier mon compte</button>
            </form>
        <?php endif; ?>

        <p class="helper">Le code expire après 10 minutes.</p>

        <?php if (!empty($pending['debug_message'])): ?>
            <div class="debug"><?php echo htmlspecialchars($pending['debug_message']); ?></div>
        <?php endif; ?>

        <?php if (!empty($pending['otp']) && empty($success)): ?>
            <div class="debug">
                <strong>Mode test :</strong> code OTP de démonstration : <strong><?php echo htmlspecialchars($pending['otp']); ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const otpForm = document.getElementById('otpForm');
        const otpHidden = document.getElementById('otp');
        const otpInputs = Array.from(document.querySelectorAll('.otp-input'));

        function combineOtp() {
            const code = otpInputs.map(input => input.value.replace(/\D/g, '')).join('');
            otpHidden.value = code;
            return code;
        }

        otpInputs.forEach((input, index) => {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 1);
                const next = otpInputs[index + 1];
                if (this.value && next) {
                    next.focus();
                }
                combineOtp();
            });

            input.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !this.value && index > 0) {
                    otpInputs[index - 1].focus();
                }

                if (event.key === 'ArrowLeft' && index > 0) {
                    otpInputs[index - 1].focus();
                }

                if (event.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('paste', function (event) {
                event.preventDefault();
                const pasted = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
                if (!pasted) return;

                pasted.split('').forEach((digit, position) => {
                    if (otpInputs[position]) {
                        otpInputs[position].value = digit;
                    }
                });
                combineOtp();
                let lastFilled = null;
                for (let i = otpInputs.length - 1; i >= 0; i--) {
                    if (otpInputs[i].value) {
                        lastFilled = otpInputs[i];
                        break;
                    }
                }
                if (lastFilled) {
                    lastFilled.focus();
                }
            });
        });

        if (otpForm) {
            otpForm.addEventListener('submit', function (event) {
                const code = combineOtp();
                if (!/^\d{6}$/.test(code)) {
                    event.preventDefault();
                    const firstEmpty = otpInputs.find(input => !input.value);
                    if (firstEmpty) firstEmpty.focus();
                    alert('Le code OTP doit contenir 6 chiffres.');
                }
            });
        }
    </script>
</body>
</html>
