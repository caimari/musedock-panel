<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;

class ReplicationController
{
    // ═══════════════════════════════════════════════════════════
    // ─── Helper ──────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    private function computeLegacyRole(): string
    {
        $pg    = Settings::get('repl_pg_role', 'standalone');
        $mysql = Settings::get('repl_mysql_role', 'standalone');

        if ($pg === 'master' || $mysql === 'master') return 'master';
        if ($pg === 'slave'  || $mysql === 'slave')  return 'slave';
        return 'standalone';
    }

    private function syncLegacyRole(): void
    {
        $role = $this->computeLegacyRole();
        Settings::set('repl_role', $role);
        try {
            Database::update('servers', ['role' => $role], 'is_local = true');
        } catch (\Throwable) {}
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Main View ───────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function index(): void
    {
        $pgRole    = Settings::get('repl_pg_role', 'standalone');
        $mysqlRole = Settings::get('repl_mysql_role', 'standalone');
        $role      = $this->computeLegacyRole();

        $pgVersion    = ReplicationService::detectPgVersion();
        $mysqlVersion = ReplicationService::detectMysqlVersion();

        // Per-engine status
        $pgMasterStatus    = null;
        $pgSlaveStatus     = null;
        $mysqlMasterStatus = null;
        $mysqlSlaveStatus  = null;

        if ($pgRole === 'master') {
            $pgMasterStatus = ReplicationService::getPgMasterStatus();
        } elseif ($pgRole === 'slave') {
            $pgSlaveStatus = ReplicationService::getPgSlaveStatus();
        }

        if ($mysqlRole === 'master') {
            $mysqlMasterStatus = ReplicationService::getMysqlMasterGtidStatus();
        } elseif ($mysqlRole === 'slave') {
            $mysqlSlaveStatus = ReplicationService::getMysqlSlaveStatusWithGtid();
        }

        // Replication users and authorized IPs
        $pgUsers    = ReplicationService::getReplicationUsers('pg');
        $mysqlUsers = ReplicationService::getReplicationUsers('mysql');
        $pgIps      = ReplicationService::getAuthorizedIps('pg');
        $mysqlIps   = ReplicationService::getAuthorizedIps('mysql');

        // Cluster nodes (for automatic mode)
        $clusterNodes = [];
        try {
            $clusterNodes = ClusterService::getNodes();
        } catch (\Throwable) {}

        // PostgreSQL database list
        $pgDatabases = [];
        try {
            $pgDatabases = Database::fetchAll(
                "SELECT datname, pg_database_size(datname) as size FROM pg_database WHERE datistemplate = false ORDER BY datname"
            );
        } catch (\Throwable) {}

        // MySQL database list
        $mysqlDatabases = [];
        try {
            $excluded = ['information_schema', 'performance_schema', 'mysql', 'sys'];
            $rows = Database::fetchAllMysql("SHOW DATABASES");
            foreach ($rows as $row) {
                $db = $row['Database'] ?? array_values($row)[0] ?? '';
                if ($db !== '' && !in_array(strtolower($db), $excluded, true)) {
                    $mysqlDatabases[] = $db;
                }
            }
        } catch (\Throwable) {}

        $configuredAt = Settings::get('repl_configured_at');

        View::render('settings/replication', [
            'layout'             => 'main',
            'pageTitle'          => 'Replicacion',
            'role'               => $role,
            'pgRole'             => $pgRole,
            'mysqlRole'          => $mysqlRole,
            'pgVersion'          => $pgVersion,
            'mysqlVersion'       => $mysqlVersion,
            'pgMasterStatus'     => $pgMasterStatus,
            'pgSlaveStatus'      => $pgSlaveStatus,
            'mysqlMasterStatus'  => $mysqlMasterStatus,
            'mysqlSlaveStatus'   => $mysqlSlaveStatus,
            'pgUsers'            => $pgUsers,
            'mysqlUsers'         => $mysqlUsers,
            'pgIps'              => $pgIps,
            'mysqlIps'           => $mysqlIps,
            'clusterNodes'       => $clusterNodes,
            'pgDatabases'        => $pgDatabases,
            'mysqlDatabases'     => $mysqlDatabases,
            'configuredAt'       => $configuredAt,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mode 1: Activate Master (config only) ──────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/activate-master
     * Only touches config files + restart. No credentials needed.
     */
    public function activateMaster(): void
    {
        View::verifyCsrf();
        $engine = $_POST['engine'] ?? '';

        if ($engine === 'pg') {
            $result = ReplicationService::activatePgMaster();
            if ($result['ok']) {
                Settings::set('repl_pg_role', 'master');
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                $this->syncLegacyRole();
                LogService::log('replication.activate', 'pg_master', 'PostgreSQL activado como master');
                Flash::set('success', 'PostgreSQL activado como Master');
            } else {
                Flash::set('error', 'Error activando master PG: ' . $result['error']);
            }
        } elseif ($engine === 'mysql') {
            $result = ReplicationService::activateMysqlMaster();
            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'master');
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                $this->syncLegacyRole();
                LogService::log('replication.activate', 'mysql_master', 'MySQL activado como master');
                Flash::set('success', 'MySQL activado como Master');
            } else {
                Flash::set('error', 'Error activando master MySQL: ' . $result['error']);
            }
        } else {
            Flash::set('error', 'Motor no especificado');
        }

        header('Location: /settings/replication');
        exit;
    }

    /**
     * POST /settings/replication/reset-standalone
     * Reset an engine back to standalone.
     */
    public function resetStandalone(): void
    {
        View::verifyCsrf();
        $engine = $_POST['engine'] ?? '';

        if ($engine === 'pg') {
            Settings::set('repl_pg_role', 'standalone');
            LogService::log('replication.reset', 'pg_standalone', 'PostgreSQL reseteado a standalone');
            Flash::set('success', 'PostgreSQL reseteado a Standalone');
        } elseif ($engine === 'mysql') {
            Settings::set('repl_mysql_role', 'standalone');
            LogService::log('replication.reset', 'mysql_standalone', 'MySQL reseteado a standalone');
            Flash::set('success', 'MySQL reseteado a Standalone');
        } else {
            Flash::set('error', 'Motor no especificado');
        }

        $this->syncLegacyRole();
        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mode 1: Replication Users CRUD ─────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/repl-user/create (JSON)
     */
    public function createReplUser(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine   = $_POST['engine'] ?? '';
        $username = trim($_POST['username'] ?? '');

        if (!in_array($engine, ['pg', 'mysql'])) {
            echo json_encode(['ok' => false, 'error' => 'Motor no valido']);
            exit;
        }

        $result = ReplicationService::createReplicationUser($engine, $username ?: null);

        if ($result['ok']) {
            LogService::log('replication.user', 'create', "{$engine}: {$result['username']}");
        }

        echo json_encode($result);
        exit;
    }

    /**
     * POST /settings/replication/repl-user/delete (JSON)
     */
    public function deleteReplUser(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            echo json_encode(['ok' => false, 'error' => 'ID no valido']);
            exit;
        }

        $result = ReplicationService::deleteReplicationUser($id);

        if ($result['ok']) {
            LogService::log('replication.user', 'delete', "ID: {$id}");
        }

        echo json_encode($result);
        exit;
    }

    /**
     * GET /settings/replication/repl-users (JSON)
     */
    public function listReplUsers(): void
    {
        header('Content-Type: application/json');
        $engine = $_GET['engine'] ?? 'pg';
        $users = ReplicationService::getReplicationUsers($engine);
        echo json_encode(['ok' => true, 'users' => $users]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mode 1: Authorized IPs CRUD ────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/authorized-ip/add (JSON)
     */
    public function addAuthorizedIp(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine = $_POST['engine'] ?? '';
        $ip     = trim($_POST['ip'] ?? '');
        $label  = trim($_POST['label'] ?? '');

        if (!in_array($engine, ['pg', 'mysql'])) {
            echo json_encode(['ok' => false, 'error' => 'Motor no valido']);
            exit;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'IP no valida']);
            exit;
        }

        $result = ReplicationService::addAuthorizedIp($engine, $ip, $label);

        if ($result['ok']) {
            LogService::log('replication.ip', 'add', "{$engine}: {$ip} ({$label})");
        }

        echo json_encode($result);
        exit;
    }

    /**
     * POST /settings/replication/authorized-ip/remove (JSON)
     */
    public function removeAuthorizedIp(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) {
            echo json_encode(['ok' => false, 'error' => 'ID no valido']);
            exit;
        }

        $result = ReplicationService::removeAuthorizedIp($id);

        if ($result['ok']) {
            LogService::log('replication.ip', 'remove', "ID: {$id}");
        }

        echo json_encode($result);
        exit;
    }

    /**
     * GET /settings/replication/authorized-ips (JSON)
     */
    public function listAuthorizedIps(): void
    {
        header('Content-Type: application/json');
        $engine = $_GET['engine'] ?? 'pg';
        $ips = ReplicationService::getAuthorizedIps($engine);
        echo json_encode(['ok' => true, 'ips' => $ips]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mode 2: Convert to Slave (manual) ──────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/convert-to-slave
     * Requires confirm=DELETE. Destructive operation.
     */
    public function convertToSlave(): void
    {
        View::verifyCsrf();

        if (($_POST['confirm'] ?? '') !== 'DELETE') {
            Flash::set('error', 'Escribe DELETE para confirmar');
            header('Location: /settings/replication');
            exit;
        }

        $engine   = $_POST['engine'] ?? '';
        $masterIp = trim($_POST['master_ip'] ?? '');
        $port     = (int)($_POST['port'] ?? 0);
        $user     = trim($_POST['user'] ?? '');
        $pass     = $_POST['pass'] ?? '';

        if (!in_array($engine, ['pg', 'mysql'])) {
            Flash::set('error', 'Motor no especificado');
            header('Location: /settings/replication');
            exit;
        }

        if (!filter_var($masterIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP del master no valida');
            header('Location: /settings/replication');
            exit;
        }

        if (!$port || !$user || !$pass) {
            Flash::set('error', 'Completa todos los campos (IP, puerto, usuario, password)');
            header('Location: /settings/replication');
            exit;
        }

        // Auto-backup all databases before destructive streaming replication setup
        $doBackup = ($_POST['auto_backup'] ?? '1') === '1';
        if ($doBackup) {
            $backupResult = \MuseDockPanel\Services\FileSyncService::backupAllDatabasesBeforeReplication($engine);
            LogService::log('replication.backup', 'pre-convert', sprintf(
                "Backup pre-replicación (%s): %s — %d bases de datos",
                $engine,
                $backupResult['path'] ?? 'N/A',
                count($backupResult['databases'] ?? [])
            ));
        }

        if ($engine === 'pg') {
            $result = ReplicationService::setupPgSlave($masterIp, $port, $user, $pass);
            if ($result['ok']) {
                Settings::set('repl_pg_role', 'slave');
                Settings::set('repl_pg_remote_ip', $masterIp);
                Settings::set('repl_pg_port', (string)$port);
                Settings::set('repl_pg_user', $user);
                Settings::set('repl_pg_pass', ReplicationService::encryptPassword($pass));
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                $this->syncLegacyRole();
                LogService::log('replication.slave', 'pg_convert', "PG configurado como slave de {$masterIp}");
                Flash::set('success', 'PostgreSQL configurado como Slave');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }
        } else {
            $result = ReplicationService::setupMysqlSlave($masterIp, $port, $user, $pass);
            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'slave');
                Settings::set('repl_mysql_remote_ip', $masterIp);
                Settings::set('repl_mysql_port', (string)$port);
                Settings::set('repl_mysql_user', $user);
                Settings::set('repl_mysql_pass', ReplicationService::encryptPassword($pass));
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                $this->syncLegacyRole();
                LogService::log('replication.slave', 'mysql_convert', "MySQL configurado como slave de {$masterIp}");
                Flash::set('success', 'MySQL configurado como Slave');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }
        }

        header('Location: /settings/replication');
        exit;
    }

    /**
     * POST /settings/replication/test-slave-master (JSON)
     * Test connection to a master before converting to slave.
     */
    public function testSlaveMaster(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine = $_POST['engine'] ?? '';
        $host   = trim($_POST['host'] ?? '');
        $port   = (int)($_POST['port'] ?? 0);
        $user   = trim($_POST['user'] ?? '');
        $pass   = $_POST['pass'] ?? '';

        if (!in_array($engine, ['pg', 'mysql']) || !$host || !$port || !$user) {
            echo json_encode(['ok' => false, 'message' => 'Parametros incompletos']);
            exit;
        }

        $result = $engine === 'pg'
            ? ReplicationService::testPgConnection($host, $port, $user, $pass)
            : ReplicationService::testMysqlConnection($host, $port, $user, $pass);

        echo json_encode([
            'ok'      => $result['ok'],
            'message' => $result['ok'] ? 'Conexion exitosa' : $result['error'],
            'version' => $result['version'],
        ]);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mode 3: Auto-configure via Cluster ─────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/auto-configure (JSON)
     */
    public function autoConfigure(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $nodeId = (int)($_POST['node_id'] ?? 0);
        $engine = $_POST['engine'] ?? '';

        if ($nodeId < 1 || !in_array($engine, ['pg', 'mysql'])) {
            echo json_encode(['ok' => false, 'error' => 'Parametros incompletos']);
            exit;
        }

        $result = ReplicationService::autoConfigureReplication($nodeId, $engine);

        if ($result['ok']) {
            $roleKey = $engine === 'pg' ? 'repl_pg_role' : 'repl_mysql_role';
            Settings::set($roleKey, 'slave');
            Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
            $this->syncLegacyRole();
            LogService::log('replication.auto', "{$engine}_slave", "Auto-configurado como slave via cluster nodo #{$nodeId}");
        }

        echo json_encode($result);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Promote / Demote ───────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function promote(): void
    {
        View::verifyCsrf();
        $engine = $_POST['engine'] ?? '';

        if ($engine === 'pg') {
            $pgRole = Settings::get('repl_pg_role', 'standalone');
            if ($pgRole !== 'slave') {
                Flash::set('error', 'PostgreSQL no esta en modo slave');
                header('Location: /settings/replication');
                exit;
            }

            $result = ReplicationService::promotePgSlave();
            if ($result['ok']) {
                Settings::set('repl_pg_role', 'master');
                $this->syncLegacyRole();
                LogService::log('replication.promote', 'pg', 'PostgreSQL promovido a Master');
                Flash::set('success', 'PostgreSQL promovido a Master');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }

        } elseif ($engine === 'mysql') {
            $mysqlRole = Settings::get('repl_mysql_role', 'standalone');
            if ($mysqlRole !== 'slave') {
                Flash::set('error', 'MySQL no esta en modo slave');
                header('Location: /settings/replication');
                exit;
            }

            $result = ReplicationService::promoteMysqlSlave();
            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'master');
                $this->syncLegacyRole();
                LogService::log('replication.promote', 'mysql', 'MySQL promovido a Master');
                Flash::set('success', 'MySQL promovido a Master');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }
        } else {
            Flash::set('error', 'Motor no especificado');
        }

        header('Location: /settings/replication');
        exit;
    }

    public function demote(): void
    {
        View::verifyCsrf();
        $engine = $_POST['engine'] ?? '';
        $newMasterIp = trim($_POST['new_master_ip'] ?? '');

        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP del nuevo master no valida');
            header('Location: /settings/replication');
            exit;
        }

        if ($engine === 'pg') {
            $pgPort = (int)Settings::get('repl_pg_port', '5432');
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));

            $result = ReplicationService::demotePgMaster($newMasterIp, $pgPort, $pgUser, $pgPass);
            if ($result['ok']) {
                Settings::set('repl_pg_role', 'slave');
                Settings::set('repl_pg_remote_ip', $newMasterIp);
                $this->syncLegacyRole();
                LogService::log('replication.demote', 'pg', "Degradado a slave, master: {$newMasterIp}");
                Flash::set('success', 'PostgreSQL degradado a Slave');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }

        } elseif ($engine === 'mysql') {
            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));

            $result = ReplicationService::demoteMysqlMaster($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);
            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'slave');
                Settings::set('repl_mysql_remote_ip', $newMasterIp);
                $this->syncLegacyRole();
                LogService::log('replication.demote', 'mysql', "Degradado a slave, master: {$newMasterIp}");
                Flash::set('success', 'MySQL degradado a Slave');
            } else {
                Flash::set('error', 'Error: ' . $result['error']);
            }
        } else {
            Flash::set('error', 'Motor no especificado');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Status JSON (AJAX polling) ─────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function status(): void
    {
        header('Content-Type: application/json');

        $pgRole    = Settings::get('repl_pg_role', 'standalone');
        $mysqlRole = Settings::get('repl_mysql_role', 'standalone');

        $pgStatus    = null;
        $mysqlStatus = null;

        if ($pgRole === 'master') {
            $pgStatus = ReplicationService::getPgMasterStatus();
        } elseif ($pgRole === 'slave') {
            $pgStatus = ReplicationService::getPgSlaveStatus();
        }

        if ($mysqlRole === 'master') {
            $mysqlStatus = ReplicationService::getMysqlMasterGtidStatus();
        } elseif ($mysqlRole === 'slave') {
            $mysqlStatus = ReplicationService::getMysqlSlaveStatusWithGtid();
        }

        echo json_encode([
            'pg' => ['role' => $pgRole, 'status' => $pgStatus],
            'mysql' => ['role' => $mysqlRole, 'status' => $mysqlStatus],
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Test Connection (generic) ──────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function testConnection(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine = $_POST['engine'] ?? '';
        $host   = trim($_POST['host'] ?? '');
        $port   = (int)($_POST['port'] ?? 0);
        $user   = trim($_POST['user'] ?? '');
        $pass   = $_POST['pass'] ?? '';

        if (!in_array($engine, ['pg', 'mysql']) || !$host || !$port || !$user) {
            echo json_encode(['ok' => false, 'message' => 'Parametros incompletos', 'version' => '']);
            exit;
        }

        $result = $engine === 'pg'
            ? ReplicationService::testPgConnection($host, $port, $user, $pass)
            : ReplicationService::testMysqlConnection($host, $port, $user, $pass);

        echo json_encode([
            'ok'      => $result['ok'],
            'message' => $result['ok'] ? 'Conexion exitosa' : $result['error'],
            'version' => $result['version'],
        ]);
        exit;
    }
}
