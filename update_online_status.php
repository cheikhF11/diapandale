<?php
/**
 * Endpoint appelé toutes les 2 minutes en arrière-plan (voir accueil.php)
 * pour maintenir à jour le statut "en ligne" / "dernière connexion" du membre.
 */
session_start();

require_once __DIR__ . '/dataBase/bd.php';

header('Content-Type: application/json');

if (!isset($_SESSION['number']) || !isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'non_connecte']);
    exit;
}

try {
    $connexion = getDatabaseConnection();
    $query = $connexion->prepare(
        "UPDATE membre SET statut_en_ligne = 1, derniere_connexion = NOW() WHERE num = ?"
    );
    $query->execute([$_SESSION['number']]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Erreur update_online_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
