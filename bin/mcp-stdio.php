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
    } elseif (($msg['method'] ?? '') === 'tools/call'
        && !empty($msg['params']['arguments']['apply'])
        && \MuseDockPanel\Mcp\McpTools::isWrite((string)($msg['params']['name'] ?? ''))
        && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        // Sin root, una acción que modifica se queda a medias: la BD sí se escribe,
        // pero no los ficheros del sistema (DKIM, Maildir, Caddy…). Pasó el 2026-10-02.
        $resp = ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null, 'result' => [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'Este transporte stdio no corre como root: las acciones que modifican (apply) quedarían a medias. Ejecútalo como root (ssh root@servidor php bin/mcp-stdio.php) o usa el MCP del panel.']],
        ]];
    } else {
        $resp = McpServer::handle($msg, 'stdio');
    }
    if ($resp !== null) {
        fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush(STDOUT);
    }
}
