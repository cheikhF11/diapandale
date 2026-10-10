<?php
/**
 * Connexion à la base de données.
 * Les identifiants proviennent toujours du fichier .env (en local) ou des variables
 * d'environnement Vercel (en production) et ne sont jamais codés en dur dans le code
 * applicatif.
 *
 * Variables supportées :
 *   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD   (obligatoires, sauf mot de passe vide en local)
 *   DB_PORT      (optionnel, défaut 3306)
 *   DB_SSL       (optionnel : "true" pour chiffrer la connexion en TLS — requis par la
 *                 plupart des bases MySQL hébergées : TiDB Cloud, Aiven, PlanetScale...)
 *   DB_SSL_CA    (optionnel : chemin d'un certificat CA ; sinon le CA système est utilisé)
 *   DB_SSL_VERIFY (optionnel : "false" pour chiffrer sans vérifier le certificat du serveur,
 *                 uniquement si le fournisseur utilise un CA privé que vous ne pouvez pas fournir)
 */
require_once __DIR__ . '/../config/env.php';

function ensureMemberProfileColumns(PDO $connexion): void {
    $columns = $connexion->query("SHOW COLUMNS FROM membre")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('adresse', $columns, true)) {
        $connexion->exec("ALTER TABLE membre ADD COLUMN adresse VARCHAR(255) NULL AFTER email");
    }

    if (!in_array('photo_profil', $columns, true)) {
        $connexion->exec("ALTER TABLE membre ADD COLUMN photo_profil TEXT NULL AFTER email");
    } else {
        $columnDef = $connexion->query("SHOW COLUMNS FROM membre LIKE 'photo_profil'")->fetch(PDO::FETCH_ASSOC);
        if ($columnDef && stripos((string) ($columnDef['Type'] ?? ''), 'text') === false && (int) ($columnDef['Length'] ?? 0) < 5000) {
            $connexion->exec("ALTER TABLE membre MODIFY photo_profil TEXT NULL");
        }
    }
}

function ensureDaaraInfoColumns(PDO $connexion): void {
    // Aucune colonne de réseau n'est gérée ici : les liens de groupes sont désormais
    // stockés séparément dans la table `group_links` pour garder le Dahira propre.
    $columns = $connexion->query("SHOW COLUMNS FROM daara_info")->fetchAll(PDO::FETCH_COLUMN);

    foreach (['facebook_url', 'instagram_url', 'youtube_url', 'website_url'] as $column) {
        if (in_array($column, $columns, true)) {
            $connexion->exec("ALTER TABLE daara_info DROP COLUMN {$column}");
        }
    }
}

function ensureGroupLinksColumns(PDO $connexion): void {
    $tables = $connexion->query("SHOW TABLES LIKE 'group_links'")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        $connexion->exec(
            "CREATE TABLE IF NOT EXISTS group_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_url VARCHAR(255) NULL,
                whatsapp_url VARCHAR(255) NULL,
                modifie_par VARCHAR(30) NULL,
                date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return;
    }

    $columns = $connexion->query("SHOW COLUMNS FROM group_links")->fetchAll(PDO::FETCH_COLUMN);

    foreach (['telegram_url', 'whatsapp_url'] as $column) {
        if (!in_array($column, $columns, true)) {
            $connexion->exec("ALTER TABLE group_links ADD COLUMN {$column} VARCHAR(255) NULL");
        }
    }
}

function getDatabaseConnection() {
    // Une seule connexion par requête (partagée entre le gestionnaire de sessions et la page).
    static $connexion = null;
    if ($connexion instanceof PDO) {
        return $connexion;
    }

    $host     = trim((string) env('DB_HOST', ''));
    $dbname   = trim((string) env('DB_NAME', ''));
    $username = trim((string) env('DB_USER', ''));
    $password = (string) env('DB_PASSWORD', '');
    $port     = (int) env('DB_PORT', 0);

    if ($host === '' || $dbname === '' || $username === '') {
        throw new RuntimeException('Configuration de base de données incomplète. Vérifiez les variables DB_HOST, DB_NAME et DB_USER dans le fichier .env.');
    }

    $dsn = "mysql:host={$host};" . ($port > 0 ? "port={$port};" : '') . "dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Connexion chiffrée (TLS) pour les bases MySQL distantes.
    if (in_array(strtolower((string) env('DB_SSL', 'false')), ['1', 'true', 'yes', 'on'], true)) {
        $ca = trim((string) env('DB_SSL_CA', ''));
        if ($ca === '') {
            foreach ([
                '/etc/pki/tls/certs/ca-bundle.crt',      // Amazon Linux (Vercel)
                '/etc/ssl/certs/ca-certificates.crt',    // Debian / Ubuntu
                '/etc/ssl/cert.pem',                     // macOS / Alpine
            ] as $candidate) {
                if (is_readable($candidate)) {
                    $ca = $candidate;
                    break;
                }
            }
        }
        $verify = !in_array(strtolower((string) env('DB_SSL_VERIFY', 'true')), ['0', 'false', 'no', 'off'], true);
        if ($ca !== '' && is_readable($ca)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
        } else {
            // Aucun CA trouvé : connexion chiffrée mais sans vérification du certificat.
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
        }
    }

    try {
        $connexion = new PDO($dsn, $username, $password, $options);
        ensureMemberProfileColumns($connexion);
        ensureDaaraInfoColumns($connexion);
        ensureGroupLinksColumns($connexion);

        return $connexion;
    } catch (PDOException $e) {
        error_log('Erreur de connexion BD: ' . $e->getMessage());
        throw $e;
    }
}
