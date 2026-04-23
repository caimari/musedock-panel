<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Services\FirewallService;
use MuseDockPanel\Services\LogService;

class FirewallController
{
    public function index(): void
    {
        $type     = FirewallService::getType();
        $active   = FirewallService::isActive();
        $adminIp  = FirewallService::getAdminIp();
        $networkInterfaces = FirewallService::getNetworkInterfaces();
        $rules    = [];
        $policy   = 'desconocida';

        if ($type === 'ufw') {
            $rules  = FirewallService::ufwGetRules();
            $policy = FirewallService::ufwGetDefault();
        } elseif ($type === 'iptables') {
            $rules  = FirewallService::iptablesGetRules();
            $policy = FirewallService::iptablesGetPolicy();
        }

        // Suggestions
        $slaveIp = Settings::get('repl_remote_ip', '');
        $replSuggestions  = FirewallService::suggestRulesForReplication($slaveIp);
        $hostSuggestions  = FirewallService::suggestRulesForHosting();

        // Security audit
        $securityWarnings = FirewallService::auditRules($rules, $policy);

        // Manual iptables rules outside UFW
        $manualIptables = FirewallService::getManualIptablesRules();

        View::render('settings/firewall', [
            'layout'            => 'main',
            'pageTitle'         => 'Firewall',
            'type'              => $type,
            'active'            => $active,
            'adminIp'           => $adminIp,
            'networkInterfaces' => $networkInterfaces,
            'rules'             => $rules,
            'policy'            => $policy,
            'replSuggestions'   => $replSuggestions,
            'hostSuggestions'   => $hostSuggestions,
            'securityWarnings'  => $securityWarnings,
            'manualIptables'    => $manualIptables,
        ]);
    }

