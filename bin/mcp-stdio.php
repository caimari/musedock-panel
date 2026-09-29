<?php
/**
 * Transporte stdio del servidor MCP de MuseDock Panel.
 *
 * Uso típico desde VS Code / Claude Code (la autenticación es tu clave SSH):
 *   ssh root@servidor php /opt/musedock-panel/bin/mcp-stdio.php
 *
 * Lee mensajes JSON-RPC (uno por línea) de stdin y escribe las respuestas en
 * stdout. Todo lo que no sea JSON-RPC va a stderr para no corromper el canal.
 * Respeta el interruptor del panel: si el MCP está desactivado, no arranca.
 */

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use MuseDockPanel\Mcp\McpServer;
use MuseDockPanel\Settings;

if (Settings::get('mcp_enabled', '0') !== '1') {
    fwrite(STDERR, "MuseDock MCP está desactivado en este servidor. Actívalo en Ajustes → MCP.\n");
    exit(1);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg)) {
        $resp = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
    } else {
        $resp = McpServer::handle($msg, 'stdio');
    }
    if ($resp !== null) {
        fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush(STDOUT);
    }
}
