<?php
/**
 * Messagerie de masse : envoi d'un e-mail à tous les membres (ou à un groupe).
 *
 * L'envoi se fait « par lots » : chaque requête envoie un lot (25 destinataires
 * par défaut) puis rend la main. La page d'administration enchaîne les lots en
 * affichant une barre de progression. Résultat : aucun dépassement de durée
 * sur Vercel (30 s), même avec des centaines de membres, et un envoi interrompu
 * peut être repris là où il s'est arrêté.
 */

const CAMPAGNE_AUDIENCES = [
    'tous'        => 'Tous les membres',
    'travailleur' => 'Les travailleurs',
    'etudiant'    => 'Les étudiants',
    'eleve'       => 'Les élèves',
    'homme'       => 'Les hommes',
    'femme'       => 'Les femmes',
];

/** @return array{0:string,1:array} clause WHERE + paramètres */
function campagneWhere(string $audience): array
{
    $sql = "confirm = 1 AND email IS NOT NULL AND email <> ''";
    $params = [];

    switch ($audience) {
        case 'travailleur':
        case 'etudiant':
        case 'eleve':
            $sql .= ' AND profession = ?';
            $params[] = $audience;
            break;
        case 'homme':
            $sql .= " AND genre = 'Homme'";
            break;
        case 'femme':
            $sql .= " AND genre = 'Femme'";
            break;
    }

    return [$sql, $params];
}

/** Nombre de destinataires par groupe (une seule requête). */
function campagneCounts(PDO $db): array
{
    $r = $db->query(
        "SELECT COUNT(*) AS tous,
                COALESCE(SUM(profession = 'travailleur'), 0) AS travailleur,
                COALESCE(SUM(profession = 'etudiant'), 0) AS etudiant,
                COALESCE(SUM(profession = 'eleve'), 0) AS eleve,
                COALESCE(SUM(genre = 'Homme'), 0) AS homme,
                COALESCE(SUM(genre = 'Femme'), 0) AS femme
         FROM membre WHERE confirm = 1 AND email IS NOT NULL AND email <> ''"
    )->fetch();

    $out = [];
    foreach (array_keys(CAMPAGNE_AUDIENCES) as $key) {
        $out[$key] = (int) ($r[$key] ?? 0);
    }
    return $out;
}

/** Crée une campagne et renvoie son identifiant (0 s'il n'y a aucun destinataire). */
function campagneCreate(PDO $db, string $sujet, string $contenu, string $audience, string $auteur): int
{
    if (!isset(CAMPAGNE_AUDIENCES[$audience])) {
        $audience = 'tous';
    }
    [$where, $params] = campagneWhere($audience);

    $q = $db->prepare("SELECT COUNT(*) FROM membre WHERE {$where}");
    $q->execute($params);
    $total = (int) $q->fetchColumn();
    if ($total === 0) {
        return 0;
    }

    $db->prepare(
        "INSERT INTO message_campagne (sujet, contenu, audience, total, cree_par) VALUES (?, ?, ?, ?, ?)"
    )->execute([$sujet, $contenu, $audience, $total, $auteur]);

    return (int) $db->lastInsertId();
}

function campagneEtat(array $c, ?string $erreur = null): array
{
    return [
        'ok'       => true,
        'id'       => (int) $c['id'],
        'statut'   => (string) $c['statut'],
        'done'     => $c['statut'] !== 'en_cours',
        'total'    => (int) $c['total'],
        'envoyes'  => (int) $c['envoyes'],
        'echecs'   => (int) $c['echecs'],
        'erreur'   => $erreur,
    ];
}

function campagneLoad(PDO $db, int $id): ?array
{
    $q = $db->prepare("SELECT * FROM message_campagne WHERE id = ?");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Envoie le prochain lot d'une campagne et renvoie son état. */
function campagneBatch(PDO $db, int $id): array
{
    $c = campagneLoad($db, $id);
    if (!$c) {
        return ['ok' => false, 'error' => 'Envoi introuvable.'];
    }
    if ($c['statut'] !== 'en_cours') {
        return campagneEtat($c);
    }
    if (mailConfig() === null) {
        return ['ok' => false, 'error' => "L'envoi d'e-mails n'est pas configuré sur le serveur (variables SMTP_*)."];
    }

    [$where, $params] = campagneWhere((string) $c['audience']);
    $size = mailBatchSize();

    $sel = $db->prepare("SELECT id, email FROM membre WHERE {$where} AND id > ? ORDER BY id ASC LIMIT {$size}");
    $sel->execute(array_merge($params, [(int) $c['dernier_id']]));
    $rows = $sel->fetchAll();

    if ($rows) {
        $last = (int) $rows[count($rows) - 1]['id'];

        // « Réservation » du lot : deux requêtes simultanées ne peuvent pas envoyer le même lot.
        $claim = $db->prepare(
            "UPDATE message_campagne SET dernier_id = ? WHERE id = ? AND dernier_id = ? AND statut = 'en_cours'"
        );
        $claim->execute([$last, $id, (int) $c['dernier_id']]);
        if ($claim->rowCount() !== 1) {
            return campagneEtat(campagneLoad($db, $id) ?: $c);
        }

        $res = envoyerMailGroupe(
            array_column($rows, 'email'),
            (string) $c['sujet'],
            mailParagraphs((string) $c['contenu']),
            (string) $c['sujet']
        );

        $db->prepare("UPDATE message_campagne SET envoyes = envoyes + ?, echecs = echecs + ? WHERE id = ?")
           ->execute([$res['sent'], $res['failed'], $id]);

        $more = $db->prepare("SELECT 1 FROM membre WHERE {$where} AND id > ? LIMIT 1");
        $more->execute(array_merge($params, [$last]));
        $hasMore = (bool) $more->fetchColumn();
    } else {
        $res = ['error' => null];
        $hasMore = false;
    }

    if (!$hasMore) {
        $db->prepare("UPDATE message_campagne SET statut = 'termine', date_fin = NOW() WHERE id = ? AND statut = 'en_cours'")
           ->execute([$id]);
    }

    return campagneEtat(campagneLoad($db, $id) ?: $c, $res['error'] ?? null);
}

function campagneCancel(PDO $db, int $id): void
{
    $db->prepare("UPDATE message_campagne SET statut = 'annule', date_fin = NOW() WHERE id = ? AND statut = 'en_cours'")
       ->execute([$id]);
}

function campagneStatutLabel(string $statut): array
{
    switch ($statut) {
        case 'termine':
            return ['Terminé', 'badge--success'];
        case 'annule':
            return ['Annulé', 'badge--muted'];
        default:
            return ['En cours', 'badge--warning'];
    }
}
