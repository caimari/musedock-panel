<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
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
        // Try pg_lsclusters
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

    // ─── Local MySQL PDO ─────────────────────────────────────
    public static function getMysqlPdo(): ?\PDO
    {
        // Try socket auth first
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
        // Fallback: read from debian.cnf
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
        // Fallback: env vars
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

            // Track [section] headers
            if (preg_match('/^\[(.+)]$/', $trimmed, $m)) {
                // Append remaining keys before leaving current section
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
                    // Match: key = ..., #key = ..., # key = ...
                    if (preg_match('/^[#;\s]*' . preg_quote($key, '/') . '\s*[=:]/', $trimmed)) {
                        $line = "{$key} = {$value}";
                        unset($remaining[$key]);
                        break;
                    }
                }
            }

            $modified[] = $line;
        }

        // Append remaining at end of file (or section)
        foreach ($remaining as $k => $v) {
            $modified[] = "{$k} = {$v}";
        }

        return file_put_contents($path, implode("\n", $modified) . "\n") !== false;
    }

    // ─── Setup Master ────────────────────────────────────────
    public static function setupPgMaster(string $slaveIp, string $replUser, string $replPass): array
    {
        $steps = [];
        $configDir = static::getPgConfigDir();
        $pgConf = "{$configDir}/postgresql.conf";
        $hbaConf = "{$configDir}/pg_hba.conf";

        if (!file_exists($pgConf)) {
            return ['ok' => false, 'steps' => [], 'error' => "postgresql.conf no encontrado en {$configDir}"];
        }

        // Backup
        static::backupFile($pgConf);
        static::backupFile($hbaConf);
        $steps[] = ['name' => 'Backup configuracion', 'ok' => true, 'output' => 'OK'];

        // Detect PG version for wal_keep param
        $pgVer = (int)(static::detectPgVersion() ?? '16');
        $walKeepParam = $pgVer >= 13 ? 'wal_keep_size' : 'wal_keep_segments';
        $walKeepValue = $pgVer >= 13 ? '512MB' : '64';

        // Modify postgresql.conf
        $ok = static::modifyConfigFile($pgConf, [
            'wal_level'         => 'replica',
            'max_wal_senders'   => '5',
            $walKeepParam       => $walKeepValue,
            'hot_standby'       => 'on',
            'listen_addresses'  => "'*'",
        ]);
        $steps[] = ['name' => 'Modificar postgresql.conf', 'ok' => $ok, 'output' => $ok ? 'OK' : 'Error escribiendo'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar postgresql.conf'];

        // Add pg_hba.conf entry
        $escapedIp = escapeshellarg($slaveIp);
        $hbaLine = "host    replication     {$replUser}     {$slaveIp}/32     md5";
        $existing = file_get_contents($hbaConf);
        if (!str_contains($existing, $hbaLine)) {
            file_put_contents($hbaConf, $existing . "\n{$hbaLine}\n");
        }
        $steps[] = ['name' => 'Añadir entrada pg_hba.conf', 'ok' => true, 'output' => $hbaLine];

        // Create replication user
        $safeUser = preg_replace('/[^a-zA-Z0-9_]/', '', $replUser);
        $safePass = escapeshellarg($replPass);
        $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$safeUser}') THEN CREATE ROLE {$safeUser} WITH REPLICATION LOGIN PASSWORD {$safePass}; END IF; END \$\$;";
        $output = shell_exec("sudo -u postgres psql -c " . escapeshellarg($sql) . " 2>&1");
        $steps[] = ['name' => 'Crear usuario replicacion', 'ok' => true, 'output' => trim($output ?? 'OK')];

        // Restart PostgreSQL
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

        // Backup
        static::backupFile($configPath);
        $steps[] = ['name' => 'Backup configuracion MySQL', 'ok' => true, 'output' => 'OK'];

        // Generate server-id from IP
        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = (int)($parts[3] ?? 1);
        if ($serverId < 1) $serverId = 1;

        // Modify config
        $ok = static::modifyConfigFile($configPath, [
            'server-id'      => (string)$serverId,
            'log-bin'        => 'mysql-bin',
            'bind-address'   => '0.0.0.0',
            'binlog_format'  => 'ROW',
        ], 'mysqld');
        $steps[] = ['name' => 'Modificar configuracion MySQL', 'ok' => $ok, 'output' => $ok ? "server-id={$serverId}" : 'Error'];
        if (!$ok) return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo modificar configuracion MySQL'];

        // Create replication user
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

        // Restart MySQL
        $output = shell_exec("systemctl restart {$service} 2>&1");
        $running = trim((string)shell_exec("systemctl is-active {$service} 2>/dev/null")) === 'active';
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => $running, 'output' => $running ? 'Activo' : trim($output ?? 'Error')];

        return ['ok' => $running, 'steps' => $steps, 'error' => $running ? null : "{$service} no arranco"];
    }

    // ─── Setup Slave ─────────────────────────────────────────
    public static function setupPgSlave(string $masterIp, int $port, string $replUser, string $replPass): array
    {
        $steps = [];
        $pgVer = static::detectPgVersion() ?? '16';
        $dataDir = static::getPgDataDir();

        // Stop PostgreSQL
        shell_exec("systemctl stop postgresql 2>&1");
        $steps[] = ['name' => 'Detener PostgreSQL', 'ok' => true, 'output' => 'OK'];

        // Clear data directory
        shell_exec("rm -rf " . escapeshellarg($dataDir) . "/*");
        $steps[] = ['name' => 'Limpiar directorio de datos', 'ok' => true, 'output' => $dataDir];

        // pg_basebackup (the -R flag creates standby.signal on PG >= 12)
        $safeMaster = escapeshellarg($masterIp);
        $safeUser = escapeshellarg($replUser);
        $safeDir = escapeshellarg($dataDir);
        $cmd = "PGPASSWORD=" . escapeshellarg($replPass) . " pg_basebackup -h {$safeMaster} -p {$port} -U {$safeUser} -D {$safeDir} -Fp -Xs -P -R 2>&1";
        $output = shell_exec($cmd);
        $hasSignal = file_exists("{$dataDir}/standby.signal") || file_exists("{$dataDir}/recovery.conf");
        $steps[] = ['name' => 'pg_basebackup', 'ok' => $hasSignal, 'output' => $hasSignal ? 'Completado' : trim($output ?? 'Error')];

        if (!$hasSignal) {
            // For PG < 12, create recovery.conf manually
            if ((int)$pgVer < 12) {
                $recoveryConf = "standby_mode = 'on'\nprimary_conninfo = 'host={$masterIp} port={$port} user={$replUser} password={$replPass}'\n";
                file_put_contents("{$dataDir}/recovery.conf", $recoveryConf);
                $steps[] = ['name' => 'Crear recovery.conf', 'ok' => true, 'output' => 'PG < 12'];
            } else {
                return ['ok' => false, 'steps' => $steps, 'error' => 'pg_basebackup fallo. Verifique credenciales y conectividad.'];
            }
        }

        // Fix permissions
        shell_exec("chown -R postgres:postgres " . escapeshellarg($dataDir));
        $steps[] = ['name' => 'Corregir permisos', 'ok' => true, 'output' => 'OK'];

        // Start PostgreSQL
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

        // Backup & modify config
        static::backupFile($configPath);

        // Server-id must be different from master
        $localIp = trim((string)shell_exec("hostname -I | awk '{print \$1}'"));
        $parts = explode('.', $localIp);
        $serverId = ((int)($parts[3] ?? 2)) + 100;

        $ok = static::modifyConfigFile($configPath, [
            'server-id'  => (string)$serverId,
            'relay-log'  => 'relay-bin',
            'read_only'  => '1',
        ], 'mysqld');
        $steps[] = ['name' => 'Configurar MySQL slave', 'ok' => $ok, 'output' => "server-id={$serverId}"];

        // Restart to apply config
        shell_exec("systemctl restart {$service} 2>&1");
        $steps[] = ['name' => "Reiniciar {$service}", 'ok' => true, 'output' => 'OK'];

        // Configure replication
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

    // ─── Monitoring ──────────────────────────────────────────
    public static function getPgMasterStatus(): ?array
    {
        try {
            $rows = \MuseDockPanel\Database::fetchAll("SELECT client_addr, state, sent_lsn, write_lsn, flush_lsn, replay_lsn, sync_state FROM pg_stat_replication");
            if (empty($rows)) return null;
            $row = $rows[0];
            // Calculate lag in bytes
            $sentLsn = $row['sent_lsn'] ?? '';
            $replayLsn = $row['replay_lsn'] ?? '';
            $lagBytes = 0;
            if ($sentLsn && $replayLsn) {
                $sent = \MuseDockPanel\Database::fetchOne("SELECT pg_wal_lsn_diff(:sent::pg_lsn, :replay::pg_lsn) as diff", ['sent' => $sentLsn, 'replay' => $replayLsn]);
                $lagBytes = (int)($sent['diff'] ?? 0);
            }
            $row['lag_bytes'] = $lagBytes;
            $row['replicas'] = $rows;
            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getPgSlaveStatus(): ?array
    {
        try {
            // Check if this is actually a standby
            $isRecovery = \MuseDockPanel\Database::fetchOne("SELECT pg_is_in_recovery() as recovery");
            if (!$isRecovery || $isRecovery['recovery'] !== true) return null;

            $receiver = \MuseDockPanel\Database::fetchOne("SELECT status, sender_host, sender_port, last_msg_send_time, last_msg_receipt_time FROM pg_stat_wal_receiver");

            $lsn = \MuseDockPanel\Database::fetchOne("
                SELECT pg_last_wal_receive_lsn() as receive_lsn,
                       pg_last_wal_replay_lsn() as replay_lsn,
                       pg_last_xact_replay_timestamp() as replay_time
            ");

            $lagSeconds = 0;
            if (!empty($lsn['replay_time'])) {
                $lagRow = \MuseDockPanel\Database::fetchOne("SELECT EXTRACT(EPOCH FROM (NOW() - :ts::timestamptz))::int as lag", ['ts' => $lsn['replay_time']]);
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
            // Try new syntax first
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

    // ─── Switchover ──────────────────────────────────────────
    public static function promotePgSlave(): array
    {
        $steps = [];
        $pgVer = static::detectPgVersion() ?? '16';

        // Try pg_ctlcluster first, then pg_ctl
        $output = shell_exec("pg_ctlcluster {$pgVer} main promote 2>&1");
        if ($output === null || str_contains($output, 'error')) {
            $dataDir = static::getPgDataDir();
            $output = shell_exec("sudo -u postgres pg_ctl promote -D " . escapeshellarg($dataDir) . " 2>&1");
        }
        $steps[] = ['name' => 'Promover PostgreSQL', 'ok' => true, 'output' => trim($output ?? 'OK')];

        sleep(2);
        // Verify no longer in recovery
        try {
            $check = \MuseDockPanel\Database::fetchOne("SELECT pg_is_in_recovery() as recovery");
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

            // Remove read_only
            $pdo->exec("SET GLOBAL read_only = 0");
            $steps[] = ['name' => 'Promover MySQL', 'ok' => true, 'output' => 'SLAVE detenido, read_only desactivado'];

            // Update config file
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
        // Reconfigure as slave pointing to the new master
        return static::setupPgSlave($newMasterIp, $port, $replUser, $replPass);
    }

    public static function demoteMysqlMaster(string $newMasterIp, int $port, string $replUser, string $replPass): array
    {
        return static::setupMysqlSlave($newMasterIp, $port, $replUser, $replPass);
    }
}
