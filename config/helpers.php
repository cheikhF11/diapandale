<?php
/**
 * Fonctions utilitaires partagées par toutes les pages.
 */

/* ═══ Échappement, URL, réseau ══════════════════════════════════════════ */

/** Échappe une valeur pour l'affichage HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * URL publique du site (utilisée dans les e-mails : lien de réinitialisation…).
 * Définissez APP_URL dans les variables d'environnement pour la fixer.
 */
function appBaseUrl(): string
{
    $configured = trim((string) env('APP_URL', ''));
    if ($configured !== '') {
        if (!preg_match('~^https?://~i', $configured)) {
            $configured = 'https://' . $configured;
        }
        return rtrim($configured, '/');
    }

    $vercel = trim((string) env('VERCEL_PROJECT_PRODUCTION_URL', ''));
    if ($vercel !== '') {
        return 'https://' . $vercel;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }

    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return (isHttps() ? 'https' : 'http') . '://' . $host . ($dir === '/' ? '' : $dir);
}

function clientIp(): string
{
    foreach (['HTTP_X_VERCEL_FORWARDED_FOR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Accepte uniquement une URL http(s) valide, sinon null. */
function safeUrl(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return null;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

/* ═══ Session ═══════════════════════════════════════════════════════════ */

/**
 * Démarre la session persistante : cookie valable 30 jours, renouvelé à chaque
 * visite (le membre reste connecté tant qu'il revient au moins une fois par mois).
 */
function appSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = isHttps();
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Renouvellement glissant de la durée de vie du cookie.
    if (!headers_sent() && session_id() !== '') {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function destroyAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    if (!headers_sent()) {
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/* ═══ CSRF & messages flash ═════════════════════════════════════════════ */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Vérifie le jeton envoyé par un formulaire (POST) ou une requête AJAX (en-tête). */
function csrfValid(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf'])
        && hash_equals((string) $_SESSION['csrf'], $sent);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retourne puis efface les messages flash. */
function pullFlashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

/* ═══ Authentification & rôles ══════════════════════════════════════════ */

function fetchCurrentUser(PDO $db): ?array
{
    if (empty($_SESSION['logged_in']) || empty($_SESSION['number'])) {
        return null;
    }

    $query = $db->prepare(
        "SELECT id, num, prenom, nom, email, genre, role, adresse, profession, profession_details,
                niveau_etude, filiere, classe, a_photo, photo_maj, confirm
         FROM membre WHERE num = ? LIMIT 1"
    );
    $query->execute([(string) $_SESSION['number']]);
    $user = $query->fetch();

    return $user ?: null;
}

/**
 * Exige un membre connecté et confirmé ; sinon redirige vers la connexion.
 * Met aussi à jour son statut « en ligne » (au plus une fois par minute).
 */
function requireLogin(PDO $db, bool $touch = true): array
{
    $user = fetchCurrentUser($db);

    if (!$user || (int) $user['confirm'] !== 1) {
        destroyAppSession();
        redirect('index.php');
    }

    if ($touch) {
        try {
            $db->prepare(
                "UPDATE membre SET statut_en_ligne = 1, derniere_connexion = NOW()
                 WHERE id = ? AND (derniere_connexion IS NULL OR derniere_connexion < (NOW() - INTERVAL 60 SECOND))"
            )->execute([(int) $user['id']]);
        } catch (Throwable $e) {
            error_log('Statut en ligne : ' . $e->getMessage());
        }
    }

    return $user;
}

function isAdmin(?array $user): bool
{
    return $user !== null && in_array((string) ($user['role'] ?? ''), ADMIN_ROLES, true);
}

function hasRole(?array $user, string $role): bool
{
    return $user !== null && (string) ($user['role'] ?? '') === $role;
}

/* ═══ Formatage ═════════════════════════════════════════════════════════ */

/** Date en français : « 12 mars 2026 », avec option jour de semaine et heure. */
function formatDateFr($value, bool $withTime = false, bool $withWeekday = false): string
{
    if ($value === null || $value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '—';
    }

    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if (!$ts) {
        return '—';
    }

    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
              'septembre', 'octobre', 'novembre', 'décembre'];

    $day = (int) date('j', $ts);
    $out = ($withWeekday ? $jours[(int) date('w', $ts)] . ' ' : '')
         . $day . ($day === 1 ? 'er' : '') . ' '
         . $mois[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);

    if ($withTime) {
        $out .= ' à ' . date('H\hi', $ts);
    }

    return $out;
}

/** Nom court du mois pour les graphiques : « 2026-03 » → « mars 26 ». */
function formatMonthShort(string $ym): string
{
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    $idx = (int) $m[2] - 1;
    return ($mois[$idx] ?? $m[2]) . ' ' . substr($m[1], 2);
}

/** Âge en années à partir d'une date de naissance (null si inconnue ou invalide). */
function calculateAge($date): ?int
{
    if (empty($date) || $date === '0000-00-00') {
        return null;
    }
    try {
        $birth = new DateTimeImmutable((string) $date);
        $today = new DateTimeImmutable('today');
    } catch (Throwable $e) {
        return null;
    }
    if ($birth > $today) {
        return null;
    }
    return (int) $birth->diff($today)->y;
}

/** Numéro de téléphone nettoyé : chiffres et « + » initial uniquement. */
function normalizePhone(string $number): string
{
    $number = trim($number);
    $plus = strpos($number, '+') === 0 ? '+' : '';
    return $plus . preg_replace('/\D+/', '', $number);
}

/** Chiffres pour un lien WhatsApp (wa.me) ; ajoute l'indicatif 221 pour un numéro local à 9 chiffres. */
function whatsappDigits(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number);
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 9) {
        $digits = '221' . $digits;
    }
    return strlen($digits) >= 10 ? $digits : '';
}

/* ═══ Membres ═══════════════════════════════════════════════════════════ */

function firstLetter(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    return function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8')
        : strtoupper(substr($text, 0, 1));
}

function memberInitials(array $m): string
{
    $initials = firstLetter((string) ($m['prenom'] ?? '')) . firstLetter((string) ($m['nom'] ?? ''));
    return $initials !== '' ? $initials : '?';
}

function memberFullName(array $m): string
{
    return trim(((string) ($m['prenom'] ?? '')) . ' ' . ((string) ($m['nom'] ?? '')));
}

function categorieLabel(?string $profession): string
{
    switch ($profession) {
        case 'travailleur':
            return 'Travailleur';
        case 'etudiant':
            return 'Étudiant';
        case 'eleve':
            return 'Élève';
        default:
            return '';
    }
}

/** Ligne affichée sous le nom : profession, ou niveau d'étude / classe. */
function memberSubtitle(array $m): string
{
    switch ($m['profession'] ?? '') {
        case 'travailleur':
            $job = trim((string) ($m['profession_details'] ?? ''));
            return $job !== '' ? $job : 'Travailleur';
        case 'etudiant':
            $level = trim((string) ($m['niveau_etude'] ?? ''));
            return $level !== '' ? 'Étudiant · ' . $level : 'Étudiant';
        case 'eleve':
            $class = trim((string) ($m['classe'] ?? ''));
            return $class !== '' ? 'Élève · ' . $class : 'Élève';
        default:
            return '';
    }
}

/** URL de la photo d'un membre (null s'il n'en a pas). */
function memberPhotoUrl(array $m, bool $full = false): ?string
{
    if (empty($m['a_photo']) || empty($m['id'])) {
        return null;
    }
    return 'photo.php?id=' . (int) $m['id'] . '&v=' . (int) ($m['photo_maj'] ?? 0) . ($full ? '&s=full' : '');
}

/** Pastille ronde : photo si elle existe, sinon initiales (aussi utilisées si l'image échoue). */
function avatarHtml(array $m, string $size = 'md', bool $full = false): string
{
    $html = '<span class="avatar avatar--' . e($size) . '" aria-hidden="true">' . e(memberInitials($m));
    $url = memberPhotoUrl($m, $full);
    if ($url !== null) {
        $html .= '<img src="' . e($url) . '" alt="" loading="lazy" decoding="async">';
    }
    return $html . '</span>';
}

/* ═══ Listes de référence ═══════════════════════════════════════════════ */

const NIVEAUX_ETUDE = ['Licence 1', 'Licence 2', 'Licence 3', 'Master 1', 'Master 2', 'Doctorat'];
const CLASSES = [
    'Collège' => ['6ème', '5ème', '4ème', '3ème'],
    'Lycée - Série L' => ['Seconde L', 'Première L', 'Terminale L'],
    'Lycée - Série S' => ['Seconde S', 'Première S', 'Terminale S'],
];

function classeValide(string $classe): bool
{
    foreach (CLASSES as $liste) {
        if (in_array($classe, $liste, true)) {
            return true;
        }
    }
    return false;
}

/* ═══ Interface ═════════════════════════════════════════════════════════ */

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false">'
         . '<use href="#i-' . e($name) . '"/></svg>';
}

/** Chemin d'un fichier de assets/ avec empreinte : le navigateur recharge dès qu'il change. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . $path;
    $version = is_file($file) ? substr((string) md5_file($file), 0, 8) : '1';
    return 'assets/' . $path . '?v=' . $version;
}

/** Affiche et vide les messages flash (succès / erreur / info). */
function renderFlashes(): void
{
    foreach (pullFlashes() as $f) {
        $type = in_array($f['type'], ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info';
        $ico = $type === 'success' ? 'check-circle' : ($type === 'info' ? 'info' : 'alert-circle');
        echo '<div class="alert alert-' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
           . icon($ico) . '<span>' . e($f['message']) . '</span></div>';
    }
}

/* ═══ Protection contre les attaques par force brute ════════════════════ */

function attemptKey(string $identifier): string
{
    return substr(strtolower(trim($identifier)), 0, 255);
}

/** Trop d'échecs récents (5 par compte+IP, ou 30 par IP, sur 15 minutes) ? */
function attemptsBlocked(PDO $db, string $identifier): bool
{
    $ip = clientIp();

    $q = $db->prepare(
        "SELECT COUNT(*) FROM tentatives_connexion
         WHERE identifiant = ? AND ip = ? AND date_tentative > (NOW() - INTERVAL 15 MINUTE)"
    );
    $q->execute([attemptKey($identifier), $ip]);
    if ((int) $q->fetchColumn() >= 5) {
        return true;
    }

    $q = $db->prepare(
        "SELECT COUNT(*) FROM tentatives_connexion
         WHERE ip = ? AND date_tentative > (NOW() - INTERVAL 15 MINUTE)"
    );
    $q->execute([$ip]);
    return (int) $q->fetchColumn() >= 30;
}

function recordAttempt(PDO $db, string $identifier): void
{
    $db->prepare("INSERT INTO tentatives_connexion (identifiant, ip) VALUES (?, ?)")
       ->execute([attemptKey($identifier), clientIp()]);

    if (random_int(1, 25) === 1) { // ménage occasionnel
        $db->exec("DELETE FROM tentatives_connexion WHERE date_tentative < (NOW() - INTERVAL 1 DAY)");
    }
}

function clearAttempts(PDO $db, string $identifier): void
{
    $db->prepare("DELETE FROM tentatives_connexion WHERE identifiant = ? AND ip = ?")
       ->execute([attemptKey($identifier), clientIp()]);
}
