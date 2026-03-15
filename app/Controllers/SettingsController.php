<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class SettingsController
{
    // ================================================================
    // Services
    // ================================================================

    public function services(): void
    {
        $services = $this->getServicesStatus();

        View::render('settings/services', [
            'layout' => 'main',
            'pageTitle' => 'Servicios del Servidor',
            'services' => $services,
        ]);
    }

    public function serviceAction(): void
    {
        $service = $_POST['service'] ?? '';
        $action = $_POST['action'] ?? '';

        $allowed = $this->getAllowedServices();
        if (!in_array($service, $allowed)) {
            Flash::set('error', 'Servicio no permitido.');
            Router::redirect('/settings/services');
            return;
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload'];

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
            $repairOutput = shell_exec('/usr/bin/php8.3 /var/www/vhosts/musedock.com/httpdocs/cli/repair-caddy-routes.php 2>&1') ?? '';
            LogService::log('caddy.repair', 'auto', "Auto-repair after {$action}");

            // Update autosave
            $currentConfig = @file_get_contents('http://localhost:2019/config/');
            if ($currentConfig) {
                @file_put_contents('/var/lib/caddy/.config/caddy/autosave.json', $currentConfig);
            }
        }

        $actionLabels = ['start' => 'iniciado', 'stop' => 'detenido', 'restart' => 'reiniciado', 'reload' => 'recargado'];
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

    // ================================================================
    // Server Info & Panel URL
    // ================================================================

    public function server(): void
    {
        $settings = \MuseDockPanel\Settings::getAll();

        // Detect server info
        $hostname = trim(shell_exec('hostname') ?? '');
        $os = trim(shell_exec('uname -r') ?? '');
        $distro = trim(shell_exec('lsb_release -ds 2>/dev/null') ?? '');
        $uptime = trim(shell_exec("uptime -p 2>/dev/null | sed 's/up //'") ?? '');
        $currentTz = trim(shell_exec('timedatectl show --property=Timezone --value 2>/dev/null') ?? date_default_timezone_get());
        $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '');

        // Get all timezones
        $timezones = \DateTimeZone::listIdentifiers();

        // Panel access info
        $panelPort = \MuseDockPanel\Env::get('PANEL_PORT', '8444');

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
        ]);
    }

    public function serverSave(): void
    {
        $timezone = trim($_POST['timezone'] ?? '');
        $panelHostname = trim($_POST['panel_hostname'] ?? '');
        $panelProtocol = trim($_POST['panel_protocol'] ?? 'http');

        // Validate timezone
        if (!empty($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers())) {
            \MuseDockPanel\Settings::set('panel_timezone', $timezone);
            // Apply system timezone
            shell_exec(sprintf('timedatectl set-timezone %s 2>/dev/null', escapeshellarg($timezone)));
        }

        // Panel hostname (optional — for HTTPS with domain)
        \MuseDockPanel\Settings::set('panel_hostname', $panelHostname);
        \MuseDockPanel\Settings::set('panel_protocol', in_array($panelProtocol, ['http', 'https']) ? $panelProtocol : 'http');

        // Detect and store server IP
        $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '');
        \MuseDockPanel\Settings::set('server_ip', $serverIp);

        LogService::log('settings.server', 'server', "Updated server settings: tz={$timezone}, hostname={$panelHostname}, protocol={$panelProtocol}");
        Flash::set('success', 'Configuracion del servidor guardada.');
        Router::redirect('/settings/server');
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

            $versions[$ver] = [
                'version' => $ver,
                'binary' => $phpBin,
                'ini_file' => $iniFile,
                'ini' => $ini,
                'pools' => $poolCount,
                'status' => $status,
                'extensions' => $extList,
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

    // ================================================================
    // Security
    // ================================================================

    public function security(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        $allowedIps = \MuseDockPanel\Env::get('ALLOWED_IPS', '');

        // Active sessions
        $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
        $sessionCount = count(glob("{$sessionPath}/sess_*"));

        View::render('settings/security', [
            'layout' => 'main',
            'pageTitle' => 'Seguridad',
            'allowedIps' => $allowedIps,
            'sessionCount' => $sessionCount,
        ]);
    }

    public function securitySave(): void
    {
        $allowedIps = trim($_POST['allowed_ips'] ?? '');

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

        LogService::log('settings.security', 'allowed_ips', "Updated ALLOWED_IPS: {$allowedIps}");
        Flash::set('success', 'Configuracion de seguridad guardada.');
        Router::redirect('/settings/security');
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
}
