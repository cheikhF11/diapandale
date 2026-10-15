<?php
/**
 * Appelé toutes les 2 minutes en arrière-plan (voir app.js) pour maintenir le
 * statut « en ligne » du membre. Renouvelle aussi la session persistante.
 */
require_once __DIR__ . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'methode'], 405);
}
if (empty($_SESSION['number']) || empty($_SESSION['logged_in'])) {
    jsonResponse(['success' => false, 'error' => 'non_connecte'], 401);
}
if (!csrfValid()) {
    jsonResponse(['success' => false, 'error' => 'csrf'], 403);
}

try {
    getDatabaseConnection()
        ->prepare("UPDATE membre SET statut_en_ligne = 1, derniere_connexion = NOW() WHERE num = ?")
        ->execute([(string) $_SESSION['number']]);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('update_online_status : ' . $e->getMessage());
    jsonResponse(['success' => false], 500);
}
