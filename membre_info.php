<?php
/**
 * Fiche détaillée d'un membre (JSON), affichée dans la fenêtre modale.
 *
 * Règle de confidentialité : la date de naissance et l'âge ne sont JAMAIS
 * envoyés aux membres ordinaires ; seule l'administration les reçoit.
 */
require_once __DIR__ . '/config/app.php';

if (empty($_SESSION['logged_in']) || empty($_SESSION['number'])) {
    jsonResponse(['ok' => false, 'error' => 'non_connecte'], 401);
}

try {
    $db = getDatabaseConnection();
    $viewer = fetchCurrentUser($db);
    if (!$viewer || (int) $viewer['confirm'] !== 1) {
        jsonResponse(['ok' => false, 'error' => 'non_connecte'], 401);
    }
    $viewerIsAdmin = isAdmin($viewer);

    $id = (int) ($_GET['id'] ?? 0);
    $q = $db->prepare(
        "SELECT id, num, prenom, nom, email, adresse, genre, role, profession, profession_details,
                niveau_etude, filiere, classe, a_photo, photo_maj, date_inscription"
        . ($viewerIsAdmin ? ", date_naissance" : "")
        . " FROM membre WHERE id = ? AND confirm = 1 LIMIT 1"
    );
    $q->execute([$id]);
    $m = $q->fetch();

    if (!$m) {
        jsonResponse(['ok' => false, 'error' => 'introuvable'], 404);
    }

    $lignes = [];
    $lignes[] = ['icone' => 'user', 'label' => 'Genre', 'valeur' => (string) $m['genre']];

    switch ($m['profession']) {
        case 'travailleur':
            $lignes[] = ['icone' => 'briefcase', 'label' => 'Profession', 'valeur' => (string) ($m['profession_details'] ?: 'Non renseignée')];
            break;
        case 'etudiant':
            $lignes[] = ['icone' => 'graduation', 'label' => "Niveau d'études", 'valeur' => (string) ($m['niveau_etude'] ?: 'Non renseigné')];
            if (!empty($m['filiere'])) {
                $lignes[] = ['icone' => 'book', 'label' => 'Filière', 'valeur' => (string) $m['filiere']];
            }
            break;
        case 'eleve':
            $lignes[] = ['icone' => 'graduation', 'label' => 'Classe', 'valeur' => (string) ($m['classe'] ?: 'Non renseignée')];
            break;
    }

    $tel = preg_replace('/[^0-9+]/', '', (string) $m['num']);
    $lignes[] = ['icone' => 'phone', 'label' => 'Téléphone', 'valeur' => (string) $m['num'], 'href' => $tel !== '' ? 'tel:' . $tel : null];
    $lignes[] = ['icone' => 'mail', 'label' => 'E-mail', 'valeur' => (string) $m['email'], 'href' => 'mailto:' . $m['email']];
    $lignes[] = ['icone' => 'map-pin', 'label' => 'Adresse', 'valeur' => trim((string) $m['adresse']) !== '' ? (string) $m['adresse'] : 'Non renseignée'];
    $lignes[] = ['icone' => 'calendar', 'label' => 'Membre depuis', 'valeur' => formatDateFr($m['date_inscription'])];

    if ($viewerIsAdmin) {
        $age = calculateAge($m['date_naissance'] ?? null);
        $lignes[] = ['icone' => 'cake', 'label' => 'Âge (administration)', 'valeur' => $age !== null ? $age . ($age > 1 ? ' ans' : ' an') : 'Non renseigné'];
    }

    $actions = [];
    if ($tel !== '') {
        $actions[] = ['icone' => 'phone', 'label' => 'Appeler', 'href' => 'tel:' . $tel];
    }
    $wa = whatsappDigits((string) $m['num']);
    if ($wa !== '') {
        $actions[] = ['icone' => 'message', 'label' => 'WhatsApp', 'href' => 'https://wa.me/' . $wa];
    }
    $actions[] = ['icone' => 'mail', 'label' => 'E-mail', 'href' => 'mailto:' . $m['email']];

    jsonResponse([
        'ok' => true,
        'member' => [
            'id'         => (int) $m['id'],
            'nom_complet' => memberFullName($m),
            'initiales'  => memberInitials($m),
            'photo'      => memberPhotoUrl($m, true),
            'sous_titre' => memberSubtitle($m),
            'categorie'  => hasRole($m, 'admin_daara') || hasRole($m, 'admin_membre') ? 'Administration' : categorieLabel($m['profession']),
            'lignes'     => $lignes,
            'actions'    => $actions,
        ],
    ]);
} catch (Throwable $e) {
    error_log('membre_info.php : ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'erreur'], 500);
}
