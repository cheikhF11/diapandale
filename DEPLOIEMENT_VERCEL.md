# 🚀 Déploiement sur Vercel — Guide complet

Ce projet (PHP + MySQL) a été adapté pour Vercel **sans modifier la logique des pages**
(`index.php`, `form.php`, `accueil.php`, `dashboard.php`, `admin_*.php`, `verify_otp.php`,
`logout.php`, `update_online_status.php`, `sendMail/`, `securite/` sont inchangés).

> Il continue de fonctionner comme avant sur XAMPP / Apache / InfinityFree.

---

## 1. Ce qui a été ajouté ou modifié (et pourquoi)

| Fichier | Rôle |
| --- | --- |
| `vercel.json` | Indique à Vercel d'exécuter PHP (runtime communautaire `vercel-php@0.7.4`, PHP 8.3) et de rediriger toutes les requêtes vers `api/index.php`. |
| `api/index.php` | **Routeur** : exécute la page demandée (liste blanche des 9 pages), sert `assets/` et le logo `bmh1.jpeg`, et répond **404** pour tout le reste. `.env`, `vendor/`, `config/`, `composer.json`, `*.sql`… ne sont donc jamais accessibles depuis Internet. |
| `api/php.ini` | Désactive l'affichage des erreurs PHP aux visiteurs (elles vont dans les logs Vercel) et sécurise le cookie de session. |
| `config/session.php` | **Sessions stockées dans MySQL** (table `php_sessions`, créée automatiquement). Indispensable : sur Vercel le disque est éphémère et plusieurs instances répondent en parallèle, donc les sessions par fichiers PHP feraient « déconnecter » l'utilisateur à chaque requête. |
| `dataBase/bd.php` | Même fonction `getDatabaseConnection()`, avec en plus : une seule connexion par requête, `DB_PORT` optionnel, et **TLS** (`DB_SSL`, `DB_SSL_CA`, `DB_SSL_VERIFY`) exigé par la plupart des bases MySQL hébergées. Sans ces variables, le comportement est identique à avant. |
| `.vercelignore` | N'envoie pas `.env`, `vendor/`, `.sql`, `.md`, etc. sur Vercel. |
| `composer.lock` | Hash mis à jour (le lock n'était plus synchronisé avec `composer.json`). Mêmes paquets (PHPMailer 7.1.1). |
| `database_schema.sql` | Ajout de la table `php_sessions` (facultatif : elle se crée toute seule). |
| `.env.example`, `.gitignore` | Nouvelles variables documentées ; `.vercel` ignoré. |

Rien d'autre n'a été touché. `.htaccess` (redirection HTTPS) n'est plus utile : Vercel force déjà le HTTPS.

---

## 2. ⚠️ Prérequis indispensable : une base MySQL accessible depuis Internet

La base **InfinityFree (`sql304.infinityfree.com`) n'est joignable que depuis l'hébergement
InfinityFree lui-même**. Vercel ne pourra **pas** s'y connecter. Il faut donc une base MySQL hébergée
ailleurs, avec accès distant. Quelques options (vérifiez les offres gratuites actuelles) :

- **TiDB Cloud (Starter/Serverless)** — compatible MySQL, offre gratuite, TLS. Hôte du type
  `gateway01.xxx.prod.aws.tidbcloud.com`, port **4000**, utilisateur du type `xxxxxx.root`.
- **Aiven for MySQL**, **Railway**, **Clever Cloud**, ou tout MySQL/MariaDB sur un VPS.
- Via le **Marketplace Vercel** (onglet *Storage*) si une base MySQL y est proposée.

Quelle que soit l'option, notez : **hôte, port, nom de base, utilisateur, mot de passe**.

> Vercel n'a pas d'adresse IP fixe (sauf option payante) : dans le pare-feu de la base, autorisez
> les connexions depuis toutes les IP (`0.0.0.0/0`). La sécurité repose alors sur un mot de passe
> fort + TLS.

### 2.1 Créer les tables

1. Créez une base vide (ex. `diapandale`) chez votre fournisseur.
2. Ouvrez `database_schema.sql`, **supprimez les 2 premières lignes de code**
   `CREATE DATABASE IF NOT EXISTS diapandale ...;` et `USE diapandale;`
   (la plupart des fournisseurs cloud imposent le nom de base ; vous le choisirez dans leur console SQL).
3. Exécutez le reste dans la console SQL du fournisseur (ou avec un client MySQL / phpMyAdmin / DBeaver).

### 2.2 Récupérer vos membres existants (si vous avez déjà des données sur InfinityFree)

1. Dans phpMyAdmin d'InfinityFree : base → onglet **Exporter** → format SQL → **Exécuter**.
2. Importez ce fichier dans la nouvelle base (après avoir créé les tables, ou à la place de l'étape 2.1
   si l'export contient les `CREATE TABLE`). La table `php_sessions` se crée seule.
3. Les mots de passe sont hachés (`password_hash`) : ils continuent de fonctionner tels quels.

---

## 3. Variables d'environnement à définir sur Vercel

Sur Vercel il n'y a **pas de fichier `.env`** : les identifiants se saisissent dans
**Project → Settings → Environment Variables** (cochez *Production*, *Preview* et *Development*).

| Variable | Valeur | Obligatoire |
| --- | --- | --- |
| `DB_HOST` | hôte de votre base MySQL cloud | ✅ |
| `DB_PORT` | port (ex. `4000` pour TiDB, `3306` sinon) | si ≠ 3306 |
| `DB_NAME` | nom de la base | ✅ |
| `DB_USER` | utilisateur | ✅ |
| `DB_PASSWORD` | mot de passe | ✅ |
| `DB_SSL` | `true` (connexion chiffrée) | ✅ pour presque tous les fournisseurs cloud |
| `DB_SSL_CA` | chemin d'un certificat CA, **seulement** si le fournisseur utilise son propre CA (ex. Aiven) : placez le fichier dans le projet, p. ex. `config/ca.pem`, et mettez `config/ca.pem` | non |
| `DB_SSL_VERIFY` | `false` en dernier recours si le certificat du serveur n'est pas vérifiable | non |
| `SMTP_HOST` | `smtp.gmail.com` | ✅ |
| `SMTP_PORT` | `587` | ✅ |
| `SMTP_SECURE` | `tls` | ✅ |
| `SMTP_USERNAME` | votre adresse Gmail | ✅ |
| `SMTP_PASSWORD` | **mot de passe d'application** Gmail (16 caractères) | ✅ |
| `SMTP_FROM_NAME` | `Dahira Ndiapandal Bou Serigne Saliou Mbacke` | ✅ |
| `SMTP_FROM_EMAIL` | adresse expéditrice (celle de `SMTP_USERNAME` avec Gmail) | ✅ |

À savoir :
- **Pas de guillemets** autour des valeurs dans l'interface Vercel (contrairement au fichier `.env`).
- Après toute modification d'une variable : **redéployez** (Deployments → ⋯ → Redeploy), elle n'est prise en compte qu'à un nouveau déploiement.
- Reprenez les valeurs `SMTP_*` de votre ancien `.env`.

---

## 4. Déployer

### Option A — Depuis GitHub (recommandé)

1. Créez un dépôt GitHub et poussez le contenu de ce dossier (le `.gitignore` exclut déjà `.env` et `vendor/`).
   ```bash
   git init && git add . && git commit -m "Projet adapté pour Vercel"
   git branch -M main
   git remote add origin https://github.com/VOTRE-COMPTE/VOTRE-DEPOT.git
   git push -u origin main
   ```
   ⚠️ Vérifiez avec `git status` qu'aucun fichier `.env` n'est suivi avant de pousser.
2. Sur <https://vercel.com/new>, importez le dépôt.
3. Réglages du projet :
   - **Framework Preset** : `Other`
   - **Root Directory** : le dossier qui contient `vercel.json` (la racine du dépôt si vous avez poussé ce dossier tel quel)
   - **Build Command / Output Directory / Install Command** : laissez **vides** (le runtime PHP lance lui-même `composer install`)
4. Dépliez **Environment Variables** et saisissez les variables du §3.
5. Cliquez **Deploy**.

### Option B — Avec la CLI Vercel

```bash
npm i -g vercel
cd chemin/vers/ce/dossier
vercel login
vercel link                 # crée/associe le projet
vercel env add DB_HOST      # répétez pour chaque variable du §3 (choisir Production + Preview + Development)
vercel --prod
```

---

## 5. Vérifier après le déploiement

1. Ouvrez l'URL `https://votre-projet.vercel.app/` → la page de connexion s'affiche, avec le style (CSS) appliqué.
2. Créez un compte via **Créer un compte** (`/form.php`) → vous recevez l'email avec le code OTP → validez (`/verify_otp.php`).
3. Connectez-vous → `accueil.php`, `dashboard.php` s'affichent ; rechargez plusieurs fois : vous restez connecté.
4. Déconnectez-vous → retour à la connexion, `accueil.php` redirige bien vers `index.php`.
5. Donnez les rôles admin en SQL (voir fin de `database_schema.sql`) puis testez `admin_daara.php` / `admin_membres.php`.
6. Vérifiez que ces adresses renvoient bien **404** : `/.env`, `/composer.json`, `/vendor/autoload.php`, `/config/env.php`, `/database_schema.sql`.

---

## 6. Dépannage

| Symptôme | Cause probable / solution |
| --- | --- |
| Page blanche ou **500** | Ouvrez **Deployments → votre déploiement → Logs** (ou `vercel logs`). Les erreurs PHP (`error_log`) y apparaissent. |
| « Une erreur est survenue » à la connexion / `Configuration de base de données incomplète` | Variables `DB_*` absentes ou non redéployées (§3). |
| `Connection timed out` / `Connection refused` | La base n'est pas accessible depuis Internet, ou son pare-feu bloque Vercel (§2 : autoriser `0.0.0.0/0`). InfinityFree **ne fonctionne pas** (§2). |
| `Connections using insecure transport are prohibited` / `SSL required` | Ajoutez `DB_SSL=true` et redéployez. |
| `Cannot connect to MySQL using SSL` | Le certificat du serveur n'est pas reconnu : fournissez le CA du fournisseur via `DB_SSL_CA`, ou en dernier recours `DB_SSL_VERIFY=false`. |
| Vous êtes déconnecté à chaque page | La base est inaccessible au gestionnaire de sessions → voir les logs (`Session read/write`). Vérifiez aussi que l'utilisateur MySQL a le droit `CREATE` (pour créer `php_sessions`) ; sinon créez la table à la main (bloc dans `database_schema.sql`). |
| L'email OTP n'arrive pas | `SMTP_PASSWORD` doit être un **mot de passe d'application** Gmail (validation en 2 étapes requise). Regardez aussi le dossier spam et les logs (`Erreur d'envoi d'email`). |
| Build : erreur Composer | Vérifiez que `composer.json` et `composer.lock` sont bien dans le dépôt, et que `vendor/` n'est pas poussé. |
| Le logo/CSS ne s'affichent pas | Les fichiers doivent être dans `assets/` (ou `bmh1.jpeg` à la racine). Tout autre chemin est volontairement bloqué par `api/index.php` : ajoutez-le à la liste blanche si besoin. |

---

## 7. Notes techniques

- **Durée des sessions** : inchangée (réglage PHP par défaut, ~24 min d'inactivité ; le « ping » de `accueil.php` toutes les 2 minutes prolonge la session).
- **Fuseau horaire** : Vercel s'exécute en UTC, ce qui correspond à l'heure du Sénégal (UTC+0).
- **Ajouter une page PHP** : ajoutez son nom (sans `.php`) à la liste `$__pages` dans `api/index.php`.
- **Développement local** :
  - avec XAMPP : comme avant (Apache, `.env` local) ;
  - pour simuler Vercel : `php -S localhost:8000 -t . api/index.php` (avec un `.env` ou des variables d'environnement). `vercel dev` n'est pas supporté par `vercel-php`.
- **Limites** : le runtime `vercel-php` est une solution **communautaire** (non officielle Vercel). Les fonctions serverless ont une durée maximale (ici 30 s) et le plan gratuit a des quotas ; l'envoi d'email SMTP et les requêtes SQL de ce projet restent largement en dessous.

---

## 8. 🔐 Sécurité — à faire

Votre ancien `.env` contenait des identifiants réels (base InfinityFree, **mot de passe d'application Gmail**)
et a transité dans l'archive partagée. Par prudence :
- **révoquez et régénérez le mot de passe d'application Gmail** (Compte Google → Sécurité → Mots de passe d'application) ;
- changez le mot de passe de la base InfinityFree si vous la conservez ;
- ne publiez jamais `.env` (il est exclu par `.gitignore` et `.vercelignore`).
