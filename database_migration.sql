-- ═══════════════════════════════════════════════════════════════════════
--  MISE À NIVEAU d'une base EXISTANTE (photos de profil, adresse, messagerie…)
--
--  ⚠️ FACULTATIF : le site applique automatiquement ces modifications à la
--  première connexion (voir config/schema.php). N'utilisez ce script que si
--  l'utilisateur MySQL n'a pas le droit ALTER / CREATE.
--
--  Exécutez-le UNE SEULE FOIS. Si une colonne existe déjà, MySQL répondra
--  « Duplicate column name » : ignorez simplement cette ligne et continuez.
--  Aucune donnée existante n'est modifiée ni supprimée.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE membre ADD COLUMN adresse          VARCHAR(255) NULL;
ALTER TABLE membre ADD COLUMN a_photo          TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE membre ADD COLUMN photo_maj        INT UNSIGNED NULL;
ALTER TABLE membre ADD COLUMN otp_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE membre ADD COLUMN otp_sent_at      DATETIME     NULL;
ALTER TABLE membre ADD COLUMN reset_token_hash CHAR(64)     NULL;
ALTER TABLE membre ADD COLUMN reset_expires_at DATETIME     NULL;

ALTER TABLE daara_info ADD COLUMN instagram_url VARCHAR(255) NULL;
ALTER TABLE daara_info ADD COLUMN website_url   VARCHAR(255) NULL;
ALTER TABLE daara_info ADD COLUMN telegram_url  VARCHAR(255) NULL;
ALTER TABLE daara_info ADD COLUMN whatsapp_url  VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS membre_photo (
    membre_id   INT         NOT NULL PRIMARY KEY,
    mime        VARCHAR(40) NOT NULL,
    data        MEDIUMBLOB  NOT NULL,
    thumb_mime  VARCHAR(40) NOT NULL,
    thumb       MEDIUMBLOB  NOT NULL,
    updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    identifiant     VARCHAR(255) NOT NULL,
    ip              VARCHAR(45)  NOT NULL,
    date_tentative  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tentatives_id (identifiant, date_tentative),
    INDEX idx_tentatives_ip (ip, date_tentative)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS message_campagne (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
