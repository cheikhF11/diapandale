# 🌙 Dahira Ndiapandal Bou Serigne Saliou Mbacke — Guide d'installation

Plateforme de gestion des membres, avec tableau de bord interactif et gestion
des accès administrateurs.

## ☁️ Déploiement sur Vercel

Le projet est prêt pour Vercel (runtime PHP `vercel-php`, sessions stockées en base MySQL,
routeur sécurisé). **Guide complet pas à pas : [`DEPLOIEMENT_VERCEL.md`](DEPLOIEMENT_VERCEL.md).**
Attention : il faut une base MySQL accessible depuis Internet (InfinityFree ne l'est pas).

## 📋 Prérequis

- PHP 7.4 ou supérieur
- MySQL 5.7 ou supérieur
- Serveur web (Apache/Nginx) ou XAMPP/WAMP en local
- Composer (uniquement si vous voulez réinstaller les dépendances vous-même — le
  dossier `vendor/` est déjà fourni et fonctionne tel quel)

## 🚀 Installation

### 1. Base de données

Importez le script fourni **`database_schema.sql`** (script unique et complet,
sans étape supplémentaire) :

```bash
mysql -u root -p < database_schema.sql
```

Ou depuis phpMyAdmin : onglet **SQL** → collez le contenu de `database_schema.sql`
→ **Exécuter**.

Cela crée la base `bichroulbase` et les tables : `membre`, `daara_info`,
`daara_historique`, ainsi que les index nécessaires.

### 2. Configuration (fichier `.env`)

Toutes les informations sensibles (base de données, SMTP) sont dans un fichier
**`.env`** à la racine, au lieu d'être codées en dur dans chaque page (c'était
le principal problème de sécurité de l'ancienne version : les identifiants
MySQL et le mot de passe SMTP étaient dupliqués et visibles dans plusieurs
fichiers PHP).

Un fichier `.env` est déjà présent avec les identifiants d'hébergement actuels
(InfinityFree). Si vous changez d'hébergement ou d'environnement (local, autre
serveur), copiez `.env.example` en `.env` et renseignez vos propres valeurs :

```
DB_HOST=...
DB_NAME=...
DB_USER=...
DB_PASSWORD=...

SMTP_HOST=smtp.gmail.com
SMTP_USERNAME=...
SMTP_PASSWORD=...
```

⚠️ **Le fichier `.env` ne doit jamais être rendu public** (il est déjà exclu
via `.gitignore`). Sur un hébergement mutualisé comme InfinityFree, assurez-vous
simplement qu'il est bien uploadé au même niveau que `index.php`.

### 3. Structure du projet

```
/
├── index.php                 # Connexion
├── form.php                  # Inscription
├── accueil.php                # Accueil membre (recherche, stats)
├── dashboard.php               # 📊 Tableau de bord interactif
├── admin_daara.php            # Admin : informations du Daara
├── admin_membres.php          # Admin : gestion des membres
├── update_online_status.php     # Endpoint AJAX statut en ligne
├── verify_otp.php              # Vérification du code reçu par email
├── logout.php
│
├── api/
│   ├── index.php                # (Vercel) routeur / point d'entrée unique
│   └── php.ini                   # (Vercel) réglages PHP
├── config/
│   ├── env.php                 # Chargeur de variables d'environnement
│   └── session.php              # (Vercel) sessions stockées en MySQL
├── dataBase/
│   └── bd.php                   # Connexion PDO centralisée
├── securite/
│   └── securisation.php          # Nettoyage des entrées utilisateur
├── sendMail/
│   └── mail.php                  # Envoi d'emails (PHPMailer)
│
├── assets/
│   ├── css/daara-theme.css        # Charte graphique commune (voir palette)
│   ├── js/
│   └── img/
│
├── .env                          # Identifiants (ne pas partager)
├── .env.example                   # Modèle
├── vercel.json                    # (Vercel) configuration du déploiement
├── .vercelignore                  # (Vercel) fichiers non envoyés
├── DEPLOIEMENT_VERCEL.md          # Guide de déploiement Vercel
├── database_schema.sql            # Script de création de la base
└── vendor/                        # Dépendances Composer (PHPMailer)
```

### 4. Audio de bienvenue (optionnel)

Créez un dossier `audio/` à la racine avec un fichier `welcome.mp3` (message de
bienvenue). Si le fichier est absent, la page fonctionne normalement — la
lecture audio échoue simplement en silence.

