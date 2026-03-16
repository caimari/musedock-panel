<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Services\ReplicationService;
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
            $mysqlMasterStatus = ReplicationService::getMysqlMasterStatus();
        } elseif ($mysqlRole === 'slave') {
            $mysqlSlaveStatus = ReplicationService::getMysqlSlaveStatusWithGtid();
        }

        // Multi-slave data (only relevant when at least one engine is master)
        $slaves    = [];
        $slavesData = '[]';
        if ($role === 'master') {
            $slaves    = ReplicationService::getSlaves();
            $slavesData = json_encode($slaves, JSON_UNESCAPED_UNICODE);
        }

        // PostgreSQL database list with sizes
        $pgDatabases = [];
        try {
            $pgDatabases = Database::fetchAll(
                "SELECT datname, pg_database_size(datname) as size FROM pg_database WHERE datistemplate = false ORDER BY datname"
            );
        } catch (\Throwable) {}

        // MySQL database list (filter system schemas)
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

        // All repl_* settings
        $allSettings = Settings::getAll();
        $settings = array_filter($allSettings, fn($k) => str_starts_with($k, 'repl_'), ARRAY_FILTER_USE_KEY);

        $configuredAt = Settings::get('repl_configured_at');

        View::render('settings/replication', [
            'layout'             => 'main',
            'pageTitle'          => 'Replicacion',
            'settings'           => $settings,
            'role'               => $role,
            'pgRole'             => $pgRole,
            'mysqlRole'          => $mysqlRole,
            'pgVersion'          => $pgVersion,
            'mysqlVersion'       => $mysqlVersion,
            'pgMasterStatus'     => $pgMasterStatus,
            'pgSlaveStatus'      => $pgSlaveStatus,
            'mysqlMasterStatus'  => $mysqlMasterStatus,
            'mysqlSlaveStatus'   => $mysqlSlaveStatus,
            'slaves'             => $slaves,
            'slavesData'         => $slavesData,
            'pgDatabases'        => $pgDatabases,
            'mysqlDatabases'     => $mysqlDatabases,
            'configuredAt'       => $configuredAt,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Save Config ─────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function saveConfig(): void
    {
        View::verifyCsrf();

        $engine = $_POST['engine'] ?? '';

        if ($engine === 'pg') {
            $remoteIp = trim($_POST['remote_ip'] ?? '');
            $port     = (int)($_POST['pg_port'] ?? 5432);
            $user     = trim($_POST['pg_user'] ?? 'replicator');
            $pass     = $_POST['pg_pass'] ?? '';

            if ($remoteIp !== '' && !filter_var($remoteIp, FILTER_VALIDATE_IP)) {
                Flash::set('error', 'IP remota de PostgreSQL no valida');
                header('Location: /settings/replication');
                exit;
            }

            if ($port < 1 || $port > 65535) {
                Flash::set('error', 'Puerto de PostgreSQL fuera de rango (1-65535)');
                header('Location: /settings/replication');
                exit;
            }

            Settings::set('repl_pg_remote_ip', $remoteIp);
            Settings::set('repl_pg_port', (string)$port);
            Settings::set('repl_pg_user', $user);
            // Keep legacy key updated for backwards compatibility
            Settings::set('repl_remote_ip', $remoteIp);

            if ($pass !== '') {
                Settings::set('repl_pg_pass', ReplicationService::encryptPassword($pass));
            }

            LogService::log('replication.config', 'save_pg', "PG remote IP: {$remoteIp}, port: {$port}");
            Flash::set('success', 'Configuracion de replicacion PostgreSQL guardada');

        } elseif ($engine === 'mysql') {
            $remoteIp = trim($_POST['remote_ip'] ?? '');
            $port     = (int)($_POST['mysql_port'] ?? 3306);
            $user     = trim($_POST['mysql_user'] ?? 'repl_user');
            $pass     = $_POST['mysql_pass'] ?? '';

            if ($remoteIp !== '' && !filter_var($remoteIp, FILTER_VALIDATE_IP)) {
                Flash::set('error', 'IP remota de MySQL no valida');
                header('Location: /settings/replication');
                exit;
            }

            if ($port < 1 || $port > 65535) {
                Flash::set('error', 'Puerto de MySQL fuera de rango (1-65535)');
                header('Location: /settings/replication');
                exit;
            }

            Settings::set('repl_mysql_remote_ip', $remoteIp);
            Settings::set('repl_mysql_port', (string)$port);
            Settings::set('repl_mysql_user', $user);

            if ($pass !== '') {
                Settings::set('repl_mysql_pass', ReplicationService::encryptPassword($pass));
            }

            LogService::log('replication.config', 'save_mysql', "MySQL remote IP: {$remoteIp}, port: {$port}");
            Flash::set('success', 'Configuracion de replicacion MySQL guardada');

        } else {
            Flash::set('error', 'Motor no especificado (pg o mysql)');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Master ────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function setupMaster(): void
    {
        View::verifyCsrf();

        $engine = $_POST['engine'] ?? '';

        // Handle reset to standalone for a specific engine
        if (($_POST['reset'] ?? '') === 'standalone') {
            if ($engine === 'pg') {
                Settings::set('repl_pg_role', 'standalone');
                LogService::log('replication.reset', 'pg_standalone', 'PostgreSQL reseteado a standalone');
                Flash::set('success', 'PostgreSQL reseteado a Standalone');
            } elseif ($engine === 'mysql') {
                Settings::set('repl_mysql_role', 'standalone');
                LogService::log('replication.reset', 'mysql_standalone', 'MySQL reseteado a standalone');
                Flash::set('success', 'MySQL reseteado a Standalone');
            } else {
                // Legacy: reset both
                Settings::set('repl_pg_role', 'standalone');
                Settings::set('repl_mysql_role', 'standalone');
                LogService::log('replication.reset', 'standalone', 'Reseteado a standalone');
                Flash::set('success', 'Servidor reseteado a Standalone');
            }

            $legacyRole = $this->computeLegacyRole();
            Settings::set('repl_role', $legacyRole);
            Database::update('servers', ['role' => $legacyRole], 'is_local = true');

            header('Location: /settings/replication');
            exit;
        }

        if ($engine === 'pg') {
            $remoteIp = Settings::get('repl_pg_remote_ip') ?: Settings::get('repl_remote_ip');
            if (!$remoteIp) {
                Flash::set('error', 'Configure primero la IP remota de PostgreSQL');
                header('Location: /settings/replication');
                exit;
            }

            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));
            if (!$pgPass) {
                Flash::set('error', 'Password de replicacion PostgreSQL no configurado');
                header('Location: /settings/replication');
                exit;
            }

            $result = ReplicationService::setupPgMaster($remoteIp, $pgUser, $pgPass);

            if ($result['ok']) {
                Settings::set('repl_pg_role', 'master');
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.setup', 'pg_master', 'PostgreSQL configurado como master');
                Flash::set('success', 'PostgreSQL configurado como Master correctamente');
            } else {
                Flash::set('error', 'Error configurando master PG: ' . $result['error']);
            }

        } elseif ($engine === 'mysql') {
            $remoteIp = Settings::get('repl_mysql_remote_ip') ?: Settings::get('repl_remote_ip');
            if (!$remoteIp) {
                Flash::set('error', 'Configure primero la IP remota de MySQL');
                header('Location: /settings/replication');
                exit;
            }

            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));
            if (!$mysqlPass) {
                Flash::set('error', 'Password de replicacion MySQL no configurado');
                header('Location: /settings/replication');
                exit;
            }

            $result = ReplicationService::setupMysqlMaster($remoteIp, $mysqlUser, $mysqlPass);

            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'master');
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.setup', 'mysql_master', 'MySQL configurado como master');
                Flash::set('success', 'MySQL configurado como Master correctamente');
            } else {
                Flash::set('error', 'Error configurando master MySQL: ' . $result['error']);
            }

        } else {
            Flash::set('error', 'Motor no especificado (pg o mysql)');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Setup Slave ─────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public function setupSlave(): void
    {
        View::verifyCsrf();

        if (($_POST['confirm'] ?? '') !== 'DELETE') {
            Flash::set('error', 'Operacion no confirmada (se requiere DELETE)');
            header('Location: /settings/replication');
            exit;
        }

        $engine = $_POST['engine'] ?? '';

        if ($engine === 'pg') {
            $remoteIp = Settings::get('repl_pg_remote_ip') ?: Settings::get('repl_remote_ip');
            if (!$remoteIp) {
                Flash::set('error', 'Configure primero la IP del servidor master PostgreSQL');
                header('Location: /settings/replication');
                exit;
            }

            $pgPort = (int)Settings::get('repl_pg_port', '5432');
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));

            $result = ReplicationService::setupPgSlave($remoteIp, $pgPort, $pgUser, $pgPass);

            if ($result['ok']) {
                Settings::set('repl_pg_role', 'slave');
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.setup', 'pg_slave', 'PostgreSQL configurado como slave');
                Flash::set('success', 'PostgreSQL configurado como Slave correctamente');
            } else {
                Flash::set('error', 'Error configurando slave PG: ' . $result['error']);
            }

        } elseif ($engine === 'mysql') {
            $remoteIp = Settings::get('repl_mysql_remote_ip') ?: Settings::get('repl_remote_ip');
            if (!$remoteIp) {
                Flash::set('error', 'Configure primero la IP del servidor master MySQL');
                header('Location: /settings/replication');
                exit;
            }

            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));

            $result = ReplicationService::setupMysqlSlave($remoteIp, $mysqlPort, $mysqlUser, $mysqlPass);

            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'slave');
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.setup', 'mysql_slave', 'MySQL configurado como slave');
                Flash::set('success', 'MySQL configurado como Slave correctamente');
            } else {
                Flash::set('error', 'Error configurando slave MySQL: ' . $result['error']);
            }

        } else {
            Flash::set('error', 'Motor no especificado (pg o mysql)');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Promote ─────────────────────────────────────────────
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
                Settings::set('repl_role', $this->computeLegacyRole());
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.promote', 'pg_slave_to_master', 'PostgreSQL slave promovido a Master');
                Flash::set('success', 'PostgreSQL promovido a Master correctamente');
            } else {
                Flash::set('error', 'Error al promover PostgreSQL: ' . $result['error']);
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
                Settings::set('repl_role', $this->computeLegacyRole());
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.promote', 'mysql_slave_to_master', 'MySQL slave promovido a Master');
                Flash::set('success', 'MySQL promovido a Master correctamente');
            } else {
                Flash::set('error', 'Error al promover MySQL: ' . $result['error']);
            }

        } else {
            Flash::set('error', 'Motor no especificado (pg o mysql)');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Demote ──────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

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
            $pgRole = Settings::get('repl_pg_role', 'standalone');
            if ($pgRole !== 'master') {
                Flash::set('error', 'PostgreSQL no esta en modo master');
                header('Location: /settings/replication');
                exit;
            }

            $pgPort = (int)Settings::get('repl_pg_port', '5432');
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));

            $result = ReplicationService::demotePgMaster($newMasterIp, $pgPort, $pgUser, $pgPass);

            if ($result['ok']) {
                Settings::set('repl_pg_role', 'slave');
                Settings::set('repl_pg_remote_ip', $newMasterIp);
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_remote_ip', $newMasterIp);
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.demote', 'pg_master_to_slave', "PostgreSQL degradado a slave, nuevo master: {$newMasterIp}");
                Flash::set('success', 'PostgreSQL degradado a Slave correctamente');
            } else {
                Flash::set('error', 'Error al degradar PostgreSQL: ' . $result['error']);
            }

        } elseif ($engine === 'mysql') {
            $mysqlRole = Settings::get('repl_mysql_role', 'standalone');
            if ($mysqlRole !== 'master') {
                Flash::set('error', 'MySQL no esta en modo master');
                header('Location: /settings/replication');
                exit;
            }

            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));

            $result = ReplicationService::demoteMysqlMaster($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);

            if ($result['ok']) {
                Settings::set('repl_mysql_role', 'slave');
                Settings::set('repl_mysql_remote_ip', $newMasterIp);
                Settings::set('repl_role', $this->computeLegacyRole());
                Settings::set('repl_remote_ip', $newMasterIp);
                Database::update('servers', ['role' => $this->computeLegacyRole()], 'is_local = true');
                LogService::log('replication.demote', 'mysql_master_to_slave', "MySQL degradado a slave, nuevo master: {$newMasterIp}");
                Flash::set('success', 'MySQL degradado a Slave correctamente');
            } else {
                Flash::set('error', 'Error al degradar MySQL: ' . $result['error']);
            }

        } else {
            Flash::set('error', 'Motor no especificado (pg o mysql)');
        }

        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Multi-Slave Endpoints ───────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * POST /settings/replication/add-slave
     */
    public function addSlave(): void
    {
        View::verifyCsrf();

        $name      = trim($_POST['slave_name'] ?? '');
        $primaryIp = trim($_POST['primary_ip'] ?? '');

        if ($name === '' || $primaryIp === '') {
            Flash::set('error', 'Nombre e IP primaria son obligatorios');
            header('Location: /settings/replication');
            exit;
        }

        if (!filter_var($primaryIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP primaria no valida');
            header('Location: /settings/replication');
            exit;
        }

        $fallbackIp = trim($_POST['fallback_ip'] ?? '');
        if ($fallbackIp !== '' && !filter_var($fallbackIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP fallback no valida');
            header('Location: /settings/replication');
            exit;
        }

        $logicalDbs = '';
        if (!empty($_POST['pg_logical_databases']) && is_array($_POST['pg_logical_databases'])) {
            $logicalDbs = implode(',', array_map('trim', $_POST['pg_logical_databases']));
        }

        $data = [
            'name'                 => $name,
            'primary_ip'           => $primaryIp,
            'fallback_ip'          => $fallbackIp,
            'pg_port'              => (int)($_POST['pg_port'] ?? 5432),
            'pg_user'              => trim($_POST['pg_user'] ?? 'replicator'),
            'pg_pass'              => $_POST['pg_pass'] ?? '',
            'mysql_port'           => (int)($_POST['mysql_port'] ?? 3306),
            'mysql_user'           => trim($_POST['mysql_user'] ?? 'repl_user'),
            'mysql_pass'           => $_POST['mysql_pass'] ?? '',
            'pg_enabled'           => isset($_POST['pg_enabled']),
            'mysql_enabled'        => isset($_POST['mysql_enabled']),
            'pg_sync_mode'         => $_POST['pg_sync_mode'] ?? 'async',
            'pg_repl_type'         => $_POST['pg_repl_type'] ?? 'physical',
            'pg_logical_databases' => $logicalDbs,
            'mysql_gtid_enabled'   => isset($_POST['mysql_gtid_enabled']),
        ];

        $id = ReplicationService::addSlave($data);
        LogService::log('replication.slave', 'add', "Slave anadido: {$name} ({$primaryIp}), ID: {$id}");
        Flash::set('success', "Slave '{$name}' anadido correctamente");
        header('Location: /settings/replication');
        exit;
    }

    /**
     * POST /settings/replication/update-slave
     */
    public function updateSlave(): void
    {
        View::verifyCsrf();

        $id = (int)($_POST['slave_id'] ?? 0);
        if ($id < 1) {
            Flash::set('error', 'ID de slave no valido');
            header('Location: /settings/replication');
            exit;
        }

        $slave = ReplicationService::getSlave($id);
        if (!$slave) {
            Flash::set('error', 'Slave no encontrado');
            header('Location: /settings/replication');
            exit;
        }

        $name      = trim($_POST['slave_name'] ?? '');
        $primaryIp = trim($_POST['primary_ip'] ?? '');

        if ($name === '' || $primaryIp === '') {
            Flash::set('error', 'Nombre e IP primaria son obligatorios');
            header('Location: /settings/replication');
            exit;
        }

        if (!filter_var($primaryIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP primaria no valida');
            header('Location: /settings/replication');
            exit;
        }

        $fallbackIp = trim($_POST['fallback_ip'] ?? '');
        if ($fallbackIp !== '' && !filter_var($fallbackIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP fallback no valida');
            header('Location: /settings/replication');
            exit;
        }

        $logicalDbs = '';
        if (!empty($_POST['pg_logical_databases']) && is_array($_POST['pg_logical_databases'])) {
            $logicalDbs = implode(',', array_map('trim', $_POST['pg_logical_databases']));
        }

        $data = [
            'name'                 => $name,
            'primary_ip'           => $primaryIp,
            'fallback_ip'          => $fallbackIp,
            'pg_port'              => (int)($_POST['pg_port'] ?? 5432),
            'pg_user'              => trim($_POST['pg_user'] ?? 'replicator'),
            'pg_pass'              => $_POST['pg_pass'] ?? '',
            'mysql_port'           => (int)($_POST['mysql_port'] ?? 3306),
            'mysql_user'           => trim($_POST['mysql_user'] ?? 'repl_user'),
            'mysql_pass'           => $_POST['mysql_pass'] ?? '',
            'pg_enabled'           => isset($_POST['pg_enabled']),
            'mysql_enabled'        => isset($_POST['mysql_enabled']),
            'pg_sync_mode'         => $_POST['pg_sync_mode'] ?? 'async',
            'pg_repl_type'         => $_POST['pg_repl_type'] ?? 'physical',
            'pg_logical_databases' => $logicalDbs,
            'mysql_gtid_enabled'   => isset($_POST['mysql_gtid_enabled']),
        ];

        ReplicationService::updateSlave($id, $data);
        LogService::log('replication.slave', 'update', "Slave actualizado: {$name} ({$primaryIp}), ID: {$id}");
        Flash::set('success', "Slave '{$name}' actualizado correctamente");
        header('Location: /settings/replication');
        exit;
    }

    /**
     * POST /settings/replication/{id}/remove-slave
     */
    public function removeSlave(): void
    {
        View::verifyCsrf();

        $id = (int)($_POST['slave_id'] ?? 0);
        if ($id < 1) {
            Flash::set('error', 'ID de slave no valido');
            header('Location: /settings/replication');
            exit;
        }

        $slave = ReplicationService::getSlave($id);
        if (!$slave) {
            Flash::set('error', 'Slave no encontrado');
            header('Location: /settings/replication');
            exit;
        }

        $name = $slave['name'];
        ReplicationService::removeSlave($id);
        LogService::log('replication.slave', 'remove', "Slave eliminado: {$name}, ID: {$id}");
        Flash::set('success', "Slave '{$name}' eliminado correctamente");
        header('Location: /settings/replication');
        exit;
    }

    /**
     * POST /settings/replication/apply-master
     * Apply master configuration for ALL slaves at once.
     * Only operates on engines that are currently in master mode.
     */
    public function applyMaster(): void
    {
        View::verifyCsrf();

        $slaves = ReplicationService::getSlaves();
        if (empty($slaves)) {
            Flash::set('error', 'No hay slaves configurados');
            header('Location: /settings/replication');
            exit;
        }

        $pgRole    = Settings::get('repl_pg_role', 'standalone');
        $mysqlRole = Settings::get('repl_mysql_role', 'standalone');

        $results = [];
        $allOk   = true;

        // Check which engines are relevant among slaves
        $anyPg    = false;
        $anyMysql = false;
        foreach ($slaves as $s) {
            if ($s['pg_enabled'])    $anyPg    = true;
            if ($s['mysql_enabled']) $anyMysql = true;
        }

        if ($anyPg && $pgRole === 'master') {
            $result = ReplicationService::setupPgMasterMulti($slaves);
            $results['postgresql'] = $result;
            if (!$result['ok']) $allOk = false;

            // Setup logical publications if any slave uses logical replication
            foreach ($slaves as $s) {
                if ($s['pg_enabled'] && ($s['pg_repl_type'] ?? 'physical') === 'logical' && !empty($s['pg_logical_databases'])) {
                    $dbs = array_filter(explode(',', $s['pg_logical_databases']));
                    if (!empty($dbs)) {
                        $pubResult = ReplicationService::setupPgLogicalPublisher($dbs);
                        $results['pg_logical'] = $pubResult;
                    }
                }
            }

            // Auto-generate synchronous_standby_names from sync slaves
            $syncNames = [];
            foreach ($slaves as $s) {
                if ($s['pg_enabled'] && ($s['pg_sync_mode'] ?? 'async') !== 'async') {
                    $syncNames[] = $s['name'];
                }
            }
            if (!empty($syncNames)) {
                $mode = 'sync';
                foreach ($slaves as $s) {
                    if ($s['pg_enabled'] && ($s['pg_sync_mode'] ?? 'async') === 'remote_apply') {
                        $mode = 'remote_apply';
                        break;
                    }
                }
                ReplicationService::setPgSyncMode($mode, $syncNames);
            }
        }

        if ($anyMysql && $mysqlRole === 'master') {
            $result = ReplicationService::setupMysqlMasterMulti($slaves);
            $results['mysql'] = $result;
            if (!$result['ok']) $allOk = false;
        }

        if ($allOk) {
            Settings::set('repl_configured_at', date('Y-m-d H:i:s'));

            // Update slave statuses
            foreach ($slaves as $s) {
                ReplicationService::updateSlaveStatus((int)$s['id'], 'configured');
            }

            // Sync legacy role
            $legacyRole = $this->computeLegacyRole();
            Settings::set('repl_role', $legacyRole);
            Database::update('servers', ['role' => $legacyRole], 'is_local = true');

            LogService::log('replication.apply', 'master_multi', 'Master configurado para ' . count($slaves) . ' slaves');
            Flash::set('success', 'Configuracion Master aplicada para ' . count($slaves) . ' slaves');
        } else {
            $errors = [];
            foreach ($results as $engine => $r) {
                if (!$r['ok']) $errors[] = "{$engine}: {$r['error']}";
            }
            Flash::set('error', 'Error aplicando configuracion: ' . implode(' | ', $errors));
        }

        header('Location: /settings/replication');
        exit;
    }

    /**
     * GET /settings/replication/slave-status (JSON)
     * Returns status for all slaves — used for AJAX polling.
     */
    public function slaveStatus(): void
    {
        header('Content-Type: application/json');

        $role   = $this->computeLegacyRole();
        $slaves = ReplicationService::getSlaves();

        $pgRepl      = ReplicationService::getPgMasterStatusMulti();
        $mysqlMaster = ReplicationService::getMysqlMasterGtidStatus();

        // Index PG replication rows by client IP
        $pgByIp = [];
        foreach ($pgRepl as $r) {
            $ip = $r['client_addr'] ?? '';
            $pgByIp[$ip] = $r;
        }

        $slaveData = [];
        foreach ($slaves as $slave) {
            $entry = [
                'id'                => $slave['id'],
                'name'              => $slave['name'],
                'primary_ip'        => $slave['primary_ip'],
                'fallback_ip'       => $slave['fallback_ip'] ?? '',
                'active_connection' => $slave['active_connection'] ?? 'primary',
                'status'            => $slave['status'] ?? 'pending',
                'pg_enabled'        => (bool)$slave['pg_enabled'],
                'mysql_enabled'     => (bool)$slave['mysql_enabled'],
                'pg_status'         => null,
                'mysql_status'      => null,
            ];

            if ($slave['pg_enabled']) {
                $activeIp = ($slave['active_connection'] === 'fallback' && !empty($slave['fallback_ip']))
                    ? $slave['fallback_ip']
                    : $slave['primary_ip'];

                $pgMatch = $pgByIp[$activeIp]
                    ?? $pgByIp[$slave['primary_ip']]
                    ?? $pgByIp[$slave['fallback_ip'] ?? '']
                    ?? null;

                if ($pgMatch) {
                    $entry['pg_status'] = [
                        'state'      => $pgMatch['state']      ?? 'disconnected',
                        'lag_bytes'  => $pgMatch['lag_bytes']  ?? 0,
                        'sent_lsn'   => $pgMatch['sent_lsn']   ?? '',
                        'replay_lsn' => $pgMatch['replay_lsn'] ?? '',
                        'sync_state' => $pgMatch['sync_state'] ?? '',
                    ];
                }
            }

            if ($slave['mysql_enabled'] && $mysqlMaster) {
                $entry['mysql_status'] = [
                    'file'          => $mysqlMaster['File']          ?? '',
                    'position'      => $mysqlMaster['Position']      ?? '',
                    'gtid_mode'     => $mysqlMaster['Gtid_Mode']     ?? 'OFF',
                    'gtid_executed' => $mysqlMaster['Gtid_Executed'] ?? '',
                ];
            }

            $slaveData[] = $entry;
        }

        echo json_encode([
            'role'      => $role,
            'slaves'    => $slaveData,
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * POST /settings/replication/test-slave-connection
     * Test primary + fallback IPs for a slave.
     */
    public function testSlaveConnection(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine     = $_POST['engine'] ?? '';
        $primaryIp  = trim($_POST['primary_ip'] ?? '');
        $fallbackIp = trim($_POST['fallback_ip'] ?? '');
        $port       = (int)($_POST['port'] ?? 0);
        $user       = trim($_POST['user'] ?? '');
        $pass       = $_POST['pass'] ?? '';

        if (!in_array($engine, ['pg', 'mysql']) || !$primaryIp || !$port || !$user) {
            echo json_encode(['ok' => false, 'message' => 'Parametros incompletos']);
            exit;
        }

        $result = ReplicationService::testConnectionWithFallback($engine, $primaryIp, $fallbackIp, $port, $user, $pass);

        echo json_encode([
            'ok'            => $result['ok'],
            'message'       => $result['ok']
                ? 'Conexion exitosa via ' . ($result['connected_via'] === 'primary' ? 'IP primaria' : 'IP fallback') . ' (' . $result['ip'] . ')'
                : $result['error'],
            'connected_via' => $result['connected_via'],
            'version'       => $result['version'],
        ]);
        exit;
    }

    /**
     * POST /settings/replication/save-advanced
     * Save advanced replication settings.
     */
    public function saveAdvanced(): void
    {
        View::verifyCsrf();

        $walLevel    = in_array($_POST['pg_wal_level'] ?? '', ['replica', 'logical']) ? $_POST['pg_wal_level'] : 'replica';
        $gtidMode    = isset($_POST['mysql_gtid_mode']) ? '1' : '0';
        $binlogFormat = in_array($_POST['mysql_binlog_format'] ?? '', ['ROW', 'MIXED', 'STATEMENT']) ? $_POST['mysql_binlog_format'] : 'ROW';

        Settings::set('repl_pg_wal_level', $walLevel);
        Settings::set('repl_mysql_gtid_mode', $gtidMode);
        Settings::set('repl_mysql_binlog_format', $binlogFormat);

        LogService::log('replication.config', 'advanced', "WAL: {$walLevel}, GTID: {$gtidMode}, Binlog: {$binlogFormat}");
        Flash::set('success', 'Configuracion avanzada guardada');
        header('Location: /settings/replication');
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Status JSON endpoint ────────────────────────────────
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

        $data = [
            'pg' => [
                'role'   => $pgRole,
                'status' => $pgStatus,
            ],
            'mysql' => [
                'role'   => $mysqlRole,
                'status' => $mysqlStatus,
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Include multi-slave data when any engine is master
        $legacyRole = $this->computeLegacyRole();
        if ($legacyRole === 'master') {
            $data['pg_repl_all'] = ReplicationService::getPgMasterStatusMulti();
            $data['slaves']      = ReplicationService::getSlaves();
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Test Connection JSON endpoint ───────────────────────
    // ═══════════════════════════════════════════════════════════

    public function testConnection(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine = $_POST['engine'] ?? '';

        // If no explicit host/port/user are posted, use the engine's own stored settings
        if (isset($_POST['host']) && $_POST['host'] !== '') {
            $host = trim($_POST['host']);
            $port = (int)($_POST['port'] ?? 0);
            $user = trim($_POST['user'] ?? '');
            $pass = $_POST['pass'] ?? '';
        } else {
            if ($engine === 'pg') {
                $host = Settings::get('repl_pg_remote_ip') ?: Settings::get('repl_remote_ip', '');
                $port = (int)Settings::get('repl_pg_port', '5432');
                $user = Settings::get('repl_pg_user', 'replicator');
                $pass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass', ''));
            } elseif ($engine === 'mysql') {
                $host = Settings::get('repl_mysql_remote_ip') ?: Settings::get('repl_remote_ip', '');
                $port = (int)Settings::get('repl_mysql_port', '3306');
                $user = Settings::get('repl_mysql_user', 'repl_user');
                $pass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass', ''));
            } else {
                echo json_encode(['ok' => false, 'message' => 'Motor no especificado (pg o mysql)', 'version' => '']);
                exit;
            }
        }

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
