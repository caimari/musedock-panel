<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Env;

class ReplicationService
{
    // ─── Encryption ──────────────────────────────────────────
    private static function encryptionKey(): string
    {
        return hash('sha256', Env::get('DB_PASS', 'musedock-default-key'), true);
    }

    public static function encryptPassword(string $plain): string
    {
        if ($plain === '') return '';
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', static::encryptionKey(), 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    public static function decryptPassword(string $stored): string
    {
        if ($stored === '') return '';
        $data = base64_decode($stored);
        if ($data === false || !str_contains($data, '::')) return '';
        [$iv, $encrypted] = explode('::', $data, 2);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', static::encryptionKey(), 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    // ─── Version Detection ───────────────────────────────────
    public static function detectPgVersion(): ?string
    {
        $out = trim((string)shell_exec('psql --version 2>/dev/null'));
        if (preg_match('/(\d+)\./', $out, $m)) return $m[1];
        $clusters = trim((string)shell_exec('pg_lsclusters -h 2>/dev/null'));
        if ($clusters && preg_match('/^(\d+)\s/', $clusters, $m)) return $m[1];
        return null;
    }

    public static function detectMysqlVersion(): ?string
    {
        $out = trim((string)shell_exec('mysql --version 2>/dev/null'));
        if (preg_match('/(\d+\.\d+\.\d+)/', $out, $m)) return $m[1];
        return null;
    }

    public static function detectMysqlServiceName(): string
    {
        $mariadb = trim((string)shell_exec('systemctl is-active mariadb 2>/dev/null'));
        return $mariadb === 'active' ? 'mariadb' : 'mysql';
    }

    // ─── Config Paths ────────────────────────────────────────
    public static function getPgConfigDir(): string
    {
        $ver = static::detectPgVersion() ?? '16';
        $cluster = trim((string)shell_exec("pg_lsclusters -h 2>/dev/null | head -1 | awk '{print \$2}'"));
        $cluster = $cluster ?: 'main';
        return "/etc/postgresql/{$ver}/{$cluster}";
    }

    public static function getPgDataDir(): string
    {
        $out = trim((string)shell_exec("sudo -u postgres psql -tAc \"SHOW data_directory\" 2>/dev/null"));
        if ($out && is_dir($out)) return $out;
        $ver = static::detectPgVersion() ?? '16';
        return "/var/lib/postgresql/{$ver}/main";
    }

    public static function getMysqlConfigPath(): string
    {
        $paths = [
            '/etc/mysql/mysql.conf.d/mysqld.cnf',
            '/etc/mysql/mariadb.conf.d/50-server.cnf',
            '/etc/mysql/my.cnf',
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) return $p;
        }
        return '/etc/mysql/my.cnf';
    }

    // ─── Connection Tests ────────────────────────────────────
    public static function testPgConnection(string $host, int $port, string $user, string $pass): array
    {
        try {
            $dsn = "pgsql:host=" . addcslashes($host, "'") . ";port={$port};dbname=postgres;connect_timeout=5";
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $ver = $pdo->query("SELECT version()")->fetchColumn();
            return ['ok' => true, 'error' => '', 'version' => $ver];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'version' => ''];
        }
    }

