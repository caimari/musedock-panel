<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Reparación automática de las réplicas (PostgreSQL y MariaDB/MySQL) en un SLAVE.
 *
 * Caso típico: el principal es una máquina virtual con alta disponibilidad y, al caer su
 * servidor físico, arranca en otro con la réplica de disco de hace ~1 min. La réplica de
 * base de datos de este nodo ya había recibido ese último minuto: queda "por delante" del
 * principal y no puede seguirle (PostgreSQL deja de recibir; MariaDB se para con un error
 * de posición). Antes había que rehacerla a mano.
 *
 * Solo actúa si: la copia automática está activada (replication_auto_repair, por defecto
 * sí), este nodo es slave y no está apartado ni en un cambio de rol, la réplica lleva
 * BROKEN_MINUTES rota, y el principal responde y es principal de verdad (si el que está
 * caído es él, se espera como siempre). Un intento cada RETRY_SECONDS por réplica.
 *
 * Nada se borra: PostgreSQL copia a un directorio temporal y aparta el antiguo
 * (ReplicationService::setupPgSlaveForCluster); MariaDB guarda antes una copia completa
 * de lo que tiene la réplica (puede contener ese último minuto que el principal perdió).
 * Se comprueba el espacio en disco antes. Avisa al empezar y al terminar.
 *
 * El trabajo pesado corre fuera del cluster-worker (bin/replication-auto-repair.php).
 */
final class ReplicationAutoRepairService
{
    private const STATE = 'replication_autorepair_state';
    private const BROKEN_MINUTES = 15;
    private const RETRY_SECONDS = 21600;
    public const BACKUP_DIR = '/var/backups/musedock-replica-repair';
    public const LOCK = '/run/musedock-replica-repair.lock';

    public static function enabled(): bool
    {
        return Settings::get('replication_auto_repair', '1') === '1';
    }

    /**
     * Réplicas que hay que reparar ya (solo comprueba; no toca nada).
     * @return array<string,string> componente ("pg:14/main", "mysql") → motivo
     */
    public static function due(): array
    {
        if (!self::enabled() || Settings::get('cluster_role', '') !== 'slave' || Settings::get('cluster_fenced', '0') === '1') {
            return [];
        }
        $active = RoleSwitchService::activeTask(1);
        if ($active && ($active['state'] ?? '') === 'running') {
            return [];
        }
        $state = self::state();
        $now = time();
        $broken = [];

        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        foreach (PgClusterService::listClusters() as $c) {
            if (($c['cluster'] ?? '') === 'panel' || (int)$c['port'] === $panelPort) {
                continue;
            }
            $rec = ReplicationService::queryCluster($c, 'SELECT pg_is_in_recovery()');
            if (($rec[0][0] ?? '') !== 't') {
                continue;
            }
            $wr = ReplicationService::queryCluster($c, 'SELECT status FROM pg_stat_wal_receiver');
            if (($wr[0][0] ?? '') !== 'streaming') {
                $broken['pg:' . $c['key']] = 'PostgreSQL ' . $c['key'] . ' no recibe del principal';
            }
        }
        if (Settings::get('repl_mysql_role', 'standalone') !== 'standalone') {
            try {
                $ss = ReplicationService::getMysqlSlaveStatus();
            } catch (\Throwable) {
                $ss = null;
            }
            if ($ss) {
                $io = $ss['Slave_IO_Running'] ?? $ss['Replica_IO_Running'] ?? '';
                $sql = $ss['Slave_SQL_Running'] ?? $ss['Replica_SQL_Running'] ?? '';
                $ioErrno = (int)($ss['Last_IO_Errno'] ?? 0);
                // Parada por un error (hilo SQL detenido, o el principal no tiene la
                // posición que pide: 1236). Un hilo de E/S "conectando" (principal caído o
                // corte de red) no es para reparar: se recupera solo.
                if ($sql !== 'Yes' || ($io !== 'Yes' && $ioErrno === 1236)) {
                    $err = trim((string)($ss['Last_SQL_Error'] ?? '') ?: (string)($ss['Last_IO_Error'] ?? ''));
                    $broken['mysql'] = 'MariaDB/MySQL parada' . ($err !== '' ? ': ' . mb_substr($err, 0, 200) : '');
                }
            }
        }

        // Cuánto lleva rota cada una (se olvida en cuanto vuelve a funcionar).
        $since = array_intersect_key($state['broken_since'] ?? [], $broken);
        foreach ($broken as $k => $_) {
            $since[$k] = $since[$k] ?? $now;
        }
        $state['broken_since'] = $since;
        self::saveState($state);

        $due = [];
        foreach ($broken as $k => $why) {
            if ($now - $since[$k] >= self::BROKEN_MINUTES * 60
                && $now - (int)($state['attempt'][$k] ?? 0) >= self::RETRY_SECONDS) {
                $due[$k] = $why;
            }
        }
        return $due;
    }

