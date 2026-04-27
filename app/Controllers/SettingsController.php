<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\FirewallService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\NotificationService;
use MuseDockPanel\Services\SecurityService;

class SettingsController
{
    // ================================================================
    // Services
    // ================================================================

    public function services(): void
    {
        $services = $this->getServicesStatus();
        $panelIds = array_column($services, 'id');
        // Also exclude aliases (mysql↔mariadb, redis↔redis-server, etc.)
        if (in_array('mysql', $panelIds) || in_array('mariadb', $panelIds)) {
            $panelIds = array_merge($panelIds, ['mysql', 'mariadb', 'mysqld']);
        }
        if (in_array('redis-server', $panelIds)) {
            $panelIds[] = 'redis';
        }
        $panelIds = array_unique($panelIds);
        $systemServices = $this->getAllSystemServices($panelIds);

        View::render('settings/services', [
            'layout' => 'main',
            'pageTitle' => 'Servicios del Servidor',
            'services' => $services,
            'systemServices' => $systemServices,
        ]);
    }

    public function serviceAction(): void
    {
        $service = $_POST['service'] ?? '';
        $action = $_POST['action'] ?? '';

        $allowed = $this->getAllowedServices();
        $isSystemService = false;

        if (!in_array($service, $allowed)) {
            // Check if it's a valid system service
            if (preg_match('/^[a-zA-Z0-9_@:.-]+\.service$/', $service) || preg_match('/^[a-zA-Z0-9_@:.-]+$/', $service)) {
                $svcFile = trim(shell_exec(sprintf('systemctl show %s --property=FragmentPath --value 2>/dev/null', escapeshellarg($service))) ?? '');
                if (empty($svcFile) || !file_exists($svcFile)) {
                    Flash::set('error', 'Servicio no encontrado.');
                    Router::redirect('/settings/services');
                    return;
                }
                // Block critical system services
                $critical = $this->getCriticalServices();
                $svcBase = preg_replace('/\.service$/', '', $service);
                if (in_array($svcBase, $critical)) {
                    Flash::set('error', 'No se puede modificar un servicio critico del sistema.');
                    Router::redirect('/settings/services');
                    return;
                }
                $isSystemService = true;
            } else {
                Flash::set('error', 'Servicio no permitido.');
                Router::redirect('/settings/services');
                return;
            }
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'];

        // Block reload for Caddy (it wipes API routes by reloading Caddyfile)
        if ($service === 'caddy' && $action === 'reload') {
            Flash::set('error', 'Reload de Caddy no permitido — borraria las rutas de los dominios. Usa Reiniciar en su lugar.');
            Router::redirect('/settings/services');
            return;
        }

        if (!in_array($action, $allowedActions)) {
            Flash::set('error', 'Accion no permitida.');
            Router::redirect('/settings/services');
            return;
        }

        $output = shell_exec(sprintf('systemctl %s %s 2>&1', escapeshellarg($action), escapeshellarg($service)));
        $status = trim(shell_exec(sprintf('systemctl is-active %s 2>/dev/null', escapeshellarg($service))) ?? '');

        LogService::log('service.' . $action, $service, "{$action} service: {$service} (status: {$status})");

        // After Caddy restart/start → auto-repair routes + update autosave
        if ($service === 'caddy' && in_array($action, ['restart', 'start'])) {
            sleep(2); // Wait for Caddy to fully start

            $repairScript = '';
            $candidates = [
                PANEL_ROOT . '/cli/repair-caddy-routes.php',
                '/var/www/vhosts/musedock.com/httpdocs/cli/repair-caddy-routes.php',
            ];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $repairScript = $candidate;
                    break;
                }
            }

            if ($repairScript !== '') {
                $phpBin = is_executable(PHP_BINARY) ? PHP_BINARY : '/usr/bin/php';
                $repairOutput = shell_exec(sprintf(
                    '%s %s 2>&1',
                    escapeshellarg($phpBin),
                    escapeshellarg($repairScript)
                )) ?? '';
                LogService::log('caddy.repair', 'auto', "Auto-repair after {$action}: {$repairScript}");
            } else {
                LogService::log('caddy.repair', 'auto', "Auto-repair skipped after {$action}: script not found");
            }

            // Update autosave
            $currentConfig = @file_get_contents('http://localhost:2019/config/');
            if ($currentConfig) {
                @file_put_contents('/var/lib/caddy/.config/caddy/autosave.json', $currentConfig);
            }
        }

