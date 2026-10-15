<?php
/**
 * Envoi d'e-mails (PHPMailer + SMTP).
 *
 * Configuration par variables d'environnement :
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE (tls | ssl), SMTP_USERNAME, SMTP_PASSWORD,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME, MAIL_BATCH_SIZE (destinataires par message
 *   groupé, 25 par défaut).
 *
 * Aucun mode « test » : si l'envoi échoue, la fonction renvoie false / une erreur
 * et le détail technique est écrit dans les journaux du serveur.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../securite/securisation.php';

use PHPMailer\PHPMailer\PHPMailer;

/* ═══ Configuration ═════════════════════════════════════════════════════ */

function mailConfig(): ?array
{
    $username  = trim((string) env('SMTP_USERNAME', ''));
    $fromEmail = trim((string) env('SMTP_FROM_EMAIL', ''));
    if ($fromEmail === '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $username;
    }

    $config = [
        'host'       => trim((string) env('SMTP_HOST', '')),
        'port'       => (int) env('SMTP_PORT', 587),
        'secure'     => strtolower(trim((string) env('SMTP_SECURE', 'tls'))),
        'username'   => $username,
        'password'   => (string) env('SMTP_PASSWORD', ''),
        'from_email' => $fromEmail,
        'from_name'  => trim((string) env('SMTP_FROM_NAME', 'DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE')),
    ];

    if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '' || $config['from_email'] === '') {
        error_log('Configuration SMTP incomplète : vérifiez les variables SMTP_* (voir .env.example).');
        return null;
    }

    return $config;
}

function mailBatchSize(): int
{
    return max(1, min(50, (int) env('MAIL_BATCH_SIZE', 25)));
}

/** Prépare un expéditeur PHPMailer prêt à l'emploi (SMTP authentifié, UTF-8, logo intégré). */
function creerMailer(array $config, bool &$hasLogo = false): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host     = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->Port     = $config['port'] > 0 ? $config['port'] : 587;
    $mail->Timeout  = 20;
    $mail->CharSet  = 'UTF-8';

    if ($config['secure'] === 'ssl' || $config['secure'] === 'smtps') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif (in_array($config['secure'], ['none', 'false', 'off', '0'], true)) {
        $mail->SMTPSecure  = '';
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ];

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addReplyTo($config['from_email'], $config['from_name']);
    $mail->isHTML(true);

    // Logo intégré au message (affiché dans l'en-tête).
    $logo = dirname(__DIR__) . '/bmh1.jpeg';
    $hasLogo = false;
    if (is_readable($logo)) {
        try {
            $mail->addEmbeddedImage($logo, 'logo-dahira', 'logo.jpg', 'base64', 'image/jpeg');
            $hasLogo = true;
        } catch (Throwable $e) {
            error_log('Logo e-mail non intégré : ' . $e->getMessage());
        }
    }

    return $mail;
}

/* ═══ Gabarit HTML ══════════════════════════════════════════════════════ */

/** Texte brut → paragraphes HTML sûrs (liens cliquables, retours à la ligne). */
function mailParagraphs(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    $blocks = preg_split("/\n{2,}/", $text) ?: [];
    $html = '';

    foreach ($blocks as $block) {
        $safe = htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace_callback('~(https?://[^\s<]+)~i', function ($m) {
            $url = $m[1];
            $tail = '';
            while ($url !== '' && strpos('.,;:!?)', substr($url, -1)) !== false) {
                $tail = substr($url, -1) . $tail;
                $url = substr($url, 0, -1);
            }
            return '<a href="' . $url . '" style="color:#8c6a2d;">' . $url . '</a>' . $tail;
        }, $safe);
        $html .= '<p style="margin:0 0 14px;">' . nl2br((string) $safe, false) . '</p>';
    }

    return $html;
}

