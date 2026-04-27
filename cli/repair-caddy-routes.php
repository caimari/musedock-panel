#!/usr/bin/env php
<?php
/**
 * Lightweight Caddy repair for MuseDock Panel nodes.
 * Ensures srv0/listeners exist and reapplies panel domain route/TLS policies.
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
$config = require PANEL_ROOT . '/config/panel.php';
$caddyApi = $config['caddy']['api_url'] ?? 'http://localhost:2019';

echo "[repair-caddy] API: {$caddyApi}\n";

$repairWebmailRoute = static function (): void {
    try {
        $result = \MuseDockPanel\Services\WebmailService::repairConfiguredRoute();
        if (!empty($result['skipped'])) {
            return;
        }
        if ($result['ok'] ?? false) {
            echo "[repair-caddy] OK: ruta webmail aplicada.\n";
        } else {
            fwrite(STDERR, "[repair-caddy] WARNING webmail: " . ($result['error'] ?? 'error desconocido') . "\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[repair-caddy] WARNING webmail: " . $e->getMessage() . "\n");
    }
};

$panelPort = (int)\MuseDockPanel\Env::get('PANEL_PORT', 8444);
$caddyfile = @file_get_contents('/etc/caddy/Caddyfile') ?: '';
$staticPanelTls = preg_match('/(^|\n)\s*(https?:\/\/[^\s{]+:' . preg_quote((string)$panelPort, '/') . '|:' . preg_quote((string)$panelPort, '/') . ')\b/', $caddyfile)
    && preg_match('/\btls\s+internal\b/', $caddyfile);
if ($staticPanelTls) {
    echo "[repair-caddy] INFO: PANEL_PORT {$panelPort} esta declarado en Caddyfile con tls internal; se intentara reponer ruta runtime del dominio del panel si procede.\n";
}

if (!\MuseDockPanel\Services\SystemService::ensureCaddyHttpServerReady($caddyApi, true)) {
    fwrite(STDERR, "[repair-caddy] ERROR: no se pudo preparar srv0/listeners.\n");
    exit(1);
}

// Post-check: do not report success if Caddy still returns malformed listen/routes payloads.
$listenRaw = @file_get_contents("{$caddyApi}/config/apps/http/servers/srv0/listen");
$routesRaw = @file_get_contents("{$caddyApi}/config/apps/http/servers/srv0/routes");
$listen = json_decode((string)$listenRaw, true);
$routes = json_decode((string)$routesRaw, true);
if (!is_array($listen) || !array_is_list($listen) || !is_array($routes) || !array_is_list($routes)) {
    fwrite(STDERR, "[repair-caddy] ERROR: srv0 incompleto (listen=" . trim((string)$listenRaw) . ", routes=" . trim((string)$routesRaw) . ").\n");
    exit(1);
}

echo "[repair-caddy] OK: srv0/listeners activos.\n";

$panelOwner = \MuseDockPanel\Services\SystemService::panelPortOwner($caddyApi);
$panelManaged = \MuseDockPanel\Services\SystemService::panelRuntimeManagedByPanel($caddyApi);
if (!$panelManaged) {
    echo "[repair-caddy] INFO: PANEL_PORT gestionado por {$panelOwner}; se intentara reponer ruta runtime del dominio del panel en ese servidor si apunta al panel interno.\n";
}

try {
    \MuseDockPanel\Services\SystemService::ensureTlsCatchAllPolicy($caddyApi);
    $policiesRaw = @file_get_contents("{$caddyApi}/config/apps/tls/automation/policies");
    $policies = json_decode((string)$policiesRaw, true);
    $hasAcmeCatchAll = false;
    if (is_array($policies)) {
        foreach ($policies as $policy) {
            if (!empty($policy['subjects'])) {
                continue;
            }
            foreach (($policy['issuers'] ?? []) as $issuer) {
                if (($issuer['module'] ?? '') === 'acme' && !isset($issuer['challenges']['dns'])) {
                    $hasAcmeCatchAll = true;
                    break 2;
                }
            }
            break;
        }
    }
    if ($hasAcmeCatchAll) {
        echo "[repair-caddy] OK: politicas TLS verificadas.\n";
    } else {
        fwrite(STDERR, "[repair-caddy] WARNING TLS: catch-all ACME HTTP-01 ausente (posible policy internal-only).\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[repair-caddy] WARNING TLS: " . $e->getMessage() . "\n");
}

$result = \MuseDockPanel\Services\SystemService::ensurePanelDomainRouteFromSettings();
if (!empty($result['skipped'])) {
    $reason = (string)($result['reason'] ?? '');
    if ($reason === 'panel_hostname_empty') {
        echo "[repair-caddy] INFO: panel_hostname vacio, no se aplica ruta dedicada.\n";
    } elseif (str_starts_with($reason, 'panel-port-owned-by-')) {
        $owner = substr($reason, strlen('panel-port-owned-by-'));
        echo "[repair-caddy] INFO: PANEL_PORT ya lo gestiona {$owner}; no se pudo aplicar ruta dedicada del panel en runtime.\n";
    } else {
        echo "[repair-caddy] INFO: ruta dedicada del panel omitida ({$reason}).\n";
    }
} elseif ($result['ok'] ?? false) {
    $panelHostname = trim((string)\MuseDockPanel\Settings::get('panel_hostname', ''));
    if (!empty($result['idempotent'])) {
        echo "[repair-caddy] OK: ruta del panel ya estaba aplicada para {$panelHostname}.\n";
    } else {
        echo "[repair-caddy] OK: ruta del panel aplicada para {$panelHostname}.\n";
    }
    if (!empty($result['warning'])) {
        fwrite(STDERR, "[repair-caddy] WARNING route: " . $result['warning'] . "\n");
    }
} else {
    fwrite(STDERR, "[repair-caddy] WARNING route: " . ($result['error'] ?? 'error desconocido') . "\n");
}

$repairWebmailRoute();

echo "[repair-caddy] DONE\n";
