<?php
/**
 * Photos de profil.
 *
 * Sur Vercel le disque est en lecture seule / éphémère : les photos sont donc
 * stockées en base de données (table membre_photo), en deux tailles :
 *   - « full »  : 480 × 480 px, pour la fiche du membre ;
 *   - « thumb » : 160 × 160 px, pour les listes (très léger).
 *
 * Le navigateur recadre et réduit déjà l'image avant l'envoi (voir app.js) ;
 * le serveur la contrôle ensuite (type réel, dimensions, taille) et, si
 * l'extension GD est disponible, la ré-encode proprement en JPEG.
 */

const PHOTO_MAX_BYTES       = 6291456;  // 6 Mo (fichier brut, formulaire sans JavaScript)
const PHOTO_MAX_DATA_BYTES  = 1048576;  // 1 Mo (image recadrée envoyée par le navigateur)
const PHOTO_MAX_PIXELS      = 36000000; // protège la mémoire du serveur
const PHOTO_FULL_SIZE       = 480;
const PHOTO_THUMB_SIZE      = 160;

/** Décode une « data URL » (data:image/jpeg;base64,...) ; null si invalide. */
function photoDecodeDataUrl(string $dataUrl): ?string
{
    if (!preg_match('~^data:image/(?:jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$~', $dataUrl, $m)) {
        return null;
    }
    $binary = base64_decode($m[1], true);
    if ($binary === false || $binary === '' || strlen($binary) > PHOTO_MAX_DATA_BYTES) {
        return null;
    }
    return $binary;
}

/**
 * Contrôle une image et la ramène à un carré de $size px (JPEG).
 * @return array{mime:string,data:string}|null
 */
function photoNormalize(string $binary, int $size, int $quality = 85): ?array
{
    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        return null;
    }

    [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
    $allowed = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png'];
    if (defined('IMAGETYPE_WEBP')) {
        $allowed[IMAGETYPE_WEBP] = 'image/webp';
    }
    if (!isset($allowed[$type]) || $width < 1 || $height < 1 || $width * $height > PHOTO_MAX_PIXELS) {
        return null;
    }

    // Sans GD : on garde le fichier tel quel s'il est raisonnable.
    if (!function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagejpeg')) {
        if (strlen($binary) > PHOTO_MAX_DATA_BYTES || max($width, $height) > 1600) {
            return null;
        }
        return ['mime' => $allowed[$type], 'data' => $binary];
    }

    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return null;
    }

    // Respect de l'orientation EXIF des photos prises au téléphone.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data') && function_exists('imagerotate')) {
        try {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($binary));
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $angles = [3 => 180, 6 => -90, 8 => 90];
            if (isset($angles[$orientation])) {
                $rotated = @imagerotate($src, $angles[$orientation], 0);
                if ($rotated !== false) {
                    imagedestroy($src);
                    $src = $rotated;
                }
            }
        } catch (Throwable $e) {
            // orientation ignorée
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $side = min($w, $h);
    $sx = (int) floor(($w - $side) / 2);
    $sy = (int) floor(($h - $side) / 2);

    $dst = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $size, $size, $white);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

    ob_start();
    imagejpeg($dst, null, $quality);
    $out = (string) ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return $out !== '' ? ['mime' => 'image/jpeg', 'data' => $out] : null;
}

/**
 * Lit la photo envoyée par un formulaire : champ « photo_data » (image recadrée
 * par le navigateur) ou, à défaut, fichier « photo » (formulaire sans JavaScript).
 *
 * @return array{data:string,mime:string,thumb:string,thumb_mime:string}|null
 *         null si aucune photo n'a été envoyée (voir $error pour un éventuel problème)
 */
function photoFromRequest(array $post, array $files, ?string &$error = null): ?array
{
    $error = null;
    $raw = null;

    if (!empty($post['photo_data']) && is_string($post['photo_data'])) {
        $raw = photoDecodeDataUrl($post['photo_data']);
        if ($raw === null) {
            $error = "La photo envoyée est invalide. Choisissez-en une autre (JPG, PNG ou WebP).";
            return null;
        }
    } elseif (!empty($files['photo']) && ($files['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $files['photo'];
        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $error = "Le téléversement de la photo a échoué. Réessayez avec une image plus légère.";
            return null;
        }
        if (($f['size'] ?? 0) > PHOTO_MAX_BYTES || !is_uploaded_file((string) $f['tmp_name'])) {
            $error = "La photo est trop volumineuse (6 Mo maximum).";
            return null;
        }
        $raw = (string) file_get_contents((string) $f['tmp_name']);
    } else {
        return null;
    }

    $main = photoNormalize($raw, PHOTO_FULL_SIZE, 85);
    if ($main === null) {
        $error = "Ce fichier n'est pas une image valide (JPG, PNG ou WebP).";
        return null;
    }

    $thumb = null;
    if (function_exists('imagecreatefromstring')) {
        $thumb = photoNormalize($main['data'], PHOTO_THUMB_SIZE, 82);
    } elseif (!empty($post['photo_thumb']) && is_string($post['photo_thumb'])) {
        // Sans GD : vignette fournie par le navigateur, après contrôle.
        $thumbRaw = photoDecodeDataUrl($post['photo_thumb']);
        if ($thumbRaw !== null && strlen($thumbRaw) <= 150000) {
            $info = @getimagesizefromstring($thumbRaw);
            if ($info !== false && max($info[0], $info[1]) <= 400
                && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                $thumb = ['mime' => image_type_to_mime_type($info[2]), 'data' => $thumbRaw];
            }
        }
    }
    if ($thumb === null) {
        $thumb = $main;
    }

    return [
        'data'       => $main['data'],
        'mime'       => $main['mime'],
        'thumb'      => $thumb['data'],
        'thumb_mime' => $thumb['mime'],
    ];
}

/** Enregistre (ou remplace) la photo d'un membre. */
function photoSave(PDO $db, int $memberId, array $photo): void
{
    $stmt = $db->prepare(
        "INSERT INTO membre_photo (membre_id, mime, data, thumb_mime, thumb)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data),
                                 thumb_mime = VALUES(thumb_mime), thumb = VALUES(thumb)"
    );
    $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
    $stmt->bindValue(2, $photo['mime']);
    $stmt->bindValue(3, $photo['data'], PDO::PARAM_LOB);
    $stmt->bindValue(4, $photo['thumb_mime']);
    $stmt->bindValue(5, $photo['thumb'], PDO::PARAM_LOB);
    $stmt->execute();

    $db->prepare("UPDATE membre SET a_photo = 1, photo_maj = UNIX_TIMESTAMP() WHERE id = ?")
       ->execute([$memberId]);
}

function photoDelete(PDO $db, int $memberId): void
{
    $db->prepare("DELETE FROM membre_photo WHERE membre_id = ?")->execute([$memberId]);
    $db->prepare("UPDATE membre SET a_photo = 0, photo_maj = NULL WHERE id = ?")->execute([$memberId]);
}
