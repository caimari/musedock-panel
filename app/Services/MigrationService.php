<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;

/**
 * Database migration runner.
 *
 * Migrations live in /database/migrations/ as PHP files.
 * Each file returns a callable that receives a PDO instance.
 * Naming convention: YYYY_MM_DD_HHMMSS_description.php
 *
 * The panel_migrations table tracks which have been executed.
 */
class MigrationService
{
    /**
     * Ensure the migrations table exists
     */
    public static function ensureTable(): void
    {
        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS panel_migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }

    /**
     * Get list of already-executed migration names
     */
    public static function getExecuted(): array
    {
        self::ensureTable();
        $rows = Database::fetchAll("SELECT migration FROM panel_migrations ORDER BY migration");
        return array_column($rows, 'migration');
    }

    /**
     * Get all migration files sorted by name
     */
    public static function getAvailable(): array
    {
        $dir = PANEL_ROOT . '/database/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob("{$dir}/*.php");
        sort($files);
        return $files;
    }

    /**
     * Get pending migrations (available but not yet executed)
     */
    public static function getPending(): array
    {
        $executed = self::getExecuted();
        $pending = [];

        foreach (self::getAvailable() as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $executed, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * Run all pending migrations
     * Returns array of results: ['name' => ..., 'ok' => bool, 'error' => string|null]
     */
    public static function runPending(): array
    {
        $results = [];
        $pending = self::getPending();

        if (empty($pending)) {
            return $results;
        }

        $pdo = Database::connect();

        foreach ($pending as $file) {
            $name = basename($file, '.php');
            $result = ['name' => $name, 'ok' => false, 'error' => null];

            try {
                $pdo->beginTransaction();

                // Each migration file should return a callable: function(PDO $pdo): void
                $migration = require $file;

                if (is_callable($migration)) {
                    $migration($pdo);
                }
                // Legacy support: if the file just runs SQL directly (like the old format),
                // it has already executed by the time require returns.

                // Record as executed
                $stmt = $pdo->prepare("INSERT INTO panel_migrations (migration) VALUES (:name) ON CONFLICT (migration) DO NOTHING");
                $stmt->execute(['name' => $name]);

                $pdo->commit();
                $result['ok'] = true;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $result['error'] = $e->getMessage();
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Mark a migration as executed without running it
     * Useful for migrations already applied manually (like the initial shell migration)
     */
    public static function markAsExecuted(string $name): void
    {
        self::ensureTable();
        $stmt = Database::connect()->prepare(
            "INSERT INTO panel_migrations (migration) VALUES (:name) ON CONFLICT (migration) DO NOTHING"
        );
        $stmt->execute(['name' => $name]);
    }
}
