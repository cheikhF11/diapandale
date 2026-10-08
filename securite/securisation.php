<?php
function securisation($donnees)
{
    if ($donnees === null) {
        return '';
    }

    if (is_array($donnees)) {
        return array_map('securisation', $donnees);
    }

    $donnees = trim((string) $donnees);
    $donnees = strip_tags($donnees);
    $donnees = stripslashes($donnees);
    return $donnees;
}
?>