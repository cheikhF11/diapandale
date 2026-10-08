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

        return $connexion;
    } catch (PDOException $e) {
        error_log('Erreur de connexion BD: ' . $e->getMessage());
        throw $e;
    }
}
