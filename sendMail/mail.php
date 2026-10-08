<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../securite/securisation.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function envoyerMail($email, $sujet, $message) {
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $smtpHost = trim((string) env('SMTP_HOST', ''));
    $smtpUsername = trim((string) env('SMTP_USERNAME', ''));
    $smtpPassword = (string) env('SMTP_PASSWORD', '');
    $smtpPort = (int) env('SMTP_PORT', 587);
    $smtpSecure = trim((string) env('SMTP_SECURE', PHPMailer::ENCRYPTION_STARTTLS));
    $fromEmail = trim((string) env('SMTP_FROM_EMAIL', ''));
    $fromName = trim((string) env('SMTP_FROM_NAME', 'DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE'));

    if ($smtpHost === '' || $smtpUsername === '' || $smtpPassword === '' || $fromEmail === '') {
        error_log('Configuration SMTP incomplète. Vérifiez les variables SMTP_* dans le fichier .env.');
        return false;
    }

    $sujet = securisation($sujet);
    $message = (string) $message;

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = $smtpSecure !== '' ? $smtpSecure : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort > 0 ? $smtpPort : 587;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = $sujet;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Erreur d\'envoi d\'email: ' . $e->getMessage());
        return false;
    }
}
