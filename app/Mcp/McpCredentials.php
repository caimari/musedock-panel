<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

/**
 * Credenciales que crea el MCP (buzones, acceso SFTP de un hosting, usuarios de
 * base de datos…). Regla: el secreto se genera en el servidor y NUNCA se devuelve
 * en la respuesta de la herramienta (quedaría en el historial del chat). Se guarda
 * cifrado y el usuario lo ve una vez en Ajustes → MCP → Credenciales pendientes;
 * caduca a los 7 días o cuando las borra.
 */
class McpCredentials
{
    private const KEY = 'mcp_pending_credentials';
    private const TTL = 7 * 86400;

    public const KINDS = [
        'mail'     => 'Buzón de correo',
        'sftp'     => 'Acceso SFTP/SSH del hosting',
        'database' => 'Usuario de base de datos',
        'other'    => 'Otra credencial',
    ];

    public static function generate(int $len = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_.';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * $label: para qué es (buzón, usuario@host…). $details: datos NO secretos que
     * ayudan a usarla (host, puerto, base de datos…).
     */
    public static function store(string $kind, string $label, string $secret, array $details = []): void
    {
        $list = array_values(array_filter(self::raw(), fn($c) => (int)($c['at'] ?? 0) > time() - self::TTL));
        $list[] = [
            'kind'    => isset(self::KINDS[$kind]) ? $kind : 'other',
            'label'   => $label,
            'enc'     => ReplicationService::encryptPassword($secret),
            'details' => $details,
            'at'      => time(),
        ];
        Settings::set(self::KEY, json_encode(array_slice($list, -30)));
    }

    /** Para Ajustes → MCP (descifradas). */
    public static function pending(): array
    {
        $out = [];
        foreach (self::raw() as $c) {
            if ((int)($c['at'] ?? 0) <= time() - self::TTL) {
                continue;
            }
            $kind = (string)($c['kind'] ?? 'mail');     // las antiguas eran todas de buzones
            $out[] = [
                'kind'       => $kind,
                'kind_label' => self::KINDS[$kind] ?? $kind,
                'label'      => (string)($c['label'] ?? $c['email'] ?? '?'),
                'password'   => ReplicationService::decryptPassword((string)$c['enc']),
                'details'    => (array)($c['details'] ?? []),
                'at'         => gmdate('Y-m-d H:i', (int)$c['at']) . ' UTC',
            ];
        }
        return $out;
    }

    /** Texto para la respuesta de la herramienta (sin el secreto). */
    public static function notice(string $label): string
    {
        return "La contraseña de {$label} se ha generado en el servidor y NO se muestra aquí: el usuario la ve una vez en Ajustes → MCP → Credenciales pendientes.";
    }

    private static function raw(): array
    {
        return json_decode(Settings::get(self::KEY, '[]'), true) ?: [];
    }
}
