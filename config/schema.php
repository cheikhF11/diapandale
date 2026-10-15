<?php
/**
 * Mise à niveau automatique du schéma.
 *
 * Crée les tables et colonnes ajoutées par cette version (photos de profil,
 * adresse, messagerie, sécurité…) si elles n'existent pas encore. Cela permet de
 * déployer le nouveau code sur une base DÉJÀ remplie sans perdre aucun membre.
 *
 * Si l'utilisateur MySQL n'a pas le droit ALTER / CREATE, exécutez à la main le
 * script « database_migration.sql » (mêmes instructions).
 *
 * @return bool true si tout est à jour
 */
function ensureSchema(PDO $db): bool
{
    $ok = true;

    $tables = [
        'membre_photo' => "CREATE TABLE IF NOT EXISTS membre_photo (
            membre_id  INT          NOT NULL PRIMARY KEY,
            mime       VARCHAR(40)  NOT NULL,
            data       MEDIUMBLOB   NOT NULL,
            thumb_mime VARCHAR(40)  NOT NULL,
            thumb      MEDIUMBLOB   NOT NULL,
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'tentatives_connexion' => "CREATE TABLE IF NOT EXISTS tentatives_connexion (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            identifiant     VARCHAR(255) NOT NULL,
            ip              VARCHAR(45)  NOT NULL,
            date_tentative  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tentatives_id (identifiant, date_tentative),
            INDEX idx_tentatives_ip (ip, date_tentative)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'message_campagne' => "CREATE TABLE IF NOT EXISTS message_campagne (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            sujet          VARCHAR(255) NOT NULL,
            contenu        MEDIUMTEXT   NOT NULL,
            audience       VARCHAR(30)  NOT NULL DEFAULT 'tous',
            total          INT          NOT NULL DEFAULT 0,
            envoyes        INT          NOT NULL DEFAULT 0,
            echecs         INT          NOT NULL DEFAULT 0,
            dernier_id     INT          NOT NULL DEFAULT 0,
            statut         VARCHAR(20)  NOT NULL DEFAULT 'en_cours',
            cree_par       VARCHAR(30)  NULL,
            date_creation  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_fin       DATETIME     NULL,
            INDEX idx_campagne_statut (statut),
            INDEX idx_campagne_date (date_creation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $name => $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            $ok = false;
            error_log("Schéma : création de {$name} impossible : " . $e->getMessage());
        }
    }

    // Colonnes à ajouter aux tables existantes.
    $wanted = [
        'membre' => [
            'adresse'          => 'VARCHAR(255) NULL',
            'a_photo'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            'photo_maj'        => 'INT UNSIGNED NULL',
            'otp_attempts'     => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'otp_sent_at'      => 'DATETIME NULL',
            'reset_token_hash' => 'CHAR(64) NULL',
            'reset_expires_at' => 'DATETIME NULL',
        ],
        'daara_info' => [
            'instagram_url' => 'VARCHAR(255) NULL',
            'website_url'   => 'VARCHAR(255) NULL',
            'telegram_url'  => 'VARCHAR(255) NULL',
            'whatsapp_url'  => 'VARCHAR(255) NULL',
        ],
    ];

    try {
        $rows = $db->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('membre', 'daara_info')"
        )->fetchAll();

        $existing = [];
        foreach ($rows as $r) {
            $existing[strtolower($r['t'])][strtolower($r['c'])] = true;
        }

        foreach ($wanted as $table => $columns) {
            if (empty($existing[$table])) {
                continue; // table absente : base non initialisée (voir database_schema.sql)
            }
            foreach ($columns as $column => $definition) {
                if (isset($existing[$table][$column])) {
                    continue;
                }
                try {
                    $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                } catch (Throwable $e) {
                    $ok = false;
                    error_log("Schéma : ajout de {$table}.{$column} impossible : " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        error_log('Schéma : lecture des colonnes impossible : ' . $e->getMessage());
    }

    return $ok;
}
