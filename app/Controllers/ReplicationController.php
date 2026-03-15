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
    public function index(): void
    {
        $settings = Settings::getAll();
        $role = $settings['repl_role'] ?? 'standalone';
        $pgVersion = ReplicationService::detectPgVersion();
        $mysqlVersion = ReplicationService::detectMysqlVersion();

        $pgStatus = null;
        $mysqlStatus = null;
        if ($role === 'master') {
            $pgStatus = ReplicationService::getPgMasterStatus();
            $mysqlStatus = ReplicationService::getMysqlMasterStatus();
        } elseif ($role === 'slave') {
            $pgStatus = ReplicationService::getPgSlaveStatus();
            $mysqlStatus = ReplicationService::getMysqlSlaveStatus();
        }

        View::render('settings/replication', [
            'layout'       => 'main',
            'pageTitle'    => 'Replicacion',
            'settings'     => $settings,
            'role'         => $role,
            'pgVersion'    => $pgVersion,
            'mysqlVersion' => $mysqlVersion,
            'pgStatus'     => $pgStatus,
            'mysqlStatus'  => $mysqlStatus,
            'pgEnabled'    => ($settings['repl_pg_enabled'] ?? '0') === '1',
            'mysqlEnabled' => ($settings['repl_mysql_enabled'] ?? '0') === '1',
        ]);
    }

    public function saveConfig(): void
    {
        View::verifyCsrf();

        $remoteIp   = trim($_POST['remote_ip'] ?? '');
        $pgPort     = (int)($_POST['pg_port'] ?? 5432);
        $pgUser     = trim($_POST['pg_user'] ?? 'replicator');
        $pgPass     = $_POST['pg_pass'] ?? '';
        $mysqlPort  = (int)($_POST['mysql_port'] ?? 3306);
        $mysqlUser  = trim($_POST['mysql_user'] ?? 'repl_user');
        $mysqlPass  = $_POST['mysql_pass'] ?? '';
        $pgEnabled  = isset($_POST['pg_enabled']) ? '1' : '0';
        $mysqlEnabled = isset($_POST['mysql_enabled']) ? '1' : '0';

        // Validate IP
        if ($remoteIp !== '' && !filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP remota no valida');
            header('Location: /settings/replication');
            exit;
        }

        // Validate ports
        if ($pgPort < 1 || $pgPort > 65535 || $mysqlPort < 1 || $mysqlPort > 65535) {
            Flash::set('error', 'Puerto fuera de rango (1-65535)');
            header('Location: /settings/replication');
            exit;
        }

        Settings::set('repl_remote_ip', $remoteIp);
        Settings::set('repl_pg_port', (string)$pgPort);
        Settings::set('repl_pg_user', $pgUser);
        Settings::set('repl_mysql_port', (string)$mysqlPort);
        Settings::set('repl_mysql_user', $mysqlUser);
        Settings::set('repl_pg_enabled', $pgEnabled);
        Settings::set('repl_mysql_enabled', $mysqlEnabled);

        // Only update passwords if provided (not empty = new value)
        if ($pgPass !== '') {
            Settings::set('repl_pg_pass', ReplicationService::encryptPassword($pgPass));
        }
        if ($mysqlPass !== '') {
            Settings::set('repl_mysql_pass', ReplicationService::encryptPassword($mysqlPass));
        }

        LogService::log('replication.config', 'save', "IP: {$remoteIp}, PG: {$pgEnabled}, MySQL: {$mysqlEnabled}");
        Flash::set('success', 'Configuracion de replicacion guardada');
        header('Location: /settings/replication');
        exit;
    }

    public function setupMaster(): void
    {
        View::verifyCsrf();

        $remoteIp = Settings::get('repl_remote_ip');
        if (!$remoteIp) {
            Flash::set('error', 'Configure primero la IP del servidor remoto');
            header('Location: /settings/replication');
            exit;
        }

        $results = [];
        $allOk = true;

        // PostgreSQL
        if (Settings::get('repl_pg_enabled') === '1') {
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));
            if (!$pgPass) {
                Flash::set('error', 'Password de replicacion PostgreSQL no configurado');
                header('Location: /settings/replication');
                exit;
            }
            $result = ReplicationService::setupPgMaster($remoteIp, $pgUser, $pgPass);
            $results['postgresql'] = $result;
            if (!$result['ok']) $allOk = false;
        }

        // MySQL
        if (Settings::get('repl_mysql_enabled') === '1') {
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));
            if (!$mysqlPass) {
                Flash::set('error', 'Password de replicacion MySQL no configurado');
                header('Location: /settings/replication');
                exit;
            }
            $result = ReplicationService::setupMysqlMaster($remoteIp, $mysqlUser, $mysqlPass);
            $results['mysql'] = $result;
            if (!$result['ok']) $allOk = false;
        }

        if ($allOk) {
            Settings::set('repl_role', 'master');
            Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
            Database::update('servers', ['role' => 'master'], 'is_local = true');
            LogService::log('replication.setup', 'master', 'Configurado como master');
            Flash::set('success', 'Servidor configurado como Master correctamente');
        } else {
            $errors = [];
            foreach ($results as $engine => $r) {
                if (!$r['ok']) $errors[] = "{$engine}: {$r['error']}";
            }
            Flash::set('error', 'Error en configuracion: ' . implode(' | ', $errors));
        }

        header('Location: /settings/replication');
        exit;
    }

    public function setupSlave(): void
    {
        View::verifyCsrf();

        if (($_POST['confirm'] ?? '') !== 'yes') {
            Flash::set('error', 'Operacion no confirmada');
            header('Location: /settings/replication');
            exit;
        }

        $remoteIp = Settings::get('repl_remote_ip');
        if (!$remoteIp) {
            Flash::set('error', 'Configure primero la IP del servidor master');
            header('Location: /settings/replication');
            exit;
        }

        $results = [];
        $allOk = true;

        // PostgreSQL
        if (Settings::get('repl_pg_enabled') === '1') {
            $pgPort = (int)Settings::get('repl_pg_port', '5432');
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));
            $result = ReplicationService::setupPgSlave($remoteIp, $pgPort, $pgUser, $pgPass);
            $results['postgresql'] = $result;
            if (!$result['ok']) $allOk = false;
        }

        // MySQL
        if (Settings::get('repl_mysql_enabled') === '1') {
            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));
            $result = ReplicationService::setupMysqlSlave($remoteIp, $mysqlPort, $mysqlUser, $mysqlPass);
            $results['mysql'] = $result;
            if (!$result['ok']) $allOk = false;
        }

        if ($allOk) {
            Settings::set('repl_role', 'slave');
            Settings::set('repl_configured_at', date('Y-m-d H:i:s'));
            Database::update('servers', ['role' => 'slave'], 'is_local = true');
            LogService::log('replication.setup', 'slave', 'Configurado como slave');
            Flash::set('success', 'Servidor configurado como Slave correctamente');
        } else {
            $errors = [];
            foreach ($results as $engine => $r) {
                if (!$r['ok']) $errors[] = "{$engine}: {$r['error']}";
            }
            Flash::set('error', 'Error: ' . implode(' | ', $errors));
        }

        header('Location: /settings/replication');
        exit;
    }

    public function promote(): void
    {
        View::verifyCsrf();

        $role = Settings::get('repl_role');
        if ($role !== 'slave') {
            Flash::set('error', 'Solo un servidor slave puede ser promovido');
            header('Location: /settings/replication');
            exit;
        }

        $allOk = true;
        $errors = [];

        if (Settings::get('repl_pg_enabled') === '1') {
            $result = ReplicationService::promotePgSlave();
            if (!$result['ok']) { $allOk = false; $errors[] = 'PG: ' . $result['error']; }
        }

        if (Settings::get('repl_mysql_enabled') === '1') {
            $result = ReplicationService::promoteMysqlSlave();
            if (!$result['ok']) { $allOk = false; $errors[] = 'MySQL: ' . $result['error']; }
        }

        if ($allOk) {
            Settings::set('repl_role', 'master');
            Database::update('servers', ['role' => 'master'], 'is_local = true');
            LogService::log('replication.promote', 'slave_to_master', 'Slave promovido a Master');
            Flash::set('success', 'Servidor promovido a Master correctamente');
        } else {
            Flash::set('error', 'Error al promover: ' . implode(' | ', $errors));
        }

        header('Location: /settings/replication');
        exit;
    }

    public function demote(): void
    {
        View::verifyCsrf();

        $role = Settings::get('repl_role');
        if ($role !== 'master') {
            Flash::set('error', 'Solo un servidor master puede ser degradado');
            header('Location: /settings/replication');
            exit;
        }

        $newMasterIp = trim($_POST['new_master_ip'] ?? '');
        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'IP del nuevo master no valida');
            header('Location: /settings/replication');
            exit;
        }

        $allOk = true;
        $errors = [];

        if (Settings::get('repl_pg_enabled') === '1') {
            $pgPort = (int)Settings::get('repl_pg_port', '5432');
            $pgUser = Settings::get('repl_pg_user', 'replicator');
            $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_pass'));
            $result = ReplicationService::demotePgMaster($newMasterIp, $pgPort, $pgUser, $pgPass);
            if (!$result['ok']) { $allOk = false; $errors[] = 'PG: ' . $result['error']; }
        }

        if (Settings::get('repl_mysql_enabled') === '1') {
            $mysqlPort = (int)Settings::get('repl_mysql_port', '3306');
            $mysqlUser = Settings::get('repl_mysql_user', 'repl_user');
            $mysqlPass = ReplicationService::decryptPassword(Settings::get('repl_mysql_pass'));
            $result = ReplicationService::demoteMysqlMaster($newMasterIp, $mysqlPort, $mysqlUser, $mysqlPass);
            if (!$result['ok']) { $allOk = false; $errors[] = 'MySQL: ' . $result['error']; }
        }

        if ($allOk) {
            Settings::set('repl_role', 'slave');
            Settings::set('repl_remote_ip', $newMasterIp);
            Database::update('servers', ['role' => 'slave'], 'is_local = true');
            LogService::log('replication.demote', 'master_to_slave', "Degradado a slave, nuevo master: {$newMasterIp}");
            Flash::set('success', 'Servidor degradado a Slave correctamente');
        } else {
            Flash::set('error', 'Error al degradar: ' . implode(' | ', $errors));
        }

        header('Location: /settings/replication');
        exit;
    }

    public function status(): void
    {
        header('Content-Type: application/json');

        $role = Settings::get('repl_role', 'standalone');
        $data = [
            'role'      => $role,
            'pg'        => null,
            'mysql'     => null,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if ($role === 'master') {
            $data['pg'] = ReplicationService::getPgMasterStatus();
            $data['mysql'] = ReplicationService::getMysqlMasterStatus();
        } elseif ($role === 'slave') {
            $data['pg'] = ReplicationService::getPgSlaveStatus();
            $data['mysql'] = ReplicationService::getMysqlSlaveStatus();
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    public function testConnection(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $engine = $_POST['engine'] ?? '';
        $host = trim($_POST['host'] ?? '');
        $port = (int)($_POST['port'] ?? 0);
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';

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
