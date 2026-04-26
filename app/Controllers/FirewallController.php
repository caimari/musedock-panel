<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Services\FirewallService;
use MuseDockPanel\Services\NotificationService;
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

        // Manual iptables rules outside UFW
        $manualIptables = FirewallService::getManualIptablesRules();
        $rulePresets = FirewallService::getRulePresets();
        $fullSnapshots = FirewallService::getFullSnapshots();
        $changeWatchEnabled = Settings::get('firewall_change_watch_enabled', '0') === '1';
        $emailConfigured = NotificationService::isEmailConfigured();
        $monitorEnabled = Settings::get('monitor_enabled', '1') === '1';
        $lockdownState = FirewallService::getTemporaryLockdownState();

        // Security audit
        $securityWarnings = FirewallService::auditRules($rules, $policy);
        if (!empty($manualIptables)) {
            $securityWarnings = array_merge(
                $securityWarnings,
                FirewallService::auditManualIptablesRules($manualIptables)
            );
        }
        $securityWarnings = array_merge(
            $securityWarnings,
            FirewallService::auditIpv6Coverage($networkInterfaces)
        );

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
            'rulePresets'       => $rulePresets,
            'fullSnapshots'     => $fullSnapshots,
            'changeWatchEnabled'=> $changeWatchEnabled,
            'emailConfigured'   => $emailConfigured,
            'monitorEnabled'    => $monitorEnabled,
            'lockdownState'     => $lockdownState,
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
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'eliminar reglas de firewall')) {
            exit;
        }

        $type    = FirewallService::getType();
        $number  = (int)($_POST['rule_number'] ?? 0);
        $backend = strtolower(trim((string)($_POST['backend'] ?? '')));

        if ($number < 1) {
            Flash::set('error', 'Numero de regla no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        $logBackend = $type;
        if ($backend === 'iptables') {
            $result = FirewallService::iptablesDeleteRule($number);
            $logBackend = 'iptables';
        } elseif ($backend === 'ufw') {
            $result = FirewallService::ufwDeleteRule($number);
            $logBackend = 'ufw';
        } elseif ($type === 'ufw') {
            $result = FirewallService::ufwDeleteRule($number);
            $logBackend = 'ufw';
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesDeleteRule($number);
            $logBackend = 'iptables';
        } else {
            Flash::set('error', 'No se detecto un firewall activo.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($result['ok']) {
            LogService::log('firewall.delete_rule', (string)$number, "Regla #{$number} eliminada ({$logBackend})");
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
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'editar reglas de firewall')) {
            exit;
        }

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

        $type = FirewallService::getType();
        if ($type === 'ufw') {
            $result = FirewallService::ufwEnable();
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesEnable();
        } else {
            $result = ['ok' => false, 'output' => 'No se detecto un firewall compatible para activar'];
        }

        if ($result['ok']) {
            LogService::log('firewall.enable', $type, 'Firewall activado');
            $name = strtoupper($type === 'iptables' ? 'iptables' : 'ufw');
            Flash::set('success', "Firewall {$name} activado correctamente.");
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

        $type = FirewallService::getType();
        if ($type === 'ufw') {
            $result = FirewallService::ufwDisable();
        } elseif ($type === 'iptables') {
            $result = FirewallService::iptablesDisable();
        } else {
            $result = ['ok' => false, 'output' => 'No se detecto un firewall compatible para desactivar'];
        }

        if ($result['ok']) {
            LogService::log('firewall.disable', $type, 'Firewall desactivado');
            $name = strtoupper($type === 'iptables' ? 'iptables' : 'ufw');
            Flash::set('success', "Firewall {$name} desactivado.");
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
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'guardar reglas de firewall')) {
            exit;
        }

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

    public function toggleIpv6Interface(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'cambiar estado de IPv6 en la interfaz')) {
            exit;
        }

        $iface = trim((string)($_POST['interface'] ?? ''));
        $mode = strtolower(trim((string)($_POST['mode'] ?? '')));
        if ($iface === '' || !in_array($mode, ['enable', 'disable'], true)) {
            Flash::set('error', 'Solicitud invalida para cambio de IPv6.');
            header('Location: /settings/firewall');
            exit;
        }

        $result = FirewallService::setIpv6InterfaceEnabled($iface, $mode === 'enable');
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo cambiar IPv6: ' . (string)($result['error'] ?? 'error desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log(
            'firewall.ipv6.toggle',
            $iface,
            $mode === 'enable' ? 'IPv6 activada en interfaz' : 'IPv6 desactivada en interfaz'
        );
        Flash::set('success', (string)($result['message'] ?? 'Estado de IPv6 actualizado.'));
        header('Location: /settings/firewall');
        exit;
    }

    public function fixIpv6Lockdown(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'aplicar bloqueo IPv6 por defecto')) {
            exit;
        }

        $result = FirewallService::applyIpv6DefaultLockdown();
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo aplicar el fix IPv6: ' . (string)($result['error'] ?? 'error desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.ipv6.fix', 'lockdown', 'Fix IPv6 aplicado: bloqueo por defecto');
        Flash::set('success', (string)($result['message'] ?? 'Fix IPv6 aplicado.'));
        header('Location: /settings/firewall');
        exit;
    }

    public function savePreset(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'guardar presets de firewall')) {
            exit;
        }

        $presetId = trim((string)($_POST['preset_id'] ?? ''));
        $name     = trim((string)($_POST['preset_name'] ?? ''));
        $action   = strtolower(trim((string)($_POST['action'] ?? 'allow')));
        $from     = trim((string)($_POST['from'] ?? ''));
        $port     = trim((string)($_POST['port'] ?? ''));
        $protocol = strtolower(trim((string)($_POST['protocol'] ?? 'tcp')));
        $comment  = trim((string)($_POST['comment'] ?? ''));
        $anyIp    = isset($_POST['any_ip']);

        if ($name === '') {
            Flash::set('error', 'Debes indicar un nombre para el preset.');
            header('Location: /settings/firewall');
            exit;
        }
        if (strlen($name) > 80) {
            Flash::set('error', 'Nombre de preset demasiado largo (max 80 caracteres).');
            header('Location: /settings/firewall');
            exit;
        }
        if (!in_array($action, ['allow', 'deny'], true)) {
            Flash::set('error', 'Accion de preset no valida.');
            header('Location: /settings/firewall');
            exit;
        }
        if ($anyIp || $from === '') {
            $from = '0.0.0.0/0';
        } elseif (!filter_var($from, FILTER_VALIDATE_IP) && !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $from)) {
            Flash::set('error', 'IP/CIDR del preset no valida.');
            header('Location: /settings/firewall');
            exit;
        }
        if (!in_array($protocol, ['tcp', 'udp', 'both', 'all'], true)) {
            Flash::set('error', 'Protocolo del preset no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        $portNum = (int)$port;
        if ($protocol !== 'all' && ($port === '' || $portNum < 1 || $portNum > 65535)) {
            Flash::set('error', 'Puerto del preset fuera de rango (1-65535).');
            header('Location: /settings/firewall');
            exit;
        }
        if ($protocol === 'all') {
            $port = '';
        }

        $saved = FirewallService::saveRulePreset([
            'id'       => $presetId,
            'name'     => $name,
            'action'   => $action,
            'from'     => $from,
            'port'     => $port,
            'protocol' => $protocol,
            'comment'  => $comment,
        ]);

        LogService::log('firewall.preset.save', (string)$saved['id'], 'Preset guardado: ' . $saved['name']);
        Flash::set('success', 'Preset de firewall guardado.');
        header('Location: /settings/firewall');
        exit;
    }

    public function deletePreset(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'eliminar presets de firewall')) {
            exit;
        }

        $presetId = trim((string)($_POST['preset_id'] ?? ''));
        if ($presetId === '') {
            Flash::set('error', 'Preset no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        $preset = FirewallService::findRulePreset($presetId);
        $deleted = FirewallService::deleteRulePreset($presetId);
        if (!$deleted) {
            Flash::set('error', 'No se pudo eliminar el preset (no existe o ya fue eliminado).');
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.preset.delete', $presetId, 'Preset eliminado: ' . (string)($preset['name'] ?? $presetId));
        Flash::set('success', 'Preset eliminado correctamente.');
        header('Location: /settings/firewall');
        exit;
    }

    public function applyPreset(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'aplicar presets de firewall')) {
            exit;
        }

        $presetId = trim((string)($_POST['preset_id'] ?? ''));
        $preset = FirewallService::findRulePreset($presetId);
        if (!$preset) {
            Flash::set('error', 'Preset no encontrado.');
            header('Location: /settings/firewall');
            exit;
        }

        $type = FirewallService::getType();
        if ($type === 'none') {
            Flash::set('error', 'No se detecto un firewall activo.');
            header('Location: /settings/firewall');
            exit;
        }

        $action = strtolower((string)($preset['action'] ?? 'allow'));
        $source = (string)($preset['from'] ?? '0.0.0.0/0');
        $port   = (string)($preset['port'] ?? '');
        $proto  = strtolower((string)($preset['protocol'] ?? 'tcp'));
        $comment = (string)($preset['comment'] ?? '');

        if (!in_array($action, ['allow', 'deny'], true) || !in_array($proto, ['tcp', 'udp', 'both', 'all'], true)) {
            Flash::set('error', 'El preset contiene una configuracion invalida.');
            header('Location: /settings/firewall');
            exit;
        }
        if ($proto !== 'all' && (int)$port < 1) {
            Flash::set('error', 'El preset no tiene un puerto valido para el protocolo seleccionado.');
            header('Location: /settings/firewall');
            exit;
        }

        if ($type === 'ufw') {
            $result = FirewallService::ufwAddRule($action, $source, $port, $proto, $comment);
            if (!$result['ok']) {
                Flash::set('error', 'Error al aplicar preset: ' . ($result['output'] ?? 'desconocido'));
                header('Location: /settings/firewall');
                exit;
            }
        } else {
            // iptables no soporta "both" en una sola regla: aplicamos tcp + udp.
            if ($proto === 'both') {
                $tcp = FirewallService::iptablesAddRule($action, $source, (int)$port, 'tcp');
                $udp = FirewallService::iptablesAddRule($action, $source, (int)$port, 'udp');
                if (!$tcp['ok'] || !$udp['ok']) {
                    Flash::set('error', 'Error al aplicar preset en iptables (tcp/udp).');
                    header('Location: /settings/firewall');
                    exit;
                }
            } else {
                $result = FirewallService::iptablesAddRule($action, $source, (int)$port, $proto);
                if (!$result['ok']) {
                    Flash::set('error', 'Error al aplicar preset: ' . ($result['output'] ?? 'desconocido'));
                    header('Location: /settings/firewall');
                    exit;
                }
            }
        }

        LogService::log('firewall.preset.apply', $presetId, 'Preset aplicado: ' . (string)$preset['name']);
        Flash::set('success', 'Preset aplicado: ' . (string)$preset['name']);
        header('Location: /settings/firewall');
        exit;
    }

    public function saveFullSnapshot(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'guardar snapshots completos de firewall')) {
            exit;
        }

        $name = trim((string)($_POST['snapshot_name'] ?? ''));
        $note = trim((string)($_POST['snapshot_note'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Debes indicar un nombre para el snapshot completo.');
            header('Location: /settings/firewall');
            exit;
        }
        if (strlen($name) > 100) {
            Flash::set('error', 'Nombre de snapshot demasiado largo (max 100 caracteres).');
            header('Location: /settings/firewall');
            exit;
        }

        $result = FirewallService::createFullSnapshot($name, $note);
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo guardar snapshot: ' . (string)($result['error'] ?? 'desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        $snapshot = (array)($result['snapshot'] ?? []);
        LogService::log('firewall.snapshot.save', (string)($snapshot['id'] ?? ''), 'Snapshot completo guardado: ' . (string)($snapshot['name'] ?? $name));
        Flash::set('success', 'Snapshot completo guardado.');
        header('Location: /settings/firewall');
        exit;
    }

    public function applyFullSnapshot(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'restaurar snapshots completos de firewall')) {
            exit;
        }

        $id = trim((string)($_POST['snapshot_id'] ?? ''));
        if ($id === '') {
            Flash::set('error', 'Snapshot no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        $snapshot = FirewallService::findFullSnapshot($id);
        if (!$snapshot) {
            Flash::set('error', 'Snapshot no encontrado.');
            header('Location: /settings/firewall');
            exit;
        }

        $result = FirewallService::applyFullSnapshot($id);
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'Error al restaurar snapshot: ' . (string)($result['error'] ?? 'desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.snapshot.apply', $id, 'Snapshot completo restaurado: ' . (string)($snapshot['name'] ?? $id));
        Flash::set('success', 'Snapshot restaurado: ' . (string)($snapshot['name'] ?? $id));
        header('Location: /settings/firewall');
        exit;
    }

    public function deleteFullSnapshot(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'eliminar snapshots completos de firewall')) {
            exit;
        }

        $id = trim((string)($_POST['snapshot_id'] ?? ''));
        if ($id === '') {
            Flash::set('error', 'Snapshot no valido.');
            header('Location: /settings/firewall');
            exit;
        }

        $snapshot = FirewallService::findFullSnapshot($id);
        $ok = FirewallService::deleteFullSnapshot($id);
        if (!$ok) {
            Flash::set('error', 'No se pudo eliminar el snapshot (no existe o ya fue eliminado).');
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.snapshot.delete', $id, 'Snapshot completo eliminado: ' . (string)($snapshot['name'] ?? $id));
        Flash::set('success', 'Snapshot eliminado.');
        header('Location: /settings/firewall');
        exit;
    }

    public function exportConfig(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'exportar configuracion de firewall')) {
            exit;
        }

        $result = FirewallService::exportConfiguration();
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo exportar: ' . (string)($result['error'] ?? 'desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        $data = (array)($result['data'] ?? []);
        $filename = 'musedock-firewall-export-' . gmdate('Ymd-His') . '.json';

        LogService::log('firewall.export', 'download', 'Export de configuracion firewall');
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public function importConfig(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'importar configuracion de firewall')) {
            exit;
        }

        if (!isset($_FILES['config_file']) || !is_array($_FILES['config_file'])) {
            Flash::set('error', 'Debes seleccionar un archivo JSON para importar.');
            header('Location: /settings/firewall');
            exit;
        }

        $file = $_FILES['config_file'];
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            Flash::set('error', 'Error de subida de archivo.');
            header('Location: /settings/firewall');
            exit;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size < 2 || $size > 2 * 1024 * 1024) {
            Flash::set('error', 'Archivo invalido: maximo 2MB.');
            header('Location: /settings/firewall');
            exit;
        }

        $raw = (string)file_get_contents($tmpPath);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Flash::set('error', 'JSON invalido en archivo de import.');
            header('Location: /settings/firewall');
            exit;
        }

        $replace = isset($_POST['replace_existing']);
        $result = FirewallService::importConfiguration($payload, $replace);
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo importar: ' . (string)($result['error'] ?? 'desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.import', $replace ? 'replace' : 'append', 'Import de configuracion firewall');
        Flash::set('success', (string)($result['message'] ?? 'Configuracion importada correctamente.'));
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

    public function toggleChangeWatch(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'cambiar vigilancia de integridad del firewall')) {
            exit;
        }

        $enable = ((string)($_POST['enable'] ?? '')) === '1';
        Settings::set('firewall_change_watch_enabled', $enable ? '1' : '0');

        LogService::log(
            $enable ? 'firewall.change_watch.enable' : 'firewall.change_watch.disable',
            'integrity',
            $enable
                ? 'Vigilancia de cambios externos de firewall activada'
                : 'Vigilancia de cambios externos de firewall desactivada'
        );

        if ($enable) {
            Flash::set('success', 'Vigilancia de cambios de firewall activada. Se notificaran cambios externos con anti-spam.');
        } else {
            Flash::set('success', 'Vigilancia de cambios de firewall desactivada.');
        }

        header('Location: /settings/firewall');
        exit;
    }

    public function startTemporaryLockdown(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'activar lockdown temporal')) {
            exit;
        }

        $minutes = (int)($_POST['minutes'] ?? 15);
        $minutes = max(1, min(120, $minutes));
        $adminIp = FirewallService::getAdminIp();
        $config = require PANEL_ROOT . '/config/panel.php';
        $panelPort = (int)($config['port'] ?? 8444);

        $result = FirewallService::startTemporaryLockdown($adminIp, $minutes, $panelPort);
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo activar lockdown: ' . (string)($result['error'] ?? 'error desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        $untilTs = (int)($result['until_ts'] ?? 0);
        LogService::log('firewall.lockdown.start', $adminIp, "Lockdown temporal {$minutes} min");
        Flash::set('success', 'Lockdown temporal activo hasta ' . gmdate('Y-m-d H:i:s', $untilTs) . ' UTC.');
        header('Location: /settings/firewall');
        exit;
    }

    public function stopTemporaryLockdown(): void
    {
        View::verifyCsrf();
        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'desactivar lockdown temporal')) {
            exit;
        }

        $result = FirewallService::stopTemporaryLockdown();
        if (!($result['ok'] ?? false)) {
            Flash::set('error', 'No se pudo desactivar lockdown: ' . (string)($result['error'] ?? 'error desconocido'));
            header('Location: /settings/firewall');
            exit;
        }

        LogService::log('firewall.lockdown.stop', 'manual', 'Lockdown temporal desactivado');
        Flash::set('success', 'Lockdown temporal desactivado.');
        header('Location: /settings/firewall');
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
