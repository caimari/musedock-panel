<?php
namespace MuseDockPanel;

/**
 * Panel settings — key-value store backed by panel_settings table.
 */
class Settings
{
    private static array $cache = [];
    private static bool $loaded = false;

    public static function loadAll(): void
    {
        if (self::$loaded) return;
        try {
            $rows = Database::fetchAll("SELECT key, value FROM panel_settings");
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        } catch (\Exception $e) {
            // Table may not exist yet (pre-migration)
        }
        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::loadAll();
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::query(
            "INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :val, NOW())
             ON CONFLICT (key) DO UPDATE SET value = :val2, updated_at = NOW()",
            ['key' => $key, 'val' => $value, 'val2' => $value]
        );
        self::$cache[$key] = $value;
    }

    public static function getAll(): array
    {
        self::loadAll();
        return self::$cache;
    }
}
