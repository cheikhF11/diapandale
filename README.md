# 🌙 Dahira Ndiapandal Bou Serigne Saliou Mbacké — Plateforme des membres

Application PHP / MySQL de gestion des membres : inscription avec photo, annuaire, tableau de bord,
informations du Daara et messagerie e-mail à tous les membres. Conçue pour **Vercel** (voir
`DEPLOIEMENT_VERCEL.md`) et pour un hébergement PHP classique (XAMPP, o2switch, etc.).

## ✨ Fonctionnalités

**Tous les membres**
- Inscription avec **photo de profil** (recadrage intégré), **adresse**, confirmation par code reçu par e-mail
- Connexion, **session conservée 30 jours** (stockée en base, renouvelée à chaque visite), « mot de passe oublié » par lien sécurisé
- **Annuaire** : la liste n'affiche que la photo, le nom, le prénom et la profession / le niveau d'études.
  Un clic ouvre la **fiche** (fenêtre modale) avec toutes les autres informations **sauf l'âge** ;
  elle se ferme en touchant à l'extérieur, avec la flèche ou la touche Échap
- Recherche instantanée et filtres (travailleurs, étudiants, élèves)
- Tableau de bord, informations de la prochaine séance du Daara, page « Mon profil » (photo, adresse, mot de passe)

**Administration** (`admin_daara`, `admin_membre`)
- **L'âge n'est visible que par l'administration** (fiche membre, tranches d'âge du tableau de bord).
  Il n'est jamais envoyé au navigateur des membres ordinaires.
- **Messagerie** : e-mail à tous les membres ou à un groupe (travailleurs, étudiants, élèves, hommes, femmes), avec barre de progression, historique et reprise d'un envoi interrompu
- Admin Daara : mise à jour des informations de la séance + option « informer tous les membres par e-mail », historique
- Admin Membres : ajout (avec photo), consultation et suppression des membres

## 🚀 Installation

1. **Base de données** : importez `database_schema.sql` (nouvelle installation).
   Pour une base **déjà existante**, rien à faire : le site ajoute lui-même les nouvelles colonnes à la première
   connexion (`config/schema.php`). Si votre utilisateur MySQL n'a pas le droit `ALTER`/`CREATE`, exécutez `database_migration.sql`.
2. **Configuration** : copiez `.env.example` en `.env` (usage local) ou saisissez les variables dans Vercel
   (`DB_*`, `SMTP_*`, `APP_URL`, `MAIL_BATCH_SIZE`).
3. **Dépendances** : `composer install` (PHPMailer).
4. **Rôles administrateurs** :
   ```sql
   UPDATE membre SET role = 'admin_daara'  WHERE email = 'admin1@exemple.com';
   UPDATE membre SET role = 'admin_membre' WHERE email = 'admin2@exemple.com';
   ```

## 🗂️ Structure

```
index.php  form.php  verify_otp.php  reset_password.php   Connexion, inscription, confirmation, mot de passe
accueil.php  dashboard.php  profil.php                    Espace membre
membre_info.php  photo.php                                Fiche membre (JSON) et photos (réservées aux connectés)
admin_membres.php  admin_daara.php  admin_message.php     Administration
config/   app.php (amorçage, session) · helpers.php · photo.php · schema.php · campagne.php · session.php · env.php
includes/ layout.php (en-tête, navigation, icônes)
sendMail/ mail.php (SMTP, gabarit HTML, envoi groupé)
assets/   css/app.css (responsive) · js/app.js · js/message.js
api/      index.php (routeur Vercel)
```

## 🔒 Sécurité

Mots de passe hachés, requêtes préparées, jetons CSRF sur tous les formulaires et appels AJAX, échappement HTML systématique,
limitation des tentatives de connexion, code de confirmation limité (5 essais, 10 min), liens de réinitialisation à usage unique (1 h),
déconnexion par POST, photos contrôlées et ré-encodées côté serveur, aucune donnée de test ni code affiché à l'écran.

## 🐛 Dépannage

| Symptôme | Piste |
|---|---|
| Le code de confirmation n'arrive pas | Vérifiez `SMTP_*` et les journaux ; le bouton « Renvoyer le code » est disponible |
| « Une erreur est survenue » | Consultez les journaux Vercel (*Logs*) : le détail technique y est écrit |
| Photo refusée | Format JPG, PNG ou WebP uniquement |
| Les e-mails de la messagerie échouent | Quotas du fournisseur SMTP (Gmail : 500/jour) → réduire `MAIL_BATCH_SIZE` ou changer de SMTP |

*Conçu par BICHRY_DEV.*
