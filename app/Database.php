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

            $host = (string)($db['host'] ?? '127.0.0.1');
            $port = (int)($db['port'] ?? 5432);
            $timeout = max(1, (int)($db['connect_timeout'] ?? 5));
            $dsn = self::buildPgDsn($host, $port, (string)$db['database'], $timeout);

            try {
                self::$instance = self::newPdo($dsn, $db, $timeout);
            } catch (\PDOException $primaryError) {
                $socketDir = '/var/run/postgresql';
                $socketFile = "{$socketDir}/.s.PGSQL.{$port}";

                if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && file_exists($socketFile)) {
                    try {
                        self::$instance = self::newPdo(
                            self::buildPgDsn($socketDir, $port, (string)$db['database'], $timeout),
                            $db,
                            $timeout
                        );
                    } catch (\PDOException $socketError) {
                        throw new \RuntimeException(
                            'PostgreSQL connection failed via TCP and Unix socket. TCP: '
                            . $primaryError->getMessage()
                            . ' | socket: '
                            . $socketError->getMessage(),
                            0,
                            $socketError
                        );
                    }
                } else {
                    throw $primaryError;
                }
            }
        }

        return self::$instance;
    }

    private static function buildPgDsn(string $host, int $port, string $database, int $timeout): string
    {
        return "pgsql:host={$host};port={$port};dbname={$database};connect_timeout={$timeout}";
    }

    private static function newPdo(string $dsn, array $db, int $timeout): \PDO
    {
        return new \PDO($dsn, $db['username'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => $timeout,
        ]);
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
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
