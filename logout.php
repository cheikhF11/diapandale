<?php
require_once __DIR__ . '/config/app.php';

// La déconnexion se fait par formulaire POST protégé (évite les déconnexions forcées par un lien tiers).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    if (!empty($_SESSION['number'])) {
        try {
            getDatabaseConnection()
                ->prepare("UPDATE membre SET statut_en_ligne = 0 WHERE num = ?")
                ->execute([(string) $_SESSION['number']]);
        } catch (Throwable $e) {
            error_log('Déconnexion : ' . $e->getMessage());
        }
    }
    destroyAppSession();
    redirect('index.php');
}

redirect(!empty($_SESSION['logged_in']) ? 'accueil.php' : 'index.php');
