<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Database;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\MailService;
use MuseDockPanel\Settings;

/**
 * Cambios que la IA prepara por MCP pero que no se aplican hasta que un
 * administrador los aprueba en Ajustes → MCP (modal de confirmación, se pueden
 * aprobar o rechazar varios a la vez). Para cambios sin secretos; los de
 * contraseña van por McpPasswordChanges, que además pide la contraseña del admin.
 *
 * Tipos: mail_quota (cuota de un buzón; 0 = sin límite).
 */
class McpChangeRequests
{
    private const KEY = 'mcp_change_requests';
    private const TTL = 7 * 86400;

    public const TYPES = ['mail_quota' => 'Cuota de buzón'];

    public static function add(string $type, string $target, array $data, string $summary, string $reason = ''): array
    {
        $list = self::raw();
        // Una sola solicitud viva por (tipo, objetivo): la nueva sustituye a la anterior.
        $list = array_values(array_filter($list, fn($r) => !($r['type'] === $type && $r['target'] === $target)));
        $r = ['id' => bin2hex(random_bytes(6)), 'type' => $type, 'target' => $target, 'data' => $data,
              'summary' => $summary, 'reason' => mb_substr(trim($reason), 0, 200), 'at' => time()];
        $list[] = $r;
        self::save($list);
        LogService::log('mcp.change.request', $target, "Solicitud MCP: {$summary}");
        return $r;
    }

    public static function pending(): array
    {
        return array_map(fn($r) => $r + ['type_label' => self::TYPES[$r['type']] ?? $r['type'],
            'at_label' => gmdate('Y-m-d H:i', (int)$r['at']) . ' UTC'], self::raw());
    }

    /** Aprueba o rechaza varias. Devuelve [aplicadas, rechazadas, errores]. */
    public static function decide(array $ids, bool $approve): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $done = 0; $rejected = 0; $errors = [];
        $keep = [];
        foreach (self::raw() as $r) {
            if (!in_array($r['id'], $ids, true)) {
                $keep[] = $r;
                continue;
            }
            if (!$approve) {
                $rejected++;
                LogService::log('mcp.change.rejected', $r['target'], "Rechazada: {$r['summary']}");
                continue;
            }
            try {
                self::apply($r);
                $done++;
                LogService::log('mcp.change.approved', $r['target'], "Aprobada y aplicada: {$r['summary']}");
            } catch (\Throwable $e) {
                $errors[] = "{$r['target']}: " . $e->getMessage();
                $keep[] = $r;   // se queda para reintentar o rechazar
            }
        }
        self::save($keep);
        return [$done, $rejected, $errors];
    }

    private static function apply(array $r): void
    {
        switch ($r['type']) {
            case 'mail_quota':
                $a = Database::fetchOne("SELECT id FROM mail_accounts WHERE lower(email) = :e", ['e' => strtolower($r['target'])]);
                if (!$a) {
                    throw new \RuntimeException('el buzón ya no existe');
                }
                MailService::updateAccount((int)$a['id'], ['quota_mb' => max(0, (int)$r['data']['quota_mb'])]);
                return;
        }
        throw new \RuntimeException("tipo desconocido {$r['type']}");
    }

    private static function raw(): array
    {
        $list = json_decode(Settings::get(self::KEY, '[]'), true) ?: [];
        return array_values(array_filter($list, fn($r) => (int)($r['at'] ?? 0) > time() - self::TTL));
    }

    private static function save(array $list): void
    {
        Settings::set(self::KEY, json_encode(array_slice(array_values($list), -200)));
    }
}
