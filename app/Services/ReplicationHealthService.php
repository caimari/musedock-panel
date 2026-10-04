<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Salud de las réplicas (PostgreSQL, MariaDB/MySQL, Redis) y aviso cuando se
 * rompen. Hasta ahora el panel vigilaba servicios, lsyncd y la caída del
 * master, pero NO que una réplica dejara de recibir: un slot perdido por
 * superar max_slot_wal_keep_size, una réplica de MariaDB parada por un error o
 * Redis desenganchado pasaban en silencio hasta el día del relevo.
 *
 * Solo lectura. Lo llama cluster-worker cada 5 min; avisa una vez por problema
 * (y lo repite cada 6 h si sigue) y avisa también cuando se arregla.
 */
final class ReplicationHealthService
{
    private const STATE = 'replication_health_state';
    private const REPEAT_SECONDS = 21600;
    /** Minutos que un slot puede estar sin réplica conectada antes de avisar (reinicios, cortes breves). */
    private const SLOT_INACTIVE_MINUTES = 10;

    /** @return array<string,string> clave estable → texto del problema */
    public static function issues(): array
    {
        $issues = [];
        $state = self::state();
        $now = time();

        // ── PostgreSQL ──
        foreach (PgClusterService::listClusters() as $c) {
            $key = $c['key'] ?? '?';
            $inRecovery = ReplicationService::queryCluster($c, 'SELECT pg_is_in_recovery()');
            if (($inRecovery[0][0] ?? '') === 't') {
                // Réplica: tiene que estar recibiendo.
                $wr = ReplicationService::queryCluster($c, 'SELECT status, sender_host FROM pg_stat_wal_receiver');
                if (($wr[0][0] ?? '') !== 'streaming') {
                    $issues["pg:{$key}:receiver"] = "PostgreSQL {$key} es réplica pero NO está recibiendo del master"
                        . (($wr[0][0] ?? '') !== '' ? " (estado: {$wr[0][0]})" : ' (sin conexión)') . '.';
                }
                continue;
            }
            // Principal: sus slots (solo los que retienen WAL; uno sin restart_lsn no se ha usado nunca).
            $rows = ReplicationService::queryCluster($c,
                "SELECT slot_name, active, coalesce(wal_status,''), coalesce(pg_size_pretty(safe_wal_size),'') "
                . "FROM pg_replication_slots WHERE slot_type = 'physical' AND restart_lsn IS NOT NULL");
            foreach ($rows as $r) {
                [$slot, $active, $walStatus, $safe] = array_pad($r, 4, '');
                $sk = "pg:{$key}:slot:{$slot}";
                if ($walStatus === 'lost') {
                    $issues[$sk . ':lost'] = "PostgreSQL {$key}: el slot «{$slot}» se ha PERDIDO (la réplica estuvo fuera más de lo que permite max_slot_wal_keep_size). "
                        . 'Esa réplica ya no puede ponerse al día sola: hay que volver a copiarla (pg_basebackup).';
                    continue;
                }
                if ($walStatus === 'unreserved') {
                    $issues[$sk . ':unreserved'] = "PostgreSQL {$key}: el slot «{$slot}» está a punto de perderse (queda {$safe} de margen). Revisa la réplica cuanto antes.";
                }
                if ($active !== 't') {
                    $since = (int)($state['slot_inactive_since'][$sk] ?? 0) ?: $now;
                    $state['slot_inactive_since'][$sk] = $since;
                    if ($now - $since >= self::SLOT_INACTIVE_MINUTES * 60) {
                        $issues[$sk . ':inactive'] = "PostgreSQL {$key}: la réplica del slot «{$slot}» lleva " . round(($now - $since) / 60)
                            . ' min sin conectar' . ($safe !== '' ? " (margen antes de perderlo: {$safe})" : '') . '.';
                    }
                } else {
                    unset($state['slot_inactive_since'][$sk]);
                }
            }
        }

        // ── MariaDB / MySQL (solo si es réplica) ──
        try {
            $ss = ReplicationService::getMysqlSlaveStatus();
            if ($ss) {
                $io = $ss['Slave_IO_Running'] ?? $ss['Replica_IO_Running'] ?? '';
                $sql = $ss['Slave_SQL_Running'] ?? $ss['Replica_SQL_Running'] ?? '';
                $err = trim((string)($ss['Last_SQL_Error'] ?? $ss['Last_IO_Error'] ?? $ss['Last_Error'] ?? ''));
                if ($io !== 'Yes' || $sql !== 'Yes') {
                    $issues['mysql:replica'] = "MariaDB/MySQL: la réplica está parada (IO: {$io}, SQL: {$sql})" . ($err !== '' ? ". Error: " . mb_substr($err, 0, 300) : '') . '.';
                } elseif ((int)($ss['Seconds_Behind_Master'] ?? 0) > 900) {
                    $issues['mysql:lag'] = 'MariaDB/MySQL: la réplica va ' . (int)$ss['Seconds_Behind_Master'] . ' s por detrás del master.';
                }
            }
        } catch (\Throwable) {
        }

        // ── Redis (solo si es réplica) ──
        if (trim((string)shell_exec('command -v redis-cli 2>/dev/null')) !== '') {
            $pass = '';
            foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                    $pass = trim($m[1], '"\'');
                }
            }
            $cli = ($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'timeout 5 redis-cli --no-auth-warning ';
            $info = (string)shell_exec($cli . 'INFO replication 2>/dev/null');
            if (preg_match('/^role:slave/m', $info) && !preg_match('/^master_link_status:up/m', $info)) {
                $issues['redis:link'] = 'Redis es réplica pero el enlace con el master está caído.';
            }
        }

        self::saveState($state);
        return $issues;
    }

    /** Lo llama cluster-worker: avisa de lo nuevo, repite lo que sigue cada 6 h y avisa de lo arreglado. */
    public static function checkAndNotify(): array
    {
        $issues = self::issues();
        $state = self::state();
        $now = time();
        $notified = $state['notified'] ?? [];
        $new = [];
        foreach ($issues as $k => $text) {
            if (!isset($notified[$k]) || $now - (int)$notified[$k] >= self::REPEAT_SECONDS) {
                $new[$k] = $text;
                $notified[$k] = $now;
            }
        }
        $fixed = array_diff_key($notified, $issues);
        foreach (array_keys($fixed) as $k) {
            unset($notified[$k]);
        }
        $state['notified'] = $notified;
        $state['last'] = ['at' => date('Y-m-d H:i:s'), 'issues' => array_values($issues)];
        self::saveState($state);

        $host = gethostname();
        if ($new) {
            NotificationService::send("[{$host}] Réplica con problemas",
                "Este servidor ({$host}) ha detectado problemas de réplica:\n\n- " . implode("\n- ", $new)
                . "\n\nMientras no se arregle, un relevo podría perder datos o no funcionar.", 'replication');
            LogService::log('cluster.replication', 'alert', implode(' | ', $new));
        }
        if ($fixed) {
            NotificationService::send("[{$host}] Réplica recuperada",
                "Se han resuelto " . count($fixed) . " problema(s) de réplica en {$host}.", 'replication');
            LogService::log('cluster.replication', 'recovered', implode(', ', array_keys($fixed)));
        }
        return ['issues' => $issues, 'notified_now' => array_keys($new), 'recovered' => array_keys($fixed)];
    }

    private static function state(): array
    {
        $s = json_decode(Settings::get(self::STATE, '{}'), true);
        return is_array($s) ? $s : [];
    }

    private static function saveState(array $s): void
    {
        Settings::set(self::STATE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