    public function addRule(): void
    {
        View::verifyCsrf();

        $type     = FirewallService::getType();
        $action   = strtolower(trim($_POST['action'] ?? ''));
        $from     = trim($_POST['from'] ?? '');
        $port     = trim($_POST['port'] ?? '');
        $protocol = strtolower(trim($_POST['protocol'] ?? 'tcp'));
        $comment  = trim($_POST['comment'] ?? '');
        $anyIp    = isset($_POST['any_ip']);

        // Validate action
        if (!in_array($action, ['allow', 'deny'])) {
            Flash::set('error', 'Accion no valida. Debe ser allow o deny.');
            header('Location: /settings/firewall');
            exit;
        }

        // Validate IP
        if ($anyIp) {
            $from = '0.0.0.0/0';
        } elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_IP) && !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $from)) {
            Flash::set('error', 'Direccion IP no valida.');
            header('Location: /settings/firewall');
            exit;
        }

        // Validate port (not required for 'all' protocol)
        $portNum = (int)$port;
        if ($port !== '' && ($portNum < 1 || $portNum > 65535)) {
            Flash::set('error', 'Puerto fuera de rango (1-65535).');
            header('Location: /settings/firewall');
            exit;
        }

        // Validate protocol
        if (!in_array($protocol, ['tcp', 'udp', 'both', 'all'])) {
            Flash::set('error', 'Protocolo no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($type === 'ufw') {
            $result = FirewallService::ufwAddRule($action, $from, $port, $protocol, $comment);
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesAddRule($action, $from, $portNum, $protocol);
        } else {
            Flash::set('error', 'No se detecto un firewall activo.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($result['ok']) {
            LogService::log('firewall.add_rule', $port ?: 'all', "Regla: {$action} from {$from} port {$port} proto {$protocol}");
            Flash::set('success', 'Regla agregada correctamente.');
        } else {
            Flash::set('error', 'Error al agregar regla: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function deleteRule(): void
    {
        View::verifyCsrf();

        $type   = FirewallService::getType();
        $number = (int)($_POST['rule_number'] ?? 0);

        if ($number < 1) {
            Flash::set('error', 'Numero de regla no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($type === 'ufw') {
            $result = FirewallService::ufwDeleteRule($number);
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesDeleteRule($number);
        } else {
            Flash::set('error', 'No se detecto un firewall activo.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($result['ok']) {
            LogService::log('firewall.delete_rule', (string)$number, "Regla #{$number} eliminada");
            Flash::set('success', 'Regla eliminada correctamente.');
        } else {
            Flash::set('error', 'Error al eliminar regla: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function editRule(): void
    {
        View::verifyCsrf();

        $type     = FirewallService::getType();
        $number   = (int)($_POST['rule_number'] ?? 0);
        $action   = strtolower(trim($_POST['action'] ?? ''));
        $from     = trim($_POST['from'] ?? '');
        $port     = trim($_POST['port'] ?? '');
        $protocol = strtolower(trim($_POST['protocol'] ?? 'tcp'));
        $comment  = trim($_POST['comment'] ?? '');
        $anyIp    = isset($_POST['any_ip']);

        if ($number < 1) {
            Flash::set('error', 'Numero de regla no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        if (!in_array($action, ['allow', 'deny'])) {
            Flash::set('error', 'Accion no valida.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($anyIp) {
            $from = '0.0.0.0/0';
        } elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_IP) && !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $from)) {
            Flash::set('error', 'Direccion IP no valida.');
            header('Location: /settings/firewall');
            exit;
        }

        $portNum = (int)$port;
        if ($port !== '' && ($portNum < 1 || $portNum > 65535)) {
            Flash::set('error', 'Puerto fuera de rango (1-65535).');
            header('Location: /settings/firewall');
            exit;
        }

        if (!in_array($protocol, ['tcp', 'udp', 'both', 'all'])) {
            Flash::set('error', 'Protocolo no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($type === 'ufw') {
            $result = FirewallService::ufwEditRule($number, $action, $from, $port, $protocol, $comment);
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesEditRule($number, $action, $from, $portNum, $protocol);
        } else {
            Flash::set('error', 'No se detecto un firewall activo.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($result['ok']) {
            LogService::log('firewall.edit_rule', (string)$number, "Regla #{$number} editada: {$action} from {$from} port {$port} proto {$protocol}");
            Flash::set('success', 'Regla editada correctamente.');
        } else {
            Flash::set('error', 'Error al editar regla: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function enableFirewall(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');

        if (!$this->verifyAdminPasswordOrRedirect($password, 'activar el firewall')) {
            exit;
        }

        $result = FirewallService::ufwEnable();

        if ($result['ok']) {
            LogService::log('firewall.enable', 'ufw', 'Firewall UFW activado');
            Flash::set('success', 'Firewall UFW activado correctamente.');
        } else {
            Flash::set('error', 'Error al activar el firewall: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function disableFirewall(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');

        if (!$this->verifyAdminPasswordOrRedirect($password, 'desactivar el firewall')) {
            exit;
        }

        $result = FirewallService::ufwDisable();

        if ($result['ok']) {
            LogService::log('firewall.disable', 'ufw', 'Firewall UFW desactivado');
            Flash::set('success', 'Firewall UFW desactivado.');
        } else {
            Flash::set('error', 'Error al desactivar el firewall: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function emergencyAllow(): void
    {
        View::verifyCsrf();

        $adminIp = FirewallService::getAdminIp();
        $result  = FirewallService::emergencyAllowIp($adminIp);

        if ($result['ok']) {
            LogService::log('firewall.emergency', $adminIp, "Acceso de emergencia permitido para IP: {$adminIp}");
            Flash::set('success', "Acceso de emergencia permitido para tu IP: {$adminIp}");
        } else {
            Flash::set('error', 'Error al aplicar regla de emergencia: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function saveRules(): void
    {
        View::verifyCsrf();

        $result = FirewallService::iptablesSave();

        if ($result['ok']) {
            LogService::log('firewall.save', 'iptables', 'Reglas de iptables guardadas');
            Flash::set('success', 'Reglas de iptables guardadas correctamente.');
        } else {
            Flash::set('error', 'Error al guardar reglas: ' . ($result['output'] ?? 'desconocido'));
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function suggestRules(): void
    {
        header('Content-Type: application/json');

        $slaveIp = Settings::get('repl_remote_ip', '');
        $replSuggestions = FirewallService::suggestRulesForReplication($slaveIp);
        $hostSuggestions = FirewallService::suggestRulesForHosting();

        echo json_encode([
            'replication' => $replSuggestions,
            'hosting'     => $hostSuggestions,
        ]);
        exit;
    }

    private function verifyAdminPasswordOrRedirect(string $password, string $actionLabel): bool
    {
        $password = trim($password);
        if ($password === '') {
            Flash::set('error', "Contrasena de administrador requerida para {$actionLabel}.");
            header('Location: /settings/firewall');
            return false;
        }

        $adminId = (int)($_SESSION['panel_user']['id'] ?? 0);
        if ($adminId < 1) {
            Flash::set('error', 'Sesion no valida. Inicia sesion de nuevo.');
            header('Location: /login');
            return false;
        }

        $admin = Database::fetchOne(
            "SELECT password_hash FROM panel_admins WHERE id = :id",
            ['id' => $adminId]
        );
        if (!$admin || !password_verify($password, (string)($admin['password_hash'] ?? ''))) {
            Flash::set('error', 'Contrasena de administrador incorrecta.');
            header('Location: /settings/firewall');
            return false;
        }

        return true;
    }
}
