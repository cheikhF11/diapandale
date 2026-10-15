<?php
/**
 * Éléments d'interface partagés : <head>, icônes, navigation, pied de page.
 */

/** Icônes (jeu « Feather », domaine public) : appelées avec <use href="#i-nom">. */
function renderSprite(): void
{
    $i = [
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'graduation' => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'message' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'minus' => '<line x1="5" y1="12" x2="19" y2="12"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'cake' => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/>',
    ];

    echo '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>';
    foreach ($i as $name => $paths) {
        echo '<symbol id="i-' . $name . '" viewBox="0 0 24 24">' . $paths . '</symbol>';
    }
    echo '</defs></svg>';
}

/**
 * Ouvre le document HTML jusqu'au <body>.
 * Options : bodyClass, ping (bool), head (HTML supplémentaire), description.
 */
function renderHead(string $title, array $o = []): void
{
    $bodyClass = trim((string) ($o['bodyClass'] ?? ''));
    $desc = (string) ($o['description'] ?? 'Plateforme de gestion des membres du ' . APP_NAME_SHORT . ' ' . APP_SUBTITLE . '.');

    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
       . '<meta name="theme-color" content="#141414">'
       . '<meta name="description" content="' . e($desc) . '">'
       . '<meta name="csrf-token" content="' . e(csrfToken()) . '">'
       . '<title>' . e($title) . ' · ' . e(APP_NAME_SHORT) . '</title>'
       . '<link rel="icon" href="bmh1.jpeg">'
       . '<link rel="apple-touch-icon" href="bmh1.jpeg">'
       . '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">'
       . ($o['head'] ?? '')
       . '</head><body' . ($bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '')
       . (!empty($o['ping']) ? ' data-ping' : '') . '>'
       . '<a class="skip-link" href="#contenu">Aller au contenu</a>';

    renderSprite();
}

/** Barre de navigation. $active : accueil | membres | dashboard | profil | daara | gestion | messagerie */
function renderNav(array $user, string $active = ''): void
{
    $items = [
        ['key' => 'accueil',   'href' => 'accueil.php',               'icon' => 'home',  'label' => 'Accueil'],
        ['key' => 'membres',   'href' => 'accueil.php?tab=membres',   'icon' => 'users', 'label' => 'Membres'],
        ['key' => 'dashboard', 'href' => 'dashboard.php',             'icon' => 'chart', 'label' => 'Tableau de bord'],
    ];
    $admin = [];
    if (hasRole($user, 'admin_daara')) {
        $admin[] = ['key' => 'daara', 'href' => 'admin_daara.php', 'icon' => 'book', 'label' => 'Daara'];
    }
    if (hasRole($user, 'admin_membre')) {
        $admin[] = ['key' => 'gestion', 'href' => 'admin_membres.php', 'icon' => 'user-plus', 'label' => 'Gestion des membres'];
    }
    if (isAdmin($user)) {
        $admin[] = ['key' => 'messagerie', 'href' => 'admin_message.php', 'icon' => 'send', 'label' => 'Messagerie'];
    }

    $link = function (array $it) use ($active): string {
        $cls = 'nav-link' . ($it['key'] === $active ? ' is-active' : '');
        return '<li><a class="' . $cls . '" href="' . e($it['href']) . '"'
             . ($it['key'] === $active ? ' aria-current="page"' : '') . '>'
             . icon($it['icon']) . '<span>' . e($it['label']) . '</span></a></li>';
    };

    echo '<header class="app-header"><div class="app-header__bar">'
       . '<a class="brand" href="accueil.php"><img src="bmh1.jpeg" alt="Logo du Dahira" width="42" height="42">'
       . '<span class="brand__text"><b>' . e(APP_NAME) . '</b><small>Bienvenue, ' . e((string) $user['prenom']) . '</small></span></a>'
       . '<button type="button" class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="navPanel" aria-label="Ouvrir le menu">'
       . icon('menu', 'i-menu') . icon('x', 'i-close') . '</button>'
       . '<nav class="nav-panel" id="navPanel" aria-label="Navigation principale"><ul class="nav-list">';

    foreach ($items as $it) {
        echo $link($it);
    }
    if ($admin) {
        echo '<li class="nav-sep" role="separator"></li>';
        foreach ($admin as $it) {
            echo $link($it);
        }
    }

    echo '<li class="nav-sep" role="separator"></li>'
       . '<li><a class="nav-link' . ($active === 'profil' ? ' is-active' : '') . '" href="profil.php">'
       . '<span class="nav-user">' . avatarHtml($user, 'sm') . '<span>Mon profil</span></span></a></li>'
       . '<li><form method="post" action="logout.php" class="m-0">' . csrfField()
       . '<button type="submit" class="nav-link">' . icon('log-out') . '<span>Déconnexion</span></button></form></li>'
       . '</ul></nav></div></header>';
}

/** Fenêtre « fiche membre » : se ferme en touchant à l'extérieur ou avec la flèche. */
function renderMemberModal(): void
{
    echo '<div class="modal" id="memberModal" role="dialog" aria-modal="true" aria-labelledby="memberModalTitle" aria-hidden="true">'
       . '<div class="modal__backdrop" data-modal-close></div>'
       . '<div class="modal__dialog">'
       . '<button type="button" class="modal__back" data-modal-close aria-label="Fermer la fiche">' . icon('arrow-left') . '</button>'
       . '<div id="memberModalContent"></div>'
       . '</div></div>';
}

/** Fin de page : pied de page, scripts, fermeture du document. Options : scripts (urls), inline (JS). */
function renderFooter(array $o = []): void
{
    echo '<footer class="site-footer">Conçu par <b>BICHRY_DEV</b> © ' . date('Y') . ' · ' . e(APP_NAME_SHORT) . '</footer>';
    foreach (($o['scripts'] ?? []) as $src) {
        echo '<script src="' . e($src) . '"></script>';
    }
    echo '<script src="' . e(asset('js/app.js')) . '"></script>';
    if (!empty($o['pageScript'])) {
        echo '<script src="' . e(asset($o['pageScript'])) . '"></script>';
    }
    if (!empty($o['inline'])) {
        echo '<script>' . $o['inline'] . '</script>';
    }
    echo '</body></html>';
}

/** Ouvre une page d'authentification (connexion, inscription…). */
function renderAuthHead(string $title, string $heading, string $lead = '', bool $wide = false): void
{
    renderHead($title, ['bodyClass' => 'auth']);
    echo '<main class="auth-wrap" id="contenu"><div class="auth-card' . ($wide ? ' auth-card--wide' : '') . '">'
       . '<div class="auth-brand"><img src="bmh1.jpeg" alt="Logo du Dahira" width="84" height="84">'
       . '<h1>' . e($heading) . '</h1>'
       . '<p>' . e($lead !== '' ? $lead : APP_NAME) . '</p></div>';
}

function renderAuthFoot(array $o = []): void
{
    echo '</div></main><div class="auth-footer">Conçu par <b>BICHRY_DEV</b> © ' . date('Y') . '</div>';
    foreach (($o['scripts'] ?? []) as $src) {
        echo '<script src="' . e($src) . '"></script>';
    }
    echo '<script src="' . e(asset('js/app.js')) . '"></script>';
    if (!empty($o['inline'])) {
        echo '<script>' . $o['inline'] . '</script>';
    }
    echo '</body></html>';
}
