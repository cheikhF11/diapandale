<?php
/**
 * Point d'entrée unique pour Vercel (runtime vercel-php).
 *
 * Vercel n'exécute pas les fichiers PHP "à la racine" comme Apache : toutes les
 * requêtes sont redirigées ici (voir vercel.json), puis ce routeur :
 *   1. exécute la bonne page PHP du projet (liste blanche, aucune autre exécution possible) ;
 *   2. sert les fichiers statiques autorisés (assets/, logo...) ;
 *   3. répond 404 pour tout le reste (.env, vendor, composer.json, etc. ne sont jamais exposés).
 *
 * Les pages sont incluses au niveau global (pas dans une fonction) afin que leur
 * code se comporte exactement comme avec Apache/XAMPP.
 */

$__root = dirname(__DIR__);

// ── Chemin demandé (sans query string), normalisé ───────────────────────────
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__path = rawurldecode(is_string($__path) ? $__path : '/');
$__path = '/' . trim(str_replace('\\', '/', $__path), '/');

// ── 1. Pages PHP autorisées ─────────────────────────────────────────────────
$__pages = [
    'index', 'form', 'accueil', 'dashboard', 'groupes',
    'admin_daara', 'admin_membres', 'verify_otp',
    'logout', 'update_online_status',
];

$__name = ltrim($__path, '/');
if ($__name === '') {
    $__name = 'index';
}
if (substr($__name, -4) === '.php') {
    $__name = substr($__name, 0, -4);
}

if (in_array($__name, $__pages, true)) {
    $__script = $__root . '/' . $__name . '.php';

    chdir($__root);

    // Mêmes variables serveur que si la page était appelée directement.
    $_SERVER['SCRIPT_FILENAME'] = $__script;
    $_SERVER['SCRIPT_NAME']     = '/' . $__name . '.php';
    $_SERVER['PHP_SELF']        = '/' . $__name . '.php';

    // Sessions stockées en base de données (le disque de Vercel est éphémère).
    require_once $__root . '/config/session.php';

    unset($__root, $__path, $__pages, $__name);
    require $__script;
    return;
}

// ── 2. Fichiers statiques autorisés ─────────────────────────────────────────
$__mimes = [
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'application/javascript; charset=utf-8',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'webp'  => 'image/webp',
    'svg'   => 'image/svg+xml',
    'ico'   => 'image/x-icon',
    'mp3'   => 'audio/mpeg',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
];

$__file = realpath($__root . $__path);
$__ext  = strtolower(pathinfo($__path, PATHINFO_EXTENSION));

$__allowed = false;
if ($__file !== false && is_file($__file) && isset($__mimes[$__ext])) {
    $__rootReal = realpath($__root);
    foreach (['/assets/', '/audio/'] as $__dir) {
        if (strpos($__file, $__rootReal . $__dir) === 0) {
            $__allowed = true;
        }
    }
    // Logo placé à la racine du projet
    if ($__file === $__rootReal . '/bmh1.jpeg') {
        $__allowed = true;
    }
}

if ($__allowed) {
    header('Content-Type: ' . $__mimes[$__ext]);
    header('Content-Length: ' . filesize($__file));
    header('Cache-Control: public, max-age=3600, s-maxage=86400');
    readfile($__file);
    return;
}

// ── 3. Tout le reste : 404 ──────────────────────────────────────────────────
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Page introuvable</title></head>'
   . '<body style="font-family:sans-serif;text-align:center;padding:3rem">'
   . '<h1>404</h1><p>Page introuvable.</p><p><a href="/">Retour à l\'accueil</a></p></body></html>';