### 5. Configuration des rôles administrateurs

Après inscription de quelques membres via `form.php`, attribuez les rôles en
base (exemples fournis en commentaire à la fin de `database_schema.sql`) :

```sql
UPDATE membre SET role = 'admin_daara'  WHERE num IN ('+221771234567', '+221779876543');
UPDATE membre SET role = 'admin_membre' WHERE num IN ('+221776666666');
```

## 🎨 Charte graphique officielle

Centralisée dans `assets/css/daara-theme.css` :

| Élément                       | Couleur   |
| ----------------------------- | --------- |
| Navbar                        | `#111111` |
| Texte navbar                  | `#FFFFFF` |
| Fond général                  | `#F2F2F2` |
| Cartes                        | `#FFFFFF` |
| Titres                        | `#111111` |
| Texte courant                 | `#4A4A4A` |
| Bouton principal              | `#111111` |
| Bouton au survol              | `#B58B45` |
| Éléments décoratifs/religieux | `#B58B45` |
| Bordures                      | `#D0D0D0` |

Cette palette a été appliquée sur **toutes** les pages (connexion, inscription,
accueil, dashboard, pages admin).

## 📊 Tableau de bord interactif

`dashboard.php` affiche, en temps réel depuis la base de données :

- 4 indicateurs clés (membres confirmés, en ligne, actifs, en attente)
- Un graphique d'évolution des inscriptions (12 derniers mois)
- La répartition par genre (donut)
- La répartition par profession (barres)
- La liste des derniers membres inscrits

Réalisé avec **Chart.js** (chargé via CDN, aucune installation nécessaire),
dans les couleurs de la charte graphique ci-dessus.

## 🔒 Corrections de sécurité et de fiabilité apportées

- **Identifiants centralisés** : plus aucun mot de passe ou identifiant codé en
  dur et dupliqué dans chaque fichier — tout passe par `.env`.
- **Bug corrigé** : `accueil.php` mettait à jour des colonnes (`is_online`,
  `last_seen`) qui n'existaient pas dans la base — remplacées par les bonnes
  colonnes du schéma (`statut_en_ligne`, `derniere_connexion`).
- **Fichier manquant ajouté** : `update_online_status.php`, appelé par le
  JavaScript de `accueil.php` toutes les 2 minutes, n'existait pas (404) → créé.
- **Administration centrée sur les rôles réels** : les accès sont gérés via les
  rôles `admin_daara` et `admin_membre`, sans module dédié supplémentaire.
- **Fichiers étrangers au projet supprimés** : le zip contenait des résidus
  d'un ancien projet immobilier sans rapport (`css/`, `js/` avec Bootstrap et
  des scripts de gestion de villas/locations, un dossier `PHPMailer/` en double
  faisant doublon avec `vendor/phpmailer`). Tout cela a été retiré.
- **Variables CSS incohérentes corrigées** : certaines règles de style
  pointaient vers des variables renommées/supprimées (`var(--green)`,
  `var(--gold)`…) et ne s'appliquaient plus — corrigé avec des alias de
  compatibilité.
- **Fonctionnalités Cotisations et Messagerie retirées** du projet à la
  demande (pages, requêtes SQL, tables, liens de menu et onglets associés
  supprimés partout).

## 🎯 Fonctionnalités

### Pour tous les membres

- Inscription avec détails selon profession, connexion sécurisée (hash + OTP email)
- Tableau de bord interactif et statistiques
- Recherche de membres par nom, consultation des infos du Daara
- Statut en ligne / dernière connexion

### Admin Daara

- Modification du thème du mois, du conférencier, planification des séances, historique

### Admin Membres

- Ajout et suppression de membres, gestion des droits d’administration

## 🐛 Dépannage

**Erreur de connexion à la base** → vérifiez les valeurs dans `.env`, assurez-vous
que le fichier a bien été uploadé sur le serveur (à la racine, comme `index.php`).

**L'audio ne se lance pas** → normal si `/audio/welcome.mp3` n'existe pas encore ;
ajoutez le fichier pour l'activer.

**Un rôle admin ne voit pas son menu** → vérifiez la colonne `role` dans `membre`,
puis déconnectez/reconnectez le compte concerné.

## 📞 Support

Pour toute question, contactez l'administrateur technique du Dahira.

---

**🌙 Conçu par des membres du Dahira Ndiapandal Bou Serigne Saliou Mbacke © 2026**
