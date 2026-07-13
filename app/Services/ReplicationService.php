<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Services\PgClusterService;

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

    /**
     * Detect the ACTUAL database vendor and version, from the live server
     * (SELECT VERSION()) rather than the client binary. Returns:
     *   ['vendor' => 'mariadb'|'mysql', 'version' => '10.6.x'|'8.0.x', 'raw' => '...']
     *
     * This is the fix for the vendor-mixup: MariaDB and Oracle MySQL share the
     * `mysql` client but need entirely different replication syntax. We must
     * never send Oracle GTID options to MariaDB, and must never query
     * @@gtid_mode on MariaDB (it doesn't exist there).
     */
    public static function detectDbVendor(?\PDO $pdo = null): array
    {
        $raw = '';
        $pdo = $pdo ?? static::getMysqlPdo();
        if ($pdo) {
            try {
                $raw = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
            } catch (\Throwable) {}
        }
        if ($raw === '') {
            // Fall back to the client banner (less reliable but better than nothing).
            $raw = trim((string)shell_exec('mysql --version 2>/dev/null'));
        }

        $isMaria = stripos($raw, 'mariadb') !== false;
        $version = '';
        if (preg_match('/(\d+\.\d+\.\d+)/', $raw, $m)) {
            $version = $m[1];
        } elseif (preg_match('/(\d+\.\d+)/', $raw, $m)) {
            $version = $m[1];
        }

        return [
            'vendor'  => $isMaria ? 'mariadb' : 'mysql',
            'version' => $version,
            'raw'     => $raw,
        ];
    }

    /**
     * Assess whether native binary replication is viable between this master's
     * MySQL-family engine and a slave's engine/version. MariaDB↔MySQL binary
     * replication is NOT reliably supported and must be blocked with a warning.
     *
     * @param array $slaveInfo ['vendor'=>..., 'version'=>...] of the slave
     * @return array ['compatible'=>bool, 'severity'=>'ok'|'warning'|'critical', 'message'=>...]
     */
    public static function assessMysqlCompatibility(array $masterInfo, array $slaveInfo): array
    {
        $mv = $masterInfo['vendor'] ?? '';
        $sv = $slaveInfo['vendor'] ?? '';

        if ($mv === '' || $sv === '') {
            return ['compatible' => false, 'severity' => 'warning',
                'message' => 'No se pudo determinar el motor de uno de los extremos. No configure replicación nativa hasta verificarlo.'];
        }

        if ($mv !== $sv) {
            return ['compatible' => false, 'severity' => 'critical',
                'message' => "Incompatible: master es {$mv} " . ($masterInfo['version'] ?? '')
                    . " y slave es {$sv} " . ($slaveInfo['version'] ?? '')
                    . ". La replicación binaria fiable exige la MISMA familia (ambos MariaDB o ambos MySQL) y versiones compatibles. "
                    . "Recomendación: instalar {$mv} " . ($masterInfo['version'] ?? '') . " en el slave, o usar sincronización por dumps mientras tanto. "
                    . "Nunca se sustituirá automáticamente el motor si existen bases de datos."];
        }

        // Same vendor: warn if major versions differ a lot.
        $mMajor = (int)explode('.', $masterInfo['version'] ?? '0')[0];
        $sMajor = (int)explode('.', $slaveInfo['version'] ?? '0')[0];
        if ($mMajor !== $sMajor) {
            return ['compatible' => true, 'severity' => 'warning',
                'message' => "Ambos {$mv} pero versiones mayores distintas ({$masterInfo['version']} vs {$slaveInfo['version']}). "
                    . "La replicación suele funcionar master→slave más nuevo, pero verifique compatibilidad."];
        }

        return ['compatible' => true, 'severity' => 'ok',
            'message' => "Compatible: ambos {$mv} " . ($masterInfo['version'] ?? '') . "."];
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
        if (file_exists('/etc/mysql/debian.cnf') && is_readable('/etc/mysql/debian.cnf')) {
            $conf = @parse_ini_file('/etc/mysql/debian.cnf', true) ?: [];
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
            $vendor = static::detectDbVendor($pdo ?? null);
            if ($vendor['vendor'] === 'mariadb') {
                // MariaDB: GTID is implicit with binlog; use strict mode, never gtid_mode.
                $mysqlConf['gtid_strict_mode'] = 'ON';
                $mysqlConf['log_slave_updates'] = 'ON';
            } else {
                $mysqlConf['gtid_mode'] = 'ON';
                $mysqlConf['enforce_gtid_consistency'] = 'ON';
            }
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
        $vendor = static::detectDbVendor();

        static::backupFile($configPath);

        // Vendor-specific GTID master config.
        if ($vendor['vendor'] === 'mariadb') {
            // MariaDB: GTID is implicit once binlog is on; NEVER set gtid_mode /
            // enforce-gtid-consistency (those are Oracle MySQL only and break MariaDB).
            $conf = [
                'log-bin'          => 'mysql-bin',
                'binlog_format'    => Settings::get('repl_mysql_binlog_format', 'ROW'),
                'gtid_strict_mode' => 'ON',
                'log_slave_updates'=> 'ON',
            ];
            $label = 'MariaDB GTID (gtid_strict_mode=ON)';
        } else {
            // Oracle MySQL 8: gtid_mode + enforce_gtid_consistency.
            $conf = [
                'log-bin'                    => 'mysql-bin',
                'binlog_format'              => Settings::get('repl_mysql_binlog_format', 'ROW'),
                'gtid_mode'                  => 'ON',
                'enforce_gtid_consistency'   => 'ON',
            ];
            $label = 'MySQL 8 GTID (gtid_mode=ON)';
        }

        $ok = static::modifyConfigFile($configPath, $conf, 'mysqld');
        $steps[] = ['name' => 'Configurar GTID master', 'ok' => $ok, 'output' => $ok ? $label : 'Error'];

        Settings::set('repl_mysql_gtid_mode', '1');
        Settings::set('repl_mysql_vendor', $vendor['vendor']);

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

        $vendor = static::detectDbVendor();
        if ($vendor['vendor'] === 'mariadb') {
            $conf = [
                'server-id'        => (string)$serverId,
                'relay-log'        => 'relay-bin',
                'read_only'        => '1',
                'log-bin'          => 'mysql-bin',
                'gtid_strict_mode' => 'ON',
                'log_slave_updates'=> 'ON',
            ];
        } else {
            $conf = [
                'server-id'                 => (string)$serverId,
                'gtid_mode'                 => 'ON',
                'enforce_gtid_consistency'  => 'ON',
                'relay-log'                 => 'relay-bin',
                'read_only'                 => '1',
                'log-bin'                   => 'mysql-bin',
            ];
        }
        $ok = static::modifyConfigFile($configPath, $conf, 'mysqld');
        $steps[] = ['name' => 'Configurar GTID slave', 'ok' => $ok, 'output' => "server-id={$serverId}, {$vendor['vendor']} GTID"];

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        if (!$running) {
            return ['ok' => false, 'steps' => $steps, 'error' => "{$service} no arranco"];
        }

        $pdo = static::getMysqlPdo();
        if (!$pdo) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo conectar a MySQL local'];
        }

        try {
            if ($vendor['vendor'] === 'mariadb') {
                // MariaDB GTID: MASTER_USE_GTID=slave_pos (NOT MASTER_AUTO_POSITION).
                $pdo->exec("STOP SLAVE");
                $pdo->exec("CHANGE MASTER TO MASTER_HOST=" . $pdo->quote($masterIp) .
                    ", MASTER_PORT={$port}" .
                    ", MASTER_USER=" . $pdo->quote($user) .
                    ", MASTER_PASSWORD=" . $pdo->quote($pass) .
                    ", MASTER_USE_GTID=slave_pos");
                $pdo->exec("START SLAVE");
                $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => true, 'output' => 'MariaDB: MASTER_USE_GTID=slave_pos'];
            } else {
                // Oracle MySQL 8: SOURCE_AUTO_POSITION on 8.0.23+, else MASTER_AUTO_POSITION.
                $isNew = !empty($vendor['version']) && version_compare($vendor['version'], '8.0.23', '>=');
                $pdo->exec("STOP REPLICA");
                if ($isNew) {
                    $pdo->exec("CHANGE REPLICATION SOURCE TO SOURCE_HOST=" . $pdo->quote($masterIp) .
                        ", SOURCE_PORT={$port}, SOURCE_USER=" . $pdo->quote($user) .
                        ", SOURCE_PASSWORD=" . $pdo->quote($pass) . ", SOURCE_AUTO_POSITION=1");
                    $pdo->exec("START REPLICA");
                    $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => true, 'output' => 'MySQL8: SOURCE_AUTO_POSITION=1'];
                } else {
                    $pdo->exec("CHANGE MASTER TO MASTER_HOST=" . $pdo->quote($masterIp) .
                        ", MASTER_PORT={$port}, MASTER_USER=" . $pdo->quote($user) .
                        ", MASTER_PASSWORD=" . $pdo->quote($pass) . ", MASTER_AUTO_POSITION=1");
                    $pdo->exec("START SLAVE");
                    $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => true, 'output' => 'MySQL: MASTER_AUTO_POSITION=1'];
                }
            }
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'Configurar replicacion GTID', 'ok' => false, 'output' => $e->getMessage()];
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'steps' => $steps, 'error' => null];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Slave (single — legacy) ───────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * DEPRECATED / UNSAFE legacy entry point.
     *
     * The old implementation ran `systemctl stop postgresql` (stopping ALL
     * clusters) and `rm -rf` on a data directory resolved implicitly from the
     * default port — on a multi-cluster host this wiped 14/main. It is now a
     * hard-blocked stub: it refuses to act and redirects callers to the
     * cluster-explicit path setupPgSlaveForCluster().
     *
     * Kept only so existing callers/signatures do not fatal; it never performs
     * a destructive operation.
     */
    public static function setupPgSlave(string $masterIp, int $port, string $replUser, string $replPass): array
    {
        return [
            'ok'    => false,
            'steps' => [[
                'name'   => 'Bloqueado por seguridad',
                'ok'     => false,
                'output' => 'setupPgSlave() legacy está deshabilitado: no distingue clústeres y podría borrar datos. '
                          . 'Use setupPgSlaveForCluster() con identidad explícita (versión, clúster, puerto).',
            ]],
            'error' => 'Operación bloqueada: el procedimiento slave legacy es destructivo en un host multi-clúster. '
                     . 'Seleccione un clúster PostgreSQL explícito.',
        ];
    }

    /**
     * Safe, cluster-explicit slave setup. Operates on ONE cluster only.
     *
     * Guarantees:
     *  - Never runs `systemctl stop postgresql` (umbrella). Uses pg_ctlcluster
     *    on the specific (version, cluster) via its own unit.
     *  - Never `rm -rf` a live data directory. Streams into a fresh TEMP dir,
     *    validates it, and only then swaps it in — moving the old dir aside
     *    (never deleting) so a failure is fully recoverable.
     *  - Verifies master/slave major-version match before touching anything.
     *  - Refuses to overwrite a populated data dir unless $confirmWipe is true.
     *  - dry-run mode returns the planned commands without executing them.
     *
     * @param array  $cluster      descriptor from PgClusterService (target/local cluster to become standby)
     * @param string $masterIp     master host (WireGuard IP)
     * @param int    $sourcePort   master's port for THIS cluster
     * @param string $replUser     replication role
     * @param string $replPass     replication password
     * @param bool   $confirmWipe  explicit confirmation to replace populated data dir
     * @param bool   $dryRun       if true, do not execute — only plan
     */
    public static function setupPgSlaveForCluster(
        array $cluster,
        string $masterIp,
        int $sourcePort,
        string $replUser,
        string $replPass,
        bool $confirmWipe = false,
        bool $dryRun = false
    ): array {
        $steps = [];

        // ── Guard 0: descriptor sanity ──────────────────────────────
        foreach (['version', 'cluster', 'port', 'data_dir', 'unit'] as $k) {
            if (empty($cluster[$k])) {
                return ['ok' => false, 'steps' => $steps, 'error' => "Descriptor de clúster incompleto (falta '{$k}'). Operación abortada."];
            }
        }
        $dataDir = $cluster['data_dir'];
        // Never allow operating on a root-ish or empty path.
        $realParent = dirname($dataDir);
        if ($dataDir === '' || $dataDir === '/' || $realParent === '/' || !str_starts_with($dataDir, '/var/lib/postgresql/')) {
            return ['ok' => false, 'steps' => $steps, 'error' => "data_dir '{$dataDir}' no es un directorio de clúster válido. Operación abortada."];
        }
        $localMajor = (int)$cluster['version'];

        $tmpDir  = rtrim($dataDir, '/') . '.basebackup.' . date('Ymd_His');
        $oldDir  = rtrim($dataDir, '/') . '.old.' . date('Ymd_His');
        $unit    = $cluster['unit'];

        // ── Plan (used by dry-run and for logging) ──────────────────
        $plan = [
            "pg_ctlcluster {$cluster['version']} {$cluster['cluster']} stop   # solo este clúster",
            "pg_basebackup -h {$masterIp} -p {$sourcePort} -U {$replUser} -D {$tmpDir} -Fp -Xs -P -R   # a directorio temporal",
            "validar {$tmpDir}/PG_VERSION == {$localMajor} y presencia de standby.signal",
            "mv {$dataDir} {$oldDir}   # apartar, NO borrar",
            "mv {$tmpDir} {$dataDir}",
            "chown -R postgres:postgres {$dataDir}",
            "pg_ctlcluster {$cluster['version']} {$cluster['cluster']} start",
            "(rollback si falla: restaurar {$oldDir} → {$dataDir})",
        ];

        // ── dry-run returns the plan even if the master is unreachable ──
        // (a dry-run must be able to show what WOULD happen; connectivity and
        //  version checks are surfaced by preflightPgSlave(), not here).
        if ($dryRun) {
            return [
                'ok'      => true,
                'dry_run' => true,
                'steps'   => $steps,
                'plan'    => $plan,
                'error'   => null,
            ];
        }

        // ── Guard 1: version match master vs local cluster ──────────
        $masterInfo = static::testPgConnection($masterIp, $sourcePort, $replUser, $replPass);
        if (!$masterInfo['ok']) {
            return ['ok' => false, 'steps' => $steps, 'error' => "No se pudo conectar al master {$masterIp}:{$sourcePort}: {$masterInfo['error']}"];
        }
        $masterMajor = 0;
        if (preg_match('/PostgreSQL\s+(\d+)/', (string)$masterInfo['version'], $mm)) {
            $masterMajor = (int)$mm[1];
        }
        if ($masterMajor > 0 && $masterMajor !== $localMajor) {
            return ['ok' => false, 'steps' => $steps, 'error' => "Versión mayor incompatible: master PG{$masterMajor} vs slave PG{$localMajor}. La replicación física exige la misma versión mayor."];
        }
        $steps[] = ['name' => 'Verificar versión master/slave', 'ok' => true, 'output' => "master PG{$masterMajor} = slave PG{$localMajor}"];

        // ── Guard 2: populated data dir requires explicit confirmation ──
        $hasData = PgClusterService::dataDirHasData($cluster);
        if ($hasData && !$confirmWipe) {
            return [
                'ok'    => false,
                'steps' => $steps,
                'error' => "El directorio {$dataDir} contiene datos. Se requiere confirmación explícita (confirmWipe) que incluya slave, clúster y puerto antes de reemplazarlo.",
            ];
        }

        // ── Execute (safe order) ────────────────────────────────────
        // Stop ONLY this cluster.
        shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' stop 2>&1');
        $steps[] = ['name' => "Detener clúster {$cluster['key']}", 'ok' => true, 'output' => 'pg_ctlcluster stop (solo este clúster)'];

        // Stream into a fresh temp dir using a protected password file (no PGPASSWORD in argv).
        $pgpass = static::writeTempPgpass($masterIp, $sourcePort, '*', $replUser, $replPass);
        $safeMaster = escapeshellarg($masterIp);
        $safeUser   = escapeshellarg($replUser);
        $safeTmp    = escapeshellarg($tmpDir);
        $env = 'PGPASSFILE=' . escapeshellarg($pgpass) . ' ';
        $cmd = $env . "pg_basebackup -h {$safeMaster} -p {$sourcePort} -U {$safeUser} -D {$safeTmp} -Fp -Xs -P -R 2>&1";
        $output = shell_exec($cmd);
        @unlink($pgpass);

        $tmpOk = is_file("{$tmpDir}/PG_VERSION")
              && (file_exists("{$tmpDir}/standby.signal") || file_exists("{$tmpDir}/recovery.conf"));
        // Validate the streamed cluster's major version matches.
        if ($tmpOk) {
            $tmpVer = trim((string)@file_get_contents("{$tmpDir}/PG_VERSION"));
            if ($tmpVer !== '' && (int)$tmpVer !== $localMajor) {
                $tmpOk = false;
                $output = "PG_VERSION del backup ({$tmpVer}) no coincide con el clúster ({$localMajor})";
            }
        }
        $steps[] = ['name' => 'pg_basebackup a directorio temporal', 'ok' => $tmpOk, 'output' => $tmpOk ? "OK → {$tmpDir}" : trim((string)$output)];

        if (!$tmpOk) {
            // Clean up the failed temp dir; original data dir is untouched.
            shell_exec('rm -rf ' . escapeshellarg($tmpDir) . ' 2>&1');
            shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' start 2>&1');
            return ['ok' => false, 'steps' => $steps, 'error' => 'pg_basebackup falló; el directorio de datos original NO se modificó.'];
        }

        // Swap: move old aside (never delete), move temp in.
        shell_exec('mv ' . escapeshellarg($dataDir) . ' ' . escapeshellarg($oldDir) . ' 2>&1');
        shell_exec('mv ' . escapeshellarg($tmpDir) . ' ' . escapeshellarg($dataDir) . ' 2>&1');
        shell_exec('chown -R postgres:postgres ' . escapeshellarg($dataDir) . ' 2>&1');
        $steps[] = ['name' => 'Sustituir directorio de datos', 'ok' => true, 'output' => "anterior apartado en {$oldDir}"];

        // Start ONLY this cluster.
        shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' start 2>&1');
        sleep(2);
        $running = PgClusterService::isRunning($cluster);

        if (!$running) {
            // Rollback: restore the old data dir.
            shell_exec('rm -rf ' . escapeshellarg($dataDir) . ' 2>&1');
            shell_exec('mv ' . escapeshellarg($oldDir) . ' ' . escapeshellarg($dataDir) . ' 2>&1');
            shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' start 2>&1');
            $steps[] = ['name' => 'Rollback', 'ok' => true, 'output' => "Restaurado {$oldDir} → {$dataDir}"];
            return ['ok' => false, 'steps' => $steps, 'error' => "El clúster {$cluster['key']} no arrancó como slave; se restauró el estado anterior."];
        }

        $steps[] = ['name' => "Iniciar clúster {$cluster['key']}", 'ok' => true, 'output' => 'Activo como standby'];
        return ['ok' => true, 'steps' => $steps, 'error' => null, 'old_data_dir' => $oldDir];
    }

    /**
     * Write a temporary, 0600 .pgpass file so replication passwords never appear
     * in the process argument list (visible via `ps`). Caller must unlink it.
     */
    private static function writeTempPgpass(string $host, int $port, string $db, string $user, string $pass): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mdpgpass_');
        // pgpass format: host:port:db:user:password  ('*' matches any db)
        $line = sprintf("%s:%d:%s:%s:%s\n", $host, $port, $db, $user, str_replace(['\\', ':'], ['\\\\', '\\:'], $pass));
        file_put_contents($file, $line);
        @chmod($file, 0600);
        return $file;
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
    // ─── Replication matrix (UI) ─────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Build the replication matrix rows for the UI:
     *   Slave | Engine | Instance | Port | Role | Status | Lag | Slot | Last error
     *
     * Combines configured per-cluster PG instances with the LIVE per-cluster
     * streaming state, plus a MySQL/MariaDB row per slave. Read-only.
     */
    public static function buildMatrix(): array
    {
        $rows = [];
        $liveStreaming = static::getPgStreamingByCluster();
        $localVendor = static::detectDbVendor();

        foreach (static::getSlaves() as $slave) {
            // PostgreSQL per-cluster instances.
            foreach (static::getPgInstances((int)$slave['id']) as $inst) {
                $key = "{$inst['pg_version']}/{$inst['cluster_name']}";
                $live = $liveStreaming[$key] ?? null;
                $status = $inst['status'] ?? 'pending';
                $lag = '';
                if ($live && ($live['role'] ?? '') === 'slave') {
                    $status = $live['streaming'] ? 'streaming' : 'desconectado';
                    $lag = isset($live['lag_seconds']) ? $live['lag_seconds'] . 's' : '';
                }
                $rows[] = [
                    'slave'      => $slave['name'],
                    'slave_id'   => (int)$slave['id'],
                    'engine'     => 'PostgreSQL',
                    'instance'   => $key,
                    'port'       => (int)$inst['source_port'],
                    'role'       => 'slave',
                    'status'     => $status,
                    'lag'        => $lag,
                    'slot'       => $inst['slot_name'] ?? '',
                    'last_error' => $inst['last_error'] ?? '',
                    'enabled'    => !empty($inst['enabled']),
                    'instance_id'=> (int)$inst['id'],
                ];
            }

            // MySQL/MariaDB row (if the slave has it enabled).
            if (!empty($slave['mysql_enabled'])) {
                // Compatibility check master(local) vs slave engine is advisory here.
                $slaveVendor = ['vendor' => $slave['mysql_vendor'] ?? '', 'version' => $slave['mysql_version'] ?? ''];
                $compat = ($slaveVendor['vendor'] && $slaveVendor['vendor'] !== $localVendor['vendor'])
                    ? 'incompatible (' . $localVendor['vendor'] . '→' . $slaveVendor['vendor'] . ')'
                    : ($slave['status'] ?? 'pending');
                $rows[] = [
                    'slave'      => $slave['name'],
                    'slave_id'   => (int)$slave['id'],
                    'engine'     => $localVendor['vendor'] === 'mariadb' ? 'MariaDB' : 'MySQL',
                    'instance'   => $localVendor['version'] ?: ($slave['mysql_version'] ?? ''),
                    'port'       => (int)($slave['mysql_port'] ?? 3306),
                    'role'       => 'slave',
                    'status'     => $compat,
                    'lag'        => '',
                    'slot'       => '',
                    'last_error' => '',
                    'enabled'    => true,
                    'instance_id'=> 0,
                ];
            }
        }

        return $rows;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Preflight + confirmation (safety gate) ──────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Build the required literal confirmation token for a destructive slave
     * conversion. It embeds slave name, cluster and port so a copy-pasted token
     * cannot target the wrong instance.
     * e.g. "SLAVE:filemon CLUSTER:14/main PORT:5432"
     */
    public static function slaveConfirmToken(string $slaveName, array $cluster): string
    {
        return sprintf(
            'SLAVE:%s CLUSTER:%s PORT:%d',
            strtolower(trim($slaveName)),
            $cluster['key'] ?? '?',
            $cluster['port'] ?? 0
        );
    }

    public static function verifySlaveConfirmToken(string $provided, string $slaveName, array $cluster): bool
    {
        return hash_equals(
            static::slaveConfirmToken($slaveName, $cluster),
            trim($provided)
        );
    }

    /**
     * Preflight report shown before ANY destructive PostgreSQL slave operation.
     * Reads-only; never modifies. Surfaces exactly what the prompt requires:
     * master/slave, engine, version, cluster, port, data dir, data size, free
     * space, service state, existing target DBs, WireGuard reachability, version
     * compatibility, the commands that WOULD run, files modified, services
     * restarted, risks and estimated time.
     */
    public static function preflightPgSlave(
        array $cluster,
        string $masterIp,
        int $sourcePort,
        string $replUser,
        string $replPass,
        string $slaveName = ''
    ): array {
        $report = [
            'engine'        => 'PostgreSQL',
            'slave'         => $slaveName,
            'cluster'       => $cluster['key'] ?? '?',
            'version'       => $cluster['version'] ?? '?',
            'port'          => $cluster['port'] ?? 0,
            'data_dir'      => $cluster['data_dir'] ?? '',
            'config_file'   => $cluster['config_file'] ?? '',
            'unit'          => $cluster['unit'] ?? '',
            'master_ip'     => $masterIp,
            'source_port'   => $sourcePort,
            'checks'        => [],
            'commands'      => [],
            'files_modified'=> [],
            'services'      => [],
            'risks'         => [],
            'blocking'      => [],   // conditions that must be resolved first
            'confirm_token' => static::slaveConfirmToken($slaveName, $cluster),
        ];

        $add = function (string $name, bool $ok, string $detail) use (&$report) {
            $report['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        // Data size + free space.
        $sizeBytes = PgClusterService::dataDirSizeBytes($cluster);
        $freeBytes = PgClusterService::dataDirFreeBytes($cluster);
        $report['data_size_bytes'] = $sizeBytes;
        $report['free_bytes']      = $freeBytes;
        $add('Tamaño de datos', true, static::humanBytes($sizeBytes));
        $enoughSpace = $freeBytes > $sizeBytes; // basebackup needs a temp copy
        $add('Espacio libre suficiente para basebackup temporal', $enoughSpace,
            static::humanBytes($freeBytes) . ' libre vs ' . static::humanBytes($sizeBytes) . ' de datos');
        if (!$enoughSpace) $report['blocking'][] = 'Espacio libre insuficiente para el directorio temporal de pg_basebackup.';

        // Service state (this cluster only).
        $running = PgClusterService::isRunning($cluster);
        $add('Estado del clúster local', true, $running ? 'activo' : 'detenido');

        // Populated target?
        $hasData = PgClusterService::dataDirHasData($cluster);
        $add('Directorio de datos con contenido', true, $hasData ? 'SÍ — requiere confirmación explícita' : 'vacío');
        if ($hasData) {
            $report['risks'][] = "El directorio {$cluster['data_dir']} contiene datos; se apartará (no se borra) y se sustituirá por el standby.";
        }

        // WireGuard reachability + master connection + version compatibility.
        $conn = static::testPgConnection($masterIp, $sourcePort, $replUser, $replPass);
        $add('Conectividad WireGuard al master', $conn['ok'],
            $conn['ok'] ? "OK ({$masterIp}:{$sourcePort})" : "FALLO: {$conn['error']}");
        if (!$conn['ok']) $report['blocking'][] = "No hay conexión al master {$masterIp}:{$sourcePort}.";

        if ($conn['ok']) {
            $masterMajor = 0;
            if (preg_match('/PostgreSQL\s+(\d+)/', (string)$conn['version'], $mm)) $masterMajor = (int)$mm[1];
            $localMajor = (int)($cluster['version'] ?? 0);
            $compatible = $masterMajor === 0 || $masterMajor === $localMajor;
            $add('Compatibilidad de versión mayor', $compatible, "master PG{$masterMajor} vs slave PG{$localMajor}");
            if (!$compatible) $report['blocking'][] = "Versión mayor incompatible (PG{$masterMajor} vs PG{$localMajor}).";
        }

        // Commands / files / services that WOULD run.
        $tmpDir = rtrim($cluster['data_dir'], '/') . '.basebackup.<ts>';
        $report['commands'] = [
            "pg_ctlcluster {$cluster['version']} {$cluster['cluster']} stop",
            "pg_basebackup -h {$masterIp} -p {$sourcePort} -U {$replUser} -D {$tmpDir} -Fp -Xs -P -R",
            "mv {$cluster['data_dir']} {$cluster['data_dir']}.old.<ts>",
            "mv {$tmpDir} {$cluster['data_dir']}",
            "chown -R postgres:postgres {$cluster['data_dir']}",
            "pg_ctlcluster {$cluster['version']} {$cluster['cluster']} start",
        ];
        $report['files_modified'] = [$cluster['data_dir'] . ' (reemplazado; anterior apartado en .old.<ts>)'];
        $report['services'] = [$cluster['unit'] . ' (stop/start — SOLO este clúster)'];
        $report['risks'][] = 'Los demás clústeres PostgreSQL NO se detienen.';
        $report['estimated_time'] = static::estimateBasebackupSeconds($sizeBytes);
        $report['ok_to_proceed'] = empty($report['blocking']);

        return $report;
    }

    private static function humanBytes(int $b): string
    {
        if ($b <= 0) return '0 B';
        $u = ['B','KB','MB','GB','TB']; $i = (int)floor(log($b, 1024));
        $i = min($i, count($u) - 1);
        return round($b / (1024 ** $i), 1) . ' ' . $u[$i];
    }

    private static function estimateBasebackupSeconds(int $bytes): string
    {
        // Rough: assume ~50 MB/s over WireGuard as a conservative floor.
        $secs = (int)ceil($bytes / (50 * 1024 * 1024));
        if ($secs < 60) return "~{$secs}s";
        return '~' . ceil($secs / 60) . ' min';
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Per-cluster PG instances CRUD (replication_pg_instances)
    // ═══════════════════════════════════════════════════════════

    /** All PG instance relationships for a slave. Password stays encrypted. */
    public static function getPgInstances(int $slaveId): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM replication_pg_instances WHERE slave_id = :sid ORDER BY source_port ASC",
                ['sid' => $slaveId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** Every enabled PG instance across all slaves (for master-side config). */
    public static function getAllPgInstances(bool $enabledOnly = false): array
    {
        try {
            $sql = "SELECT i.*, s.name AS slave_name, s.primary_ip, s.fallback_ip
                    FROM replication_pg_instances i
                    JOIN replication_slaves s ON s.id = i.slave_id";
            if ($enabledOnly) $sql .= " WHERE i.enabled = true";
            $sql .= " ORDER BY s.name, i.source_port";
            return Database::fetchAll($sql);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Create/update a per-cluster instance row. Derives a UNIQUE slot_name and
     * application_name per (slave, cluster) so multiple slaves and clusters never
     * collide on the master.
     */
    public static function upsertPgInstance(int $slaveId, array $data): int
    {
        $slave = static::getSlave($slaveId);
        $slaveTag = $slave ? preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($slave['name'])) : "slave{$slaveId}";
        $ver = preg_replace('/[^0-9]/', '', (string)($data['pg_version'] ?? ''));
        $cluster = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['cluster_name'] ?? ''));

        // Unique, deterministic identifiers.
        $slot = $data['slot_name'] ?? '';
        if ($slot === '') $slot = "slot_{$slaveTag}_{$ver}_{$cluster}";
        $appName = $data['application_name'] ?? '';
        if ($appName === '') $appName = "{$slaveTag}_{$ver}_{$cluster}";

        $row = [
            'slave_id'          => $slaveId,
            'pg_version'        => $ver,
            'cluster_name'      => $cluster,
            'source_port'       => (int)($data['source_port'] ?? 0),
            'target_port'       => (int)($data['target_port'] ?? ($data['source_port'] ?? 0)),
            'replication_type'  => in_array($data['replication_type'] ?? 'physical', ['physical','logical'], true) ? $data['replication_type'] : 'physical',
            'sync_mode'         => in_array($data['sync_mode'] ?? 'async', ['async','sync','remote_apply'], true) ? $data['sync_mode'] : 'async',
            'replication_user'  => preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['replication_user'] ?? 'replicator')),
            'slot_name'         => preg_replace('/[^a-zA-Z0-9_]/', '', $slot),
            'application_name'  => preg_replace('/[^a-zA-Z0-9_]/', '', $appName),
            'enabled'           => !empty($data['enabled']),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];
        if (!empty($data['password'])) {
            $row['encrypted_password'] = static::encryptPassword($data['password']);
        }

        $existing = Database::fetchOne(
            "SELECT id FROM replication_pg_instances WHERE slave_id = :sid AND pg_version = :v AND cluster_name = :c",
            ['sid' => $slaveId, 'v' => $ver, 'c' => $cluster]
        );
        if ($existing) {
            Database::update('replication_pg_instances', $row, 'id = :id', ['id' => $existing['id']]);
            return (int)$existing['id'];
        }
        $row['status'] = 'pending';
        $row['created_at'] = date('Y-m-d H:i:s');
        return Database::insert('replication_pg_instances', $row);
    }

    public static function deletePgInstance(int $id): void
    {
        try { Database::delete('replication_pg_instances', 'id = :id', ['id' => $id]); } catch (\Throwable) {}
    }

    public static function updatePgInstanceStatus(int $id, string $status, string $error = ''): void
    {
        try {
            Database::update('replication_pg_instances', [
                'status'          => $status,
                'last_error'      => $error,
                'last_checked_at' => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
        } catch (\Throwable) {}
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Master config, per explicit cluster (safe) ──────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Configure ONE PostgreSQL cluster as a replication master for a set of
     * slave IPs. Touches only that cluster's postgresql.conf / pg_hba.conf and
     * reloads/restarts ONLY that cluster — never the umbrella service.
     *
     * Security:
     *  - listen_addresses limited to loopback + WireGuard IP (never '*').
     *  - pg_hba entries scoped to each slave's /32 (WireGuard IP), not 0.0.0.0.
     *  - a unique physical replication slot is created per slave+cluster.
     *
     * @param array  $cluster    descriptor from PgClusterService
     * @param array  $slaveIps   list of slave WireGuard IPs to authorize
     * @param string $replUser   replication role name
     * @param string $replPass   replication role password (created if missing)
     * @param array  $slotNames  physical slot names to ensure (per slave+cluster)
     * @param bool   $dryRun
     */
    public static function setupPgMasterForCluster(
        array $cluster,
        array $slaveIps,
        string $replUser,
        string $replPass,
        array $slotNames = [],
        bool $dryRun = false
    ): array {
        $steps = [];
        foreach (['version', 'cluster', 'port', 'config_file', 'hba_file', 'unit'] as $k) {
            if (empty($cluster[$k])) {
                return ['ok' => false, 'steps' => $steps, 'error' => "Descriptor de clúster incompleto (falta '{$k}')."];
            }
        }
        $pgConf = $cluster['config_file'];
        $hbaConf = $cluster['hba_file'];
        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => $steps, 'error' => "postgresql.conf no encontrado: {$pgConf}"];
        }

        // Loopback + WireGuard IP only.
        $wgIp = static::detectWireguardIp();
        $listen = "'127.0.0.1" . ($wgIp ? ",{$wgIp}" : "") . "'";

        $pgVer = (int)$cluster['version'];
        $walKeepParam = $pgVer >= 13 ? 'wal_keep_size' : 'wal_keep_segments';
        $walKeepValue = $pgVer >= 13 ? '512MB' : '64';
        $walLevel = Settings::get('repl_pg_wal_level', 'replica');
        $maxSenders = max(10, count($slaveIps) * 2 + 3);
        $maxSlots   = max(10, count($slotNames) + count($slaveIps) + 3);

        $pgSettings = [
            'wal_level'             => $walLevel,
            'max_wal_senders'       => (string)$maxSenders,
            'max_replication_slots' => (string)$maxSlots,
            $walKeepParam           => $walKeepValue,
            'hot_standby'           => 'on',
            'listen_addresses'      => $listen,
        ];

        $hbaLines = [];
        foreach ($slaveIps as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $hbaLines[] = "host    replication     {$replUser}     {$ip}/32     scram-sha-256";
        }

        if ($dryRun) {
            return [
                'ok' => true, 'dry_run' => true, 'steps' => $steps, 'error' => null,
                'plan' => [
                    "backup {$pgConf} y {$hbaConf}",
                    "postgresql.conf: " . json_encode($pgSettings),
                    "pg_hba.conf +=\n  " . implode("\n  ", $hbaLines),
                    "crear rol de replicación {$replUser} (si falta) en :{$cluster['port']}",
                    "crear slots físicos: " . implode(', ', $slotNames),
                    "pg_ctlcluster {$cluster['version']} {$cluster['cluster']} reload   # solo este clúster",
                ],
            ];
        }

        static::backupFile($pgConf);
        static::backupFile($hbaConf);
        $steps[] = ['name' => 'Backup config', 'ok' => true, 'output' => $cluster['key']];

        $ok = static::modifyConfigFile($pgConf, $pgSettings);
        $steps[] = ['name' => 'postgresql.conf', 'ok' => $ok, 'output' => $ok ? "listen={$listen}, wal_level={$walLevel}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar postgresql.conf'];

        // pg_hba, scoped to WG /32.
        $existing = file_get_contents($hbaConf);
        $newLines = array_filter($hbaLines, fn($l) => !str_contains($existing, $l));
        if (!empty($newLines)) {
            file_put_contents($hbaConf, rtrim($existing) . "\n" . implode("\n", $newLines) . "\n");
        }
        $steps[] = ['name' => 'pg_hba.conf', 'ok' => true, 'output' => count($newLines) . ' entradas /32 (WG)'];

        // Replication role on THIS cluster's port.
        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $replUser);
        if ($replPass !== '') {
            $pgpass = static::writeTempPgpass('127.0.0.1', (int)$cluster['port'], '*', 'postgres', '');
            $safePass = escapeshellarg($replPass);
            $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='{$safeUser}') THEN CREATE ROLE {$safeUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; ELSE ALTER ROLE {$safeUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; END IF; END \$\$;";
            $out = shell_exec('sudo -u postgres psql -p ' . (int)$cluster['port'] . ' -c ' . escapeshellarg($sql) . ' 2>&1');
            @unlink($pgpass);
            $steps[] = ['name' => "Rol replicación {$safeUser}", 'ok' => true, 'output' => trim((string)$out) ?: 'OK'];
        }

        // Physical slots (unique per slave+cluster).
        foreach ($slotNames as $slot) {
            $safeSlot = preg_replace('/[^a-zA-Z0-9_]/', '', $slot);
            if ($safeSlot === '') continue;
            $sql = "SELECT pg_create_physical_replication_slot('{$safeSlot}') WHERE NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name='{$safeSlot}');";
            $out = shell_exec('sudo -u postgres psql -p ' . (int)$cluster['port'] . ' -tAc ' . escapeshellarg($sql) . ' 2>&1');
            $steps[] = ['name' => "Slot físico {$safeSlot}", 'ok' => true, 'output' => trim((string)$out) ?: 'OK/existe'];
        }

        // Reload ONLY this cluster (config changes here need reload, not restart,
        // except wal_level which needs restart — surface that to the caller).
        $needsRestart = ($walLevel !== static::currentPgSetting($cluster, 'wal_level'));
        if ($needsRestart) {
            shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' restart 2>&1');
            $steps[] = ['name' => 'Reiniciar clúster', 'ok' => true, 'output' => 'restart (wal_level cambió)'];
        } else {
            shell_exec('pg_ctlcluster ' . escapeshellarg($cluster['version']) . ' ' . escapeshellarg($cluster['cluster']) . ' reload 2>&1');
            $steps[] = ['name' => 'Recargar clúster', 'ok' => true, 'output' => 'reload'];
        }
        $running = PgClusterService::isRunning($cluster);
        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "El clúster {$cluster['key']} no está activo tras la operación"];
    }

    /** Read a live GUC value from a specific cluster (via its port). */
    public static function currentPgSetting(array $cluster, string $name): string
    {
        $port = (int)($cluster['port'] ?? 0);
        if ($port <= 0) return '';
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        $out = shell_exec('sudo -u postgres psql -p ' . $port . ' -tAc ' . escapeshellarg("SHOW {$safe}") . ' 2>/dev/null');
        return trim((string)$out);
    }

    /** Best-effort WireGuard IP (10.10.70.x on this fleet). */
    public static function detectWireguardIp(): string
    {
        $out = trim((string)shell_exec("ip -o -4 addr show 2>/dev/null | awk '{print \$4}' | cut -d/ -f1"));
        foreach (preg_split('/\s+/', $out) as $ip) {
            if (str_starts_with($ip, '10.10.70.')) return $ip;
        }
        return '';
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

    /**
     * Run a query against a SPECIFIC cluster (by port) as the postgres user and
     * return rows as arrays. Uses -tA and a caller-controlled separator so we can
     * monitor 5432 / 5433 / 5434 independently — the old monitoring only ever
     * queried the panel DB on 5433.
     *
     * Returns [] on any error (cluster down, auth, etc).
     */
    public static function queryCluster(array $cluster, string $sql): array
    {
        $port = (int)($cluster['port'] ?? 0);
        if ($port <= 0) return [];
        $cmd = 'sudo -u postgres psql -p ' . $port . ' -tAF "|" -c ' . escapeshellarg($sql) . ' 2>/dev/null';
        $out = trim((string)shell_exec($cmd));
        if ($out === '') return [];
        $rows = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if ($line === '') continue;
            $rows[] = explode('|', $line);
        }
        return $rows;
    }

    /**
     * Per-cluster master status: what standbys are connected to THIS cluster,
     * with lag in bytes. Reads pg_stat_replication on the cluster's own port.
     */
    public static function getPgMasterStatusForCluster(array $cluster): array
    {
        $sql = "SELECT application_name, client_addr, state, sync_state, "
             . "pg_wal_lsn_diff(sent_lsn, replay_lsn) AS lag_bytes, "
             . "sent_lsn, replay_lsn FROM pg_stat_replication";
        $rows = static::queryCluster($cluster, $sql);
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'application_name' => $r[0] ?? '',
                'client_addr'      => $r[1] ?? '',
                'state'            => $r[2] ?? '',
                'sync_state'       => $r[3] ?? '',
                'lag_bytes'        => (int)($r[4] ?? 0),
                'sent_lsn'         => $r[5] ?? '',
                'replay_lsn'       => $r[6] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Per-cluster slave/standby status: is THIS cluster in recovery, connected,
     * streaming, and how far behind. Reads pg_stat_wal_receiver on its own port.
     */
    public static function getPgSlaveStatusForCluster(array $cluster): ?array
    {
        $rec = static::queryCluster($cluster, "SELECT pg_is_in_recovery()");
        $inRecovery = isset($rec[0][0]) && ($rec[0][0] === 't' || $rec[0][0] === 'true');
        if (!$inRecovery) return null;

        $wr = static::queryCluster($cluster,
            "SELECT status, sender_host, sender_port, slot_name FROM pg_stat_wal_receiver");
        $lsn = static::queryCluster($cluster,
            "SELECT pg_last_wal_receive_lsn(), pg_last_wal_replay_lsn(), "
          . "COALESCE(EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))::int, 0)");

        $status = $wr[0][0] ?? 'disconnected';
        return [
            'in_recovery'  => true,
            'status'       => $status,
            'sender_host'  => $wr[0][1] ?? '',
            'sender_port'  => $wr[0][2] ?? '',
            'slot_name'    => $wr[0][3] ?? '',
            'receive_lsn'  => $lsn[0][0] ?? '',
            'replay_lsn'   => $lsn[0][1] ?? '',
            'lag_seconds'  => (int)($lsn[0][2] ?? 0),
            'streaming'    => $status === 'streaming',
        ];
    }

    /**
     * Streaming status PER cluster (not a single global boolean). Returns a map
     * keyed by "version/cluster" so dumps can be decided per instance:
     *   [ '14/main' => ['streaming'=>true, 'role'=>'slave', 'lag_seconds'=>2], ... ]
     *
     * A cluster that is NOT streaming is never considered protected.
     */
    public static function getPgStreamingByCluster(): array
    {
        $map = [];
        foreach (PgClusterService::listClusters() as $c) {
            $slave = static::getPgSlaveStatusForCluster($c);
            if ($slave !== null) {
                $map[$c['key']] = [
                    'role'        => 'slave',
                    'streaming'   => (bool)$slave['streaming'],
                    'lag_seconds' => $slave['lag_seconds'],
                    'slot_name'   => $slave['slot_name'],
                ];
            } else {
                $masters = static::getPgMasterStatusForCluster($c);
                $map[$c['key']] = [
                    'role'        => 'master',
                    'streaming'   => !empty($masters),   // has connected standbys
                    'standbys'    => count($masters),
                ];
            }
        }
        return $map;
    }

    /**
     * Decide, PER PostgreSQL cluster, whether a replica (transfer) dump can be
     * skipped because streaming is genuinely protecting THAT cluster. A cluster
     * that is not streaming is never protected, so its replica dump is kept.
     *
     * IMPORTANT: this only governs the streaming-optimisation "replica" dumps.
     * Logical/backup dumps are governed separately and are NEVER dropped just
     * because streaming is on (see keepLogicalDumps()).
     *
     * @return array keyed by "version/cluster": ['skip_replica_dump'=>bool, 'reason'=>...]
     */
    public static function dumpDecisionByCluster(): array
    {
        $decision = [];
        $streaming = static::getPgStreamingByCluster();
        foreach ($streaming as $key => $st) {
            // Only a cluster acting as a slave AND actively streaming is protected.
            $protected = ($st['role'] ?? '') === 'slave' && !empty($st['streaming']);
            $decision[$key] = [
                'skip_replica_dump' => $protected,
                'reason' => $protected
                    ? 'streaming activo en este clúster'
                    : 'sin streaming: se mantiene el dump de este clúster',
                'role'      => $st['role'] ?? 'unknown',
                'streaming' => !empty($st['streaming']),
            ];
        }
        return $decision;
    }

    /**
     * Logical/backup dumps are ALWAYS kept, regardless of streaming state, so a
     * corruption or accidental DELETE on the master (which streaming faithfully
     * replicates to the slave) is still recoverable from a point-in-time dump.
     * Honoured via the repl_dumps_keep setting (default '1').
     */
    public static function keepLogicalDumps(): bool
    {
        return Settings::get('repl_dumps_keep', '1') !== '0';
    }

    /**
     * Check if streaming replication is currently active, PER ENGINE and PER
     * PostgreSQL cluster. Never collapses PostgreSQL to a single boolean: the
     * old behaviour could skip dumps for 14/panel just because 14/main streamed.
     *
     * Returns:
     *   [
     *     'pg_by_cluster' => ['14/main'=>bool, '14/panel'=>bool, '16/musemind'=>bool],
     *     'pg_all'        => bool,   // true only if EVERY pg cluster streams
     *     'mysql'         => bool,
     *     'any_active'    => bool,
     *   ]
     */
    public static function isStreamingActive(): array
    {
        $pgByCluster = [];
        foreach (static::getPgStreamingByCluster() as $key => $st) {
            $pgByCluster[$key] = ($st['role'] ?? '') === 'slave' && !empty($st['streaming']);
        }
        $pgAll = !empty($pgByCluster) && !in_array(false, $pgByCluster, true);
        $pgAny = in_array(true, $pgByCluster, true);

        $mysqlActive = false;
        try {
            $mysqlStatus = static::getMysqlSlaveStatus();
            if ($mysqlStatus &&
                in_array($mysqlStatus['Slave_IO_Running'] ?? $mysqlStatus['Replica_IO_Running'] ?? '', ['Yes'], true) &&
                in_array($mysqlStatus['Slave_SQL_Running'] ?? $mysqlStatus['Replica_SQL_Running'] ?? '', ['Yes'], true)) {
                $mysqlActive = true;
            }
        } catch (\Throwable) {}

        return [
            'pg_by_cluster' => $pgByCluster,
            'pg_all'        => $pgAll,       // only true when ALL pg clusters stream
            'pg'            => $pgAll,       // backwards-compat: conservative (all)
            'mysql'         => $mysqlActive,
            'any_active'    => $pgAny || $mysqlActive,
        ];
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

        // Add GTID fields — vendor-aware. @@gtid_mode does NOT exist in MariaDB;
        // querying it there throws, so branch on the detected vendor.
        try {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                $vendor = static::detectDbVendor($pdo);
                if ($vendor['vendor'] === 'mariadb') {
                    // MariaDB exposes GTID position via @@gtid_slave_pos / @@gtid_current_pos.
                    $gtid = $pdo->query("SELECT @@gtid_slave_pos AS slave_pos, @@gtid_current_pos AS current_pos")->fetch(\PDO::FETCH_ASSOC);
                    if ($gtid) {
                        $status['Gtid_Mode'] = 'MariaDB';
                        $status['Gtid_Slave_Pos'] = $gtid['slave_pos'] ?? '';
                        $status['Gtid_Current_Pos'] = $gtid['current_pos'] ?? '';
                    }
                } else {
                    $gtid = $pdo->query("SELECT @@gtid_mode as gtid_mode, @@global.gtid_executed as gtid_executed")->fetch(\PDO::FETCH_ASSOC);
                    if ($gtid) {
                        $status['Gtid_Mode'] = $gtid['gtid_mode'] ?? 'OFF';
                        $status['Gtid_Executed'] = $gtid['gtid_executed'] ?? '';
                    }
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

    // ═══════════════════════════════════════════════════════════
    // ─── NEW: Activate Master (config only, no credentials) ──
    // ═══════════════════════════════════════════════════════════

    /**
     * Activate PostgreSQL as master: only touches postgresql.conf + restart.
     * Does NOT create users or modify pg_hba.conf.
     */
    public static function activatePgMaster(): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";

        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => [], 'error' => "postgresql.conf no encontrado en {$configDir}"];
        }

        static::backupFile($pgConf);
        $steps[] = ['name' => 'Backup postgresql.conf', 'ok' => true, 'output' => 'OK'];

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
        $steps[] = ['name' => 'Modificar postgresql.conf', 'ok' => $ok, 'output' => $ok ? "wal_level={$walLevel}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar postgresql.conf'];

        $output = shell_exec("systemctl restart postgresql 2>&1");
        $running = trim((string)shell_exec("systemctl is-active postgresql 2>/dev/null")) === 'active';
        $steps[] = ['name' => 'Reiniciar PostgreSQL', 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : 'PostgreSQL no arranco'];
    }

    /**
     * Activate MySQL as master: only touches my.cnf + restart.
     * Does NOT create users.
     */
    public static function activateMysqlMaster(): array
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

        if (Settings::get('repl_mysql_gtid_mode', '0') === '1') {
            $mysqlConf['gtid_mode'] = 'ON';
            $mysqlConf['enforce-gtid-consistency'] = 'ON';
        }

        $ok = static::modifyConfigFile($configPath, $mysqlConf, 'mysqld');
        $steps[] = ['name' => 'Modificar configuracion MySQL', 'ok' => $ok, 'output' => $ok ? "server-id={$serverId}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar configuracion MySQL'];

        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "{$service} no arranco"];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── NEW: Replication Users CRUD ─────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function generateSecurePassword(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Create a replication user in the database engine and store in panel DB.
     * Returns the plaintext password (shown once for copy).
     */
    public static function createReplicationUser(string $engine, ?string $username = null): array
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
        if ($username === null || $username === '') {
            $username = $engine === 'pg' ? "repl_{$suffix}" : "repl_{$suffix}";
        }
        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
        $password = static::generateSecurePassword(32);

        if ($engine === 'pg') {
            $safePass = escapeshellarg($password);
            $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$safeUser}') THEN CREATE ROLE {$safeUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; ELSE ALTER ROLE {$safeUser} WITH PASSWORD {$safePass}; END IF; END \$\$;";
            $output = shell_exec("sudo -u postgres psql -c " . escapeshellarg($sql) . " 2>&1");
            if ($output !== null && stripos($output, 'error') !== false && stripos($output, 'DO') === false) {
                return ['ok' => false, 'error' => trim($output)];
            }
        } elseif ($engine === 'mysql') {
            $pdo = static::getMysqlPdo();
            if (!$pdo) {
                return ['ok' => false, 'error' => 'No se pudo conectar a MySQL'];
            }
            try {
                // Create user with wildcard host initially (IPs are managed separately)
                $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'%' IDENTIFIED BY " . $pdo->quote($password));
                $pdo->exec("GRANT REPLICATION SLAVE ON *.* TO '{$safeUser}'@'%'");
                $pdo->exec("FLUSH PRIVILEGES");
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            return ['ok' => false, 'error' => 'Motor no valido'];
        }

        // Store in panel DB
        $encPass = static::encryptPassword($password);
        $id = Database::insert('replication_users', [
            'engine'             => $engine,
            'username'           => $safeUser,
            'password_encrypted' => $encPass,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        return [
            'ok'       => true,
            'id'       => $id,
            'username' => $safeUser,
            'password' => $password, // Plaintext, shown once
        ];
    }

    /**
     * Delete a replication user from engine and panel DB.
     */
    public static function deleteReplicationUser(int $id): array
    {
        $row = Database::fetchOne("SELECT * FROM replication_users WHERE id = :id", ['id' => $id]);
        if (!$row) return ['ok' => false, 'error' => 'Usuario no encontrado'];

        $engine = $row['engine'];
        $username = $row['username'];
        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $username);

        if ($engine === 'pg') {
            shell_exec("sudo -u postgres psql -c " . escapeshellarg("DROP ROLE IF EXISTS {$safeUser}") . " 2>&1");
        } elseif ($engine === 'mysql') {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                try {
                    // Drop all host variants
                    $rows = $pdo->query("SELECT Host FROM mysql.user WHERE User = " . $pdo->quote($safeUser))->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $pdo->exec("DROP USER IF EXISTS '{$safeUser}'@'{$r['Host']}'");
                    }
                    $pdo->exec("FLUSH PRIVILEGES");
                } catch (\Throwable) {}
            }
        }

        Database::delete('replication_users', 'id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    /**
     * Get all replication users for an engine. Password decrypted for display.
     */
    public static function getReplicationUsers(string $engine): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT * FROM replication_users WHERE engine = :engine ORDER BY created_at DESC",
                ['engine' => $engine]
            );
            foreach ($rows as &$row) {
                $row['password'] = static::decryptPassword($row['password_encrypted']);
                unset($row['password_encrypted']);
            }
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ─── NEW: Authorized IPs CRUD ────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Add an authorized IP for replication (pg_hba.conf + reload, or MySQL GRANT).
     */
    public static function addAuthorizedIp(string $engine, string $ip, string $label = ''): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP no valida'];
        }

        if ($engine === 'pg') {
            // Get all PG replication users to authorize this IP for each
            $users = static::getReplicationUsers('pg');
            $configDir = static::getPgConfigDir();
            $hbaConf = "{$configDir}/pg_hba.conf";

            if (file_exists($hbaConf)) {
                $existing = file_get_contents($hbaConf);
                $newLines = [];

                if (empty($users)) {
                    // Add a generic line for any replication user
                    $hbaLine = "host    replication     all     {$ip}/32     md5";
                    if (!str_contains($existing, "{$ip}/32")) {
                        $newLines[] = $hbaLine;
                    }
                } else {
                    foreach ($users as $user) {
                        $hbaLine = "host    replication     {$user['username']}     {$ip}/32     md5";
                        if (!str_contains($existing, $hbaLine)) {
                            $newLines[] = $hbaLine;
                        }
                    }
                }

                if (!empty($newLines)) {
                    file_put_contents($hbaConf, $existing . "\n" . implode("\n", $newLines) . "\n");
                }

                // Reload PG (not restart — pg_hba changes only need reload)
                shell_exec("systemctl reload postgresql 2>&1");
            }

        } elseif ($engine === 'mysql') {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                $users = static::getReplicationUsers('mysql');
                foreach ($users as $user) {
                    $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user['username']);
                    try {
                        $pdo->exec("CREATE USER IF NOT EXISTS '{$safeUser}'@'{$ip}' IDENTIFIED BY " . $pdo->quote($user['password']));
                        $pdo->exec("GRANT REPLICATION SLAVE ON *.* TO '{$safeUser}'@'{$ip}'");
                    } catch (\Throwable) {}
                }
                try { $pdo->exec("FLUSH PRIVILEGES"); } catch (\Throwable) {}
            }
        }

        // Store in panel DB
        $id = Database::insert('replication_authorized_ips', [
            'engine'     => $engine,
            'ip_address' => $ip,
            'label'      => $label,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Remove an authorized IP.
     */
    public static function removeAuthorizedIp(int $id): array
    {
        $row = Database::fetchOne("SELECT * FROM replication_authorized_ips WHERE id = :id", ['id' => $id]);
        if (!$row) return ['ok' => false, 'error' => 'IP no encontrada'];

        $engine = $row['engine'];
        $ip = $row['ip_address'];

        if ($engine === 'pg') {
            $configDir = static::getPgConfigDir();
            $hbaConf = "{$configDir}/pg_hba.conf";
            if (file_exists($hbaConf)) {
                $content = file_get_contents($hbaConf);
                // Remove all lines containing this IP/32
                $content = preg_replace('/^.*' . preg_quote($ip, '/') . '\/32.*$/m', '', $content);
                $content = preg_replace('/\n{3,}/', "\n\n", $content);
                file_put_contents($hbaConf, $content);
                shell_exec("systemctl reload postgresql 2>&1");
            }
        } elseif ($engine === 'mysql') {
            $pdo = static::getMysqlPdo();
            if ($pdo) {
                $users = static::getReplicationUsers('mysql');
                foreach ($users as $user) {
                    $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $user['username']);
                    try {
                        $pdo->exec("DROP USER IF EXISTS '{$safeUser}'@'{$ip}'");
                    } catch (\Throwable) {}
                }
                try { $pdo->exec("FLUSH PRIVILEGES"); } catch (\Throwable) {}
            }
        }

        Database::delete('replication_authorized_ips', 'id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    /**
     * Get all authorized IPs for an engine.
     */
    public static function getAuthorizedIps(string $engine): array
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM replication_authorized_ips WHERE engine = :engine ORDER BY created_at DESC",
                ['engine' => $engine]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ─── NEW: Auto-configure via Cluster API ─────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Called on the MASTER when a remote slave requests replication access.
     * Creates a replication user and authorizes the slave IP.
     * Returns credentials for the slave to use.
     */
    public static function createReplicationUserForRemote(string $engine, string $slaveIp): array
    {
        // Create a replication user
        $result = static::createReplicationUser($engine);
        if (!$result['ok']) {
            return $result;
        }

        // Authorize the slave IP
        $ipResult = static::addAuthorizedIp($engine, $slaveIp, "auto-cluster-{$slaveIp}");

        return [
            'ok'       => true,
            'username' => $result['username'],
            'password' => $result['password'],
            'port'     => $engine === 'pg' ? (int)Settings::get('repl_pg_port', '5432') : (int)Settings::get('repl_mysql_port', '3306'),
        ];
    }

    /**
     * Called on the SLAVE to orchestrate automatic replication setup.
     * Calls the master's cluster API to get credentials, then runs setup.
     */
    public static function autoConfigureReplication(int $clusterNodeId, string $engine): array
    {
        $steps = [];

        // Step 1: Get local IP
        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        if (!$localIp) {
            return ['ok' => false, 'steps' => [], 'error' => 'No se pudo determinar la IP local'];
        }
        $steps[] = ['name' => 'IP local detectada', 'ok' => true, 'output' => $localIp];

        // Step 2: Call master's cluster API to create user and authorize our IP
        $response = ClusterService::callNode($clusterNodeId, 'POST', 'api/cluster/action', [
            'action'  => 'repl-create-user',
            'payload' => [
                'engine'   => $engine,
                'slave_ip' => $localIp,
            ],
        ]);

        if (!$response['ok']) {
            $steps[] = ['name' => 'Solicitar credenciales al master', 'ok' => false, 'output' => $response['error'] ?? 'Error de conexion'];
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo contactar con el master: ' . ($response['error'] ?? '')];
        }

        $remoteData = $response['data'] ?? [];
        $username = $remoteData['username'] ?? '';
        $password = $remoteData['password'] ?? '';
        $port = (int)($remoteData['port'] ?? ($engine === 'pg' ? 5432 : 3306));

        if (!$username || !$password) {
            $steps[] = ['name' => 'Solicitar credenciales al master', 'ok' => false, 'output' => 'Credenciales vacias'];
            return ['ok' => false, 'steps' => $steps, 'error' => 'El master no devolvio credenciales validas'];
        }
        $steps[] = ['name' => 'Credenciales recibidas del master', 'ok' => true, 'output' => "usuario: {$username}"];

        // Step 3: Get master IP from cluster node
        $node = ClusterService::getNode($clusterNodeId);
        $masterUrl = $node['api_url'] ?? '';
        // Extract IP from URL
        $parsed = parse_url($masterUrl);
        $masterIp = $parsed['host'] ?? '';

        if (!$masterIp || !filter_var($masterIp, FILTER_VALIDATE_IP)) {
            // Try to resolve hostname
            $resolved = gethostbyname($masterIp);
            if ($resolved !== $masterIp) {
                $masterIp = $resolved;
            } else {
                $steps[] = ['name' => 'Resolver IP del master', 'ok' => false, 'output' => "No se pudo resolver: {$masterUrl}"];
                return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo determinar la IP del master'];
            }
        }
        $steps[] = ['name' => 'IP del master', 'ok' => true, 'output' => $masterIp];

        // Step 4: Run slave setup
        if ($engine === 'pg') {
            $result = static::setupPgSlave($masterIp, $port, $username, $password);
        } else {
            $result = static::setupMysqlSlave($masterIp, $port, $username, $password);
        }

        // Merge steps
        foreach ($result['steps'] ?? [] as $s) {
            $steps[] = $s;
        }

        if ($result['ok']) {
            // Save connection info
            $prefix = $engine === 'pg' ? 'repl_pg' : 'repl_mysql';
            Settings::set("{$prefix}_remote_ip", $masterIp);
            Settings::set("{$prefix}_port", (string)$port);
            Settings::set("{$prefix}_user", $username);
            Settings::set("{$prefix}_pass", static::encryptPassword($password));
        }

        return ['ok' => $result['ok'], 'steps' => $steps, 'error' => $result['error'] ?? null];
    }
}
