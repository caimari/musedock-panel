<?php
namespace MuseDockPanel;

class Database
{
    private static ?\PDO $instance = null;

    public static function connect(): \PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/panel.php';
            $db = $config['db'];

            $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}";

            self::$instance = new \PDO($dsn, $db['username'], $db['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$instance;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        self::query($sql, $data);
        return (int) self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = self::query($sql, array_merge($data, $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
}
