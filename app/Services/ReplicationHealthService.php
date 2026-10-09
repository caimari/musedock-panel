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

        // ── Buzones (Dovecot dsync) ──
        // El replicador puede no dar error y aun así no sincronizar (2026-10: el aviso de
        // cambios no estaba activo en IMAP/LMTP; 90 h sin sincronizar y nadie lo vio). Cada
        // buzón tiene al menos una sincronización completa al día: más de 26 h sin una
        // correcta, o marcado como fallido, es un problema.
        if (is_file(MailReplicationService::DROPIN) || is_file(MailReplicationService::LEGACY_DROPIN)) {
            $stale = [];
            foreach (preg_split('/\R/', (string)shell_exec("timeout 10 doveadm replicator status '*' 2>/dev/null")) ?: [] as $line) {
                $c = preg_split('/\s+/', trim($line));
                if (count($c) < 6 || !str_contains($c[0], '@')) {
                    continue;
                }
                // username priority fast-sync full-sync success-sync failed (duraciones H:MM:SS)
                [$h] = array_map('intval', explode(':', (string)$c[4]) + [0]);
                $failed = strtolower((string)end($c)) === 'y';
                if ($failed || ($c[4] !== '-' && $h >= 26)) {
                    $stale[] = $c[0] . ($failed ? ' (fallido)' : " ({$h} h)");
                }
            }
            // Cada nodo solo anota las sincronizaciones que lanza él: tras una copia lanzada
            // desde la pareja, o en un buzón sin cambios, aquí puede seguir viéndose "antigua".
            // Antes de avisar, pedir una sincronización completa de CADA buzón atrasado en
            // cuanto aparece (y luego como mucho una vez por hora por buzón), y avisar solo de
            // los que siguen atrasados 15 min después. Antes el intento era uno por hora para
            // todos a la vez y el aviso salía a los 5 min: un buzón que se atrasaba justo
            // después de otro avisaba sin haberlo intentado (calamar@, 2026-10-09).
            $kicks = array_intersect_key($state['mail_kicks'] ?? [], array_flip(array_map(static fn($s) => strtok($s, ' '), $stale)));
            $report = [];
            foreach ($stale as $s) {
                $user = strtok($s, ' ');
                if ($now - (int)($kicks[$user] ?? 0) >= 3600) {
                    shell_exec('timeout 10 doveadm replicator replicate -f ' . escapeshellarg($user) . ' >/dev/null 2>&1');
                    $kicks[$user] = $now;
                }
                if ($now - (int)$kicks[$user] >= 900) {
                    $report[] = $s;
                }
            }
            $state['mail_kicks'] = $kicks;
            unset($state['mail_kick_at']);
            if ($report) {
                $issues['mail:stale'] = 'Réplica de buzones: ' . count($report) . ' sin sincronizar con la pareja aunque se ha pedido de nuevo: '
                    . implode(', ', array_slice($report, 0, 8)) . (count($report) > 8 ? '…' : '')
                    . '. Lo que entre o se borre en ellos puede no estar en el otro nodo.';
            }
        }

        self::saveState($state);
        return $issues;
    }

    /**
     * Lo llama cluster-worker. Avisa de un problema solo si dura (por defecto 5 min:
     * un reinicio del principal de 2 min no debe mandar correo), salvo los graves
     * (slot perdido, a punto de perderse). Explica por qué, con un diagnóstico del
     * principal. Repite cada 6 h si sigue y avisa cuando se arregla, con lo que duró.
     * Durante un mantenimiento programado no se envía (NotificationService).
     */
    public static function checkAndNotify(): array
    {
        $issues = self::issues();
        $state = self::state();
        $now = time();
        $notified = $state['notified'] ?? [];
        $firstSeen = array_intersect_key($state['first_seen'] ?? [], $issues);
        $grace = 60 * AlertPolicyService::outageAfterMinutes();
        $new = [];
        foreach ($issues as $k => $text) {
            $firstSeen[$k] = $firstSeen[$k] ?? $now;
            $urgent = str_ends_with($k, ':lost') || str_ends_with($k, ':unreserved');
            if (!$urgent && $now - $firstSeen[$k] < $grace) {
                continue; // aún no: puede ser un reinicio
            }
            if (!isset($notified[$k]) || $now - (int)$notified[$k] >= self::REPEAT_SECONDS) {
                $new[$k] = $text;
                $notified[$k] = $now;
            }
        }
        $fixed = array_diff_key($notified, $issues);
        $fixedSince = [];
        foreach (array_keys($fixed) as $k) {
            $fixedSince[$k] = (int)($state['first_seen'][$k] ?? $fixed[$k]);
            unset($notified[$k]);
        }
        $state['notified'] = $notified;
        $state['first_seen'] = $firstSeen;
        $state['last'] = ['at' => date('Y-m-d H:i:s'), 'issues' => array_values($issues)];
        self::saveState($state);

        $host = gethostname();
        if ($new) {
            // ¿Por qué? Si la réplica no recibe, mirar si el principal responde.
            $diag = '';
            $noConn = array_filter(array_keys($new), static fn($k) => str_ends_with($k, ':receiver') || $k === 'mysql:replica' || $k === 'redis:link');
            $masterIp = (string)Settings::get('repl_remote_ip', '');
            if ($noConn && filter_var($masterIp, FILTER_VALIDATE_IP)) {
                $d = OutageDiagnosisService::diagnose('El servidor principal (' . $masterIp . ')', $masterIp, OutageDiagnosisService::primaryPublicIp(), 5432);
                $diag = "\n\nDIAGNÓSTICO: {$d['text']}\n" . implode("\n", $d['checks']);
                $diag .= $d['verdict'] === 'down'
                    ? "\n\nNo hay que hacer nada en este servidor: cuando el principal vuelva, la réplica se pondrá al día sola y te llegará el aviso \"Réplica recuperada\"."
                    : '';
            }
            $mins = round(($now - min(array_intersect_key($firstSeen, $new))) / 60);
            if (array_keys($new) === ['mail:stale']) {
                // Solo buzones: no son «las bases de datos» ni tiene por qué ser una réplica
                // (el principal también replica sus buzones con la pareja).
                NotificationService::send("[{$host}] Buzones sin sincronizar con la pareja",
                    "Algunos buzones de correo de este servidor ({$host}) no se han sincronizado con su pareja:\n\n- " . $new['mail:stale']
                    . "\n\nEl correo sigue funcionando. Si hubiera que pasar el mando a la pareja, en esos buzones podría faltar "
                    . "lo último que ha entrado o borrarse algo que ya se borró aquí.", 'replication');
            } else {
                NotificationService::send("[{$host}] Réplica parada desde hace {$mins} min",
                    "Las bases de datos de este servidor ({$host}) han dejado de copiarse desde el principal:\n\n- " . implode("\n- ", $new)
                    . $diag
                    . "\n\nMientras dure, si hubiera que pasar el mando a este servidor podría faltar lo último que se guardó en el principal.", 'replication');
            }
            LogService::log('cluster.replication', 'alert', implode(' | ', $new));
        }
        if ($fixed) {
            $mins = round(($now - min($fixedSince)) / 60);
            NotificationService::send("[{$host}] Réplica recuperada",
                "La réplica de {$host} vuelve a copiarse con normalidad (estuvo parada unos {$mins} min). Se ha puesto al día sola: no hay que hacer nada.\n\n"
                . 'Lo que se arregló: ' . implode(', ', array_map(static fn($k) => self::label($k), array_keys($fixed))) . '.', 'replication');
            LogService::log('cluster.replication', 'recovered', implode(', ', array_keys($fixed)));
        }
        return ['issues' => $issues, 'notified_now' => array_keys($new), 'recovered' => array_keys($fixed)];
    }

    /** Nombre legible de un problema (clave «motor:qué») para el aviso de recuperación. */
    private static function label(string $k): string
    {
        return [
            'mail:stale' => 'réplica de buzones de correo',
            'mysql:replica' => 'réplica de MariaDB/MySQL',
            'mysql:lag' => 'retraso de MariaDB/MySQL',
            'redis:link' => 'réplica de Redis',
        ][$k] ?? (str_starts_with($k, 'pg:') ? 'PostgreSQL ' . (explode(':', $k)[1] ?? '') : str_replace(':', ' ', $k));
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