        $actionLabels = ['start' => 'iniciado', 'stop' => 'detenido', 'restart' => 'reiniciado', 'reload' => 'recargado', 'enable' => 'habilitado', 'disable' => 'deshabilitado'];
        Flash::set('success', "Servicio {$service} {$actionLabels[$action]}. Estado actual: {$status}");
        Router::redirect('/settings/services');
    }

    private function getAllowedServices(): array
    {
        $services = ['caddy', 'redis-server', 'mysql', 'mariadb', 'postgresql'];

        // Add PHP-FPM services
        foreach (glob('/etc/php/*/fpm') as $fpmDir) {
            $ver = basename(dirname($fpmDir));
            $services[] = "php{$ver}-fpm";
        }

        // Supervisor
        if (file_exists('/etc/supervisor/supervisord.conf') || file_exists('/usr/bin/supervisord')) {
            $services[] = 'supervisor';
        }

        return $services;
    }

    private function getServicesStatus(): array
    {
        $serviceList = [
            'caddy' => ['name' => 'Caddy', 'icon' => 'bi-globe', 'desc' => 'Servidor web y proxy reverso con SSL automatico'],
            'redis-server' => ['name' => 'Redis', 'icon' => 'bi-lightning', 'desc' => 'Cache en memoria y sesiones'],
        ];

        // MySQL or MariaDB
        $mysqlActive = trim(shell_exec('systemctl is-active mysql 2>/dev/null') ?? '');
        $mariaActive = trim(shell_exec('systemctl is-active mariadb 2>/dev/null') ?? '');
        if ($mysqlActive === 'active' || file_exists('/lib/systemd/system/mysql.service')) {
            $serviceList['mysql'] = ['name' => 'MySQL', 'icon' => 'bi-database', 'desc' => 'Base de datos MySQL'];
        } elseif ($mariaActive === 'active' || file_exists('/lib/systemd/system/mariadb.service')) {
            $serviceList['mariadb'] = ['name' => 'MariaDB', 'icon' => 'bi-database', 'desc' => 'Base de datos MariaDB'];
        }

        // PostgreSQL
        $pgActive = trim(shell_exec('systemctl is-active postgresql 2>/dev/null') ?? '');
        if ($pgActive === 'active' || file_exists('/lib/systemd/system/postgresql.service')) {
            $serviceList['postgresql'] = ['name' => 'PostgreSQL', 'icon' => 'bi-database-gear', 'desc' => 'Base de datos PostgreSQL (panel)'];
        }

        // PHP-FPM versions
        foreach (glob('/etc/php/*/fpm') as $fpmDir) {
            $ver = basename(dirname($fpmDir));
            $svcName = "php{$ver}-fpm";
            $poolCount = count(glob("/etc/php/{$ver}/fpm/pool.d/*.conf"));
            $serviceList[$svcName] = ['name' => "PHP {$ver} FPM", 'icon' => 'bi-filetype-php', 'desc' => "{$poolCount} pool(s) activo(s)"];
        }

        // Supervisor
        if (file_exists('/usr/bin/supervisord') || file_exists('/etc/supervisor/supervisord.conf')) {
            $serviceList['supervisor'] = ['name' => 'Supervisor', 'icon' => 'bi-cpu', 'desc' => 'Gestor de procesos (queue workers)'];
        }

        // Get status for each
        $results = [];
        foreach ($serviceList as $svcId => $info) {
            $status = trim(shell_exec(sprintf('systemctl is-active %s 2>/dev/null', escapeshellarg($svcId))) ?? 'unknown');
            $enabled = trim(shell_exec(sprintf('systemctl is-enabled %s 2>/dev/null', escapeshellarg($svcId))) ?? 'unknown');
            $uptime = '';
            if ($status === 'active') {
                $uptimeRaw = trim(shell_exec(sprintf('systemctl show %s --property=ActiveEnterTimestamp --value 2>/dev/null', escapeshellarg($svcId))) ?? '');
                if (!empty($uptimeRaw)) {
                    $since = strtotime($uptimeRaw);
                    if ($since) {
                        $diff = time() - $since;
                        if ($diff < 3600) $uptime = round($diff / 60) . 'm';
                        elseif ($diff < 86400) $uptime = round($diff / 3600) . 'h';
                        else $uptime = round($diff / 86400) . 'd';
                    }
                }
            }
            $results[] = array_merge($info, [
                'id' => $svcId,
                'status' => $status,
                'enabled' => $enabled,
                'uptime' => $uptime,
            ]);
        }

        return $results;
    }

    /**
     * Critical services that cannot be stopped/disabled from the panel
     */
    private function getCriticalServices(): array
    {
        return [
            'systemd-journald', 'systemd-logind', 'systemd-udevd', 'systemd-resolved',
            'systemd-networkd', 'systemd-timesyncd', 'dbus', 'ssh', 'sshd',
            'networking', 'NetworkManager', 'init', 'getty@tty1',
        ];
    }

    /**
     * Get ALL systemd services on the system
     */
    private function getAllSystemServices(array $excludeIds = []): array
    {
        // Get list of all service unit files
        $output = shell_exec('systemctl list-unit-files --type=service --no-pager --no-legend 2>/dev/null') ?? '';
        $unitFiles = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (empty(trim($line))) continue;
            // Format: "service-name.service  enabled|disabled|static|masked  vendor-preset"
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2) {
                $svcName = $parts[0];
                $preset = $parts[1]; // enabled, disabled, static, masked, generated, alias, indirect
                $unitFiles[$svcName] = $preset;
            }
        }

        // Get active/inactive state for all services
        $output2 = shell_exec('systemctl list-units --type=service --all --no-pager --no-legend 2>/dev/null') ?? '';
        $activeStates = [];
        foreach (explode("\n", trim($output2)) as $line) {
            if (empty(trim($line))) continue;
            // Format: "● service-name.service  loaded  inactive dead   Description"
            // or:     "  service-name.service  loaded  active   running Description"
            if (preg_match('/\s*[●\s]*(\S+\.service)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)/', trim($line), $m)) {
                $activeStates[$m[1]] = [
                    'load' => $m[2],
                    'active' => $m[3],
                    'sub' => $m[4],
                    'description' => trim($m[5]),
                ];
            }
        }

        // Panel services to exclude (already shown above)
        $critical = $this->getCriticalServices();

        $results = [];
        foreach ($unitFiles as $svcFile => $preset) {
            // Skip non-service files, template units, and static/masked
            if (!str_ends_with($svcFile, '.service')) continue;
            if (str_contains($svcFile, '@') && !isset($activeStates[$svcFile])) continue;
            if (in_array($preset, ['static', 'masked', 'generated', 'alias', 'indirect'])) continue;
            // Normalize enabled-runtime to enabled
            if ($preset === 'enabled-runtime') $preset = 'enabled';

            $svcBase = preg_replace('/\.service$/', '', $svcFile);

            // Skip panel services (already shown in top section)
            if (in_array($svcBase, $excludeIds)) continue;
            // Also skip related panel services (caddy-api, mariadb@, redis-server@, etc.)
            $skipRelated = false;
            foreach ($excludeIds as $pid) {
                if ($svcBase !== $pid && str_starts_with($svcBase, $pid)) {
                    $skipRelated = true;
                    break;
                }
            }
            if ($skipRelated) continue;

            $isCritical = in_array($svcBase, $critical);
            $state = $activeStates[$svcFile] ?? null;
            $isActive = ($state['active'] ?? '') === 'active';
            $sub = $state['sub'] ?? '';
            $description = $state['description'] ?? '';

            $results[] = [
                'id' => $svcBase,
                'name' => $svcBase,
                'description' => $description,
                'status' => $isActive ? 'active' : 'inactive',
                'sub' => $sub,
                'enabled' => $preset, // enabled or disabled
                'uptime' => '',
                'critical' => $isCritical,
            ];
        }

        // Sort: active first, then alphabetical
        usort($results, function ($a, $b) {
            // Active before inactive
            if ($a['status'] !== $b['status']) {
                return $a['status'] === 'active' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $results;
    }

    // ================================================================
    // Cron Jobs
    // ================================================================

    public function crons(): void
    {
        $crons = $this->getAllCrons();

        View::render('settings/crons', [
            'layout' => 'main',
            'pageTitle' => 'Tareas Programadas (Cron)',
            'crons' => $crons,
        ]);
    }

    public function cronSave(): void
    {
        $user = trim($_POST['user'] ?? '');
        $schedule = trim($_POST['schedule'] ?? '');
        $command = trim($_POST['command'] ?? '');

        if (empty($user) || empty($schedule) || empty($command)) {
            Flash::set('error', 'Todos los campos son obligatorios.');
            Router::redirect('/settings/crons');
            return;
        }

        // Validate user exists
        $uid = trim(shell_exec("id -u " . escapeshellarg($user) . " 2>/dev/null") ?? '');
        if (empty($uid)) {
            Flash::set('error', "El usuario '{$user}' no existe.");
            Router::redirect('/settings/crons');
            return;
        }

        // Validate cron schedule format (5 fields)
        if (!preg_match('/^(\S+\s+){4}\S+$/', $schedule)) {
            Flash::set('error', 'Formato de schedule invalido. Usa 5 campos cron (ej: */5 * * * *).');
            Router::redirect('/settings/crons');
            return;
        }

        $cronLine = "{$schedule} {$command}";

        // Add to user's crontab
        $existing = trim(shell_exec(sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user))) ?? '');
        $lines = !empty($existing) ? explode("\n", $existing) : [];
        $lines[] = $cronLine;
        $newCrontab = implode("\n", $lines) . "\n";

        $tmpFile = tempnam('/tmp', 'cron_');
        file_put_contents($tmpFile, $newCrontab);
        shell_exec(sprintf('crontab -u %s %s 2>&1', escapeshellarg($user), escapeshellarg($tmpFile)));
        @unlink($tmpFile);

        LogService::log('cron.add', $user, "Added cron: {$cronLine}");
        Flash::set('success', "Cron anadido para {$user}: {$cronLine}");
        Router::redirect('/settings/crons');
    }

    public function cronUpdate(): void
    {
        $user = trim($_POST['user'] ?? '');
        $lineIndex = (int) ($_POST['line_index'] ?? -1);
        $schedule = trim($_POST['schedule'] ?? '');
        $command = trim($_POST['command'] ?? '');

        if (empty($user) || $lineIndex < 0 || empty($schedule) || empty($command)) {
            Flash::set('error', 'Todos los campos son obligatorios.');
            Router::redirect('/settings/crons');
            return;
        }

        // Validate cron schedule format (5 fields)
        if (!preg_match('/^(\S+\s+){4}\S+$/', $schedule)) {
            Flash::set('error', 'Formato de schedule invalido.');
            Router::redirect('/settings/crons');
            return;
        }

        $existing = trim(shell_exec(sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user))) ?? '');
        $lines = explode("\n", $existing);

        if (!isset($lines[$lineIndex])) {
            Flash::set('error', 'Linea no encontrada.');
            Router::redirect('/settings/crons');
            return;
        }

        $oldLine = $lines[$lineIndex];
        $newLine = "{$schedule} {$command}";
        $lines[$lineIndex] = $newLine;

        $tmpFile = tempnam('/tmp', 'cron_');
        file_put_contents($tmpFile, implode("\n", $lines) . "\n");
        shell_exec(sprintf('crontab -u %s %s 2>&1', escapeshellarg($user), escapeshellarg($tmpFile)));
        @unlink($tmpFile);

        LogService::log('cron.update', $user, "Updated cron line {$lineIndex}: {$newLine}");
        Flash::set('success', "Cron actualizado para {$user}.");
        Router::redirect('/settings/crons');
    }

    public function cronDelete(): void
    {
        $user = trim($_POST['user'] ?? '');
        $lineIndex = (int) ($_POST['line_index'] ?? -1);

        if (empty($user) || $lineIndex < 0) {
            Flash::set('error', 'Datos invalidos.');
            Router::redirect('/settings/crons');
            return;
        }

        $existing = trim(shell_exec(sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user))) ?? '');
        $lines = explode("\n", $existing);

        if (!isset($lines[$lineIndex])) {
            Flash::set('error', 'Linea no encontrada.');
            Router::redirect('/settings/crons');
            return;
        }

        $deleted = $lines[$lineIndex];
        unset($lines[$lineIndex]);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

        if (empty($lines)) {
            // Remove entire crontab
            shell_exec(sprintf('crontab -u %s -r 2>&1', escapeshellarg($user)));
        } else {
            $tmpFile = tempnam('/tmp', 'cron_');
            file_put_contents($tmpFile, implode("\n", $lines) . "\n");
            shell_exec(sprintf('crontab -u %s %s 2>&1', escapeshellarg($user), escapeshellarg($tmpFile)));
            @unlink($tmpFile);
        }

        LogService::log('cron.delete', $user, "Deleted cron: {$deleted}");
        Flash::set('success', "Cron eliminado de {$user}.");
        Router::redirect('/settings/crons');
    }

    public function cronExport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'exportar configuracion de cron', '/settings/crons')) {
            return;
        }

        $payload = [
            'type' => 'musedock-cron-config',
            'schema' => 1,
            'exported_at' => gmdate('c'),
            'panel_version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'host' => gethostname() ?: '',
            'managed_users' => $this->listManagedCronUsers(),
            'crons_by_user' => $this->getCronMapByUser(),
        ];

        LogService::log('cron.export', 'download', 'Export de configuracion cron');
        $this->streamJsonDownload($payload, 'musedock-cron-export');
    }

    public function cronImport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'importar configuracion de cron', '/settings/crons')) {
            return;
        }

        $payload = $this->readUploadedJsonFile('config_file', 2 * 1024 * 1024, '/settings/crons');
        if ($payload === null) {
            return;
        }

        $type = trim((string)($payload['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['musedock-cron-config', 'musedock-crons-config'], true)) {
            Flash::set('error', 'Archivo JSON no compatible con configuracion de Cron.');
            Router::redirect('/settings/crons');
            return;
        }

        $incomingMap = $this->extractCronMapFromPayload($payload);
        if (empty($incomingMap)) {
            Flash::set('error', 'No se encontraron tareas de cron validas en el archivo.');
            Router::redirect('/settings/crons');
            return;
        }

        $replace = isset($_POST['replace_existing']);
        $currentMap = $this->getCronMapByUser();
        $managedUsers = $this->listManagedCronUsers();
        $targetUsers = $replace ? $managedUsers : array_keys($incomingMap);

        $updatedUsers = [];
        $clearedUsers = [];
        $errors = [];

        foreach ($targetUsers as $user) {
            if (!$this->linuxUserExists($user)) {
                if (isset($incomingMap[$user])) {
                    $errors[] = "Usuario inexistente: {$user}";
                }
                continue;
            }

            if ($replace) {
                $newLines = $incomingMap[$user] ?? [];
            } else {
                if (!isset($incomingMap[$user])) {
                    continue;
                }
                $currentLines = $currentMap[$user] ?? [];
                $newLines = array_values(array_unique(array_merge($currentLines, $incomingMap[$user])));
            }

            $error = '';
            if (!$this->writeCrontabForUser($user, $newLines, $error)) {
                $errors[] = "{$user}: {$error}";
                continue;
            }

            if (empty($newLines)) {
                $clearedUsers[] = $user;
            } else {
                $updatedUsers[] = $user;
            }
        }

        if (!empty($errors)) {
            $msg = 'Importacion cron completada con errores: ' . implode(' | ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $msg .= ' ...';
            }
            Flash::set('warning', $msg);
        } else {
            $msg = $replace
                ? 'Importacion cron aplicada en modo replace.'
                : 'Importacion cron aplicada en modo append.';
            $msg .= ' Usuarios actualizados: ' . count($updatedUsers);
            if (!empty($clearedUsers)) {
                $msg .= ', limpiados: ' . count($clearedUsers);
            }
            Flash::set('success', $msg . '.');
        }

        LogService::log(
            'cron.import',
            $replace ? 'replace' : 'append',
            'Import cron: updated=' . count($updatedUsers) . ', cleared=' . count($clearedUsers) . ', errors=' . count($errors)
        );

        Router::redirect('/settings/crons');
    }

    private function getAllCrons(): array
    {
        $result = [];

        // Get all hosting users + root
        $db = \MuseDockPanel\Database::fetchAll("SELECT DISTINCT username FROM hosting_accounts ORDER BY username");
        $users = array_column($db, 'username');
        array_unshift($users, 'root');

        foreach ($users as $user) {
            $crontab = trim(shell_exec(sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user))) ?? '');
            if (empty($crontab)) continue;

            $lines = explode("\n", $crontab);
            foreach ($lines as $idx => $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') continue;

                // Parse: first 5 fields are schedule, rest is command
                if (preg_match('/^((?:\S+\s+){5})(.+)$/', $line, $m)) {
                    $result[] = [
                        'user' => $user,
                        'schedule' => trim($m[1]),
                        'command' => trim($m[2]),
                        'line_index' => $idx,
                        'raw' => $line,
                    ];
                }
            }
        }

        return $result;
    }

    private function listManagedCronUsers(): array
    {
        $db = \MuseDockPanel\Database::fetchAll("SELECT DISTINCT username FROM hosting_accounts ORDER BY username");
        $users = array_values(array_filter(array_map('strval', array_column($db, 'username'))));
        array_unshift($users, 'root');

        return array_values(array_unique($users));
    }

    private function getCronMapByUser(): array
    {
        $map = [];
        foreach ($this->listManagedCronUsers() as $user) {
            if (!$this->linuxUserExists($user)) {
                continue;
            }

            $crontab = trim((string)shell_exec(sprintf('crontab -u %s -l 2>/dev/null', escapeshellarg($user))));
            if ($crontab === '') {
                continue;
            }

            $lines = preg_split('/\r?\n/', $crontab) ?: [];
            $lines = array_values(array_filter(array_map(static function (string $line): string {
                return trim($line);
            }, $lines), static fn(string $line): bool => $line !== ''));

            if (!empty($lines)) {
                $map[$user] = $lines;
            }
        }

        return $map;
    }

    private function extractCronMapFromPayload(array $payload): array
    {
        $result = [];

        if (isset($payload['crons_by_user']) && is_array($payload['crons_by_user'])) {
            foreach ($payload['crons_by_user'] as $user => $lines) {
                $user = trim((string)$user);
                if ($user === '' || !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $user)) {
                    continue;
                }
                if (!is_array($lines)) {
                    continue;
                }
                $normalized = [];
                foreach ($lines as $line) {
                    $line = trim((string)$line);
                    if ($line !== '') {
                        $normalized[] = $line;
                    }
                }
                if (!empty($normalized)) {
                    $result[$user] = array_values(array_unique($normalized));
                }
            }

            return $result;
        }

        if (isset($payload['crons']) && is_array($payload['crons'])) {
            foreach ($payload['crons'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $user = trim((string)($item['user'] ?? ''));
                $schedule = trim((string)($item['schedule'] ?? ''));
                $command = trim((string)($item['command'] ?? ''));
                if ($user === '' || $schedule === '' || $command === '') {
                    continue;
                }
                if (!preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $user)) {
                    continue;
                }
                $line = $schedule . ' ' . $command;
                $result[$user] ??= [];
                $result[$user][] = $line;
            }
        }

        foreach ($result as $user => $lines) {
            $result[$user] = array_values(array_unique(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== '')));
            if (empty($result[$user])) {
                unset($result[$user]);
            }
        }

        return $result;
    }

    private function linuxUserExists(string $user): bool
    {
        $user = trim($user);
        if ($user === '') {
            return false;
        }

        $uid = trim((string)shell_exec("id -u " . escapeshellarg($user) . " 2>/dev/null"));
        return $uid !== '';
    }

    private function writeCrontabForUser(string $user, array $lines, string &$error = ''): bool
    {
        $user = trim($user);
        if ($user === '') {
            $error = 'usuario vacio';
            return false;
        }

        $normalized = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $normalized[] = $line;
            }
        }
        $normalized = array_values(array_unique($normalized));

        if (empty($normalized)) {
            $cmd = sprintf('crontab -u %s -r 2>&1', escapeshellarg($user));
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                $joined = trim(implode("\n", $out));
                if ($joined !== '' && !str_contains(strtolower($joined), 'no crontab for')) {
                    $error = $joined;
                    return false;
                }
            }
            return true;
        }

        $tmpFile = tempnam('/tmp', 'cron_import_');
        if ($tmpFile === false) {
            $error = 'no se pudo crear archivo temporal';
            return false;
        }

        file_put_contents($tmpFile, implode("\n", $normalized) . "\n");
        $cmd = sprintf('crontab -u %s %s 2>&1', escapeshellarg($user), escapeshellarg($tmpFile));
        exec($cmd, $out, $rc);
        @unlink($tmpFile);

        if ($rc !== 0) {
            $error = trim(implode("\n", $out)) ?: 'error desconocido';
            return false;
        }

        return true;
    }

    // ================================================================
    // Caddy Settings
    // ================================================================

    public function caddy(): void
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];

        $apiAvailable = false;
        $routes = [];
        $tlsPolicies = [];
        $rawConfig = '';

        // Check API availability
        $ch = curl_init("{$caddyApi}/config/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $configJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400 && $configJson) {
            $apiAvailable = true;
            $fullConfig = json_decode($configJson, true) ?: [];
            $rawConfig = json_encode($fullConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Parse routes
            $rawRoutes = $fullConfig['apps']['http']['servers']['srv0']['routes'] ?? [];
            foreach ($rawRoutes as $route) {
                $id = $route['@id'] ?? null;
                $terminal = $route['terminal'] ?? false;
                $hosts = [];
                foreach ($route['match'] ?? [] as $match) {
                    $hosts = array_merge($hosts, $match['host'] ?? []);
                }

                // Extract doc root and upstream from handlers
                $docRoot = null;
                $upstream = null;
                $this->extractCaddyRouteInfo($route['handle'] ?? [], $docRoot, $upstream);

                $routes[] = [
                    'id' => $id,
                    'hosts' => $hosts,
                    'terminal' => $terminal,
                    'doc_root' => $docRoot,
                    'upstream' => $upstream,
                ];
            }

            // Parse TLS policies
            $policies = $fullConfig['apps']['tls']['automation']['policies'] ?? [];
            foreach ($policies as $policy) {
                $subjects = $policy['subjects'] ?? [];
                $issuer = 'Desconocido';
                $challenge = 'auto';

                foreach ($policy['issuers'] ?? [] as $iss) {
                    $module = $iss['module'] ?? '';
                    if ($module === 'acme') {
                        $issuer = 'Let\'s Encrypt (ACME)';
                        if (isset($iss['challenges']['dns'])) {
                            $challenge = 'dns-01';
                            $provider = $iss['challenges']['dns']['provider']['name'] ?? '';
                            if ($provider) $issuer .= " + {$provider}";
                        } else {
                            $challenge = 'http-01';
                        }
                    } elseif ($module === 'zerossl') {
                        $issuer = 'ZeroSSL';
                        $challenge = 'http-01';
                    }
                    break; // first issuer is enough
                }

                $tlsPolicies[] = [
                    'subjects' => $subjects,
                    'issuer' => $issuer,
                    'challenge' => $challenge,
                ];
            }
        }

        View::render('settings/caddy', [
            'layout' => 'main',
            'pageTitle' => 'Caddy Web Server',
            'apiAvailable' => $apiAvailable,
            'routes' => $routes,
            'tlsPolicies' => $tlsPolicies,
            'rawConfig' => $rawConfig,
        ]);
    }

    public function caddyDeleteRoute(): void
    {
        $routeId = trim($_POST['route_id'] ?? '');
        if (empty($routeId)) {
            Flash::set('error', 'Route ID no especificado.');
            Router::redirect('/settings/caddy');
            return;
        }

        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];

        $ch = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            LogService::log('caddy.delete_route', $routeId, "Deleted Caddy route: {$routeId}");
            Flash::set('success', "Ruta '{$routeId}' eliminada de Caddy.");
        } else {
            Flash::set('error', "Error al eliminar la ruta: HTTP {$httpCode}");
        }

        Router::redirect('/settings/caddy');
    }

    public function caddyExport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'exportar configuracion de caddy', '/settings/caddy')) {
            return;
        }

        $fetched = $this->fetchCaddyConfig();
        if (!($fetched['ok'] ?? false)) {
            $msg = (string)($fetched['error'] ?? 'No se pudo leer la configuracion actual de Caddy.');
            Flash::set('error', $msg);
            Router::redirect('/settings/caddy');
            return;
        }

        $fullConfig = is_array($fetched['config'] ?? null) ? $fetched['config'] : [];
        $routes = $fullConfig['apps']['http']['servers']['srv0']['routes'] ?? [];
        $tlsPolicies = $fullConfig['apps']['tls']['automation']['policies'] ?? [];

        $payload = [
            'type' => 'musedock-caddy-config',
            'schema' => 1,
            'exported_at' => gmdate('c'),
            'panel_version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'host' => gethostname() ?: '',
            'meta' => [
                'route_count' => is_array($routes) ? count($routes) : 0,
                'tls_policy_count' => is_array($tlsPolicies) ? count($tlsPolicies) : 0,
            ],
            'caddy_config' => $fullConfig,
        ];

        LogService::log('caddy.export', 'download', 'Export de configuracion Caddy');
        $this->streamJsonDownload($payload, 'musedock-caddy-export');
    }

    public function caddyImport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'importar configuracion de caddy', '/settings/caddy')) {
            return;
        }

        $payload = $this->readUploadedJsonFile('config_file', 4 * 1024 * 1024, '/settings/caddy');
        if ($payload === null) {
            return;
        }

        $type = trim((string)($payload['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['musedock-caddy-config', 'musedock-caddy-export'], true)) {
            Flash::set('error', 'Archivo JSON no compatible con configuracion de Caddy.');
            Router::redirect('/settings/caddy');
            return;
        }

        if (!isset($_POST['replace_existing'])) {
            Flash::set('error', 'Debes confirmar la sobrescritura completa de configuracion para importar Caddy.');
            Router::redirect('/settings/caddy');
            return;
        }

        $configData = $payload['caddy_config'] ?? ($payload['config'] ?? null);
        if (!is_array($configData)) {
            Flash::set('error', 'El JSON no incluye `caddy_config` valido.');
            Router::redirect('/settings/caddy');
            return;
        }

        $applied = $this->loadCaddyConfig($configData);
        if (!($applied['ok'] ?? false)) {
            $msg = (string)($applied['error'] ?? 'Error desconocido al aplicar configuracion de Caddy.');
            Flash::set('error', $msg);
            Router::redirect('/settings/caddy');
            return;
        }

        LogService::log('caddy.import', 'replace', 'Import de configuracion Caddy aplicado');
        Flash::set('success', 'Configuracion de Caddy importada correctamente.');
        Router::redirect('/settings/caddy');
    }

    private function fetchCaddyConfig(): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = (string)($config['caddy']['api_url'] ?? 'http://localhost:2019');

        $ch = curl_init("{$caddyApi}/config/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 400) {
            return [
                'ok' => false,
                'error' => $error !== '' ? "Caddy API no accesible: {$error}" : "Caddy API respondio HTTP {$httpCode}",
                'http_code' => $httpCode,
                'config' => [],
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Respuesta JSON invalida de Caddy API.',
                'http_code' => $httpCode,
                'config' => [],
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'http_code' => $httpCode,
            'config' => $decoded,
        ];
    }

    private function loadCaddyConfig(array $configPayload): array
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = (string)($config['caddy']['api_url'] ?? 'http://localhost:2019');

        $json = json_encode($configPayload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'No se pudo serializar la configuracion JSON.'];
        }

        $ch = curl_init("{$caddyApi}/load");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            $detail = trim((string)$raw);
            if ($detail !== '' && strlen($detail) > 280) {
                $detail = substr($detail, 0, 280) . '...';
            }
            return [
                'ok' => false,
                'error' => $error !== ''
                    ? "Error Caddy API: {$error}"
                    : "Caddy API devolvio HTTP {$httpCode}" . ($detail !== '' ? " ({$detail})" : ''),
                'http_code' => $httpCode,
            ];
        }

        return ['ok' => true, 'error' => '', 'http_code' => $httpCode];
    }

    // ================================================================
    // Server Info & Panel URL
    // ================================================================

    public function server(): void
    {
        $settings = \MuseDockPanel\Settings::getAll();
        $panelHostnameForTls = $this->normalizePanelHostname((string)($settings['panel_hostname'] ?? ''));
        if ($panelHostnameForTls !== ''
            && $this->panelHostnameNeedsPublicTls($panelHostnameForTls)
            && (($settings['panel_tls_mode'] ?? 'self_signed') === 'self_signed')) {
            $settings['panel_tls_mode'] = 'http01';
            if (empty($settings['panel_acme_email'])) {
                $settings['panel_acme_email'] = $this->resolvePanelAcmeEmail('');
            }
        }
        $rebootNotifyEnabled = \MuseDockPanel\Settings::get('server_reboot_notify_enabled', '0') === '1';

        // Detect server info
        $hostname = trim(shell_exec('hostname') ?? '');
        $os = trim(shell_exec('uname -r') ?? '');
        $distro = trim(shell_exec('lsb_release -ds 2>/dev/null') ?? '');
        $uptime = trim(shell_exec("uptime -p 2>/dev/null | sed 's/up //'") ?? '');
        $currentTz = trim(shell_exec('timedatectl show --property=Timezone --value 2>/dev/null') ?? date_default_timezone_get());
        $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '');

        // Get all timezones
        $timezones = \DateTimeZone::listIdentifiers();

        // NTP info
        $ntpSynced = str_contains(shell_exec('timedatectl 2>/dev/null') ?? '', 'System clock synchronized: yes');
        $ntpActive = str_contains(shell_exec('timedatectl 2>/dev/null') ?? '', 'NTP service: active');
        $ntpServer = trim(shell_exec("systemctl show systemd-timesyncd --property=StatusText --value 2>/dev/null") ?? '');
        // Try to get the actual NTP server from timesyncd
        if (empty($ntpServer) || $ntpServer === '[not set]') {
            $ntpServer = trim(shell_exec("timedatectl timesync-status 2>/dev/null | grep 'Server:' | awk '{print \$2}'") ?? '');
        }

        // Panel access info
        $panelPort = \MuseDockPanel\Env::get('PANEL_PORT', '8444');
        $panelAcmeFirewallStatus = FirewallService::publicTcpPortStatus([80, 443]);

        View::render('settings/server', [
            'layout' => 'main',
            'pageTitle' => 'Servidor',
            'settings' => $settings,
            'hostname' => $hostname,
            'os' => $os,
            'distro' => $distro,
            'uptime' => $uptime,
            'currentTz' => $currentTz,
            'serverIp' => $serverIp,
            'panelPort' => $panelPort,
            'timezones' => $timezones,
            'ntpSynced' => $ntpSynced,
            'ntpActive' => $ntpActive,
            'ntpServer' => $ntpServer,
            'rebootNotifyEnabled' => $rebootNotifyEnabled,
            'panelAcmeFirewallStatus' => $panelAcmeFirewallStatus,
        ]);
    }

    public function serverSave(): void
    {
        $previousPanelHostname = $this->normalizePanelHostname((string)\MuseDockPanel\Settings::get('panel_hostname', ''));

        $timezone = trim($_POST['timezone'] ?? '');
        $panelHostnameRaw = trim($_POST['panel_hostname'] ?? '');
        $panelHostname = $this->normalizePanelHostname($panelHostnameRaw);
        $panelProtocol = trim($_POST['panel_protocol'] ?? 'http');
        $panelTlsMode = $this->normalizePanelTlsMode((string)($_POST['panel_tls_mode'] ?? \MuseDockPanel\Settings::get('panel_tls_mode', 'self_signed')));
        $panelDnsProvider = strtolower(trim((string)($_POST['panel_dns_provider'] ?? '')));
        $panelDnsProviderConfigRaw = trim((string)($_POST['panel_dns_provider_config'] ?? ''));
        $panelAcmeEmail = trim((string)($_POST['panel_acme_email'] ?? \MuseDockPanel\Settings::get('panel_acme_email', '')));
        $panelAcmeFirewallAssist = (string)($_POST['panel_acme_firewall_assist'] ?? '') === '1';
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $serverWarnings = [];

        if ($panelHostnameRaw !== '' && $panelHostname === '') {
            Flash::set('error', 'Dominio del panel invalido. Usa solo hostname (ej: panel.ejemplo.com), sin http:// ni /ruta.');
            Router::redirect('/settings/server');
            return;
        }

        if ($panelHostname !== '' && $panelTlsMode === 'self_signed' && $this->panelHostnameNeedsPublicTls($panelHostname)) {
            $panelTlsMode = 'http01';
            $panelAcmeEmail = $this->resolvePanelAcmeEmail($panelAcmeEmail);
            $serverWarnings[] = 'Dominio publico detectado: se cambio TLS del panel a Let\'s Encrypt HTTP-01/TLS-ALPN-01. Self-signed queda reservado para acceso por IP o hostnames privados.';
        }

        if (in_array($panelTlsMode, ['http01', 'dns01'], true)) {
            $panelAcmeEmail = $this->resolvePanelAcmeEmail($panelAcmeEmail);
            if ($panelAcmeEmail === '') {
                Flash::set('error', 'Email ACME requerido para modos HTTP-01/DNS-01.');
                Router::redirect('/settings/server');
                return;
            }
            if (!filter_var($panelAcmeEmail, FILTER_VALIDATE_EMAIL)) {
                Flash::set('error', 'Email ACME invalido.');
                Router::redirect('/settings/server');
                return;
            }
        } elseif ($panelAcmeEmail !== '' && !filter_var($panelAcmeEmail, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email ACME invalido.');
            Router::redirect('/settings/server');
            return;
        }

        $panelDnsProviderConfig = [];
        if ($panelTlsMode === 'dns01') {
            if ($panelHostname === '') {
                Flash::set('error', 'DNS-01 requiere dominio del panel configurado.');
                Router::redirect('/settings/server');
                return;
            }
            if ($panelDnsProvider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/i', $panelDnsProvider)) {
                Flash::set('error', 'Proveedor DNS invalido. Ejemplo: cloudflare, route53, digitalocean.');
                Router::redirect('/settings/server');
                return;
            }
            if ($panelDnsProviderConfigRaw === '') {
                Flash::set('error', 'DNS-01 requiere configuracion JSON del proveedor DNS.');
                Router::redirect('/settings/server');
                return;
            }
            $decoded = json_decode($panelDnsProviderConfigRaw, true);
            if (!is_array($decoded)) {
                Flash::set('error', 'JSON de configuracion DNS invalido.');
                Router::redirect('/settings/server');
                return;
            }
            $panelDnsProviderConfig = $decoded;
        }

        // Validate timezone
        if (!empty($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers())) {
            \MuseDockPanel\Settings::set('panel_timezone', $timezone);
            // Apply system timezone
            shell_exec(sprintf('timedatectl set-timezone %s 2>/dev/null', escapeshellarg($timezone)));
        }

        // Panel hostname (optional — for HTTPS with domain)
        \MuseDockPanel\Settings::set('panel_hostname', $panelHostname);
        \MuseDockPanel\Settings::set('panel_protocol', in_array($panelProtocol, ['http', 'https']) ? $panelProtocol : 'http');
        \MuseDockPanel\Settings::set('panel_tls_mode', $panelTlsMode);
        \MuseDockPanel\Settings::set('panel_dns_provider', $panelTlsMode === 'dns01' ? $panelDnsProvider : '');
        \MuseDockPanel\Settings::set(
            'panel_dns_provider_config',
            $panelTlsMode === 'dns01'
                ? json_encode($panelDnsProviderConfig, JSON_UNESCAPED_SLASHES)
                : ''
        );
        $panelAcmeEmailToStore = in_array($panelTlsMode, ['http01', 'dns01'], true) ? $panelAcmeEmail : '';
        \MuseDockPanel\Settings::set('panel_acme_email', $panelAcmeEmailToStore);

        if ($panelHostname !== '' && $panelTlsMode === 'http01' && $this->panelHostnameNeedsPublicTls($panelHostname)) {
            $firewallStatus = FirewallService::publicTcpPortStatus([80, 443]);
            $missingAcmePorts = array_values(array_filter(array_map('intval', $firewallStatus['missing'] ?? [])));
            if (!empty($missingAcmePorts)) {
                if ($panelAcmeFirewallAssist) {
                    if (!$this->verifyCurrentAdminPassword($adminPassword)) {
                        Flash::set('error', 'Contrasena de administrador incorrecta para abrir temporalmente puertos ACME.');
                        Router::redirect('/settings/server');
                        return;
                    }
                    $openResult = FirewallService::openTemporaryAcmePorts($missingAcmePorts, 30);
                    if ($openResult['ok'] ?? false) {
                        $opened = implode(', ', array_map('strval', $openResult['opened'] ?? []));
                        $serverWarnings[] = "Asistencia ACME: puertos {$opened} abiertos temporalmente durante 30 minutos para emitir certificado. Si usas HTTP-01, manten 80/443 publicos para renovaciones o cambia a DNS-01.";
                    } else {
                        $serverWarnings[] = 'No se pudieron abrir temporalmente puertos ACME: ' . (string)($openResult['output'] ?? 'error desconocido');
                    }
                } else {
                    $serverWarnings[] = 'Firewall: los puertos publicos ' . implode(', ', $missingAcmePorts) . ' no estan abiertos a Internet. Let\'s Encrypt HTTP-01/TLS-ALPN-01 puede fallar con timeout.';
                }
            }
        }

        // Detect and store server IP
        $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '');
        \MuseDockPanel\Settings::set('server_ip', $serverIp);

        $routeApplied = false;
        if ($panelHostname !== '') {
            $result = \MuseDockPanel\Services\SystemService::configurePanelDomainRoute($panelHostname);
            if ($result['ok'] ?? false) {
                $routeApplied = !empty($result['applied']);
                if (!empty($result['warning'])) {
                    $serverWarnings[] = (string)$result['warning'];
                }
            } else {
                $serverWarnings[] = 'Configuracion guardada, pero no se pudo activar HTTPS automatico en Caddy: ' . (string)($result['error'] ?? 'error desconocido');
            }
        } else {
            \MuseDockPanel\Services\SystemService::removePanelDomainRoute($previousPanelHostname);
        }

        LogService::log('settings.server', 'server', "Updated server settings: tz={$timezone}, hostname={$panelHostname}, protocol={$panelProtocol}, tls_mode={$panelTlsMode}");
        $panelPort = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
        if ($routeApplied) {
            Flash::set('success', "Configuracion guardada. Acceso recomendado: https://{$panelHostname}:{$panelPort}/");
        } else {
            Flash::set('success', 'Configuracion del servidor guardada.');
        }
        if (!empty($serverWarnings)) {
            Flash::set('warning', implode(' ', array_values(array_unique($serverWarnings))));
        }
        Router::redirect('/settings/server');
    }

    private function normalizePanelHostname(string $value): string
    {
        $host = strtolower(trim($value));
        if ($host === '') {
            return '';
        }

        $host = preg_replace('#^https?://#i', '', $host);
        $host = explode('/', $host)[0] ?? '';
        $host = preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');

        if ($host === '' || str_contains($host, ' ')) {
            return '';
        }

        // Require a real FQDN (at least one dot). Keep it strict for TLS automation.
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host)) {
            return '';
        }

        return $host;
    }

    private function normalizePanelTlsMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['self_signed', 'http01', 'dns01'], true) ? $mode : 'self_signed';
    }

    private function panelHostnameNeedsPublicTls(string $hostname): bool
    {
        $host = strtolower(trim($hostname));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (['.local', '.localhost', '.lan', '.internal', '.test'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        return str_contains($host, '.');
    }

    private function resolvePanelAcmeEmail(string $candidate): string
    {
        $candidates = [
            $candidate,
            (string)\MuseDockPanel\Settings::get('panel_acme_email', ''),
            (string)\MuseDockPanel\Settings::get('notify_smtp_from', ''),
            (string)\MuseDockPanel\Settings::get('notify_email_to', ''),
            (string)\MuseDockPanel\Settings::get('mail_from_address', ''),
            NotificationService::getAdminEmail(),
            'admin@musedock.com',
        ];

        foreach ($candidates as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    // ================================================================
    // PHP Settings
    // ================================================================

    public function php(): void
    {
        $versions = [];
        foreach (glob('/etc/php/*/fpm') as $fpmDir) {
            $ver = basename(dirname($fpmDir));
            $phpBin = "/usr/bin/php{$ver}";
            $iniFile = "/etc/php/{$ver}/fpm/php.ini";
            $cliIniFile = "/etc/php/{$ver}/cli/php.ini";

            // Read key php.ini values
            $ini = [];
            if (file_exists($iniFile)) {
                $iniContent = file_get_contents($iniFile);
                $iniKeys = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_time', 'max_input_vars', 'date.timezone', 'display_errors', 'error_reporting'];
                foreach ($iniKeys as $key) {
                    if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $iniContent, $m)) {
                        $ini[$key] = trim($m[1]);
                    }
                }
            }

            // Count FPM pools
            $poolCount = count(glob("/etc/php/{$ver}/fpm/pool.d/*.conf"));

            // Service status
            $svcName = "php{$ver}-fpm";
            $status = trim(shell_exec(sprintf('systemctl is-active %s 2>/dev/null', escapeshellarg($svcName))) ?? 'unknown');

            // Extensions
            $extList = [];
            $extOutput = trim(shell_exec("{$phpBin} -m 2>/dev/null") ?? '');
            if (!empty($extOutput)) {
                $extList = array_filter(explode("\n", $extOutput), fn($l) => !empty(trim($l)) && $l[0] !== '[');
            }

            // Read OPcache config
            $opcacheFile = "/etc/php/{$ver}/fpm/conf.d/10-opcache.ini";
            $opcache = [
                'opcache.enable' => '1',
                'opcache.memory_consumption' => '128',
                'opcache.interned_strings_buffer' => '8',
                'opcache.max_accelerated_files' => '10000',
                'opcache.revalidate_freq' => '2',
                'opcache.jit' => 'off',
                'opcache.jit_buffer_size' => '0',
            ];
            // Try multiple locations for opcache config
            $opcacheFiles = [
                "/etc/php/{$ver}/fpm/conf.d/10-opcache.ini",
                "/etc/php/{$ver}/mods-available/opcache.ini",
            ];
            $opcacheConfigFile = '';
            foreach ($opcacheFiles as $of) {
                if (file_exists($of)) {
                    $opcacheConfigFile = $of;
                    $ocContent = file_get_contents($of);
                    foreach ($opcache as $key => $default) {
                        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $ocContent, $m)) {
                            $opcache[$key] = trim($m[1]);
                        }
                    }
                    break;
                }
            }

            $versions[$ver] = [
                'version' => $ver,
                'binary' => $phpBin,
                'ini_file' => $iniFile,
                'ini' => $ini,
                'pools' => $poolCount,
                'status' => $status,
                'extensions' => $extList,
                'opcache' => $opcache,
                'opcache_file' => $opcacheConfigFile,
            ];
        }

        // Sort by version
        uksort($versions, 'version_compare');

        View::render('settings/php', [
            'layout' => 'main',
            'pageTitle' => 'PHP Settings',
            'versions' => $versions,
        ]);
    }

    public function phpIniSave(): void
    {
        $ver = trim($_POST['version'] ?? '');
        $allowedVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        if (!in_array($ver, $allowedVersions)) {
            Flash::set('error', 'Version de PHP no valida.');
            Router::redirect('/settings/php');
            return;
        }

        $iniFile = "/etc/php/{$ver}/fpm/php.ini";
        if (!file_exists($iniFile)) {
            Flash::set('error', "php.ini no encontrado para PHP {$ver}.");
            Router::redirect('/settings/php');
            return;
        }

        $content = file_get_contents($iniFile);
        $changes = [];

        $editableKeys = [
            'memory_limit' => '/^\d+[MmGg]?$/',
            'upload_max_filesize' => '/^\d+[MmGg]?$/',
            'post_max_size' => '/^\d+[MmGg]?$/',
            'max_execution_time' => '/^\d+$/',
            'max_input_time' => '/^-?\d+$/',
            'max_input_vars' => '/^\d+$/',
            'display_errors' => '/^(On|Off)$/i',
        ];

        foreach ($editableKeys as $key => $pattern) {
            if (isset($_POST[$key])) {
                $value = trim($_POST[$key]);
                if (!preg_match($pattern, $value)) continue;

                // Replace in php.ini
                $escaped = preg_quote($key, '/');
                if (preg_match('/^\s*' . $escaped . '\s*=/m', $content)) {
                    $content = preg_replace('/^\s*' . $escaped . '\s*=.*/m', "{$key} = {$value}", $content);
                } else {
                    $content .= "\n{$key} = {$value}\n";
                }
                $changes[] = "{$key}={$value}";
            }
        }

        if (!empty($changes)) {
            file_put_contents($iniFile, $content);
            // Restart FPM to apply
            $svcName = "php{$ver}-fpm";
            shell_exec(sprintf('systemctl restart %s 2>&1', escapeshellarg($svcName)));
            LogService::log('settings.php', "php{$ver}", "Updated php.ini: " . implode(', ', $changes));
            Flash::set('success', "php.ini de PHP {$ver} actualizado. FPM reiniciado.");
        } else {
            Flash::set('warning', 'No se realizaron cambios.');
        }

        Router::redirect('/settings/php');
    }

    /**
     * POST /settings/php/opcache-save — Save OPcache + JIT configuration
     */
    public function phpOpcacheSave(): void
    {
        $ver = trim($_POST['version'] ?? '');
        $allowedVersions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
        if (!in_array($ver, $allowedVersions)) {
            Flash::set('error', 'Version de PHP no valida.');
            Router::redirect('/settings/php');
            return;
        }

        $opcacheFile = "/etc/php/{$ver}/fpm/conf.d/10-opcache.ini";

        // If file doesn't exist, try mods-available
        if (!file_exists($opcacheFile)) {
            $modsFile = "/etc/php/{$ver}/mods-available/opcache.ini";
            if (file_exists($modsFile)) {
                $opcacheFile = $modsFile;
            } else {
                // Create it
                @mkdir(dirname($opcacheFile), 0755, true);
            }
        }

        $settings = [
            'opcache.enable' => '/^[01]$/',
            'opcache.memory_consumption' => '/^\d+$/',
            'opcache.interned_strings_buffer' => '/^\d+$/',
            'opcache.max_accelerated_files' => '/^\d+$/',
            'opcache.revalidate_freq' => '/^\d+$/',
            'opcache.jit' => '/^(off|on|tracing|function|\d+)$/i',
            'opcache.jit_buffer_size' => '/^\d+[MmGg]?$/',
        ];

        $lines = ["zend_extension=opcache.so"];
        $changes = [];

        foreach ($settings as $key => $pattern) {
            $value = trim($_POST[str_replace('.', '_', $key)] ?? '');
            if (empty($value)) continue;
            if (!preg_match($pattern, $value)) continue;
            $lines[] = "{$key}={$value}";
            $changes[] = "{$key}={$value}";
        }

        if (!empty($changes)) {
            file_put_contents($opcacheFile, implode("\n", $lines) . "\n");

            $svcName = "php{$ver}-fpm";
            shell_exec(sprintf('systemctl restart %s 2>&1', escapeshellarg($svcName)));

            LogService::log('settings.opcache', "php{$ver}", "OPcache config updated: " . implode(', ', $changes));
            Flash::set('success', "OPcache de PHP {$ver} actualizado. FPM reiniciado.");
        } else {
            Flash::set('warning', 'No se realizaron cambios.');
        }

        Router::redirect('/settings/php');
    }

    // ================================================================
    // Security
    // ================================================================

    public function security(): void
    {
        $allowedIps = \MuseDockPanel\Env::get('ALLOWED_IPS', '');

        // Active sessions
        $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
        $sessionCount = count(glob("{$sessionPath}/sess_*"));

        $hardening = SecurityService::getHardeningAudit();
        $expectedPortsRaw = Settings::get('security_expected_public_tcp_ports', '22,80,443,8444');
        $mfaRequired = Settings::get('security_mfa_required', '0') === '1';
        $mfaStats = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN mfa_enabled = true AND mfa_secret IS NOT NULL AND mfa_secret != '' THEN 1 ELSE 0 END) AS enrolled
             FROM panel_admins
             WHERE is_active = true"
        );

        View::render('settings/security', [
            'layout' => 'main',
            'pageTitle' => 'Seguridad',
            'allowedIps' => $allowedIps,
            'sessionCount' => $sessionCount,
            'hardening' => $hardening,
            'expectedPublicPorts' => $expectedPortsRaw,
            'mfaRequired' => $mfaRequired,
            'mfaActiveAdmins' => (int)($mfaStats['total'] ?? 0),
            'mfaEnrolledAdmins' => (int)($mfaStats['enrolled'] ?? 0),
        ]);
    }

    public function securitySave(): void
    {
        View::verifyCsrf();

        $allowedIps = trim($_POST['allowed_ips'] ?? '');
        $expectedPortsRaw = trim((string)($_POST['security_expected_public_tcp_ports'] ?? '22,80,443,8444'));
        $mfaRequired = isset($_POST['security_mfa_required']);

        // Validate IPs format
        if (!empty($allowedIps)) {
            $ips = array_map('trim', explode(',', $allowedIps));
            foreach ($ips as $ip) {
                // Allow IP or CIDR
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $ip)) {
                    Flash::set('error', "IP invalida: {$ip}");
                    Router::redirect('/settings/security');
                    return;
                }
            }
        }

        $ports = SecurityService::parseExpectedPublicPorts($expectedPortsRaw);
        if (empty($ports)) {
            Flash::set('error', 'Debes indicar al menos un puerto publico esperado.');
            Router::redirect('/settings/security');
            return;
        }

        if ($mfaRequired) {
            $mfaStats = Database::fetchOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN mfa_enabled = true AND mfa_secret IS NOT NULL AND mfa_secret != '' THEN 1 ELSE 0 END) AS enrolled
                 FROM panel_admins
                 WHERE is_active = true"
            );
            $total = (int)($mfaStats['total'] ?? 0);
            $enrolled = (int)($mfaStats['enrolled'] ?? 0);
            if ($total > 0 && $enrolled < $total) {
                Flash::set('error', "No puedes activar MFA obligatorio: solo {$enrolled}/{$total} admins tienen MFA activa.");
                Router::redirect('/settings/security');
                return;
            }
        }

        // Update .env
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/^ALLOWED_IPS=.*/m', $envContent)) {
                $envContent = preg_replace('/^ALLOWED_IPS=.*/m', "ALLOWED_IPS={$allowedIps}", $envContent);
            } else {
                $envContent .= "\nALLOWED_IPS={$allowedIps}\n";
            }
            file_put_contents($envFile, $envContent);
        }

        Settings::set('security_expected_public_tcp_ports', implode(',', $ports));
        Settings::set('security_mfa_required', $mfaRequired ? '1' : '0');

        LogService::log(
            'settings.security',
            'baseline',
            "Updated ALLOWED_IPS={$allowedIps}; expected_ports=" . implode(',', $ports) . '; mfa_required=' . ($mfaRequired ? '1' : '0')
        );
        Flash::set('success', 'Configuracion de seguridad guardada.');
        Router::redirect('/settings/security');
    }

    public function securityHardeningFix(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyCurrentAdminPassword($password)) {
            Flash::set('error', 'Contrasena de administrador incorrecta.');
            Router::redirect('/settings/security');
            return;
        }

        $result = SecurityService::applyRecommendedHardening();
        $ok = (bool)($result['ok'] ?? false);
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
        $summary = [];
        foreach ($steps as $s) {
            $summary[] = (string)($s['name'] ?? 'paso') . ':' . (!empty($s['ok']) ? 'ok' : 'fail');
        }

        LogService::log('settings.security.hardening.fix', 'host', implode(', ', $summary));
        if ($ok) {
            Flash::set('success', 'Hardening aplicado correctamente.');
        } else {
            Flash::set('warning', 'Hardening aplicado con incidencias. Revisa el estado en la auditoria.');
        }

        Router::redirect('/settings/security');
    }

    private function verifyCurrentAdminPassword(string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }

        $adminId = (int)($_SESSION['panel_user']['id'] ?? 0);
        if ($adminId < 1) {
            return false;
        }

        $admin = Database::fetchOne(
            "SELECT password_hash FROM panel_admins WHERE id = :id",
            ['id' => $adminId]
        );
        if (!$admin) {
            return false;
        }

        return password_verify($password, (string)($admin['password_hash'] ?? ''));
    }

    private function verifyAdminPasswordOrRedirect(string $password, string $actionLabel, string $redirectPath): bool
    {
        if ($this->verifyCurrentAdminPassword($password)) {
            return true;
        }

        Flash::set('error', 'Contrasena de administrador incorrecta para ' . $actionLabel . '.');
        Router::redirect($redirectPath);
        return false;
    }

    private function streamJsonDownload(array $data, string $prefix): void
    {
        $filename = $prefix . '-' . gmdate('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private function readUploadedJsonFile(string $field, int $maxSizeBytes, string $redirectPath): ?array
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            Flash::set('error', 'Debes seleccionar un archivo JSON para importar.');
            Router::redirect($redirectPath);
            return null;
        }

        $file = $_FILES[$field];
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            Flash::set('error', 'Error de subida de archivo.');
            Router::redirect($redirectPath);
            return null;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size < 2 || $size > $maxSizeBytes) {
            $maxMb = max(1, (int)floor($maxSizeBytes / (1024 * 1024)));
            Flash::set('error', "Archivo invalido: maximo {$maxMb}MB.");
            Router::redirect($redirectPath);
            return null;
        }

        $raw = (string)file_get_contents($tmpPath);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Flash::set('error', 'JSON invalido en archivo de importacion.');
            Router::redirect($redirectPath);
            return null;
        }

        return $payload;
    }

    private function readFail2BanWhitelist(): array
    {
        $whitelist = [];
        $jailLocal = '/etc/fail2ban/jail.local';
        if (file_exists($jailLocal)) {
            $content = (string)file_get_contents($jailLocal);
            if (preg_match('/^ignoreip\s*=\s*(.+)$/m', $content, $m)) {
                $whitelist = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$m[1]) ?: [])));
            }
        }

        return $this->normalizeFail2BanWhitelist($whitelist);
    }

    private function normalizeFail2BanWhitelist(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $entry = trim((string)$entry);
            if ($entry === '') {
                continue;
            }
            if ($this->isValidIpOrCidr($entry)) {
                $normalized[] = $entry;
            }
        }

        $normalized = array_values(array_unique($normalized));
        foreach (['127.0.0.1/8', '::1'] as $defaultEntry) {
            if (!in_array($defaultEntry, $normalized, true)) {
                array_unshift($normalized, $defaultEntry);
            }
        }

        return array_values(array_unique($normalized));
    }

    private function isValidIpOrCidr(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $mask] = explode('/', $value, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !is_numeric($mask)) {
            return false;
        }

        $maskInt = (int)$mask;
        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        return $maskInt >= 0 && $maskInt <= $max;
    }

    private function writeFail2BanWhitelist(array $entries, string &$error = ''): bool
    {
        $normalized = $this->normalizeFail2BanWhitelist($entries);
        $content = "[DEFAULT]\nignoreip = " . implode(' ', $normalized) . "\n";
        return $this->writeTextFile('/etc/fail2ban/jail.local', $content, $error);
    }

    private function writeTextFile(string $path, string $content, string &$error = ''): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = 'no se pudo crear directorio: ' . $dir;
            return false;
        }

        $written = @file_put_contents($path, $content);
        if ($written === false) {
            $error = 'no se pudo escribir ' . $path;
            return false;
        }

        return true;
    }

    private function isSafeFail2BanFilterName(string $filename): bool
    {
        if (!preg_match('/^[a-z0-9._-]+$/i', $filename)) {
            return false;
        }
        if (!str_ends_with(strtolower($filename), '.conf')) {
            return false;
        }
        if (!str_starts_with(strtolower($filename), 'musedock')) {
            return false;
        }
        return !str_contains($filename, '..') && !str_contains($filename, '/');
    }

    /**
     * POST /settings/security/pg-ssl-enable — Enable SSL on PostgreSQL
     */
    public function pgSslEnable(): void
    {
        View::verifyCsrf();

        // Find PostgreSQL config
        $pgConfFile = '';
        $pgVersion = '';
        foreach (['14', '15', '16', '17'] as $v) {
            $f = "/etc/postgresql/{$v}/main/postgresql.conf";
            if (file_exists($f)) { $pgConfFile = $f; $pgVersion = $v; break; }
        }

        if (!$pgConfFile) {
            Flash::set('error', 'No se detecto PostgreSQL instalado.');
            Router::redirect('/settings/security');
            return;
        }

        // Generate self-signed SSL certificate if not exists
        $sslDir = '/etc/postgresql/ssl';
        $certFile = "{$sslDir}/server.crt";
        $keyFile = "{$sslDir}/server.key";

        if (!file_exists($certFile)) {
            @mkdir($sslDir, 0700, true);
            $hostname = gethostname() ?: 'localhost';
            shell_exec(sprintf(
                'openssl req -new -x509 -days 3650 -nodes -out %s -keyout %s -subj "/CN=%s" 2>&1',
                escapeshellarg($certFile),
                escapeshellarg($keyFile),
                escapeshellarg($hostname)
            ));
            // PostgreSQL needs specific permissions
            shell_exec("chown postgres:postgres {$sslDir}/*");
            shell_exec("chmod 600 {$keyFile}");
            shell_exec("chmod 644 {$certFile}");
        }

        if (!file_exists($certFile)) {
            Flash::set('error', 'Error al generar certificado SSL.');
            Router::redirect('/settings/security');
            return;
        }

        // Update postgresql.conf
        $conf = file_get_contents($pgConfFile);
        if (preg_match('/^\s*ssl\s*=\s*off/m', $conf)) {
            $conf = preg_replace('/^\s*ssl\s*=\s*off/m', 'ssl = on', $conf);
        } elseif (!preg_match('/^\s*ssl\s*=\s*on/m', $conf)) {
            $conf .= "\n# SSL enabled by MuseDock Panel\nssl = on\n";
        }

        // Set cert paths if not already set
        if (!preg_match('/^\s*ssl_cert_file\s*=/m', $conf)) {
            $conf .= "ssl_cert_file = '{$certFile}'\n";
        } else {
            $conf = preg_replace('/^\s*ssl_cert_file\s*=.*/m', "ssl_cert_file = '{$certFile}'", $conf);
        }
        if (!preg_match('/^\s*ssl_key_file\s*=/m', $conf)) {
            $conf .= "ssl_key_file = '{$keyFile}'\n";
        } else {
            $conf = preg_replace('/^\s*ssl_key_file\s*=.*/m', "ssl_key_file = '{$keyFile}'", $conf);
        }

        file_put_contents($pgConfFile, $conf);

        // Restart PostgreSQL
        shell_exec("systemctl restart postgresql 2>&1");

        // Optionally update all hosting .env files
        if (!empty($_POST['update_envs'])) {
            $accounts = Database::fetchAll("SELECT id, home_dir, document_root FROM hosting_accounts WHERE status = 'active'");
            $updated = 0;
            foreach ($accounts as $acc) {
                // Check main document_root and parent (for Laravel /public)
                $dirs = [$acc['document_root']];
                if (str_ends_with($acc['document_root'], '/public')) {
                    $dirs[] = dirname($acc['document_root']);
                }
                foreach ($dirs as $dir) {
                    $envFile = $dir . '/.env';
                    if (!file_exists($envFile)) continue;
                    $env = file_get_contents($envFile);
                    if (str_contains($env, 'DB_SSLMODE')) {
                        $env = preg_replace('/^DB_SSLMODE=.*/m', 'DB_SSLMODE=prefer', $env);
                        file_put_contents($envFile, $env);
                        $updated++;
                    }
                    break;
                }
            }
            LogService::log('settings.pg_ssl', 'enable', "SSL enabled + {$updated} .env files updated to DB_SSLMODE=prefer");
            Flash::set('success', "SSL activado en PostgreSQL. {$updated} archivos .env actualizados.");
        } else {
            LogService::log('settings.pg_ssl', 'enable', 'PostgreSQL SSL enabled');
            Flash::set('success', 'SSL activado en PostgreSQL.');
        }

        Router::redirect('/settings/security');
    }

    /**
     * POST /settings/security/pg-ssl-disable — Disable SSL on PostgreSQL
     */
    public function pgSslDisable(): void
    {
        View::verifyCsrf();

        foreach (['14', '15', '16', '17'] as $v) {
            $f = "/etc/postgresql/{$v}/main/postgresql.conf";
            if (!file_exists($f)) continue;

            $conf = file_get_contents($f);
            $conf = preg_replace('/^\s*ssl\s*=\s*on/m', 'ssl = off', $conf);
            file_put_contents($f, $conf);
            break;
        }

        shell_exec("systemctl restart postgresql 2>&1");

        LogService::log('settings.pg_ssl', 'disable', 'PostgreSQL SSL disabled');
        Flash::set('success', 'SSL desactivado en PostgreSQL.');
        Router::redirect('/settings/security');
    }

    // ================================================================
    // Fail2Ban
    // ================================================================

    public function fail2ban(): void
    {
        $installed = !empty(trim(shell_exec('command -v fail2ban-client 2>/dev/null') ?? ''));

        if (!$installed) {
            View::render('settings/fail2ban', [
                'layout' => 'main',
                'pageTitle' => 'Fail2Ban',
                'installed' => false,
                'serviceStatus' => null,
                'serviceUptime' => '',
                'jails' => [],
            ]);
            return;
        }

        // Service status
        $serviceStatus = trim(shell_exec('systemctl is-active fail2ban 2>/dev/null') ?? 'unknown');
        $serviceUptime = '';
        if ($serviceStatus === 'active') {
            $uptimeRaw = trim(shell_exec('systemctl show fail2ban --property=ActiveEnterTimestamp --value 2>/dev/null') ?? '');
            if (!empty($uptimeRaw)) {
                $since = strtotime($uptimeRaw);
                if ($since) {
                    $diff = time() - $since;
                    if ($diff < 3600) $serviceUptime = round($diff / 60) . ' min';
                    elseif ($diff < 86400) $serviceUptime = round($diff / 3600, 1) . ' horas';
                    else $serviceUptime = round($diff / 86400, 1) . ' dias';
                }
            }
        }

        // Get jail list
        $jails = [];
        $statusOutput = trim(shell_exec('fail2ban-client status 2>/dev/null') ?? '');
        $jailNames = [];
        if (preg_match('/Jail list:\s*(.+)$/mi', $statusOutput, $m)) {
            $jailNames = array_map('trim', explode(',', $m[1]));
            $jailNames = array_filter($jailNames, fn($j) => !empty($j));
        }

        foreach ($jailNames as $jailName) {
            $jailOutput = trim(shell_exec(sprintf('fail2ban-client status %s 2>/dev/null', escapeshellarg($jailName))) ?? '');

            $currentlyBanned = 0;
            $totalBanned = 0;
            $totalFailed = 0;
            $bannedIps = [];

            // Parse "Currently banned" count
            if (preg_match('/Currently banned:\s*(\d+)/i', $jailOutput, $m)) {
                $currentlyBanned = (int) $m[1];
            }
            // Parse "Total banned" count
            if (preg_match('/Total banned:\s*(\d+)/i', $jailOutput, $m)) {
                $totalBanned = (int) $m[1];
            }
            // Parse "Total failed" count (Currently failed also exists but Total is more useful)
            if (preg_match('/Total failed:\s*(\d+)/i', $jailOutput, $m)) {
                $totalFailed = (int) $m[1];
            }
            // Parse banned IP list
            if (preg_match('/Banned IP list:\s*(.*)$/mi', $jailOutput, $m)) {
                $ipList = trim($m[1]);
                if (!empty($ipList)) {
                    $bannedIps = preg_split('/\s+/', $ipList);
                    $bannedIps = array_filter($bannedIps, fn($ip) => !empty($ip));
                }
            }

            // Fetch jail settings
            $maxretry = (int)trim(shell_exec(sprintf('fail2ban-client get %s maxretry 2>/dev/null', escapeshellarg($jailName))) ?? '0');
            $findtime = (int)trim(shell_exec(sprintf('fail2ban-client get %s findtime 2>/dev/null', escapeshellarg($jailName))) ?? '0');
            $bantime = (int)trim(shell_exec(sprintf('fail2ban-client get %s bantime 2>/dev/null', escapeshellarg($jailName))) ?? '0');

            $jails[] = [
                'name' => $jailName,
                'currently_banned' => $currentlyBanned,
                'total_banned' => $totalBanned,
                'total_failed' => $totalFailed,
                'banned_ips' => array_values($bannedIps),
                'maxretry' => $maxretry,
                'findtime' => $findtime,
                'bantime' => $bantime,
            ];
        }

        // Read whitelist (ignoreip) from jail.local
        $whitelist = [];
        $jailLocal = '/etc/fail2ban/jail.local';
        if (file_exists($jailLocal)) {
            $jlContent = file_get_contents($jailLocal);
            if (preg_match('/^ignoreip\s*=\s*(.+)$/m', $jlContent, $m)) {
                $whitelist = array_map('trim', preg_split('/[\s,]+/', $m[1]));
                $whitelist = array_values(array_filter($whitelist, fn($v) => !empty($v)));
            }
        }

        View::render('settings/fail2ban', [
            'layout' => 'main',
            'pageTitle' => 'Fail2Ban',
            'installed' => true,
            'serviceStatus' => $serviceStatus,
            'serviceUptime' => $serviceUptime,
            'jails' => $jails,
            'whitelist' => $whitelist,
        ]);
    }

    public function fail2banUnban(): void
    {
        $jail = trim($_POST['jail'] ?? '');
        $ip = trim($_POST['ip'] ?? '');

        // Validate jail name
        if (empty($jail) || !preg_match('/^[a-zA-Z0-9_-]+$/', $jail)) {
            Flash::set('error', 'Nombre de jail invalido.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        // Validate IP
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'Direccion IP invalida.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        $output = trim(shell_exec(sprintf(
            'fail2ban-client set %s unbanip %s 2>&1',
            escapeshellarg($jail),
            escapeshellarg($ip)
        )) ?? '');

        LogService::log('fail2ban.unban', $jail, "Unban IP {$ip} from jail {$jail}: {$output}");

        // fail2ban-client returns the IP on success, or an error message
        if (str_contains($output, 'is not banned') || str_contains($output, 'ERROR') || str_contains($output, 'NOK')) {
            Flash::set('error', "Error al desbanear {$ip} de {$jail}: {$output}");
        } else {
            Flash::set('success', "IP {$ip} desbaneada del jail {$jail}.");
        }

        Router::redirect('/settings/fail2ban');
    }

    public function fail2banBan(): void
    {
        $jail = trim($_POST['jail'] ?? '');
        $ip = trim($_POST['ip'] ?? '');

        if (empty($jail) || !preg_match('/^[a-zA-Z0-9_-]+$/', $jail)) {
            Flash::set('error', 'Nombre de jail invalido.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            Flash::set('error', 'Direccion IP invalida.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        $output = trim(shell_exec(sprintf(
            'fail2ban-client set %s banip %s 2>&1',
            escapeshellarg($jail),
            escapeshellarg($ip)
        )) ?? '');

        LogService::log('fail2ban.ban', $jail, "Ban IP {$ip} in jail {$jail}: {$output}");

        if (str_contains($output, 'ERROR') || str_contains($output, 'NOK')) {
            Flash::set('error', "Error al banear {$ip} en {$jail}: {$output}");
        } else {
            Flash::set('success', "IP {$ip} baneada en jail {$jail}.");
        }

        Router::redirect('/settings/fail2ban');
    }

    public function fail2banInstall(): void
    {
        View::verifyCsrf();

        $output = shell_exec('apt-get update -qq 2>&1 && apt-get install -y -qq fail2ban 2>&1');

        if (empty(trim(shell_exec('command -v fail2ban-client 2>/dev/null') ?? ''))) {
            Flash::set('error', 'Error al instalar Fail2Ban: ' . substr($output ?? '', -200));
            Router::redirect('/settings/fail2ban');
            return;
        }

        // Copy panel filter configs
        $panelDir = PANEL_ROOT . '/config/fail2ban';
        if (is_dir($panelDir)) {
            foreach (glob("{$panelDir}/filter.d/*.conf") as $f) {
                @copy($f, '/etc/fail2ban/filter.d/' . basename($f));
            }
            if (file_exists("{$panelDir}/musedock.conf")) {
                @copy("{$panelDir}/musedock.conf", '/etc/fail2ban/jail.d/musedock.conf');
            }
            if (file_exists("{$panelDir}/logrotate-musedock-auth")) {
                @copy("{$panelDir}/logrotate-musedock-auth", '/etc/logrotate.d/musedock-auth');
            }
        }

        foreach (['/var/log/musedock-panel-auth.log', '/var/log/musedock-portal-auth.log'] as $log) {
            if (!file_exists($log)) @file_put_contents($log, '');
        }

        shell_exec('systemctl enable fail2ban 2>&1');
        shell_exec('systemctl restart fail2ban 2>&1');

        \MuseDockPanel\Services\LogService::log('fail2ban', 'Fail2Ban installed and configured via panel');
        Flash::set('success', 'Fail2Ban instalado y configurado correctamente.');
        Router::redirect('/settings/fail2ban');
    }

    public function fail2banSetupJails(): void
    {
        View::verifyCsrf();

        $panelDir = PANEL_ROOT . '/config/fail2ban';
        $errors = [];
        $installed = [];

        // 1. Copy filter configs
        if (is_dir("{$panelDir}/filter.d")) {
            foreach (glob("{$panelDir}/filter.d/*.conf") as $f) {
                @copy($f, '/etc/fail2ban/filter.d/' . basename($f));
                $installed[] = 'filter: ' . basename($f);
            }
        }

        // 2. Copy jail config
        if (file_exists("{$panelDir}/musedock.conf")) {
            @copy("{$panelDir}/musedock.conf", '/etc/fail2ban/jail.d/musedock.conf');
            $installed[] = 'jail: musedock.conf';
        }

        // 3. Copy logrotate
        if (file_exists("{$panelDir}/logrotate-musedock-auth")) {
            @copy("{$panelDir}/logrotate-musedock-auth", '/etc/logrotate.d/musedock-auth');
        }

        // 4. Create log files if missing
        foreach (['/var/log/musedock-panel-auth.log', '/var/log/musedock-portal-auth.log'] as $log) {
            if (!file_exists($log)) {
                @file_put_contents($log, '');
                @chmod($log, 0644);
                $installed[] = 'log: ' . basename($log);
            }
        }

        // 5. Ensure Caddy hosting-access log exists and is writable
        $caddyLog = '/var/log/caddy/hosting-access.log';
        if (!file_exists($caddyLog)) {
            @mkdir(dirname($caddyLog), 0755, true);
            @file_put_contents($caddyLog, '');
            shell_exec('chown caddy:caddy ' . escapeshellarg($caddyLog) . ' 2>&1');
            $installed[] = 'log: hosting-access.log';
        }

        // 6. Configure Caddy hosting-access logger if not active
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';
        $loggerCheck = @file_get_contents("{$caddyApi}/config/logging/logs/hosting-access");
        if (!$loggerCheck || $loggerCheck === 'null') {
            // Get all hosting domains
            $accounts = Database::fetchAll("SELECT domain FROM hosting_accounts");
            $subs = Database::fetchAll("SELECT subdomain as domain FROM hosting_subdomains");
            $allDomains = [];
            foreach (array_merge($accounts, $subs) as $row) {
                $allDomains[] = $row['domain'];
                $allDomains[] = 'www.' . $row['domain'];
            }
            \MuseDockPanel\Services\SystemService::ensureHostingAccessLog($caddyApi, $allDomains);
            $installed[] = 'caddy: hosting-access logger';
        }

        // 7. Reload fail2ban
        shell_exec('systemctl restart fail2ban 2>&1');
        sleep(1);

        // 8. Check which jails started
        $statusOutput = trim(shell_exec('fail2ban-client status 2>/dev/null') ?? '');
        $jailNames = [];
        if (preg_match('/Jail list:\s*(.+)$/mi', $statusOutput, $m)) {
            $jailNames = array_map('trim', explode(',', $m[1]));
        }

        $musedockJails = array_filter($jailNames, fn($j) => str_starts_with($j, 'musedock-'));

        if (!empty($musedockJails)) {
            \MuseDockPanel\Services\LogService::log('fail2ban', 'Jails configured: ' . implode(', ', $musedockJails));
            Flash::set('success', 'Jails configurados: ' . implode(', ', $musedockJails));
        } else {
            Flash::set('error', 'Los configs se copiaron pero no se activaron jails. Verifica los logs de fail2ban: journalctl -u fail2ban -n 20');
        }

        Router::redirect('/settings/fail2ban');
    }

    public function fail2banToggleJail(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $jail = preg_replace('/[^a-z0-9_-]/i', '', $_POST['jail'] ?? '');
        $action = $_POST['action'] ?? ''; // 'disable' or 'enable'

        if (!$jail || !in_array($action, ['disable', 'enable'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        if ($action === 'disable') {
            $output = trim(shell_exec(sprintf('fail2ban-client stop %s 2>&1', escapeshellarg($jail))));
            $ok = str_contains($output, 'Jail stopped') || str_contains($output, $jail);
            \MuseDockPanel\Services\LogService::log('fail2ban', "Jail {$jail} disabled");
        } else {
            $output = trim(shell_exec(sprintf('fail2ban-client start %s 2>&1', escapeshellarg($jail))));
            $ok = !str_contains($output, 'ERROR');
            if (!$ok) {
                // Try reload instead (start may fail if jail config still exists)
                shell_exec('fail2ban-client reload 2>&1');
                $ok = true;
            }
            \MuseDockPanel\Services\LogService::log('fail2ban', "Jail {$jail} enabled");
        }

        echo json_encode(['ok' => $ok, 'message' => $ok ? 'OK' : $output]);
        exit;
    }

    public function fail2banWhitelist(): void
    {
        $action = trim($_POST['action'] ?? '');
        $ip = trim($_POST['ip'] ?? '');

        if (empty($ip) || (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $ip))) {
            Flash::set('error', 'IP o CIDR invalido.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        // Read current jail.local ignoreip
        $jailLocal = '/etc/fail2ban/jail.local';
        $currentIgnore = [];

        if (file_exists($jailLocal)) {
            $content = file_get_contents($jailLocal);
            if (preg_match('/^ignoreip\s*=\s*(.+)$/m', $content, $m)) {
                $currentIgnore = array_map('trim', preg_split('/[\s,]+/', $m[1]));
                $currentIgnore = array_filter($currentIgnore, fn($v) => !empty($v));
            }
        }

        if ($action === 'add') {
            if (!in_array($ip, $currentIgnore)) {
                $currentIgnore[] = $ip;
            }
            LogService::log('fail2ban.whitelist.add', $ip, "Added {$ip} to Fail2Ban whitelist");
            Flash::set('success', "IP {$ip} anadida a la whitelist.");
        } elseif ($action === 'remove') {
            $currentIgnore = array_values(array_filter($currentIgnore, fn($v) => $v !== $ip));
            LogService::log('fail2ban.whitelist.remove', $ip, "Removed {$ip} from Fail2Ban whitelist");
            Flash::set('success', "IP {$ip} eliminada de la whitelist.");
        } else {
            Flash::set('error', 'Accion invalida.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        // Always keep 127.0.0.1/8 and ::1
        $defaults = ['127.0.0.1/8', '::1'];
        foreach ($defaults as $d) {
            if (!in_array($d, $currentIgnore)) {
                array_unshift($currentIgnore, $d);
            }
        }

        $ignoreStr = implode(' ', $currentIgnore);
        $newContent = "[DEFAULT]\nignoreip = {$ignoreStr}\n";
        file_put_contents($jailLocal, $newContent);

        // Reload fail2ban to apply
        shell_exec('fail2ban-client reload 2>&1');

        Router::redirect('/settings/fail2ban');
    }

    public function fail2banExport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'exportar configuracion de fail2ban', '/settings/fail2ban')) {
            return;
        }

        if (empty(trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')))) {
            Flash::set('error', 'Fail2Ban no esta instalado en este servidor.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        $statusOutput = trim((string)shell_exec('fail2ban-client status 2>/dev/null'));
        $activeJails = [];
        if (preg_match('/Jail list:\s*(.+)$/mi', $statusOutput, $m)) {
            $activeJails = array_values(array_filter(array_map('trim', explode(',', (string)$m[1]))));
        }

        $filters = [];
        foreach (glob('/etc/fail2ban/filter.d/musedock*.conf') ?: [] as $filterPath) {
            if (is_file($filterPath) && is_readable($filterPath)) {
                $filters[basename($filterPath)] = (string)file_get_contents($filterPath);
            }
        }

        $payload = [
            'type' => 'musedock-fail2ban-config',
            'schema' => 1,
            'exported_at' => gmdate('c'),
            'panel_version' => defined('PANEL_VERSION') ? PANEL_VERSION : 'unknown',
            'host' => gethostname() ?: '',
            'whitelist' => $this->readFail2BanWhitelist(),
            'jail_local' => file_exists('/etc/fail2ban/jail.local') ? (string)file_get_contents('/etc/fail2ban/jail.local') : '',
            'jail_d_musedock' => file_exists('/etc/fail2ban/jail.d/musedock.conf') ? (string)file_get_contents('/etc/fail2ban/jail.d/musedock.conf') : '',
            'filters' => $filters,
            'active_jails' => $activeJails,
        ];

        LogService::log('fail2ban.export', 'download', 'Export de configuracion Fail2Ban');
        $this->streamJsonDownload($payload, 'musedock-fail2ban-export');
    }

    public function fail2banImport(): void
    {
        View::verifyCsrf();

        $password = (string)($_POST['admin_password'] ?? '');
        if (!$this->verifyAdminPasswordOrRedirect($password, 'importar configuracion de fail2ban', '/settings/fail2ban')) {
            return;
        }

        if (empty(trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')))) {
            Flash::set('error', 'Fail2Ban no esta instalado en este servidor.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        $payload = $this->readUploadedJsonFile('config_file', 2 * 1024 * 1024, '/settings/fail2ban');
        if ($payload === null) {
            return;
        }

        $type = trim((string)($payload['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['musedock-fail2ban-config', 'musedock-fail2ban-export'], true)) {
            Flash::set('error', 'Archivo JSON no compatible con configuracion de Fail2Ban.');
            Router::redirect('/settings/fail2ban');
            return;
        }

        $replace = isset($_POST['replace_existing']);
        $errors = [];
        $written = [];

        $incomingWhitelist = is_array($payload['whitelist'] ?? null) ? $payload['whitelist'] : [];
        $normalizedWhitelist = $this->normalizeFail2BanWhitelist($incomingWhitelist);

        $jailLocalPath = '/etc/fail2ban/jail.local';
        $jailMusedockPath = '/etc/fail2ban/jail.d/musedock.conf';
        $jailLocalFromPayload = is_string($payload['jail_local'] ?? null) ? (string)$payload['jail_local'] : '';
        $jailMusedockFromPayload = is_string($payload['jail_d_musedock'] ?? null) ? (string)$payload['jail_d_musedock'] : '';

        if ($replace) {
            if ($jailLocalFromPayload !== '') {
                $fileError = '';
                if (!$this->writeTextFile($jailLocalPath, $jailLocalFromPayload, $fileError)) {
                    $errors[] = "jail.local: {$fileError}";
                } else {
                    $written[] = 'jail.local';
                }
            } else {
                $fileError = '';
                if (!$this->writeFail2BanWhitelist($normalizedWhitelist, $fileError)) {
                    $errors[] = "whitelist: {$fileError}";
                } else {
                    $written[] = 'whitelist';
                }
            }

            if ($jailMusedockFromPayload !== '') {
                $fileError = '';
                if (!$this->writeTextFile($jailMusedockPath, $jailMusedockFromPayload, $fileError)) {
                    $errors[] = "musedock.conf: {$fileError}";
                } else {
                    $written[] = 'musedock.conf';
                }
            }
        } else {
            $merged = array_values(array_unique(array_merge($this->readFail2BanWhitelist(), $normalizedWhitelist)));
            $fileError = '';
            if (!$this->writeFail2BanWhitelist($merged, $fileError)) {
                $errors[] = "whitelist: {$fileError}";
            } else {
                $written[] = 'whitelist';
            }

            if ($jailMusedockFromPayload !== '' && !file_exists($jailMusedockPath)) {
                $fileError = '';
                if (!$this->writeTextFile($jailMusedockPath, $jailMusedockFromPayload, $fileError)) {
                    $errors[] = "musedock.conf: {$fileError}";
                } else {
                    $written[] = 'musedock.conf';
                }
            }
        }

        $filtersPayload = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        foreach ($filtersPayload as $filename => $content) {
            $filename = trim((string)$filename);
            if (!$this->isSafeFail2BanFilterName($filename)) {
                continue;
            }
            if (!is_string($content)) {
                continue;
            }

            $dest = '/etc/fail2ban/filter.d/' . $filename;
            if (!$replace && file_exists($dest)) {
                continue;
            }
            $fileError = '';
            if (!$this->writeTextFile($dest, $content, $fileError)) {
                $errors[] = "{$filename}: {$fileError}";
                continue;
            }
            $written[] = $filename;
        }

        $reloadOut = trim((string)shell_exec('fail2ban-client reload 2>&1'));
        if (str_contains(strtoupper($reloadOut), 'ERROR') || str_contains(strtoupper($reloadOut), 'NOK')) {
            $errors[] = 'reload fail2ban: ' . $reloadOut;
        }

        LogService::log(
            'fail2ban.import',
            $replace ? 'replace' : 'append',
            'Import fail2ban: written=' . implode(',', array_values(array_unique($written))) . '; errors=' . count($errors)
        );

        if (!empty($errors)) {
            $msg = 'Importacion Fail2Ban completada con incidencias: ' . implode(' | ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $msg .= ' ...';
            }
            Flash::set('warning', $msg);
        } else {
            Flash::set('success', 'Configuracion de Fail2Ban importada correctamente.');
        }

        Router::redirect('/settings/fail2ban');
    }

    // ================================================================
    // SSL/TLS Certificates
    // ================================================================

    public function ssl(): void
    {
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = $config['caddy']['api_url'];

        $certificates = [];
        $apiAvailable = false;

        // Get certificates from Caddy API
        $ch = curl_init("{$caddyApi}/config/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $configJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400 && $configJson) {
            $apiAvailable = true;
            $fullConfig = json_decode($configJson, true) ?: [];

            // Extract domains from routes
            $servers = $fullConfig['apps']['http']['servers'] ?? [];
            foreach ($servers as $srvName => $server) {
                foreach ($server['routes'] ?? [] as $route) {
                    foreach ($route['match'] ?? [] as $match) {
                        foreach ($match['host'] ?? [] as $host) {
                            $certificates[] = [
                                'domain' => $host,
                                'server' => $srvName,
                                'type' => 'auto',
                            ];
                        }
                    }
                }
            }

            // TLS policies
            $tlsPolicies = $fullConfig['apps']['tls']['automation']['policies'] ?? [];
        }

        View::render('settings/ssl', [
            'layout' => 'main',
            'pageTitle' => 'SSL/TLS',
            'apiAvailable' => $apiAvailable,
            'certificates' => $certificates,
            'tlsPolicies' => $tlsPolicies ?? [],
        ]);
    }

    // ================================================================
    // Log Browser
    // ================================================================

    public function logs(): void
    {
        // Allowed base directories for log files
        $allowedPrefixes = [
            PANEL_ROOT . '/storage/logs',
            '/var/log',
            '/var/www/vhosts',
        ];

        // Build list of available log files grouped by category
        $logFiles = [];

        // Panel logs
        $panelLogDir = PANEL_ROOT . '/storage/logs';
        foreach (['panel.log', 'panel-error.log'] as $f) {
            $path = $panelLogDir . '/' . $f;
            if (file_exists($path)) {
                $logFiles['Panel'][] = [
                    'path' => $path,
                    'label' => $f,
                    'size' => filesize($path),
                ];
            }
        }

        // Caddy logs (system dir)
        if (is_dir('/var/log/caddy')) {
            foreach (glob('/var/log/caddy/*.log') as $f) {
                $logFiles['Caddy'][] = [
                    'path' => $f,
                    'label' => basename($f),
                    'size' => filesize($f),
                ];
            }
        }

        // Caddy per-domain access logs (from vhosts)
        $accountUsernames = array_column(
            \MuseDockPanel\Database::fetchAll("SELECT username FROM hosting_accounts"),
            'username'
        );
        foreach (glob('/var/www/vhosts/*/logs/access.log') as $f) {
            $vhostDir = basename(dirname(dirname($f)));
            // Skip hosting accounts — they appear under "Cuentas" already
            if (in_array($vhostDir, $accountUsernames)) continue;
            if (filesize($f) === 0) continue;
            $logFiles['Caddy'][] = [
                'path' => $f,
                'label' => $vhostDir . '/access.log',
                'size' => filesize($f),
            ];
        }

        // Per-account logs
        $accounts = \MuseDockPanel\Database::fetchAll("SELECT username, php_version FROM hosting_accounts ORDER BY username");
        foreach ($accounts as $acc) {
            $logsDir = '/var/www/vhosts/' . $acc['username'] . '/logs';
            foreach (['access.log', 'error.log'] as $f) {
                $path = $logsDir . '/' . $f;
                if (file_exists($path)) {
                    $logFiles['Cuentas'][] = [
                        'path' => $path,
                        'label' => $acc['username'] . '/' . $f,
                        'size' => filesize($path),
                    ];
                }
            }
        }

        // PHP-FPM logs
        foreach (glob('/etc/php/*/fpm') as $fpmDir) {
            $ver = basename(dirname($fpmDir));
            $path = "/var/log/php{$ver}-fpm.log";
            if (file_exists($path)) {
                $logFiles['PHP-FPM'][] = [
                    'path' => $path,
                    'label' => "php{$ver}-fpm.log",
                    'size' => filesize($path),
                ];
            }
        }

        // System log
        if (file_exists('/var/log/syslog')) {
            $logFiles['Sistema'][] = [
                'path' => '/var/log/syslog',
                'label' => 'syslog',
                'size' => filesize('/var/log/syslog'),
            ];
        }

        // Read query params
        $defaultFile = '';
        foreach ($logFiles as $group) {
            if (!empty($group[0]['path'])) {
                $defaultFile = $group[0]['path'];
                break;
            }
        }
        $selectedFile = $_GET['file'] ?? $defaultFile;
        $lines = (int) ($_GET['lines'] ?? 100);
        $lines = max(10, min(1000, $lines));

        // Validate file path against directory traversal
        $logContent = '';
        $fileExists = false;
        $fileSize = 0;
        $displayPath = '';

        if (!empty($selectedFile)) {
            $realPath = realpath($selectedFile);
            if ($realPath === false) {
                $logContent = 'Archivo no encontrado';
            } else {
                $allowed = false;
                foreach ($allowedPrefixes as $prefix) {
                    $realPrefix = realpath($prefix);
                    if ($realPrefix !== false && str_starts_with($realPath, $realPrefix . '/')) {
                        $allowed = true;
                        break;
                    }
                }

                if (!$allowed) {
                    $logContent = 'Acceso denegado: ruta no permitida';
                } elseif (!is_file($realPath) || !is_readable($realPath)) {
                    $logContent = 'Archivo no encontrado';
                } else {
                    $fileExists = true;
                    $fileSize = filesize($realPath);
                    $displayPath = $realPath;
                    $logContent = shell_exec(sprintf('tail -n %d %s 2>&1', $lines, escapeshellarg($realPath))) ?? '';
                }
            }
        }

        View::render('settings/logs', [
            'layout' => 'main',
            'pageTitle' => 'Visor de Logs',
            'logFiles' => $logFiles,
            'selectedFile' => $selectedFile,
            'lines' => $lines,
            'logContent' => $logContent,
            'fileExists' => $fileExists,
            'fileSize' => $fileSize,
            'displayPath' => $displayPath,
        ]);
    }

    /**
     * POST /settings/logs/clear — Truncate a log file
     */
    public function logClear(): void
    {
        View::verifyCsrf();

        $filePath = $_POST['file'] ?? '';
        if (empty($filePath)) {
            Flash::set('error', 'No se especifico archivo.');
            header('Location: /settings/logs');
            exit;
        }

        // Validate path against allowed prefixes
        $allowedPrefixes = [
            PANEL_ROOT . '/storage/logs',
            '/var/log',
            '/var/www/vhosts',
        ];

        $realPath = realpath($filePath);
        if ($realPath === false) {
            Flash::set('error', 'Archivo no encontrado.');
            header('Location: /settings/logs');
            exit;
        }

        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            $realPrefix = realpath($prefix);
            if ($realPrefix !== false && str_starts_with($realPath, $realPrefix . '/')) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            Flash::set('error', 'No tienes permiso para modificar este archivo.');
            header('Location: /settings/logs');
            exit;
        }

        // Truncate the file (don't delete — services expect it to exist)
        $result = @file_put_contents($realPath, '');
        if ($result === false) {
            // Try with shell for files owned by root
            $cmd = sprintf('truncate -s 0 %s 2>&1', escapeshellarg($realPath));
            $output = trim((string)shell_exec($cmd));
            if ($output !== '') {
                Flash::set('error', 'Error al vaciar archivo: ' . $output);
                header('Location: /settings/logs?' . http_build_query(['file' => $filePath]));
                exit;
            }
        }

        LogService::log('logs.truncate', basename($realPath), "Archivo vaciado: {$realPath}");
        Flash::set('success', 'Archivo de log vaciado: ' . basename($realPath));
        header('Location: /settings/logs?' . http_build_query(['file' => $filePath]));
        exit;
    }

    private function extractCaddyRouteInfo(array $handlers, ?string &$docRoot, ?string &$upstream): void
    {
        foreach ($handlers as $handler) {
            $h = $handler['handler'] ?? '';

            if ($h === 'vars' && isset($handler['root'])) {
                $docRoot = $handler['root'];
            }

            if ($h === 'reverse_proxy') {
                foreach ($handler['upstreams'] ?? [] as $up) {
                    $upstream = $up['dial'] ?? null;
                    break;
                }
                // Check transport for FastCGI
                if (isset($handler['transport']['protocol']) && $handler['transport']['protocol'] === 'fastcgi') {
                    $upstream = 'FastCGI → ' . ($upstream ?? '?');
                }
            }

            if ($h === 'subroute') {
                $this->extractCaddyRouteInfo($handler['routes'] ?? [], $docRoot, $upstream);
                foreach ($handler['routes'] ?? [] as $subRoute) {
                    $this->extractCaddyRouteInfo($subRoute['handle'] ?? [], $docRoot, $upstream);
                }
            }
        }
    }

    // ================================================================
    // System Health
    // ================================================================

    public function health(): void
    {
        $checks = [];

        // ─── 1. Cron jobs ────────────────────────────────────────
        $requiredCrons = [
            'musedock-cluster'  => ['desc' => 'Cluster worker (queue, heartbeat, alerts)', 'interval' => 'Every minute'],
            'musedock-backup'   => ['desc' => 'Panel DB backup (hourly)', 'interval' => 'Every hour'],
            'musedock-filesync' => ['desc' => 'File sync worker (master-slave replication)', 'interval' => 'Every minute'],
            'musedock-monitor'  => ['desc' => 'Network/system monitoring collector', 'interval' => 'Every 30 seconds'],
            'musedock-federation' => ['desc' => 'Federation migration worker + cleanup', 'interval' => 'Every minute + hourly'],
        ];
        $cronChecks = [];
        foreach ($requiredCrons as $name => $info) {
            $file = "/etc/cron.d/{$name}";
            $exists = file_exists($file);
            $content = $exists ? @file_get_contents($file) : '';
            // Check if cron content is not empty and has valid entries (not just comments)
            $hasEntries = false;
            if ($content) {
                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if ($line && $line[0] !== '#') {
                        $hasEntries = true;
                        break;
                    }
                }
            }
            $cronChecks[$name] = [
                'name'     => $name,
                'desc'     => $info['desc'],
                'interval' => $info['interval'],
                'exists'   => $exists,
                'valid'    => $exists && $hasEntries,
            ];
        }
        $checks['crons'] = $cronChecks;

        // ─── 2. Required PHP extensions ──────────────────────────
        $requiredExtensions = [
            'pdo'       => 'Database connectivity (PDO)',
            'pdo_pgsql' => 'PostgreSQL database driver',
            'pdo_mysql' => 'MySQL database driver',
            'sqlite3'   => 'SQLite3 database (required by some apps)',
            'curl'      => 'HTTP requests (API, notifications)',
            'mbstring'  => 'Multibyte string support',
            'json'      => 'JSON encoding/decoding',
            'openssl'   => 'SSL/TLS and encryption',
            'session'   => 'Session management',
            'fileinfo'  => 'File type detection',
            'posix'     => 'POSIX functions (system users)',
            'zip'       => 'ZIP archive support',
            'gd'        => 'Image processing (GD library)',
            'xml'       => 'XML parsing',
            'bcmath'    => 'Arbitrary precision math',
        ];
        $extChecks = [];
        foreach ($requiredExtensions as $ext => $desc) {
            $extChecks[$ext] = [
                'name'      => $ext,
                'desc'      => $desc,
                'loaded'    => extension_loaded($ext),
            ];
        }
        $checks['extensions'] = $extChecks;

        // ─── 3. Required system binaries ─────────────────────────
        $requiredBinaries = [
            'php'         => ['paths' => ['/usr/bin/php', '/usr/bin/php8.3'], 'desc' => 'PHP CLI interpreter', 'package' => ''],
            'caddy'       => ['paths' => ['/usr/bin/caddy'], 'desc' => 'Web server', 'package' => ''],
            'psql'        => ['paths' => ['/usr/bin/psql'], 'desc' => 'PostgreSQL client', 'package' => 'postgresql-client'],
            'pg_dump'     => ['paths' => ['/usr/bin/pg_dump'], 'desc' => 'PostgreSQL backup tool', 'package' => 'postgresql-client'],
            'mysql'       => ['paths' => ['/usr/bin/mysql', '/usr/bin/mariadb'], 'desc' => 'MySQL/MariaDB client', 'package' => 'mariadb-client'],
            'wg'          => ['paths' => ['/usr/bin/wg'], 'desc' => 'WireGuard tools', 'package' => 'wireguard-tools'],
            'ufw'         => ['paths' => ['/usr/sbin/ufw'], 'desc' => 'Uncomplicated Firewall', 'package' => 'ufw'],
            'fail2ban-client' => ['paths' => ['/usr/bin/fail2ban-client'], 'desc' => 'Fail2Ban intrusion prevention', 'package' => 'fail2ban'],
            'rsync'       => ['paths' => ['/usr/bin/rsync'], 'desc' => 'File synchronization', 'package' => 'rsync'],
            'git'         => ['paths' => ['/usr/bin/git'], 'desc' => 'Version control', 'package' => 'git'],
            'nproc'       => ['paths' => ['/usr/bin/nproc'], 'desc' => 'CPU core detection', 'package' => 'coreutils'],
            'node'        => ['paths' => ['/usr/bin/node', '/usr/local/bin/node'], 'desc' => 'Node.js runtime (frontend builds)', 'package' => 'nodejs'],
            'npm'         => ['paths' => ['/usr/bin/npm', '/usr/local/bin/npm'], 'desc' => 'Node Package Manager', 'package' => 'npm'],
            'composer'    => ['paths' => ['/usr/bin/composer', '/usr/local/bin/composer'], 'desc' => 'PHP dependency manager', 'package' => 'composer'],
            'sshpass'     => ['paths' => ['/usr/bin/sshpass'], 'desc' => 'SSH password automation (migrations)', 'package' => 'sshpass'],
            'tar'         => ['paths' => ['/usr/bin/tar', '/bin/tar'], 'desc' => 'Archive tool', 'package' => 'tar'],
            'unzip'       => ['paths' => ['/usr/bin/unzip'], 'desc' => 'ZIP extraction', 'package' => 'unzip'],
        ];
        $binChecks = [];
        foreach ($requiredBinaries as $name => $info) {
            $found = false;
            $foundPath = '';
            foreach ($info['paths'] as $path) {
                if (is_executable($path)) {
                    $found = true;
                    $foundPath = $path;
                    break;
                }
            }
            // Try which as fallback
            if (!$found) {
                $which = trim((string)shell_exec("which {$name} 2>/dev/null"));
                if ($which && is_executable($which)) {
                    $found = true;
                    $foundPath = $which;
                }
            }
            $version = '';
            if ($found) {
                if ($name === 'php') {
                    $version = PHP_VERSION;
                } elseif ($name === 'caddy') {
                    $version = trim(shell_exec("{$foundPath} version 2>/dev/null | head -1") ?? '');
                } elseif (in_array($name, ['psql', 'pg_dump'])) {
                    $version = trim(shell_exec("{$foundPath} --version 2>/dev/null | head -1") ?? '');
                } elseif ($name === 'mysql') {
                    $version = trim(shell_exec("{$foundPath} --version 2>/dev/null | head -1") ?? '');
                } elseif ($name === 'git') {
                    $version = trim(shell_exec("{$foundPath} --version 2>/dev/null") ?? '');
                }
            }
            $binChecks[$name] = [
                'name'    => $name,
                'desc'    => $info['desc'],
                'found'   => $found,
                'path'    => $foundPath,
                'version' => $version,
                'package' => $info['package'] ?? '',
            ];
        }
        $checks['binaries'] = $binChecks;

        // ─── 4. Directories & permissions ────────────────────────
        $panelDir = defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel';
        $requiredDirs = [
            'storage/logs'     => 'Log files',
            'storage/sessions' => 'PHP sessions',
            'storage/cache'    => 'Cache files',
            'storage/backups'  => 'Database backups',
        ];
        $dirChecks = [];
        foreach ($requiredDirs as $dir => $desc) {
            $fullPath = $panelDir . '/' . $dir;
            $exists = is_dir($fullPath);
            $writable = $exists && is_writable($fullPath);
            $dirChecks[$dir] = [
                'path'     => $dir,
                'desc'     => $desc,
                'exists'   => $exists,
                'writable' => $writable,
            ];
        }
        $checks['directories'] = $dirChecks;

        // ─── 5. Panel service ────────────────────────────────────
        $svcStatus = trim(shell_exec('systemctl is-active musedock-panel 2>/dev/null') ?? 'unknown');
        $svcEnabled = trim(shell_exec('systemctl is-enabled musedock-panel 2>/dev/null') ?? 'unknown');
        $checks['service'] = [
            'active'  => $svcStatus === 'active',
            'enabled' => $svcEnabled === 'enabled',
            'status'  => $svcStatus,
        ];

        // ─── 6. Database connectivity ────────────────────────────
        $dbOk = false;
        $dbVersion = '';
        try {
            $row = \MuseDockPanel\Database::fetchOne("SELECT version() AS v");
            $dbOk = true;
            $dbVersion = $row['v'] ?? '';
        } catch (\Throwable) {}
        // Check PostgreSQL timezone
        $pgTimezone = '';
        if ($dbOk) {
            try {
                $tzRow = \MuseDockPanel\Database::fetchOne("SHOW timezone");
                $pgTimezone = $tzRow['TimeZone'] ?? $tzRow['timezone'] ?? '';
            } catch (\Throwable) {}
        }

        // Check MySQL timezone (hosting instance, port 3306)
        $mysqlTimezone = '';
        $mysqlOk = false;
        try {
            $mysqlHost = \MuseDockPanel\Env::get('MYSQL_HOST', '127.0.0.1');
            $mysqlPort = \MuseDockPanel\Env::get('MYSQL_PORT', '3306');
            $mysqlUser = \MuseDockPanel\Env::get('MYSQL_ROOT_USER', 'root');
            $mysqlPass = \MuseDockPanel\Env::get('MYSQL_ROOT_PASS', '');
            if ($mysqlPass) {
                $mysqlDsn = "mysql:host={$mysqlHost};port={$mysqlPort}";
                $mysqlPdo = new \PDO($mysqlDsn, $mysqlUser, $mysqlPass, [\PDO::ATTR_TIMEOUT => 3]);
                $mysqlTzRow = $mysqlPdo->query("SELECT @@global.time_zone AS tz, TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS utc_offset")->fetch(\PDO::FETCH_ASSOC);
                $mysqlTimezone = $mysqlTzRow['tz'] ?? '';
                $mysqlUtcOffset = $mysqlTzRow['utc_offset'] ?? '';
                $mysqlOk = true;
            }
        } catch (\Throwable) {}

        // MySQL is OK if timezone is explicitly UTC/+00:00, or if SYSTEM and actual offset is 00:00:00
        $mysqlIsUtc = in_array($mysqlTimezone, ['UTC', '+00:00'], true);
        if (!$mysqlIsUtc && $mysqlTimezone === 'SYSTEM' && $mysqlOk) {
            $mysqlIsUtc = in_array($mysqlUtcOffset, ['00:00:00', '00:00'], true);
        }

        $checks['database'] = [
            'connected'      => $dbOk,
            'version'        => $dbVersion,
            'pg_timezone'    => $pgTimezone,
            'pg_tz_ok'       => in_array($pgTimezone, ['UTC', 'Etc/UTC', 'GMT'], true),
            'mysql_ok'       => $mysqlOk,
            'mysql_timezone' => $mysqlTimezone . ($mysqlTimezone === 'SYSTEM' && $mysqlOk ? " (offset: {$mysqlUtcOffset})" : ''),
            'mysql_tz_ok'    => $mysqlIsUtc || !$mysqlOk,
        ];

        // ─── 7. GPU Health ────────────────────────────────────────
        $gpuChecks = [];
        $nvidiaSmi = trim((string)@shell_exec('which nvidia-smi 2>/dev/null'));
        if ($nvidiaSmi && is_executable($nvidiaSmi)) {
            // Check nvidia-smi -L for GPU listing and errors
            $gpuListOutput = @shell_exec('nvidia-smi -L 2>&1') ?? '';
            $gpuQueryOutput = @shell_exec('nvidia-smi --query-gpu=index,name,driver_version,memory.total,temperature.gpu,utilization.gpu,power.draw --format=csv,noheader,nounits 2>&1') ?? '';

            // Check dmesg for nvidia errors (last 200 lines)
            $dmesgErrors = trim((string)@shell_exec('dmesg 2>/dev/null | grep -i nvidia | grep -i error | tail -5'));

            // Parse each GPU
            preg_match_all('/GPU (\d+): (.+?) \(UUID: (.+?)\)/', $gpuListOutput, $gpuMatches, PREG_SET_ORDER);

            // Detect errors per GPU from nvidia-smi -L
            $gpuErrors = [];
            foreach (explode("\n", $gpuListOutput) as $line) {
                if (stripos($line, 'Unable to determine') !== false || stripos($line, 'Unknown Error') !== false) {
                    // Try to extract PCI address
                    if (preg_match('/gpu ([0-9a-f:\.]+)/i', $line, $em)) {
                        $gpuErrors[$em[1]] = trim($line);
                    } else {
                        $gpuErrors['unknown'] = trim($line);
                    }
                }
            }

            // Parse dmesg errors per GPU index
            $dmesgPerGpu = [];
            if ($dmesgErrors) {
                foreach (explode("\n", $dmesgErrors) as $line) {
                    if (preg_match('/GPU:(\d+)/', $line, $dm)) {
                        $dmesgPerGpu[(int)$dm[1]][] = trim(preg_replace('/^\[[\d\.]+\]\s*/', '', $line));
                    }
                }
            }

            // Parse working GPU details from query
            $gpuDetails = [];
            if ($gpuQueryOutput && stripos($gpuQueryOutput, 'Failed') === false) {
                foreach (explode("\n", trim($gpuQueryOutput)) as $line) {
                    $parts = array_map('trim', explode(',', $line));
                    if (count($parts) >= 7 && is_numeric($parts[0])) {
                        $gpuDetails[(int)$parts[0]] = [
                            'driver'      => $parts[2],
                            'memory'      => $parts[3] . ' MiB',
                            'temperature' => $parts[4] . '°C',
                            'utilization' => $parts[5] . '%',
                            'power'       => $parts[6] . 'W',
                        ];
                    }
                }
            }

            // Track which GPU indices we've seen
            $seenIndices = [];

            foreach ($gpuMatches as $m) {
                $idx = (int)$m[1];
                $name = $m[2];
                $uuid = $m[3];
                $seenIndices[] = $idx;

                $healthy = true;
                $status = 'OK';
                $errors = [];

                // Check dmesg errors for this GPU
                if (!empty($dmesgPerGpu[$idx])) {
                    $healthy = false;
                    $status = 'Hardware Error';
                    $errors = array_slice($dmesgPerGpu[$idx], -3);
                }

                // Check nvidia-smi communication errors
                if (!isset($gpuDetails[$idx])) {
                    $healthy = false;
                    $status = 'Not Responding';
                    if (empty($errors)) $errors[] = 'nvidia-smi cannot query this GPU';
                }

                $gpuChecks[] = [
                    'index'   => $idx,
                    'name'    => $name,
                    'uuid'    => $uuid,
                    'healthy' => $healthy,
                    'status'  => $status,
                    'errors'  => $errors,
                    'details' => $gpuDetails[$idx] ?? null,
                ];
            }

            // Detect GPUs that appear in dmesg errors but NOT in nvidia-smi -L
            foreach ($dmesgPerGpu as $dIdx => $dErrors) {
                if (in_array($dIdx, $seenIndices, true)) continue;
                // Try to get GPU name from lspci
                $lspciName = '';
                $lspciOutput = @shell_exec('lspci 2>/dev/null | grep -i "vga\|3d\|display" | grep -i nvidia');
                if ($lspciOutput) {
                    $lspciLines = explode("\n", trim($lspciOutput));
                    if (isset($lspciLines[$dIdx])) {
                        // Extract model name from lspci line
                        if (preg_match('/NVIDIA.*?(\[.*?\]|GeForce.*|RTX.*|GTX.*|Tesla.*|Quadro.*)/', $lspciLines[$dIdx], $lm)) {
                            $lspciName = trim($lm[1], '[] ');
                        }
                    }
                }

                $gpuChecks[] = [
                    'index'   => $dIdx,
                    'name'    => $lspciName ?: "GPU {$dIdx} (not responding)",
                    'uuid'    => '',
                    'healthy' => false,
                    'status'  => 'Hardware Error',
                    'errors'  => array_slice($dErrors, -3),
                    'details' => null,
                ];
            }

            // Also detect PCI errors from nvidia-smi -L output (e.g. "Unable to determine...")
            // Only add if we haven't already detected a failed GPU from dmesg
            $hasFailedGpu = false;
            foreach ($gpuChecks as $gc) {
                if (!$gc['healthy']) { $hasFailedGpu = true; break; }
            }

            if (!empty($gpuErrors) && !$hasFailedGpu) {
                $lspciOutput = @shell_exec('lspci 2>/dev/null | grep -i "vga\|3d\|display" | grep -i nvidia');
                $lspciGpus = $lspciOutput ? explode("\n", trim($lspciOutput)) : [];
                $shownCount = count($gpuChecks);

                foreach ($gpuErrors as $pciAddr => $errMsg) {
                    $gpuName = '';
                    foreach ($lspciGpus as $lLine) {
                        if (str_contains($lLine, $pciAddr) || str_contains($lLine, substr($pciAddr, -7))) {
                            if (preg_match('/NVIDIA.*?(\[.*?\]|GeForce.*|RTX.*|GTX.*|Tesla.*|Quadro.*)/', $lLine, $lm)) {
                                $gpuName = trim($lm[1], '[] ');
                            }
                            break;
                        }
                    }

                    $gpuChecks[] = [
                        'index'   => $shownCount,
                        'name'    => $gpuName ?: "Unknown GPU (PCI {$pciAddr})",
                        'uuid'    => '',
                        'healthy' => false,
                        'status'  => 'Not Responding',
                        'errors'  => [$errMsg],
                        'details' => null,
                    ];
                    $shownCount++;
                }
            } elseif (!empty($gpuErrors) && $hasFailedGpu) {
                // Append PCI error messages to the existing failed GPU entry
                foreach ($gpuChecks as &$gc) {
                    if (!$gc['healthy']) {
                        foreach ($gpuErrors as $errMsg) {
                            $gc['errors'][] = $errMsg;
                        }
                        $gc['errors'] = array_slice($gc['errors'], -5);
                        break; // only add to the first failed GPU
                    }
                }
                unset($gc);
            }
        }
        $checks['gpus'] = $gpuChecks;
        $checks['gpu_driver'] = $nvidiaSmi ? true : false;

        // Summary counts
        $totalChecks = 0;
        $passedChecks = 0;
        foreach ($checks['crons'] as $c) { $totalChecks++; if ($c['valid']) $passedChecks++; }
        foreach ($checks['extensions'] as $c) { $totalChecks++; if ($c['loaded']) $passedChecks++; }
        foreach ($checks['binaries'] as $c) { $totalChecks++; if ($c['found']) $passedChecks++; }
        foreach ($checks['directories'] as $c) { $totalChecks++; if ($c['writable']) $passedChecks++; }
        $totalChecks += 2; // service + database
        if ($checks['service']['active']) $passedChecks++;
        if ($checks['database']['connected']) $passedChecks++;
        if ($checks['database']['pg_timezone']) { $totalChecks++; if ($checks['database']['pg_tz_ok']) $passedChecks++; }
        if ($checks['database']['mysql_ok']) { $totalChecks++; if ($checks['database']['mysql_tz_ok']) $passedChecks++; }
        foreach ($checks['gpus'] as $g) { $totalChecks++; if ($g['healthy']) $passedChecks++; }

        View::render('settings/health', [
            'layout'       => 'main',
            'pageTitle'    => 'System Health',
            'checks'       => $checks,
            'totalChecks'  => $totalChecks,
            'passedChecks' => $passedChecks,
        ]);
    }

    /**
     * POST /settings/health/repair-cron — Repair a missing cron file
     */
    public function healthRepairCron(): void
    {
        View::verifyCsrf();

        $cronName = $_POST['cron'] ?? '';
        $panelDir = defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel';

        $cronTemplates = [
            'musedock-cluster' => "# MuseDock Panel — Cluster worker (queue, heartbeat, alerts)\n* * * * * root /usr/bin/php {$panelDir}/bin/cluster-worker.php >> {$panelDir}/storage/logs/cluster-worker.log 2>&1\n",
            'musedock-backup' => "# MuseDock Panel — Hourly panel DB backup\n0 * * * * root install -d -o postgres -g www-data -m 0770 {$panelDir}/storage/backups && runuser -u postgres -- pg_dump -p 5433 musedock_panel | gzip > {$panelDir}/storage/backups/panel-\$(date +\\%Y\\%m\\%d_\\%H).sql.gz && chown postgres:www-data {$panelDir}/storage/backups/panel-\$(date +\\%Y\\%m\\%d_\\%H).sql.gz && chmod 0640 {$panelDir}/storage/backups/panel-\$(date +\\%Y\\%m\\%d_\\%H).sql.gz\n# Cleanup backups older than 48 hours\n5 * * * * root find {$panelDir}/storage/backups/ -name \"panel-*.sql.gz\" -mmin +2880 -delete 2>/dev/null\n",
            'musedock-filesync' => "# MuseDock Panel — File sync worker (master -> slave file replication)\n* * * * * root /usr/bin/php {$panelDir}/bin/filesync-worker.php >> {$panelDir}/storage/logs/filesync-worker.log 2>&1\n",
            'musedock-monitor' => "# MuseDock Panel — Network/system monitoring collector (every 30s)\n* * * * * root /usr/bin/php {$panelDir}/bin/monitor-collector.php\n* * * * * root sleep 30 && /usr/bin/php {$panelDir}/bin/monitor-collector.php\n",
            'musedock-federation' => "# MuseDock Panel — Federation migration workers\n* * * * * root sleep 30 && /usr/bin/php {$panelDir}/bin/federation-worker.php >> {$panelDir}/storage/logs/federation-worker.log 2>&1\n0 * * * * root /usr/bin/php {$panelDir}/bin/federation-cleanup.php >> {$panelDir}/storage/logs/federation-cleanup.log 2>&1\n",
        ];

        if (!isset($cronTemplates[$cronName])) {
            Flash::set('error', 'Cron no reconocido.');
            Router::redirect('/settings/health');
            return;
        }

        $file = "/etc/cron.d/{$cronName}";
        $result = @file_put_contents($file, $cronTemplates[$cronName]);

        if ($result === false) {
            // Try via shell
            $escaped = escapeshellarg($cronTemplates[$cronName]);
            $escaped_file = escapeshellarg($file);
            shell_exec("echo {$escaped} > {$escaped_file} 2>&1");
            @chmod($file, 0644);
            shell_exec("systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null");
        } else {
            @chmod($file, 0644);
            shell_exec("systemctl reload cron 2>/dev/null || systemctl reload crond 2>/dev/null");
        }

        LogService::log('system.health', 'repair-cron', "Repaired cron: {$cronName}");
        Flash::set('success', "Cron {$cronName} reparado correctamente.");
        Router::redirect('/settings/health');
    }

    /**
     * POST /settings/health/fix-timezone — Set database timezone to UTC
     */
    public function healthFixTimezone(): void
    {
        View::verifyCsrf();
        $engine = $_POST['engine'] ?? '';
        $errors = [];

        if ($engine === 'postgresql' || $engine === 'all') {
            // Find PostgreSQL config files for all instances
            $pgConfigs = [];

            // Panel instance (5433)
            $panelConf = glob('/etc/postgresql/*/panel/postgresql.conf');
            foreach ($panelConf as $f) $pgConfigs[] = $f;

            // Hosting instance (5432)
            $hostingConf = glob('/etc/postgresql/*/main/postgresql.conf');
            foreach ($hostingConf as $f) $pgConfigs[] = $f;

            foreach ($pgConfigs as $conf) {
                $content = @file_get_contents($conf);
                if ($content === false) {
                    $errors[] = "Cannot read {$conf}";
                    continue;
                }

                // Replace timezone setting
                $newContent = preg_replace(
                    "/^(timezone\s*=\s*).*/m",
                    "timezone = 'UTC'",
                    $content
                );
                $newContent = preg_replace(
                    "/^(log_timezone\s*=\s*).*/m",
                    "log_timezone = 'UTC'",
                    $newContent
                );

                if ($newContent !== $content) {
                    if (@file_put_contents($conf, $newContent) === false) {
                        // Try via shell
                        $escaped = escapeshellarg($conf);
                        shell_exec("sed -i \"s/^timezone = .*/timezone = 'UTC'/\" {$escaped} 2>&1");
                        shell_exec("sed -i \"s/^log_timezone = .*/log_timezone = 'UTC'/\" {$escaped} 2>&1");
                    }
                }
            }

            // Restart PostgreSQL instances in background (delay 2s so the HTTP response arrives first)
            shell_exec('nohup bash -c "sleep 2 && systemctl restart postgresql 2>/dev/null; pg_ctlcluster 14 panel restart 2>/dev/null; pg_ctlcluster 14 main restart 2>/dev/null" > /dev/null 2>&1 &');

            LogService::log('system.health', 'fix-timezone', 'PostgreSQL timezone set to UTC');
        }

        if ($engine === 'mysql' || $engine === 'all') {
            // Set MySQL timezone to UTC
            $myCnfPaths = ['/etc/mysql/mysql.conf.d/mysqld.cnf', '/etc/mysql/my.cnf', '/etc/my.cnf'];
            $found = false;

            foreach ($myCnfPaths as $cnf) {
                if (!file_exists($cnf)) continue;
                $content = @file_get_contents($cnf);
                if ($content === false) continue;
                $found = true;

                // Check if default-time-zone is already set
                if (preg_match('/^default-time-zone\s*=/m', $content)) {
                    $content = preg_replace('/^default-time-zone\s*=.*/m', 'default-time-zone = "+00:00"', $content);
                } else {
                    // Add after [mysqld] section
                    $content = preg_replace('/^\[mysqld\]\s*$/m', "[mysqld]\ndefault-time-zone = \"+00:00\"", $content);
                }

                @file_put_contents($cnf, $content);
                break;
            }

            if (!$found) {
                $errors[] = 'MySQL config file not found';
            } else {
                shell_exec('nohup bash -c "sleep 2 && systemctl restart mysql 2>/dev/null || systemctl restart mariadb 2>/dev/null" > /dev/null 2>&1 &');
                LogService::log('system.health', 'fix-timezone', 'MySQL timezone set to UTC');
            }
        }

        if (!empty($errors)) {
            Flash::set('warning', 'Timezone parcialmente actualizado: ' . implode(', ', $errors));
        } else {
            Flash::set('success', 'Timezone de base de datos configurado a UTC correctamente. Servicios reiniciados.');
        }

        Router::redirect('/settings/health');
    }

    /**
     * POST /settings/health/repair-db — Re-run schema.sql to create any missing tables
     */
    public function healthRepairDb(): void
    {
        View::verifyCsrf();
        $panelDir = defined('PANEL_ROOT') ? PANEL_ROOT : '/opt/musedock-panel';
        $schemaFile = $panelDir . '/database/schema.sql';

        if (!file_exists($schemaFile)) {
            Flash::set('error', 'schema.sql no encontrado.');
            Router::redirect('/settings/health');
            return;
        }

        try {
            $sql = file_get_contents($schemaFile);
            Database::connect()->exec($sql);

            // Also run pending migrations
            \MuseDockPanel\Services\MigrationService::runPending();

            LogService::log('system.health', 'repair-db', 'Re-applied schema.sql + pending migrations');
            Flash::set('success', 'Base de datos reparada: schema aplicado y migraciones ejecutadas.');
        } catch (\Throwable $e) {
            Flash::set('error', 'Error reparando BD: ' . $e->getMessage());
        }

        Router::redirect('/settings/health');
    }

    public function healthInstallPackage(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $package = preg_replace('/[^a-z0-9._-]/i', '', $_POST['package'] ?? '');
        $allowed = [
            'postgresql-client', 'mariadb-client', 'wireguard-tools', 'ufw', 'fail2ban',
            'rsync', 'git', 'coreutils', 'nodejs', 'npm', 'composer', 'sshpass', 'tar', 'unzip',
        ];

        // Also allow php extensions: php8.x-{name}
        if (preg_match('/^php\d+\.\d+-[a-z0-9]+$/', $package)) {
            $allowed[] = $package;
        }

        if (!$package || !in_array($package, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Paquete no permitido']);
            exit;
        }

        $output = shell_exec("apt-get update -qq 2>&1 && apt-get install -y -qq {$package} 2>&1");
        $ok = (int)shell_exec("dpkg -l {$package} 2>/dev/null | grep -c '^ii'") > 0;

        if ($ok) {
            \MuseDockPanel\Services\LogService::log('health', "Package {$package} installed via panel");
        }

        echo json_encode([
            'ok' => $ok,
            'message' => $ok ? "{$package} instalado correctamente" : "Error instalando {$package}",
            'output' => substr($output ?? '', -300),
        ]);
        exit;
    }
}
