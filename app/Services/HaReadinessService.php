<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Env;

final class HaReadinessService
{
    /** Pure policy: fail closed on missing, unknown, transitional or active units. */
    public static function stoppedAndGuarded(array $units): bool
    {
        if (!$units) return false;
        foreach ($units as $unit) {
            if (!in_array($unit['state'] ?? '', ['inactive', 'failed'], true)
                || ($unit['guarded'] ?? false) !== true) return false;
        }
        return true;
    }

    /** Conservative boot decision. This is observation, not a distributed lease. */
    public static function bootVerdict(string $role, bool $fenced, int $expectedPeers, array $peerStates, bool $standbyFiles): array
    {
        if ($fenced) return ['ok' => false, 'reason' => 'Nodo apartado: requiere recuperación explícita.'];
        if ($role === 'slave') return ['ok' => $standbyFiles, 'reason' => $standbyFiles ? 'Marcadores standby presentes.' : 'Réplica sin standby.signal en todos los clústeres de datos.'];
        if ($role !== 'master') return ['ok' => false, 'reason' => 'Rol no válido para este guardián de HA.'];
        if ($expectedPeers < 1 || count($peerStates) !== $expectedPeers) return ['ok' => false, 'reason' => 'No se pudo consultar a todos los pares de datos.'];
        foreach ($peerStates as $state) {
            if (($state['cluster_role'] ?? '') !== 'slave' || ($state['cluster_fenced'] ?? null) !== false) {
                return ['ok' => false, 'reason' => 'Un par no confirma rol de réplica disponible: revisión manual.'];
            }
        }
        return ['ok' => true, 'reason' => 'Todos los pares consultados confirman rol de réplica.'];
    }

    /** Pure systemd content. Consumers must Require this unit, not only order after it. */
    public static function bootUnit(string $script, string $panelUnit): string
    {
        return "[Unit]\nDescription=MuseDock strict HA boot check\nWants=network-online.target\n"
            . "After=network-online.target {$panelUnit}\nRequires={$panelUnit}\n\n"
            . "[Service]\nType=oneshot\nRemainAfterExit=yes\nTimeoutStartSec=120\n"
            . "ExecStart=/usr/bin/php {$script}\n\n[Install]\nWantedBy=multi-user.target\n";
    }

    public static function consumerDropin(): string
    {
        return "[Unit]\nRequires=musedock-ha-boot-guard.service\nAfter=musedock-ha-boot-guard.service\n\n"
            . "[Service]\nExecCondition=/usr/bin/test ! -e " . FailoverSafetyService::FENCE_FLAG . "\n";
    }

    /** Read-only evidence. Application databases must be stopped, not merely default read-only.
     * Does not claim provider STONITH or a distributed writing lease.
     */
    public static function fenceEvidence(): array
    {
        $names = ['caddy.service', 'lsyncd.service', 'redis-server.service', 'supervisor.service'];
        $panelPort = (int)Env::get('DB_PORT', 5433);
        $dataFound = false;
        foreach (PgClusterService::listClusters() as $c) {
            if ((int)$c['port'] !== $panelPort) {
                $names[] = $c['unit'];
                $dataFound = true;
            }
        }
        foreach (['mariadb.service', 'mysql.service', 'postfix@-.service', 'dovecot.service'] as $name) $names[] = $name;
        $units = [];
        foreach (array_unique($names) as $name) {
            if (!preg_match('/^[a-zA-Z0-9@_.:-]+$/', $name)) continue;
            if (!str_ends_with($name, '.service')) $name .= '.service';
            $raw = (string)shell_exec('systemctl show ' . escapeshellarg($name)
                . ' -p LoadState -p ActiveState -p ExecCondition --no-pager 2>/dev/null');
            $props = [];
            foreach (explode("\n", trim($raw)) as $line) {
                $pair = explode('=', $line, 2);
                if (count($pair) === 2) $props[$pair[0]] = $pair[1];
            }
            if (($props['LoadState'] ?? '') === 'not-found') continue;
            $units[$name] = ['state' => $props['ActiveState'] ?? 'unknown',
                'guarded' => str_contains($props['ExecCondition'] ?? '', 'test ! -e ' . FailoverSafetyService::FENCE_FLAG)];
        }
        return ['verified' => $dataFound && is_file(FailoverSafetyService::FENCE_FLAG)
                && self::stoppedAndGuarded($units), 'units' => $units,
            'scope' => 'managed-services-only',
            'limitation' => 'No acredita STONITH, procesos externos a systemd ni quorum de escritura.'];
    }
}