    public static function testMysqlConnection(string $host, int $port, string $user, string $pass): array
    {
        try {
            $dsn = "mysql:host=" . addcslashes($host, "'") . ";port={$port};connect_timeout=5";
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
            return ['ok' => true, 'error' => '', 'version' => $ver];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'version' => ''];
        }
    }

    // ─── Dual IP / Fallback Connection Test ──────────────────
    public static function testConnectionWithFallback(string $engine, string $primaryIp, string $fallbackIp, int $port, string $user, string $pass): array
    {
        $testFn = $engine === 'pg' ? 'testPgConnection' : 'testMysqlConnection';

        // Try primary first
        $result = static::$testFn($primaryIp, $port, $user, $pass);
        if ($result['ok']) {
            return [
                'ok' => true,
                'connected_via' => 'primary',
                'ip' => $primaryIp,
                'version' => $result['version'],
                'error' => '',
            ];
        }

        $primaryError = $result['error'];

        // Try fallback if available
        if ($fallbackIp !== '') {
            $result = static::$testFn($fallbackIp, $port, $user, $pass);
            if ($result['ok']) {
                return [
                    'ok' => true,
                    'connected_via' => 'fallback',
                    'ip' => $fallbackIp,
                    'version' => $result['version'],
                    'error' => '',
                ];
            }
        }

        return [
            'ok' => false,
            'connected_via' => 'none',
            'ip' => '',
            'version' => '',
            'error' => "Primaria: {$primaryError}" . ($fallbackIp ? " | Fallback: {$result['error']}" : ''),
        ];
    }

    // ─── Local MySQL PDO ─────────────────────────────────────
    public static function getMysqlPdo(): ?\PDO
    {
        $socketPaths = ['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'];
        foreach ($socketPaths as $sock) {
            if (file_exists($sock)) {
                try {
                    return new \PDO("mysql:unix_socket={$sock}", 'root', '', [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    ]);
                } catch (\Throwable) {}
            }
        }
        if (file_exists('/etc/mysql/debian.cnf')) {
            $conf = parse_ini_file('/etc/mysql/debian.cnf', true);
            $section = $conf['client'] ?? [];
            $user = $section['user'] ?? 'root';
            $pass = $section['password'] ?? '';
            try {
                return new \PDO("mysql:host=127.0.0.1;port=3306", $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (\Throwable) {}
        }
        $mysqlUser = Env::get('MYSQL_ROOT_USER', 'root');
        $mysqlPass = Env::get('MYSQL_ROOT_PASS', '');
        try {
            return new \PDO("mysql:host=127.0.0.1;port=3306", $mysqlUser, $mysqlPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Config File Helpers ─────────────────────────────────
    public static function backupFile(string $path): string
    {
        $backup = $path . '.bak.' . date('Ymd_His');
        if (file_exists($path)) {
            copy($path, $backup);
        }
        return $backup;
    }

    /**
     * Modify INI-style config: set key=value pairs.
     * Uncomments lines if commented, appends if missing.
     */
    public static function modifyConfigFile(string $path, array $settings, string $section = ''): bool
    {
        if (!file_exists($path)) return false;

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $inSection = ($section === '');
        $modified = [];
        $remaining = $settings;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[(.+)]$/', $trimmed, $m)) {
                if ($inSection && !empty($remaining)) {
                    foreach ($remaining as $k => $v) {
                        $modified[] = "{$k} = {$v}";
                    }
                    $remaining = [];
                }
                $inSection = ($section !== '' && strtolower($m[1]) === strtolower($section));
            }

            if ($inSection) {
                foreach ($remaining as $key => $value) {
                    if (preg_match('/^[#;\s]*' . preg_quote($key, '/') . '\s*[=:]/', $trimmed)) {
                        $line = "{$key} = {$value}";
                        unset($remaining[$key]);
                        break;
                    }
                }
            }

            $modified[] = $line;
        }

        foreach ($remaining as $k => $v) {
            $modified[] = "{$k} = {$v}";
        }

        return file_put_contents($path, implode("\n", $modified) . "\n") !== false;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Multi-Slave CRUD ─────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getSlaves(): array
    {
        try {
            return Database::fetchAll("SELECT * FROM replication_slaves ORDER BY name ASC");
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getSlave(int $id): ?array
    {
        try {
            return Database::fetchOne("SELECT * FROM replication_slaves WHERE id = :id", ['id' => $id]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function addSlave(array $data): int
    {
        $insert = [
            'name'                => $data['name'] ?? '',
            'primary_ip'          => $data['primary_ip'] ?? '',
            'fallback_ip'         => $data['fallback_ip'] ?? '',
            'pg_port'             => (int)($data['pg_port'] ?? 5432),
            'pg_user'             => $data['pg_user'] ?? 'replicator',
            'pg_pass'             => !empty($data['pg_pass']) ? static::encryptPassword($data['pg_pass']) : '',
            'mysql_port'          => (int)($data['mysql_port'] ?? 3306),
            'mysql_user'          => $data['mysql_user'] ?? 'repl_user',
            'mysql_pass'          => !empty($data['mysql_pass']) ? static::encryptPassword($data['mysql_pass']) : '',
            'pg_enabled'          => !empty($data['pg_enabled']) ? true : false,
            'mysql_enabled'       => !empty($data['mysql_enabled']) ? true : false,
            'pg_sync_mode'        => $data['pg_sync_mode'] ?? 'async',
            'pg_repl_type'        => $data['pg_repl_type'] ?? 'physical',
            'pg_logical_databases'=> $data['pg_logical_databases'] ?? '',
            'mysql_gtid_enabled'  => !empty($data['mysql_gtid_enabled']) ? true : false,
            'status'              => 'pending',
            'active_connection'   => 'primary',
        ];

        return Database::insert('replication_slaves', $insert);
    }

    public static function updateSlave(int $id, array $data): void
    {
        $update = [
            'name'                => $data['name'] ?? '',
            'primary_ip'          => $data['primary_ip'] ?? '',
            'fallback_ip'         => $data['fallback_ip'] ?? '',
            'pg_port'             => (int)($data['pg_port'] ?? 5432),
            'pg_user'             => $data['pg_user'] ?? 'replicator',
            'mysql_port'          => (int)($data['mysql_port'] ?? 3306),
            'mysql_user'          => $data['mysql_user'] ?? 'repl_user',
            'pg_enabled'          => !empty($data['pg_enabled']) ? true : false,
            'mysql_enabled'       => !empty($data['mysql_enabled']) ? true : false,
            'pg_sync_mode'        => $data['pg_sync_mode'] ?? 'async',
            'pg_repl_type'        => $data['pg_repl_type'] ?? 'physical',
            'pg_logical_databases'=> $data['pg_logical_databases'] ?? '',
            'mysql_gtid_enabled'  => !empty($data['mysql_gtid_enabled']) ? true : false,
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        // Only update passwords if provided
        if (!empty($data['pg_pass'])) {
            $update['pg_pass'] = static::encryptPassword($data['pg_pass']);
        }
        if (!empty($data['mysql_pass'])) {
            $update['mysql_pass'] = static::encryptPassword($data['mysql_pass']);
        }

        Database::update('replication_slaves', $update, 'id = :id', ['id' => $id]);
    }

    public static function removeSlave(int $id): void
    {
        $slave = static::getSlave($id);
        if (!$slave) return;

        // Remove pg_hba entries for this slave
        $configDir = static::getPgConfigDir();
        $hbaConf = "{$configDir}/pg_hba.conf";
        if (file_exists($hbaConf)) {
            $content = file_get_contents($hbaConf);
            $ips = array_filter([$slave['primary_ip'], $slave['fallback_ip'] ?? '']);
            foreach ($ips as $ip) {
                $content = preg_replace('/^.*' . preg_quote($ip, '/') . '\/32.*$/m', '', $content);
            }
            $content = preg_replace('/\n{3,}/', "\n\n", $content);
            file_put_contents($hbaConf, $content);
        }

        // Drop MySQL replication users for this slave
        try {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $slave['mysql_user'] ?? 'repl_user');
                $ips = array_filter([$slave['primary_ip'], $slave['fallback_ip'] ?? '']);
                foreach ($ips as $ip) {
                    $pdo->exec("DROP USER IF EXISTS '{$safeUser}'@'{$ip}'");
                }
                $pdo->exec("FLUSH PRIVILEGES");
            }
        } catch (\Throwable) {}

        Database::delete('replication_slaves', 'id = :id', ['id' => $id]);
    }

    public static function updateSlaveStatus(int $id, string $status, string $activeConnection = ''): void
    {
        $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($activeConnection !== '') {
            $update['active_connection'] = $activeConnection;
        }
        Database::update('replication_slaves', $update, 'id = :id', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Master (single slave — legacy) ────────────────
    // ═══════════════════════════════════════════════════════════

    public static function setupPgMaster(string $slaveIp, string $replUser, string $replPass): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";
        $hbaConf = "{$configDir}/pg_hba.conf";

        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => [], 'error' => "postgresql.conf no encontrado en {$configDir}"];
        }

        static::backupFile($pgConf);
        static::backupFile($hbaConf);
        $steps[] = ['name' => 'Backup configuracion', 'ok' => true, 'output' => 'OK'];

        $pgVer = (int)(static::detectPgVersion() ?? '16');
        $walKeepParam = $pgVer >= 13 ? 'wal_keep_size' : 'wal_keep_segments';
        $walKeepValue = $pgVer >= 13 ? '512MB' : '64';

        $walLevel = Settings::get('repl_pg_wal_level', 'replica');

        $ok = static::modifyConfigFile($pgConf, [
            'wal_level'         => $walLevel,
            'max_wal_senders'   => '10',
            $walKeepParam       => $walKeepValue,
            'hot_standby'       => 'on',
            'listen_addresses'  => "'*'",
        ]);
        $steps[] = ['name' => 'Modificar postgresql.conf', 'ok' => $ok, 'output' => $ok ? 'OK' : 'Error escribiendo'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar postgresql.conf'];

        $hbaLine = "host    replication     {$replUser}     {$slaveIp}/32     md5";
        $existing = file_get_contents($hbaConf);
        if (!str_contains($existing, $hbaLine)) {
            file_put_contents($hbaConf, $existing . "\n{$hbaLine}\n");
        }
        $steps[] = ['name' => 'Anadir entrada pg_hba.conf', 'ok' => true, 'output' => $hbaLine];

        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $replUser);
        $safePass = escapeshellarg($replPass);
        $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$safeUser}') THEN CREATE ROLE {$safeUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; END IF; END \$\$;";
        $output = shell_exec("sudo -u postgres psql -c " . escapeshellarg($sql) . " 2>&1");
        $steps[] = ['name' => 'Crear usuario replicacion', 'ok' => true, 'output' => trim($output ?? 'OK')];

        $output = shell_exec("systemctl restart postgresql 2>&1");
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Reiniciar PostgreSQL', 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : 'PostgreSQL no arranco correctamente'];
    }

    public static function setupMysqlMaster(string $slaveIp, string $replUser, string $replPass): array
    {
        $steps = [];
        $configPath = static::getMysqlConfigPath();
        $service = static::detectMysqlServiceName();

        static::backupFile($configPath);
        $steps[] = ['name' => 'Backup configuracion MySQL', 'ok' => true, 'output' => 'OK'];

        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = (int)($parts[3] ?? 1);
        if ($serverId < 1) $serverId = 1;

        $ok = static::modifyConfigFile($configPath, [
            'server-id'      => (string)$serverId,
            'log-bin'        => 'mysql-bin',
            'bind-address'   => '0.0.0.0',
            'binlog_format'  => Settings::get('repl_mysql_binlog_format', 'ROW'),
        ], 'mysqld');
        $steps[] = ['name' => 'Modificar configuracion MySQL', 'ok' => $ok, 'output' => $ok ? "server-id={$serverId}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar configuracion MySQL'];

        $pdo = static::getMysqlPdo();
        if (!$pdo) {
            $steps[] = ['name' => 'Crear usuario replicacion', 'ok' => false, 'output' => 'No se pudo conectar a MySQL'];
            return ['ok' => false, 'steps' => $steps, 'error' => 'Conexion MySQL fallida'];
        }

        try {
            $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $replUser);
            $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$slaveIp}' IDENTIFIED BY " . $pdo->quote($replPass));
            $pdo->exec("GRANT REPLICATION SLAVE ON *.* TO '{$safeUser}'@'{$slaveIp}'");
            $pdo->exec("FLUSH PRIVILEGES");
            $steps[] = ['name' => 'Crear usuario replicacion MySQL', 'ok' => true, 'output' => "Usuario {$safeUser} creado"];
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Crear usuario replicacion MySQL', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "{$service} no arranco"];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Master Multi-Slave ─────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Configure PostgreSQL master for multiple slaves.
     * Adds ALL slave IPs (primary+fallback) to pg_hba.conf,
     * creates replication users, configures postgresql.conf.
     */
    public static function setupPgMasterMulti(array $slaves): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";
        $hbaConf = "{$configDir}/pg_hba.conf";

        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => [], 'error' => "postgresql.conf no encontrado en {$configDir}"];
        }

        static::backupFile($pgConf);
        static::backupFile($hbaConf);
        $steps[] = ['name' => 'Backup configuracion PG', 'ok' => true, 'output' => 'OK'];

        $pgVer = (int)(static::detectPgVersion() ?? '16');
        $walKeepParam = $pgVer >= 13 ? 'wal_keep_size' : 'wal_keep_segments';
        $walKeepValue = $pgVer >= 13 ? '512MB' : '64';
        $walLevel = Settings::get('repl_pg_wal_level', 'replica');
        $maxSenders = max(10, count($slaves) * 2 + 3);

        $pgConfSettings = [
            'wal_level'         => $walLevel,
            'max_wal_senders'   => (string)$maxSenders,
            $walKeepParam       => $walKeepValue,
            'hot_standby'       => 'on',
            'listen_addresses'  => "'*'",
        ];

        // Add synchronous_standby_names if any sync slaves
        $syncNames = Settings::get('repl_pg_sync_names', '');
        if ($syncNames !== '') {
            $pgConfSettings['synchronous_standby_names'] = "'{$syncNames}'";
        }

        $ok = static::modifyConfigFile($pgConf, $pgConfSettings);
        $steps[] = ['name' => 'Modificar postgresql.conf', 'ok' => $ok, 'output' => $ok ? "wal_level={$walLevel}, max_wal_senders={$maxSenders}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar postgresql.conf'];

        // Add pg_hba entries for ALL slave IPs
        $existing = file_get_contents($hbaConf);
        $newEntries = [];
        foreach ($slaves as $slave) {
            if (!$slave['pg_enabled']) continue;
            $replUser = $slave['pg_user'] ?? 'replicator';
            $ips = array_filter([$slave['primary_ip'], $slave['fallback_ip'] ?? '']);
            foreach ($ips as $ip) {
                $hbaLine = "host    replication     {$replUser}     {$ip}/32     md5";
                if (!str_contains($existing, $hbaLine) && !in_array($hbaLine, $newEntries)) {
                    $newEntries[] = $hbaLine;
                }
                // Also allow normal connections for logical replication
                if (($slave['pg_repl_type'] ?? 'physical') === 'logical') {
                    $hbaLineAll = "host    all     {$replUser}     {$ip}/32     md5";
                    if (!str_contains($existing, $hbaLineAll) && !in_array($hbaLineAll, $newEntries)) {
                        $newEntries[] = $hbaLineAll;
                    }
                }
            }
        }
        if (!empty($newEntries)) {
            file_put_contents($hbaConf, $existing . "\n" . implode("\n", $newEntries) . "\n");
        }
        $steps[] = ['name' => 'Anadir entradas pg_hba.conf', 'ok' => true, 'output' => count($newEntries) . ' entradas anadidas'];

        // Create replication users
        $createdUsers = [];
        foreach ($slaves as $slave) {
            if (!$slave['pg_enabled']) continue;
            $replUser = preg_replace('/[^a-zA-Z0-9_]/', '', $slave['pg_user'] ?? 'replicator');
            if (in_array($replUser, $createdUsers)) continue;

            $replPass = static::decryptPassword($slave['pg_pass'] ?? '');
            if ($replPass === '') continue;

            $safePass = escapeshellarg($replPass);
            $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$replUser}') THEN CREATE ROLE {$replUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; END IF; END \$\$;";
            $output = shell_exec("sudo -u postgres psql -c " . escapeshellarg($sql) . " 2>&1");
            $steps[] = ['name' => "Crear usuario PG: {$replUser}", 'ok' => true, 'output' => trim($output ?? 'OK')];
            $createdUsers[] = $replUser;
        }

        // Restart PostgreSQL
        $output = shell_exec("systemctl restart postgresql 2>&1");
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Reiniciar PostgreSQL', 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : 'PostgreSQL no arranco correctamente'];
    }

    /**
     * Configure MySQL master for multiple slaves.
     * Creates replication users for ALL slave IPs (primary+fallback).
     */
    public static function setupMysqlMasterMulti(array $slaves): array
    {
        $steps = [];
        $configPath = static::getMysqlConfigPath();
        $service = static::detectMysqlServiceName();

        static::backupFile($configPath);
        $steps[] = ['name' => 'Backup configuracion MySQL', 'ok' => true, 'output' => 'OK'];

        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = (int)($parts[3] ?? 1);
        if ($serverId < 1) $serverId = 1;

        $mysqlConf = [
            'server-id'      => (string)$serverId,
            'log-bin'        => 'mysql-bin',
            'bind-address'   => '0.0.0.0',
            'binlog_format'  => Settings::get('repl_mysql_binlog_format', 'ROW'),
        ];

        // Check if any slave wants GTID
        $anyGtid = false;
        foreach ($slaves as $slave) {
            if ($slave['mysql_enabled'] && !empty($slave['mysql_gtid_enabled'])) {
                $anyGtid = true;
                break;
            }
        }
        if ($anyGtid || Settings::get('repl_mysql_gtid_mode', '0') === '1') {
            $mysqlConf['gtid_mode'] = 'ON';
            $mysqlConf['enforce-gtid-consistency'] = 'ON';
        }

        $ok = static::modifyConfigFile($configPath, $mysqlConf, 'mysqld');
        $steps[] = ['name' => 'Modificar configuracion MySQL', 'ok' => $ok, 'output' => $ok ? "server-id={$serverId}" . ($anyGtid ? ', GTID=ON' : '') : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar configuracion MySQL'];

        $pdo = static::getMysqlPdo();
        if (!$pdo) {
            $steps[] = ['name' => 'Crear usuarios replicacion', 'ok' => false, 'output' => 'No se pudo conectar a MySQL'];
            return ['ok' => false, 'steps' => $steps, 'error' => 'Conexion MySQL fallida'];
        }

        // Create users for ALL slave IPs
        foreach ($slaves as $slave) {
            if (!$slave['mysql_enabled']) continue;
            $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $slave['mysql_user'] ?? 'repl_user');
            $replPass = static::decryptPassword($slave['mysql_pass'] ?? '');
            if ($replPass === '') continue;

            $ips = array_filter([$slave['primary_ip'], $slave['fallback_ip'] ?? '']);
            foreach ($ips as $ip) {
                try {
                    $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$ip}' IDENTIFIED BY " . $pdo->quote($replPass));
                    $pdo->exec("GRANT REPLICATION SLAVE ON *.* TO '{$safeUser}'@'{$ip}'");
                    $steps[] = ['name' => "Usuario MySQL: {$safeUser}@{$ip}", 'ok' => true, 'output' => 'OK'];
                } catch (\Throwable $e) {
                    $steps[] = ['name' => "Usuario MySQL: {$safeUser}@{$ip}", 'ok' => false, 'output' => $e->getMessage()];
                }
            }
        }

        try {
            $pdo->exec("FLUSH PRIVILEGES");
        } catch (\Throwable) {}

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "{$service} no arranco"];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Sync / Async Mode (PostgreSQL) ───────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Set PostgreSQL synchronous replication mode.
     * @param string $mode 'async', 'sync', 'remote_apply'
     * @param array $syncSlaveNames Names of slaves to include in synchronous_standby_names
     */
    public static function setPgSyncMode(string $mode, array $syncSlaveNames = []): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";

        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => [], 'error' => "postgresql.conf no encontrado"];
        }

        static::backupFile($pgConf);

        $settings = [];
        if ($mode === 'async') {
            $settings['synchronous_commit'] = 'off';
            $settings['synchronous_standby_names'] = "''";
            Settings::set('repl_pg_sync_names', '');
        } elseif ($mode === 'sync') {
            $settings['synchronous_commit'] = 'on';
            if (!empty($syncSlaveNames)) {
                $nameList = implode(', ', $syncSlaveNames);
                $standbyNames = "FIRST 1 ({$nameList})";
                $settings['synchronous_standby_names'] = "'{$standbyNames}'";
                Settings::set('repl_pg_sync_names', $standbyNames);
            }
        } elseif ($mode === 'remote_apply') {
            $settings['synchronous_commit'] = 'remote_apply';
            if (!empty($syncSlaveNames)) {
                $nameList = implode(', ', $syncSlaveNames);
                $standbyNames = "FIRST 1 ({$nameList})";
                $settings['synchronous_standby_names'] = "'{$standbyNames}'";
                Settings::set('repl_pg_sync_names', $standbyNames);
            }
        }

        $ok = static::modifyConfigFile($pgConf, $settings);
        $steps[] = ['name' => 'Modificar synchronous_commit', 'ok' => $ok, 'output' => "mode={$mode}"];

        // Reload PostgreSQL (no restart needed for these params)
        $output = shell_exec("systemctl reload postgresql 2>&1");
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Recargar PostgreSQL', 'ok' => $running, 'output' => $running ? 'OK' : trim($output ?? 'Error')];

        return ['ok' => $ok && $running, 'steps' => $steps, 'error' => null];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Logical Replication (PostgreSQL) ─────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Setup this server as a logical replication publisher.
     * Sets wal_level=logical and creates publications for specified databases.
     */
    public static function setupPgLogicalPublisher(array $databases): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";

        // Ensure wal_level=logical
        static::backupFile($pgConf);
        $ok = static::modifyConfigFile($pgConf, [
            'wal_level' => 'logical',
            'max_replication_slots' => (string)max(10, count($databases) * 2),
            'max_wal_senders' => (string)max(10, count($databases) * 2 + 3),
        ]);
        Settings::set('repl_pg_wal_level', 'logical');
        $steps[] = ['name' => 'Configurar wal_level=logical', 'ok' => $ok, 'output' => $ok ? 'OK' : 'Error'];

        // Restart required for wal_level change
        $output = shell_exec("systemctl restart postgresql 2>&1");
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Reiniciar PostgreSQL', 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        if (!$running) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'PostgreSQL no arranco'];
        }

        // Create publications in each database
        foreach ($databases as $db) {
            $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $db);
            $pubName = "pub_{$safeDb}";
            $sql = "CREATE PUBLICATION {$pubName} FOR ALL TABLES IN SCHEMA public";
            $cmd = "sudo -u postgres psql -d " . escapeshellarg($safeDb) . " -c " . escapeshellarg($sql) . " 2>&1";
            $output = trim((string)shell_exec($cmd));
            $pubOk = !str_contains(strtolower($output), 'error') || str_contains($output, 'already exists');
            $steps[] = ['name' => "Publicacion: {$pubName}", 'ok' => $pubOk, 'output' => $output ?: 'OK'];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    /**
     * Setup logical replication subscriber (run on slave).
     * Creates subscriptions to the master's publications.
     */
    public static function setupPgLogicalSubscriber(string $masterIp, int $port, string $user, string $pass, array $databases): array
    {
        $steps = [];

        foreach ($databases as $db) {
            $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $db);
            $subName = "sub_{$safeDb}";
            $pubName = "pub_{$safeDb}";
            $connInfo = "host={$masterIp} port={$port} user={$user} password={$pass} dbname={$safeDb}";
            $sql = "CREATE SUBSCRIPTION {$subName} CONNECTION " . escapeshellarg($connInfo) . " PUBLICATION {$pubName}";
            $cmd = "sudo -u postgres psql -d " . escapeshellarg($safeDb) . " -c " . escapeshellarg($sql) . " 2>&1";
            $output = trim((string)shell_exec($cmd));
            $subOk = !str_contains(strtolower($output), 'error') || str_contains($output, 'already exists');
            $steps[] = ['name' => "Suscripcion: {$subName}", 'ok' => $subOk, 'output' => $output ?: 'OK'];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── GTID (MySQL) ─────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Configure MySQL master for GTID-based replication.
     */
    public static function setupMysqlGtidMaster(): array
    {
        $steps = [];
        $configPath = static::getMysqlConfigPath();
        $service = static::detectMysqlServiceName();

        static::backupFile($configPath);

        $ok = static::modifyConfigFile($configPath, [
            'gtid_mode'                 => 'ON',
            'enforce-gtid-consistency'  => 'ON',
            'log-bin'                   => 'mysql-bin',
            'binlog_format'             => Settings::get('repl_mysql_binlog_format', 'ROW'),
        ], 'mysqld');
        $steps[] = ['name' => 'Configurar GTID master', 'ok' => $ok, 'output' => $ok ? 'gtid_mode=ON' : 'Error'];

        Settings::set('repl_mysql_gtid_mode', '1');

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "{$service} no arranco"];
    }

    /**
     * Configure MySQL slave with GTID auto-positioning.
     */
    public static function setupMysqlGtidSlave(string $masterIp, int $port, string $user, string $pass): array
    {
        $steps = [];
        $configPath = static::getMysqlConfigPath();
        $service = static::detectMysqlServiceName();

        static::backupFile($configPath);

        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = ((int)($parts[3] ?? 2)) + 100;

        $ok = static::modifyConfigFile($configPath, [
            'server-id'                 => (string)$serverId,
            'gtid_mode'                 => 'ON',
            'enforce-gtid-consistency'  => 'ON',
            'relay-log'                 => 'relay-bin',
            'read_only'                 => '1',
            'log-bin'                   => 'mysql-bin',
        ], 'mysqld');
        $steps[] = ['name' => 'Configurar GTID slave', 'ok' => $ok, 'output' => "server-id={$serverId}, GTID=ON"];

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        if (!$running) {
            return ['ok' => false, 'steps' => $steps, 'error' => "{$service} no arranco"];
        }

        // Configure replication with MASTER_AUTO_POSITION
        $pdo = static::getMysqlPdo();
        if (!$pdo) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo conectar a MySQL local'];
        }

        try {
            $mysqlVer = static::detectMysqlVersion();
            $isNew = $mysqlVer && version_compare($mysqlVer, '8.0.23', '>=');

            $pdo->exec("STOP SLAVE");
            if ($isNew) {
                $pdo->exec("CHANGE REPLICATION SOURCE TO SOURCE_HOST=" . $pdo->quote($masterIp) .
                    ", SOURCE_PORT={$port}" .
                    ", SOURCE_USER=" . $pdo->quote($user) .
                    ", SOURCE_PASSWORD=" . $pdo->quote($pass) .
                    ", SOURCE_AUTO_POSITION=1");
                $pdo->exec("START REPLICA");
            } else {
                $pdo->exec("CHANGE MASTER TO MASTER_HOST=" . $pdo->quote($masterIp) .
                    ", MASTER_PORT={$port}" .
                    ", MASTER_USER=" . $pdo->quote($user) .
                    ", MASTER_PASSWORD=" . $pdo->quote($pass) .
                    ", MASTER_AUTO_POSITION=1");
                $pdo->exec("START SLAVE");
            }
            $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => true, 'output' => 'MASTER_AUTO_POSITION=1'];
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Slave (single — legacy) ───────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function setupPgSlave(string $masterIp, int $port, string $replUser, string $replPass): array
    {
        $steps = [];
        $pgVer = static::detectPgVersion() ?? '16';
        $dataDir = static::getPgDataDir();

        shell_exec("systemctl stop postgresql 2>&1");
        $steps[] = ['name' => 'Detener PostgreSQL', 'ok' => true, 'output' => 'OK'];

        shell_exec("rm -rf " . escapeshellarg($dataDir) . "/*");
        $steps[] = ['name' => 'Limpiar directorio de datos', 'ok' => true, 'output' => $dataDir];

        $safeMaster = escapeshellarg($masterIp);
        $safeUser = escapeshellarg($replUser);
        $safeDir = escapeshellarg($dataDir);
        $cmd = "PGPASSWORD=" . escapeshellarg($replPass) . " pg_basebackup -h {$safeMaster} -p {$port} -U {$safeUser} -D {$safeDir} -Fp -Xs -P -R 2>&1";
        $output = shell_exec($cmd);
        $hasSignal = file_exists("{$dataDir}/standby.signal") || file_exists("{$dataDir}/recovery.conf");
        $steps[] = ['name' => 'pg_basebackup', 'ok' => $hasSignal, 'output' => $hasSignal ? 'Completado' : trim($output ?? 'Error')];

        if (!$hasSignal) {
            if ((int)$pgVer < 12) {
                $recoveryConf = "standby_mode = 'on'\nprimary_conninfo = 'host={$masterIp} port={$port} user={$replUser} password={$replPass}'\n";
                file_put_contents("{$dataDir}/recovery.conf", $recoveryConf);
                $steps[] = ['name' => 'Crear recovery.conf', 'ok' => true, 'output' => 'PG < 12'];
            } else {
                return ['ok' => false, 'steps' => $steps, 'error' => 'pg_basebackup fallo. Verifique credenciales y conectividad.'];
            }
        }

        shell_exec("chown -R postgres:postgres " . escapeshellarg($dataDir));
        $steps[] = ['name' => 'Corregir permisos', 'ok' => true, 'output' => 'OK'];

        shell_exec("systemctl start postgresql 2>&1");
        sleep(2);
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Iniciar PostgreSQL', 'ok' => $running, 'output' => $running ? 'Activo' : 'Error al iniciar'];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : 'PostgreSQL no arranco como slave'];
    }

    public static function setupMysqlSlave(string $masterIp, int $port, string $replUser, string $replPass): array
    {
        $steps = [];
        $configPath = static::getMysqlConfigPath();
        $service = static::detectMysqlServiceName();

        static::backupFile($configPath);

        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = ((int)($parts[3] ?? 2)) + 100;

        $ok = static::modifyConfigFile($configPath, [
            'server-id'  => (string)$serverId,
            'relay-log'  => 'relay-bin',
            'read_only'  => '1',
        ], 'mysqld');
        $steps[] = ['name' => 'Configurar MySQL slave', 'ok' => $ok, 'output' => "server-id={$serverId}"];

        shell_exec("systemctl restart {$service} 2>&1");
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => true, 'output' => 'OK'];

        $pdo = static::getMysqlPdo();
        if (!$pdo) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo conectar a MySQL local'];
        }

        try {
            $mysqlVer = static::detectMysqlVersion();
            $isNew = $mysqlVer && version_compare($mysqlVer, '8.0.23', '>=');

            $pdo->exec("STOP SLAVE");
            if ($isNew) {
                $pdo->exec("CHANGE REPLICATION SOURCE TO SOURCE_HOST=" . $pdo->quote($masterIp) .
                    ", SOURCE_PORT={$port}" .
                    ", SOURCE_USER=" . $pdo->quote($replUser) .
                    ", SOURCE_PASSWORD=" . $pdo->quote($replPass) .
                    ", SOURCE_AUTO_POSITION=1");
                $pdo->exec("START REPLICA");
            } else {
                $pdo->exec("CHANGE MASTER TO MASTER_HOST=" . $pdo->quote($masterIp) .
                    ", MASTER_PORT={$port}" .
                    ", MASTER_USER=" . $pdo->quote($replUser) .
                    ", MASTER_PASSWORD=" . $pdo->quote($replPass) .
                    ", MASTER_AUTO_POSITION=1");
                $pdo->exec("START SLAVE");
            }
            $steps[] = ['name' => 'Configurar replicacion MySQL', 'ok' => true, 'output' => 'OK'];
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Configurar replicacion MySQL', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Monitoring ──────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getPgMasterStatus(): ?array
    {
        try {
            $rows = Database::fetchAll("SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn, sync_state FROM pg_stat_replication");
            if (empty($rows)) return null;
            $row = $rows[0];
            $sentLsn = $row['sent_lsn'] ?? '';
            $replayLsn = $row['replay_lsn'] ?? '';
            $lagBytes = 0;
            if ($sentLsn && $replayLsn) {
                $sent = Database::fetchOne("SELECT pg_wal_lsn_diff(:sent::pg_lsn, :replay::pg_lsn) as diff", ['sent' => $sentLsn, 'replay' => $replayLsn]);
                $lagBytes = (int)($sent['diff'] ?? 0);
            }
            $row['lag_bytes'] = $lagBytes;
            $row['replicas'] = $rows;
            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Enhanced: return ALL rows from pg_stat_replication with lag per slave.
     */
    public static function getPgMasterStatusMulti(): array
    {
        try {
            $rows = Database::fetchAll("SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn, sync_state, application_name FROM pg_stat_replication");
            $result = [];
            foreach ($rows as $row) {
                $sentLsn = $row['sent_lsn'] ?? '';
                $replayLsn = $row['replay_lsn'] ?? '';
                $lagBytes = 0;
                if ($sentLsn && $replayLsn) {
                    try {
                        $lag = Database::fetchOne("SELECT pg_wal_lsn_diff(:sent::pg_lsn, :replay::pg_lsn) as diff", ['sent' => $sentLsn, 'replay' => $replayLsn]);
                        $lagBytes = (int)($lag['diff'] ?? 0);
                    } catch (\Throwable) {}
                }
                $row['lag_bytes'] = $lagBytes;
                $result[] = $row;
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getPgSlaveStatus(): ?array
    {
        try {
            $isRecovery = Database::fetchOne("SELECT pg_is_in_recovery() as recovery");
            if (!$isRecovery || $isRecovery['recovery'] !== true) return null;

            $receiver = Database::fetchOne("SELECT status, sender_host, sender_port, last_msg_send_time, last_msg_receipt_time FROM pg_stat_wal_receiver");

            $lsn = Database::fetchOne("
                SELECT pg_last_wal_receive_lsn() as receive_lsn,
                       pg_last_wal_replay_lsn() as replay_lsn,
                       pg_last_xact_replay_timestamp() as replay_time
            ");

            $lagSeconds = 0;
            if (!empty($lsn['replay_time'])) {
                $lagRow = Database::fetchOne("SELECT EXTRACT(EPOCH FROM (NOW() - :ts::timestamptz))::int as lag", ['ts' => $lsn['replay_time']]);
                $lagSeconds = (int)($lagRow['lag'] ?? 0);
            }

            return [
                'status'        => $receiver['status'] ?? 'disconnected',
                'sender_host'   => $receiver['sender_host'] ?? '',
                'sender_port'   => $receiver['sender_port'] ?? '',
                'receive_lsn'   => $lsn['receive_lsn'] ?? '',
                'replay_lsn'    => $lsn['replay_lsn'] ?? '',
                'replay_time'   => $lsn['replay_time'] ?? '',
                'lag_seconds'   => $lagSeconds,
                'last_msg_send' => $receiver['last_msg_send_time'] ?? '',
                'last_msg_recv' => $receiver['last_msg_receipt_time'] ?? '',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getMysqlMasterStatus(): ?array
    {
        try {
            $pdo = static::getMysqlPdo();
            if (!$pdo) return null;
            $row = $pdo->query("SHOW MASTER STATUS")->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getMysqlSlaveStatus(): ?array
    {
        try {
            $pdo = static::getMysqlPdo();
            if (!$pdo) return null;
            try {
                $row = $pdo->query("SHOW REPLICA STATUS")->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                $row = $pdo->query("SHOW SLAVE STATUS")->fetch(\PDO::FETCH_ASSOC);
            }
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Enhanced MySQL slave status with GTID information.
     */
    public static function getMysqlSlaveStatusWithGtid(): ?array
    {
        $status = static::getMysqlSlaveStatus();
        if (!$status) return null;

        // Add GTID fields
        try {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                $gtid = $pdo->query("SELECT @@gtid_mode as gtid_mode, @@global.gtid_executed as gtid_executed")->fetch(\PDO::FETCH_ASSOC);
                if ($gtid) {
                    $status['Gtid_Mode'] = $gtid['gtid_mode'] ?? 'OFF';
                    $status['Gtid_Executed'] = $gtid['gtid_executed'] ?? '';
                }
            }
        } catch (\Throwable) {}

        // These fields come from SHOW SLAVE STATUS if GTID is enabled
        $status['Executed_Gtid_Set'] = $status['Executed_Gtid_Set'] ?? '';
        $status['Retrieved_Gtid_Set'] = $status['Retrieved_Gtid_Set'] ?? '';

        return $status;
    }

    /**
     * Get MySQL master GTID status.
     */
    public static function getMysqlMasterGtidStatus(): ?array
    {
        try {
            $pdo = static::getMysqlPdo();
            if (!$pdo) return null;

            $master = $pdo->query("SHOW MASTER STATUS")->fetch(\PDO::FETCH_ASSOC);
            if (!$master) return null;

            $gtid = $pdo->query("SELECT @@gtid_mode as gtid_mode, @@global.gtid_executed as gtid_executed")->fetch(\PDO::FETCH_ASSOC);
            if ($gtid) {
                $master['Gtid_Mode'] = $gtid['gtid_mode'] ?? 'OFF';
                $master['Gtid_Executed'] = $gtid['gtid_executed'] ?? '';
            }

            return $master;
        } catch (\Throwable) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Switchover ──────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function promotePgSlave(): array
    {
        $steps = [];
        $pgVer = static::detectPgVersion() ?? '16';

        $output = shell_exec("pg_ctlcluster {$pgVer} main promote 2>&1");
        if ($output === null || str_contains($output, 'error')) {
            $dataDir = static::getPgDataDir();
            $output = shell_exec("sudo -u postgres pg_ctl promote -D " . escapeshellarg($dataDir) . " 2>&1");
        }
        $steps[] = ['name' => 'Promover PostgreSQL', 'ok' => true, 'output' => trim($output ?? 'OK')];

        sleep(2);
        try {
            $check = Database::fetchOne("SELECT pg_is_in_recovery() as recovery");
            $promoted = !$check || $check['recovery'] === false;
            $steps[] = ['name' => 'Verificar promocion', 'ok' => $promoted, 'output' => $promoted ? 'Master activo' : 'Aun en recovery'];
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Verificar promocion', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    public static function promoteMysqlSlave(): array
    {
        $steps = [];
        try {
            $pdo = static::getMysqlPdo();
            if (!$pdo) return ['ok' => false, 'steps' => [], 'error' => 'No se pudo conectar a MySQL'];

            try {
                $pdo->exec("STOP REPLICA");
                $pdo->exec("RESET REPLICA ALL");
            } catch (\Throwable) {
                $pdo->exec("STOP SLAVE");
                $pdo->exec("RESET SLAVE ALL");
            }

            $pdo->exec("SET GLOBAL read_only = 0");
            $steps[] = ['name' => 'Promover MySQL', 'ok' => true, 'output' => 'SLAVE detenido, read_only desactivado'];

            $configPath = static::getMysqlConfigPath();
            static::modifyConfigFile($configPath, ['read_only' => '0'], 'mysqld');
            $steps[] = ['name' => 'Actualizar configuracion', 'ok' => true, 'output' => 'OK'];

            return ['ok' => true, 'steps' => $steps, 'error' => null];
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Promover MySQL', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }
    }

    public static function demotePgMaster(string $newMasterIp, int $port, string $replUser, string $replPass): array
    {
        return static::setupPgSlave($newMasterIp, $port, $replUser, $replPass);
    }

    public static function demoteMysqlMaster(string $newMasterIp, int $port, string $replUser, string $replPass): array
    {
        return static::setupMysqlSlave($newMasterIp, $port, $replUser, $replPass);
    }
}
