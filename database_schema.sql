-- ═══════════════════════════════════════════════════════════════════════
--  DAHIRA NDIAPANDAL BOU SERIGNE SALIOU MBACKE
--  Script de création de la base de données
--  À exécuter en une seule fois dans phpMyAdmin (onglet "SQL") ou via
--  la ligne de commande MySQL : mysql -u root -p < database_schema.sql
-- ═══════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS diapandale CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE diapandale;

-- ───────────────────────────────────────────────────────────────────────
-- Table des membres du Dahira
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS membre (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    num                 VARCHAR(30)  NOT NULL UNIQUE,          -- numéro WhatsApp (identifiant de connexion)
    prenom              VARCHAR(100) NOT NULL,
    nom                 VARCHAR(100) NOT NULL,
    email               VARCHAR(255) NOT NULL UNIQUE,
    adresse             VARCHAR(255) NULL,                     -- adresse du membre (visible dans sa fiche)
    date_naissance      DATE         NULL,                     -- visible UNIQUEMENT par l'administration
    genre               VARCHAR(20)  NOT NULL,                 -- Homme / Femme
    profession          VARCHAR(50)  NOT NULL,                 -- travailleur / etudiant / eleve
    profession_details  VARCHAR(255) NULL,                     -- métier (si travailleur)
    niveau_etude        VARCHAR(100) NULL,                     -- (si étudiant)
    filiere             VARCHAR(255) NULL,                     -- (si étudiant)
    classe              VARCHAR(50)  NULL,                     -- (si élève)
    role                VARCHAR(100) DEFAULT NULL,              -- admin_daara / admin_membre / ou combinaison: admin_daara,admin_membre
    passeword           VARCHAR(255) NOT NULL,                 -- mot de passe haché (password_hash)
    confirm             TINYINT(1)   NOT NULL DEFAULT 0,        -- compte confirmé par OTP email
    otp_code            VARCHAR(10)  NULL,
    otp_expires_at      DATETIME     NULL,
    otp_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- essais de code incorrects
    otp_sent_at         DATETIME     NULL,                     -- dernier envoi du code (limite le renvoi)
    reset_token_hash    CHAR(64)     NULL,                     -- lien « mot de passe oublié » (haché)
    reset_expires_at    DATETIME     NULL,
    a_photo             TINYINT(1)   NOT NULL DEFAULT 0,       -- le membre a une photo de profil
    photo_maj           INT UNSIGNED NULL,                     -- horodatage de la photo (cache)
    statut_en_ligne     TINYINT(1)   NOT NULL DEFAULT 0,
    derniere_connexion  DATETIME     NULL,
    date_inscription    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Informations générales du Dahira (thème du mois, séance, conférencier…)
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS daara_info (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    theme               VARCHAR(255) NOT NULL,
    conferencier        VARCHAR(255) NOT NULL,
    date_seance         DATETIME     NOT NULL,
    lieu                VARCHAR(255) NOT NULL,
    contact             VARCHAR(100) NOT NULL,
    programme           TEXT         NOT NULL,
    description         TEXT         NULL,
    facebook_url        VARCHAR(255) NULL,
    instagram_url       VARCHAR(255) NULL,
    telegram_url        VARCHAR(255) NULL,
    youtube_url         VARCHAR(255) NULL,
    whatsapp_url        VARCHAR(255) NULL,
    website_url         VARCHAR(255) NULL,
    modifie_par         VARCHAR(30)  NULL,
    date_modification   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Historique des modifications faites par les admins
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS daara_historique (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    action       VARCHAR(255) NOT NULL,
    details      TEXT         NULL,
    modifie_par  VARCHAR(30)  NULL,
    date_action  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Sessions PHP (nécessaire sur Vercel : le disque y est éphémère, les sessions
-- sont donc stockées ici). La table est aussi créée automatiquement au premier
-- accès si elle n'existe pas ; ce bloc est fourni pour information.
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS php_sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMBLOB   NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    INDEX idx_php_sessions_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Photos de profil (stockées en base : le disque de Vercel est éphémère)
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS membre_photo (
    membre_id   INT         NOT NULL PRIMARY KEY,
    mime        VARCHAR(40) NOT NULL,
    data        MEDIUMBLOB  NOT NULL,                          -- 480 × 480 px
    thumb_mime  VARCHAR(40) NOT NULL,
    thumb       MEDIUMBLOB  NOT NULL,                          -- 160 × 160 px (listes)
    updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Protection contre les tentatives de connexion répétées
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    identifiant     VARCHAR(255) NOT NULL,
    ip              VARCHAR(45)  NOT NULL,
    date_tentative  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tentatives_id (identifiant, date_tentative),
    INDEX idx_tentatives_ip (ip, date_tentative)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Messagerie : e-mails envoyés à tous les membres (ou à un groupe)
-- ───────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS message_campagne (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    sujet          VARCHAR(255) NOT NULL,
    contenu        MEDIUMTEXT   NOT NULL,
    audience       VARCHAR(30)  NOT NULL DEFAULT 'tous',
    total          INT          NOT NULL DEFAULT 0,
    envoyes        INT          NOT NULL DEFAULT 0,
    echecs         INT          NOT NULL DEFAULT 0,
    dernier_id     INT          NOT NULL DEFAULT 0,
    statut         VARCHAR(20)  NOT NULL DEFAULT 'en_cours',  -- en_cours / termine / annule
    cree_par       VARCHAR(30)  NULL,
    date_creation  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin       DATETIME     NULL,
    INDEX idx_campagne_statut (statut),
    INDEX idx_campagne_date (date_creation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ───────────────────────────────────────────────────────────────────────
-- Index de performance
-- ───────────────────────────────────────────────────────────────────────
CREATE INDEX idx_membre_num          ON membre(num);
CREATE INDEX idx_membre_email        ON membre(email);
CREATE INDEX idx_membre_confirm      ON membre(confirm);
CREATE INDEX idx_membre_role         ON membre(role);
CREATE INDEX idx_membre_online       ON membre(statut_en_ligne);
CREATE INDEX idx_daara_info_date     ON daara_info(date_seance);

-- ───────────────────────────────────────────────────────────────────────
-- (Optionnel) Attribution des rôles administrateurs
-- Remplacez les numéros ci-dessous par les vrais numéros WhatsApp des
-- membres concernés APRÈS qu'ils se soient inscrits via form.php.
-- ───────────────────────────────────────────────────────────────────────
-- UPDATE membre SET role = 'admin_daara'   WHERE num IN ('+221771234567', '+221779876543');
-- UPDATE membre SET role = 'admin_membre'  WHERE num IN ('+221776666666');