/** Enveloppe le contenu dans le gabarit aux couleurs du Dahira. */
function mailLayout(string $titre, string $contenuHtml, bool $hasLogo = true): string
{
    $titre = htmlspecialchars($titre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = $hasLogo
        ? '<img src="cid:logo-dahira" width="64" height="64" alt="" style="display:block;margin:0 auto 12px;border-radius:50%;border:2px solid #b58b45;">'
        : '';
    $font = 'font-family:Arial,Helvetica,sans-serif;';

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $titre . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f3ef;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ef;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e6e1d8;border-radius:14px;overflow:hidden;">'
        . '<tr><td align="center" style="background:#141414;padding:24px;' . $font . '">' . $logo
        . '<div style="color:#ffffff;font-size:13px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;line-height:1.6;">Dahira Ndiapandal<br>'
        . '<span style="color:#b58b45;">Bou Serigne Saliou Mbacké</span></div></td></tr>'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background:#b58b45;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 26px;' . $font . 'color:#2d2d2d;font-size:15px;line-height:1.65;">'
        . '<h1 style="margin:0 0 18px;font-size:20px;line-height:1.3;color:#141414;">' . $titre . '</h1>'
        . $contenuHtml
        . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 26px;background:#faf8f4;border-top:1px solid #e6e1d8;' . $font . 'font-size:12px;line-height:1.6;color:#6b6b6b;">'
        . 'Vous recevez ce message parce que vous êtes membre du Dahira Ndiapandal Bou Serigne Saliou Mbacké.<br>'
        . 'Merci de ne pas répondre directement à cet e-mail automatique.'
        . '</td></tr></table></td></tr></table></body></html>';
}

function mailPlainText(string $html): string
{
    $html = (string) preg_replace('~<(style|script|title)[^>]*>.*?</\1>~is', '', $html);
    $html = (string) preg_replace('~<br\s*/?>|</p>|</h1>|</tr>~i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
}

function mailSubject(string $subject): string
{
    return trim((string) preg_replace('/[\r\n]+/', ' ', strip_tags($subject)));
}

/* ═══ Envoi ═════════════════════════════════════════════════════════════ */

/**
 * Envoie un e-mail à UN destinataire.
 *
 * @param string      $contenuHtml contenu (HTML) inséré dans le gabarit
 * @param string|null $titre       titre affiché dans le message (par défaut : le sujet)
 */
function envoyerMail($email, $sujet, $contenuHtml, ?string $titre = null): bool
{
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = mailConfig();
    if ($config === null) {
        return false;
    }

    try {
        $hasLogo = false;
        $mail = creerMailer($config, $hasLogo);
        $mail->addAddress($email);
        $mail->Subject = mailSubject((string) $sujet);
        $mail->Body    = mailLayout($titre ?? mailSubject((string) $sujet), (string) $contenuHtml, $hasLogo);
        $mail->AltBody = mailPlainText((string) $contenuHtml);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log("Erreur d'envoi d'e-mail : " . $e->getMessage());
        return false;
    }
}

/**
 * Envoie le même message à plusieurs destinataires, en copie cachée (CCI) :
 * personne ne voit les adresses des autres. Une seule connexion SMTP est
 * réutilisée pour tous les lots.
 *
 * @param string[] $emails
 * @return array{sent:int,failed:int,error:?string}
 */
function envoyerMailGroupe(array $emails, string $sujet, string $contenuHtml, ?string $titre = null): array
{
    $valides = [];
    foreach ($emails as $email) {
        $email = trim((string) $email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valides[strtolower($email)] = $email; // dédoublonnage
        }
    }
    $valides = array_values($valides);
    $result = ['sent' => 0, 'failed' => count($emails) - count($valides), 'error' => null];

    if (!$valides) {
        return $result;
    }

    $config = mailConfig();
    if ($config === null) {
        $result['failed'] += count($valides);
        $result['error'] = "La configuration d'envoi d'e-mails est incomplète.";
        return $result;
    }

    try {
        $hasLogo = false;
        $mail = creerMailer($config, $hasLogo);
        $mail->SMTPKeepAlive = true;
        $mail->Subject = mailSubject($sujet);
        $mail->Body    = mailLayout($titre ?? mailSubject($sujet), $contenuHtml, $hasLogo);
        $mail->AltBody = mailPlainText($contenuHtml);
    } catch (Throwable $e) {
        error_log("Erreur d'initialisation de l'envoi groupé : " . $e->getMessage());
        $result['failed'] += count($valides);
        $result['error'] = "Impossible d'initialiser l'envoi des e-mails.";
        return $result;
    }

    foreach (array_chunk($valides, mailBatchSize()) as $lot) {
        try {
            $mail->clearAllRecipients();
            $mail->addAddress($config['from_email'], $config['from_name']);
            foreach ($lot as $adresse) {
                $mail->addBCC($adresse);
            }
            $mail->send();
            $result['sent'] += count($lot);
        } catch (Throwable $e) {
            error_log("Erreur d'envoi groupé : " . $e->getMessage());
            $result['failed'] += count($lot);
            $result['error'] = "Le serveur d'e-mails a refusé un ou plusieurs envois.";
        }
    }

    try {
        $mail->smtpClose();
    } catch (Throwable $e) {
        // sans importance
    }

    return $result;
}

/* ═══ Messages prédéfinis ═══════════════════════════════════════════════ */

function envoyerCodeOtp(string $email, string $prenom, string $code): bool
{
    $contenu = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="margin:0 0 18px;">Voici votre code de confirmation pour finaliser votre inscription :</p>'
        . '<div style="margin:0 0 18px;padding:18px;text-align:center;background:#f4ead6;border-radius:12px;'
        . 'font-size:34px;font-weight:bold;letter-spacing:10px;color:#141414;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:0 0 14px;">Ce code est valable <strong>10 minutes</strong>. '
        . 'Si vous n’êtes pas à l’origine de cette inscription, ignorez simplement ce message.</p>';

    return envoyerMail($email, 'Votre code de confirmation', $contenu, 'Confirmez votre inscription');
}

function envoyerLienReinitialisation(string $email, string $prenom, string $url): bool
{
    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $contenu = '<p style="margin:0 0 14px;">Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="margin:0 0 18px;">Une demande de réinitialisation de mot de passe a été effectuée pour votre compte. '
        . 'Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe :</p>'
        . '<p style="margin:0 0 20px;text-align:center;"><a href="' . $url . '" style="display:inline-block;padding:14px 28px;'
        . 'background:#141414;color:#ffffff;text-decoration:none;font-weight:bold;border-radius:10px;">Choisir un nouveau mot de passe</a></p>'
        . '<p style="margin:0 0 14px;font-size:13px;color:#6b6b6b;">Ce lien est valable <strong>1 heure</strong>. '
        . 'Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'
        . '<span style="word-break:break-all;">' . $url . '</span></p>'
        . '<p style="margin:0;font-size:13px;color:#6b6b6b;">Si vous n’avez pas fait cette demande, ignorez ce message : votre mot de passe actuel reste valable.</p>';

    return envoyerMail($email, 'Réinitialisation de votre mot de passe', $contenu, 'Réinitialisation du mot de passe');
}
