<?php
/**
 * Amorçage commun à toutes les pages.
 *
 *  - fuseau horaire (Sénégal) et en-têtes de sécurité ;
 *  - session PERSISTANTE (30 jours, renouvelée à chaque visite) stockée en base
 *    de données : le membre reste connecté d'un appareil / d'un redémarrage à
 *    l'autre, y compris sur Vercel où le disque est éphémère ;
 *  - mise à niveau automatique du schéma de la base (colonnes photo, adresse…) ;
 *  - fonctions utilitaires partagées.
 *
 * Usage : require_once __DIR__ . '/config/app.php';
 */

define('APP_NAME', 'DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE');
define('APP_NAME_SHORT', 'Dahira Ndiapandal');
define('APP_SUBTITLE', 'Bou Serigne Saliou Mbacké');
define('APP_ROOT', dirname(__DIR__));
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 jours
define('SCHEMA_VERSION', 4);
define('ADMIN_ROLES', ['admin_daara', 'admin_membre']);

date_default_timezone_set('Africa/Dakar');

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../dataBase/bd.php';
require_once __DIR__ . '/../securite/securisation.php';
require_once __DIR__ . '/session.php';   // gestionnaire de sessions en base
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/photo.php';
require_once __DIR__ . '/campagne.php';
require_once __DIR__ . '/../sendMail/mail.php';
require_once __DIR__ . '/../includes/layout.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
    // Les pages contiennent des données de membres : jamais de cache partagé.
    header('Cache-Control: no-store, private');
}

// Toute erreur imprévue : message sobre pour le visiteur, détail dans les journaux du serveur.
set_exception_handler(function (Throwable $e) {
    error_log('Exception non gérée : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Service momentanément indisponible</title></head>'
       . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f3ef;font-family:system-ui,sans-serif;color:#2d2d2d;padding:24px;text-align:center">'
       . '<div><h1 style="color:#141414">Service momentanément indisponible</h1>'
       . '<p>Une erreur technique est survenue. Veuillez réessayer dans quelques instants.</p>'
       . '<p><a href="index.php" style="color:#8c6a2d;font-weight:600">Retour à l’accueil</a></p></div></body></html>';
});

appSessionStart();

// Met la base à niveau une fois par session (et à chaque nouvelle version du schéma).
if (($_SESSION['schema_v'] ?? 0) !== SCHEMA_VERSION) {
    try {
        if (ensureSchema(getDatabaseConnection())) {
            $_SESSION['schema_v'] = SCHEMA_VERSION;
        }
    } catch (Throwable $e) {
        error_log('Mise à niveau du schéma impossible : ' . $e->getMessage());
    }
}