    /** Lanza la reparación aparte (no bloquea el cluster-worker). */
    public static function launch(): string
    {
        $due = self::due();
        if (!$due || !self::master()) {
            return ''; // si el principal no está sano, se espera: no es una réplica para rehacer
        }
        $fp = @fopen(self::LOCK, 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return 'reparación ya en marcha';
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        $php = PHP_BINARY ?: '/usr/bin/php';
        shell_exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(PANEL_ROOT . '/bin/replication-auto-repair.php')
            . ' >> ' . escapeshellarg(PANEL_ROOT . '/storage/logs/replication-repair.log') . ' 2>&1 &');
        return 'reparación lanzada: ' . implode(', ', array_keys($due));
    }

    /** Lo ejecuta bin/replication-auto-repair.php, con el cerrojo tomado. */
    public static function repairDue(): array
    {
        $out = [];
        foreach (self::due() as $component => $why) {
            $out[$component] = $component === 'mysql' ? self::repairMysql($why) : self::repairPg(substr($component, 3), $why);
        }
        return $out;
    }

    /** El principal (por su panel): IP, salud de sus bases. null si no responde. */
    private static function master(): ?array
    {
        $ip = trim((string)Settings::get('repl_remote_ip', '')) ?: trim((string)Settings::get('cluster_master_ip', ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        foreach (ClusterService::getNodes() as $n) {
            $host = (string)(parse_url((string)($n['api_url'] ?? ''), PHP_URL_HOST) ?: '');
            if (($n['role'] ?? '') !== 'master' && $host !== $ip) {
                continue;
            }
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'role-switch-health', 'payload' => []]);
            $h = $r['data']['result'] ?? null;
            if (is_array($h) && ($h['role'] ?? '') === 'master' && empty($h['fenced'])) {
                return ['ip' => $ip, 'name' => (string)($n['name'] ?? $ip), 'health' => $h];
            }
        }
        return null;
    }

