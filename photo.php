<?php
/**
 * Sert la photo de profil d'un membre : photo.php?id=12[&s=full]
 * Réservé aux membres connectés. Mise en cache longue : l'adresse change
 * (paramètre v) dès que la photo est remplacée.
 */
require_once __DIR__ . '/config/app.php';

$loggedIn = !empty($_SESSION['logged_in']) && !empty($_SESSION['number']);
session_write_close(); // libère la session : les images se chargent en parallèle

if (!$loggedIn) {
    http_response_code(401);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$full = (($_GET['s'] ?? '') === 'full');

try {
    $q = getDatabaseConnection()->prepare(
        "SELECT p.mime, p.data, p.thumb_mime, p.thumb, m.photo_maj
         FROM membre_photo p JOIN membre m ON m.id = p.membre_id
         WHERE p.membre_id = ? AND m.confirm = 1 LIMIT 1"
    );
    $q->execute([$id]);
    $row = $q->fetch();
} catch (Throwable $e) {
    error_log('photo.php : ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if (!$row) {
    http_response_code(404);
    exit;
}

$mime = $full ? $row['mime'] : $row['thumb_mime'];
$data = $full ? $row['data'] : $row['thumb'];
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !is_string($data) || $data === '') {
    http_response_code(404);
    exit;
}

$etag = '"' . md5($id . '-' . ($full ? 'f' : 't') . '-' . (int) $row['photo_maj'] . '-' . strlen($data)) . '"';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=2592000, immutable');
header('ETag: ' . $etag);

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . strlen($data));
echo $data;
