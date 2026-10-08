<?php
/**
 * Chargeur de variables d'environnement (.env), sans dépendance externe.
 * Les identifiants sensibles (DB, SMTP, etc.) sont gardés hors du code source.
 */

function loadEnv(string $path): void
{
    static $loaded = false;

    if ($loaded || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key === '') {
            continue;
        }

        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            putenv("{$key}={$value}");
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $loaded = true;
}

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    $value = trim((string) $value);
    return $value !== '' ? $value : $default;
}

loadEnv(__DIR__ . '/../.env');