    private static function portOpen(string $ip, int $port): bool
    {
        $fp = @fsockopen($ip, $port, $e, $s, 5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static function repairPg(string $key, string $why): string
    {
        $host = (string)gethostname();
        $cluster = null;
        foreach (PgClusterService::listClusters() as $c) {
            if ($c['key'] === $key) {
                $cluster = $c;
            }
        }
        if (!$cluster) {
            return 'clúster no encontrado';
        }
        $m = self::master();
        $mp = $m['health']['pg'][$key] ?? null;
        if (!$m || !$mp || !empty($mp['in_recovery']) || !self::portOpen($m['ip'], (int)$cluster['port'])) {
            return 'no se repara: el principal no responde o ese clúster no es principal allí';
        }
        // Espacio: la copia nueva más la antigua apartada.
        $size = (int)trim((string)shell_exec('du -sb ' . escapeshellarg($cluster['data_dir']) . ' 2>/dev/null | cut -f1'));
        $free = (int)@disk_free_space(dirname($cluster['data_dir']));
        if ($size > 0 && $free < (int)($size * 1.2)) {
            self::markAttempt('pg:' . $key); // no repetir el aviso cada 5 min
            NotificationService::send("[{$host}] Réplica de PostgreSQL {$key}: sin espacio para repararla",
                "{$why}. Repararla necesita copiarla de nuevo desde {$m['name']} (unos " . round($size / 1073741824, 1) . " GB) y apartar la actual, "
                . 'pero solo hay ' . round($free / 1073741824, 1) . " GB libres. Libera espacio y se reintentará sola.", 'replication');
            return 'sin espacio';
        }
        self::markAttempt('pg:' . $key);
        NotificationService::send("[{$host}] Reparando la réplica de PostgreSQL {$key}",
            "{$why} desde hace más de " . self::BROKEN_MINUTES . " min y el principal ({$m['name']}) está bien: se vuelve a copiar desde él.\n\n"
            . "Los datos actuales de la réplica NO se borran: se apartan junto al directorio de datos (.old.<fecha>). "
            . "Mientras dura, esta réplica no está al día. Te aviso al terminar.", 'replication');
        $user = (string)Settings::get('repl_pg_user', Settings::get('repl_panel_slave_ip', '') !== '' ? 'repl_panel' : 'replicator');
        $pass = ReplicationService::decryptPassword((string)Settings::get('repl_pg_password', Settings::get('repl_pg_pass', '')));
        $r = ReplicationService::setupPgSlaveForCluster($cluster, $m['ip'], (int)$cluster['port'], $user, $pass, true);
        $ok = !empty($r['ok']);
        NotificationService::send("[{$host}] Réplica de PostgreSQL {$key}: " . ($ok ? 'reparada' : 'NO se pudo reparar'),
            $ok ? "Copiada de nuevo desde {$m['name']} y replicando. Los datos anteriores quedan apartados (.old.<fecha>) por si hicieran falta; bórralos cuando lo veas todo bien."
                : 'Error: ' . ($r['error'] ?? '?') . "\nLos datos de la réplica no se han tocado. Se reintentará en " . (self::RETRY_SECONDS / 3600) . ' h, o a mano: php bin/cluster-switch.php pg-rebuild ' . $m['ip'] . ' ' . $key,
            'replication');
        LogService::log('cluster.replication', 'auto-repair', "PostgreSQL {$key}: " . ($ok ? 'reparada' : 'falló: ' . ($r['error'] ?? '?')));
        return $ok ? 'reparada' : 'falló: ' . ($r['error'] ?? '?');
    }

    private static function repairMysql(string $why): string
    {
        $host = (string)gethostname();
        $m = self::master();
        $port = (int)Settings::get('repl_mysql_port', '3306');
        $mm = $m['health']['mysql'] ?? null;
        if (!$m || !is_array($mm) || empty($mm['configured']) || !empty($mm['is_slave']) || !self::portOpen($m['ip'], $port)) {
            return 'no se repara: el principal no responde o su MariaDB/MySQL no es principal';
        }
        self::markAttempt('mysql');
        // Copia de lo que tiene la réplica ANTES de sustituirlo (puede tener lo último que
        // el principal perdió). Si la copia falla, no se toca nada.
        @mkdir(self::BACKUP_DIR, 0700, true);
        $dump = self::BACKUP_DIR . '/replica-antes-de-reparar-' . date('Ymd-His') . '.sql.gz';
        $bin = trim((string)shell_exec('command -v mariadb-dump || command -v mysqldump'));
        $size = (int)trim((string)shell_exec('du -sb /var/lib/mysql 2>/dev/null | cut -f1'));
        if ($bin === '' || (int)@disk_free_space(self::BACKUP_DIR) < (int)($size * 0.6)) {
            NotificationService::send("[{$host}] Réplica de MariaDB/MySQL: no se puede reparar sola",
                "{$why}. Antes de repararla hay que guardar una copia de lo que tiene, y " . ($bin === '' ? 'no está mysqldump.' : 'no hay espacio libre suficiente en ' . self::BACKUP_DIR . '.'), 'replication');
            return 'sin copia previa posible';
        }
        $cmd = 'set -o pipefail; umask 077; ' . escapeshellarg($bin) . ' --all-databases --single-transaction --routines --events --triggers 2>/dev/null | gzip > ' . escapeshellarg($dump);
        exec('bash -c ' . escapeshellarg($cmd), $o, $rc);
        if ($rc !== 0 || !is_file($dump) || filesize($dump) < 1024) {
            return 'no se pudo guardar la copia previa (código ' . $rc . '): no se toca nada';
        }
        NotificationService::send("[{$host}] Reparando la réplica de MariaDB/MySQL",
            "{$why} desde hace más de " . self::BROKEN_MINUTES . " min y el principal ({$m['name']}) está bien: se vuelve a copiar desde él.\n\n"
            . "Antes se ha guardado una copia completa de lo que tenía la réplica: {$dump}. Te aviso al terminar.", 'replication');
        $user = (string)Settings::get('repl_mysql_user', 'repl_user');
        $pass = ReplicationService::decryptPassword((string)Settings::get('repl_mysql_pass', ''));
        $r = ReplicationService::setupMysqlSlave($m['ip'], $port, $user, $pass, true);
        $ok = !empty($r['ok']);
        NotificationService::send("[{$host}] Réplica de MariaDB/MySQL: " . ($ok ? 'reparada' : 'NO se pudo reparar'),
            $ok ? "Copiada de nuevo desde {$m['name']} y replicando. Copia de lo que había antes: {$dump}."
                : 'Error: ' . ($r['error'] ?? '?') . "\nCopia de lo que había antes: {$dump}. Se reintentará en " . (self::RETRY_SECONDS / 3600) . ' h.',
            'replication');
        LogService::log('cluster.replication', 'auto-repair', 'MariaDB/MySQL: ' . ($ok ? 'reparada' : 'falló: ' . ($r['error'] ?? '?')) . " (copia previa {$dump})");
        return $ok ? 'reparada' : 'falló: ' . ($r['error'] ?? '?');
    }

    /** El intento cuenta solo cuando de verdad empieza a reparar. */
    private static function markAttempt(string $component): void
    {
        $state = self::state();
        $state['attempt'][$component] = time();
        self::saveState($state);
    }

    private static function state(): array
    {
        $s = json_decode((string)Settings::get(self::STATE, '{}'), true);
        return is_array($s) ? $s : [];
    }

    private static function saveState(array $s): void
    {
        Settings::set(self::STATE, json_encode($s, JSON_UNESCAPED_SLASHES));
    }
}
