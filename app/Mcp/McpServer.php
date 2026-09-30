<?php
namespace MuseDockPanel\Mcp;

/**
 * Núcleo del protocolo MCP (JSON-RPC 2.0), sin dependencias.
 *
 * Es agnóstico del transporte: lo usan igual el endpoint HTTP (/api/mcp, sin
 * streaming, porque el panel corre en `php -S` monohilo y una conexión abierta
 * bloquearía todo el panel) y el transporte stdio por SSH (bin/mcp-stdio.php).
 */
final class McpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Atiende un mensaje JSON-RPC. Devuelve la respuesta, o null si era una
     * notificación (no lleva id y no se responde).
     */
    public static function handle(mixed $msg, string $via): ?array
    {
        if (!is_array($msg) || array_is_list($msg)) {
            return self::error(null, -32600, 'Invalid Request');
        }
        $isNotification = !array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? null;

        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            return $isNotification ? null : self::error($id, -32600, 'Invalid Request');
        }
        if ($isNotification || str_starts_with($method, 'notifications/')) {
            return null; // notifications/initialized, cancelled… no se responden
        }

        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        try {
            switch ($method) {
                case 'initialize':
                    $asked = (string)($params['protocolVersion'] ?? '');
                    return self::ok($id, [
                        'protocolVersion' => in_array($asked, self::SUPPORTED_VERSIONS, true) ? $asked : self::SUPPORTED_VERSIONS[0],
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => [
                            'name' => 'musedock-panel',
                            'title' => 'MuseDock Panel',
                            'version' => self::panelVersion(),
                        ],
                        'instructions' => 'Servidor MCP de MuseDock Panel (' . gethostname() . '). '
                            . 'Herramientas de lectura para consultar el estado del servidor, del cluster, servicios, correo, certificados y failover. '
                            . 'Usa list_nodes y el argumento `node` para consultar otros nodos del cluster desde el master. '
                            . 'clone_inventory detecta lo que habría que clonar para crear un slave exacto, incluido lo que el panel no gestiona. '
                            . 'Herramientas de correo que MODIFICAN (mail_domain_create, mail_dns_publish, mail_mailbox_create, mail_alias_create): '
                            . 'llámalas SIEMPRE primero sin apply para obtener el plan, muéstraselo al usuario y pide su confirmación explícita '
                            . 'antes de repetir la llamada con apply=true. Nunca pidas, escribas ni muestres contraseñas en la conversación: '
                            . 'si no se indica una, el panel la genera y el usuario la ve en Ajustes → MCP → Credenciales pendientes. '
                            . 'Tras mail_dns_publish, verifica con mail_domain_verify al cabo de unos minutos. '
                            . 'Flujo típico de "dominio solo de correo": mail_domain_create → mail_dns_publish → mail_mailbox_create / mail_alias_create → mail_domain_verify. '
                            . 'Cluster y failover: failover_preflight (lectura) dice en llano qué falta para que un relevo funcione; ejecútalo en el master Y en el slave. '
                            . 'replication_adopt registra una réplica de PostgreSQL que ya funciona (no toca datos); failover_configure (en el master) define primario, '
                            . 'servidor de relevo con IPs públicas y modo; cluster_node_services fija si un nodo es web, mail o ambos. '
                            . 'Mismo protocolo que el correo: primero sin apply, enseñar el plan y pedir confirmación.',
                    ]);

                case 'ping':
                    return self::ok($id, new \stdClass());

                case 'tools/list':
                    return self::ok($id, ['tools' => McpTools::listForMcp()]);

                case 'tools/call':
                    $name = (string)($params['name'] ?? '');
                    if (!McpTools::exists($name)) {
                        return self::error($id, -32602, "Herramienta desconocida: {$name}");
                    }
                    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                    return self::ok($id, McpTools::call($name, $args, $via));

                default:
                    return self::error($id, -32601, "Método no soportado: {$method}");
            }
        } catch (\Throwable $e) {
            return self::error($id, -32603, 'Error interno: ' . $e->getMessage());
        }
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function panelVersion(): string
    {
        $cfg = @file_get_contents(PANEL_ROOT . '/config/panel.php') ?: '';
        return preg_match("/'version'\s*=>\s*'([^']+)'/", $cfg, $m) ? $m[1] : '0';
    }
}
