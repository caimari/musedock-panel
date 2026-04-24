<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Env;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\FirewallService;
use MuseDockPanel\View;

/**
 * First-run setup wizard.
 * Shown only when no admin users exist in the database.
 */
class SetupController
{
    public static function needsSetup(): bool
    {
        try {
            $admin = Database::fetchOne("SELECT id FROM panel_admins LIMIT 1");
            return $admin === null;
        } catch (\Throwable) {
            // Database not ready — show setup
            return true;
        }
    }

    public function index(): void
    {
        if (!self::needsSetup()) {
            Router::redirect('/login');
            return;
        }

        // Check system requirements
        $checks = $this->runChecks();

        View::render('setup/index', [
            'pageTitle' => 'Setup',
            'checks' => $checks,
            'firewall' => $this->firewallSetupInfo(),
        ]);
    }

    public function install(): void
    {
        if (!self::needsSetup()) {
            Router::redirect('/login');
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $firewallMode = trim((string)($_POST['firewall_mode'] ?? 'skip'));
        $firewallTrustedSource = trim((string)($_POST['firewall_trusted_source'] ?? ''));

        if (!View::verifyCsrf()) {
            Flash::set('error', 'Token CSRF invalido. Recarga el setup e intentalo de nuevo.');
            Router::redirect('/setup');
            return;
        }

        // Validate
        if (empty($username) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,49}$/', $username)) {
            Flash::set('error', 'Username invalido (3-50 caracteres, alfanumerico).');
            Router::redirect('/setup');
            return;
        }

        if (strlen($password) < 8) {
            Flash::set('error', 'La contrasena debe tener al menos 8 caracteres.');
            Router::redirect('/setup');
            return;
        }

        if ($password !== $passwordConfirm) {
            Flash::set('error', 'Las contrasenas no coinciden.');
            Router::redirect('/setup');
            return;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Email no valido.');
            Router::redirect('/setup');
            return;
        }

        if ($firewallMode !== 'skip' && !$this->isValidTrustedSource($firewallTrustedSource)) {
            Flash::set('error', 'IP/rango de confianza no valido para firewall. Usa una IP o CIDR IPv4, por ejemplo 203.0.113.10 o 203.0.113.0/24.');
            Router::redirect('/setup');
            return;
        }

        try {
            // Try to create tables if they don't exist
            $schemaFile = PANEL_ROOT . '/database/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                Database::connect()->exec($sql);
            }

            // Create admin user
            Database::insert('panel_admins', [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'email' => $email ?: null,
                'role' => 'admin',
            ]);

            $message = 'Panel instalado correctamente. Inicia sesion con tu cuenta de admin.';
            if ($firewallMode !== 'skip') {
                $fwResult = $this->applyFirstRunFirewall($firewallMode, $firewallTrustedSource);
                $message .= $fwResult['ok']
                    ? ' Firewall configurado para acceso de confianza.'
                    : ' Aviso: el panel se instalo, pero no se pudo configurar el firewall: ' . $fwResult['message'];
            }

            Flash::set('success', $message);
            Router::redirect('/login');
        } catch (\Throwable $e) {
            Flash::set('error', 'Error durante la instalacion: ' . $e->getMessage());
            Router::redirect('/setup');
        }
    }

    private function runChecks(): array
    {
        $phpVer = PHP_VERSION;
        $checks = [
            [
                'name' => "PHP $phpVer",
                'ok' => version_compare($phpVer, '8.0.0', '>='),
                'detail' => version_compare($phpVer, '8.0.0', '>=') ? 'OK' : 'Se requiere PHP 8.0+',
            ],
            [
                'name' => 'Extension: pdo_pgsql',
                'ok' => extension_loaded('pdo_pgsql'),
                'detail' => extension_loaded('pdo_pgsql') ? 'OK' : 'Instalar: apt install php-pgsql',
            ],
            [
                'name' => 'Extension: curl',
                'ok' => extension_loaded('curl'),
                'detail' => extension_loaded('curl') ? 'OK' : 'Instalar: apt install php-curl',
            ],
            [
                'name' => 'Extension: mbstring',
                'ok' => extension_loaded('mbstring'),
                'detail' => extension_loaded('mbstring') ? 'OK' : 'Instalar: apt install php-mbstring',
            ],
        ];

        // Database connection
        $dbOk = false;
        $dbDetail = '';
        try {
            Database::connect();
            $dbOk = true;
            $dbDetail = 'Conectado';
        } catch (\Throwable $e) {
            $dbDetail = 'Error: ' . $e->getMessage();
        }
        $checks[] = ['name' => 'PostgreSQL', 'ok' => $dbOk, 'detail' => $dbDetail];

        // Caddy API
        $caddyOk = false;
        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyUrl = $config['caddy']['api_url'] . '/config/';
        $ch = curl_init($caddyUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $caddyOk = $code >= 200 && $code < 400;
        $checks[] = ['name' => 'Caddy API', 'ok' => $caddyOk, 'detail' => $caddyOk ? 'Online' : 'No accesible en ' . $config['caddy']['api_url']];

        // Writable storage
        $storageOk = is_writable(PANEL_ROOT . '/storage');
        $checks[] = ['name' => 'Storage dir writable', 'ok' => $storageOk, 'detail' => $storageOk ? 'OK' : 'chmod 750 storage/'];

        // .env exists
        $envOk = file_exists(PANEL_ROOT . '/.env');
        $checks[] = ['name' => '.env file', 'ok' => $envOk, 'detail' => $envOk ? 'OK' : 'Copiar .env.example a .env y configurar'];

        return $checks;
    }

    private function firewallSetupInfo(): array
    {
        $adminIp = FirewallService::getAdminIp();
        $panelPort = (int)Env::get('PANEL_PORT', 8444);
        $ufwInstalled = $this->commandExists('ufw');
        $ufwStatus = $ufwInstalled ? (string)shell_exec('ufw status 2>/dev/null') : '';
        $ufwActive = stripos($ufwStatus, 'active') !== false && stripos($ufwStatus, 'inactive') === false;
        $iptablesInstalled = $this->commandExists('iptables');
        $iptablesPolicy = $iptablesInstalled ? $this->iptablesInputPolicy() : 'none';
        $iptablesHasProtectiveRules = $iptablesInstalled && $this->iptablesHasProtectiveRules();

        $active = $ufwActive || $iptablesHasProtectiveRules;
        $type = $ufwActive ? 'ufw' : ($iptablesHasProtectiveRules ? 'iptables' : ($ufwInstalled ? 'ufw-inactive' : 'none'));

        return [
            'type' => $type,
            'active' => $active,
            'admin_ip' => $adminIp,
            'panel_port' => $panelPort,
            'ufw_installed' => $ufwInstalled,
            'iptables_installed' => $iptablesInstalled,
            'iptables_policy' => $iptablesPolicy,
            'can_manage' => function_exists('posix_geteuid') ? posix_geteuid() === 0 : true,
        ];
    }

    private function applyFirstRunFirewall(string $mode, string $trustedSource): array
    {
        $panelPort = (int)Env::get('PANEL_PORT', 8444);

        if ($mode === 'allow_existing') {
            $info = $this->firewallSetupInfo();
            if ($info['type'] === 'ufw') {
                $results = [
                    FirewallService::ufwAddRule('allow', $trustedSource, '22', 'tcp', 'MuseDock trusted SSH'),
                    FirewallService::ufwAddRule('allow', $trustedSource, (string)$panelPort, 'tcp', 'MuseDock trusted panel'),
                ];
                return $this->finalizeFirewallResult($results, $trustedSource);
            }

            if ($info['type'] === 'iptables') {
                $results = [
                    $this->iptablesInsertAllow($trustedSource, 22),
                    $this->iptablesInsertAllow($trustedSource, $panelPort),
                    FirewallService::iptablesSave(),
                ];
                return $this->finalizeFirewallResult($results, $trustedSource);
            }

            return ['ok' => false, 'message' => 'no se detecto firewall activo compatible'];
        }

        if ($mode === 'install_ufw') {
            if (!$this->commandExists('ufw')) {
                $install = $this->installUfw();
                if (!$install['ok']) {
                    return $install;
                }
            }

            $commands = [
                'ufw default deny incoming 2>&1',
                'ufw default allow outgoing 2>&1',
            ];
            $results = [];
            foreach ($commands as $cmd) {
                $output = trim((string)shell_exec($cmd));
                $results[] = ['ok' => stripos($output, 'error') === false, 'output' => $output ?: 'OK', 'cmd' => $cmd];
            }

            $results[] = FirewallService::ufwAddRule('allow', $trustedSource, '22', 'tcp', 'MuseDock trusted SSH');
            $results[] = FirewallService::ufwAddRule('allow', $trustedSource, (string)$panelPort, 'tcp', 'MuseDock trusted panel');
            $results[] = FirewallService::ufwEnable();

            return $this->finalizeFirewallResult($results, $trustedSource);
        }

        return ['ok' => false, 'message' => 'modo de firewall no reconocido'];
    }

    private function finalizeFirewallResult(array $results, string $trustedSource): array
    {
        $failed = array_values(array_filter($results, static fn(array $r): bool => empty($r['ok'])));
        if (!empty($failed)) {
            return [
                'ok' => false,
                'message' => implode(' | ', array_map(static fn(array $r): string => (string)($r['output'] ?? 'error desconocido'), $failed)),
            ];
        }

        $this->mergeAllowedIps($trustedSource);
        return ['ok' => true, 'message' => 'OK'];
    }

    private function installUfw(): array
    {
        $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get update >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y ufw 2>&1';
        $output = trim((string)shell_exec($cmd));
        $ok = $this->commandExists('ufw');
        return [
            'ok' => $ok,
            'message' => $ok ? 'UFW instalado' : ($output ?: 'no se pudo instalar UFW'),
            'output' => $output,
        ];
    }

    private function iptablesInsertAllow(string $trustedSource, int $port): array
    {
        $cmd = sprintf(
            'iptables -I INPUT 1 -p tcp -s %s --dport %d -j ACCEPT -m comment --comment %s 2>&1',
            escapeshellarg($trustedSource),
            $port,
            escapeshellarg($port === 22 ? 'MuseDock trusted SSH' : 'MuseDock trusted panel')
        );
        $output = trim((string)shell_exec($cmd));
        return [
            'ok' => $output === '' || stripos($output, 'error') === false,
            'output' => $output ?: 'Regla agregada',
            'cmd' => $cmd,
        ];
    }

    private function mergeAllowedIps(string $trustedSource): void
    {
        $envFile = PANEL_ROOT . '/.env';
        if (!is_file($envFile) || !is_writable($envFile)) {
            return;
        }

        $current = trim((string)Env::get('ALLOWED_IPS', ''));
        $items = $current !== '' ? array_filter(array_map('trim', explode(',', $current))) : [];
        if (!in_array($trustedSource, $items, true)) {
            $items[] = $trustedSource;
        }
        $allowedIps = implode(',', $items);

        $envContent = (string)file_get_contents($envFile);
        if (preg_match('/^ALLOWED_IPS=.*/m', $envContent)) {
            $envContent = preg_replace('/^ALLOWED_IPS=.*/m', 'ALLOWED_IPS=' . $allowedIps, $envContent);
        } else {
            $envContent .= "\nALLOWED_IPS={$allowedIps}\n";
        }
        file_put_contents($envFile, $envContent);
    }

    private function isValidTrustedSource(string $source): bool
    {
        if (filter_var($source, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $source, $m)) {
            return false;
        }

        if (!filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $prefix = (int)$m[2];
        return $prefix >= 0 && $prefix <= 32;
    }

    private function commandExists(string $command): bool
    {
        return trim((string)shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
    }

    private function iptablesInputPolicy(): string
    {
        $output = (string)shell_exec('iptables -L INPUT 2>/dev/null');
        $firstLine = strtok($output, "\n") ?: '';
        return preg_match('/policy\s+(\w+)/i', $firstLine, $m) ? strtoupper($m[1]) : 'UNKNOWN';
    }

    private function iptablesHasProtectiveRules(): bool
    {
        $policy = $this->iptablesInputPolicy();
        if (in_array($policy, ['DROP', 'REJECT'], true)) {
            return true;
        }

        $rules = trim((string)shell_exec('iptables -S INPUT 2>/dev/null'));
        if ($rules === '') {
            return false;
        }

        foreach (explode("\n", $rules) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-P INPUT')) {
                continue;
            }
            if (str_contains($line, 'DROP') || str_contains($line, 'REJECT')) {
                return true;
            }
        }

        return false;
    }
}
