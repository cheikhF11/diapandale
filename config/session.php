<?php
/**
 * Sessions PHP stockées dans MySQL (table "php_sessions").
 *
 * Pourquoi : sur Vercel, chaque requête peut être servie par une instance
 * différente dont le disque est éphémère. Les sessions "fichiers" de PHP
 * (comportement par défaut sous Apache/XAMPP) seraient perdues d'une requête
 * à l'autre : impossible de rester connecté. Ce gestionnaire remplace
 * uniquement le STOCKAGE ; le code des pages ($_SESSION, session_start(),
 * session_regenerate_id(), session_destroy()...) reste strictement identique.
 *
 * Chargé automatiquement par api/index.php avant chaque page.
 */

require_once __DIR__ . '/../dataBase/bd.php';

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private static bool $tableChecked = false;
    private ?string $readData = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Crée la table au besoin (une seule fois par instance de la fonction). */
    private function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS php_sessions (
                id            VARCHAR(128) NOT NULL PRIMARY KEY,
                data          MEDIUMBLOB   NOT NULL,
                last_activity INT UNSIGNED NOT NULL,
                INDEX idx_php_sessions_activity (last_activity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$tableChecked = true;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->ensureTable();
            $stmt = $this->pdo->prepare(
                'SELECT data FROM php_sessions WHERE id = ? AND last_activity >= ?'
            );
            $stmt->execute([$id, time() - (int) ini_get('session.gc_maxlifetime')]);
            $row = $stmt->fetchColumn();
            $this->readData = ($row === false) ? '' : (string) $row;
            return $this->readData;
        } catch (Throwable $e) {
            error_log('Session read: ' . $e->getMessage());
            $this->readData = '';
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->ensureTable();

            // Données inchangées (ex. requête AJAX de statut en ligne) : on ne fait que
            // prolonger la session. UPDATE seul => ne peut pas "ressusciter" une session
            // supprimée entre-temps par logout.php.
            if ($this->readData !== null && $data === $this->readData) {
                $stmt = $this->pdo->prepare('UPDATE php_sessions SET last_activity = ? WHERE id = ?');
                $stmt->execute([time(), $id]);
                if ($stmt->rowCount() > 0 || $data === '') {
                    return true;
                }
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO php_sessions (id, data, last_activity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)'
            );
            $stmt->execute([$id, $data, time()]);
            return true;
        } catch (Throwable $e) {
            error_log('Session write: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->ensureTable();
            $this->pdo->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
            return true;
        } catch (Throwable $e) {
            error_log('Session destroy: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $this->ensureTable();
            $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE last_activity < ?');
            $stmt->execute([time() - $max_lifetime]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Session gc: ' . $e->getMessage());
            return false;
        }
    }
}

try {
    session_set_save_handler(new DatabaseSessionHandler(getDatabaseConnection()), true);
} catch (Throwable $e) {
    // Base inaccessible : on laisse PHP utiliser ses sessions par défaut ; la page
    // affichera elle-même son message d'erreur de connexion habituel.
    error_log('Gestionnaire de sessions non installé: ' . $e->getMessage());
}
