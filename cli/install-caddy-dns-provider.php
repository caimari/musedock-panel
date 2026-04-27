#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — background installer for Caddy DNS provider modules.
 */

define('PANEL_ROOT', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

$provider = strtolower(trim((string)($argv[1] ?? '')));
$statusFile = PANEL_ROOT . '/storage/caddy-dns-provider-install.json';

$writeStatus = static function (array $data) use ($statusFile): void {
    @mkdir(dirname($statusFile), 0770, true);
    @file_put_contents($statusFile, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
};

if ($provider === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $provider)) {
    $writeStatus([
        'status' => 'error',
        'provider' => $provider,
        'finished_at' => gmdate('c'),
        'message' => 'Proveedor DNS invalido',
    ]);
    fwrite(STDERR, "Proveedor DNS invalido\n");
    exit(1);
}

$writeStatus([
    'status' => 'running',
    'provider' => $provider,
    'started_at' => gmdate('c'),
    'message' => "Compilando Caddy con dns.providers.{$provider}",
]);

try {
    $result = \MuseDockPanel\Services\SystemService::installCaddyDnsProvider($provider);
} catch (\Throwable $e) {
    $result = [
        'ok' => false,
        'error' => $e->getMessage(),
    ];
}

if ($result['ok'] ?? false) {
    $writeStatus([
        'status' => 'ok',
        'provider' => $provider,
        'finished_at' => gmdate('c'),
        'message' => (string)($result['message'] ?? "dns.providers.{$provider} instalado"),
        'backup' => (string)($result['backup'] ?? ''),
        'module' => (string)($result['module'] ?? ''),
        'version' => (string)($result['version'] ?? ''),
    ]);
    echo "[OK] " . ($result['message'] ?? "dns.providers.{$provider} instalado") . "\n";
    exit(0);
}

$message = (string)($result['error'] ?? "No se pudo instalar dns.providers.{$provider}");
if (!empty($result['rolled_back'])) {
    $message .= ' Se restauro el binario anterior.';
}

$writeStatus([
    'status' => 'error',
    'provider' => $provider,
    'finished_at' => gmdate('c'),
    'message' => $message,
    'backup' => (string)($result['backup'] ?? ''),
    'output' => (string)($result['output'] ?? ''),
]);
fwrite(STDERR, "[ERROR] {$message}\n");
if (!empty($result['output'])) {
    fwrite(STDERR, (string)$result['output'] . "\n");
}
exit(1);
