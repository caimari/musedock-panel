<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;
use MuseDockPanel\Env;

/**
 * FailoverSafetyService — the safety layer around promotion/demotion.
 *
 * The existing promoteToMaster() promotes a hardcoded /main data directory
 * (wrong cluster on a multi-cluster host), leaves MariaDB's read_only in my.cnf
 * (so a restart silently makes the new master read-only again), performs no
 * fencing, and keeps the old master in lsyncd — so when the old master comes
 * back, BOTH nodes believe they are master and both accept writes. That is
 * split-brain: divergent data on both sides, and no automatic way back.
 *
 * This service supplies the missing pieces:
 *   1. Fencing        — prove/force the old master cannot write before promoting.
 *   2. Cluster-explicit promotion — pg_ctlcluster VER CLUSTER, never /main.
 *   3. Role persistence — strip read_only from my.cnf, not just at runtime.
 *   4. lsyncd exclusion — the new master must not receive files; the old must
 *                          not push them.
 *   5. Rebuild-as-slave — the recovered old master is rebuilt FROM the new one.
 *   6. Switchover      — manual, preflighted, verified.
 *
 * Design rule: this never promotes automatically. Promotion is a human decision
 * with a preflight; automation here is what creates split-brain.
 */
class FailoverSafetyService
{
    // ─────────────────────────────────────────────────────────
    // 1. FENCING
    // ─────────────────────────────────────────────────────────

    /**
     * Verify the old master is really unable to serve/write before we promote.
     * Returns fenced=true only when we are confident it is down or was stopped.
     *
     * @param string $oldMasterIp WireGuard IP of the current/old master
     * @param bool   $force       accept "unreachable" as fenced (network partition
     *                            risk: it may be alive and serving to the world)
     */
    public static function fenceOldMaster(string $oldMasterIp, bool $force = false): array
    {
        $checks = [];

        if (!filter_var($oldMasterIp, FILTER_VALIDATE_IP)) {
            return ['fenced' => false, 'checks' => $checks, 'error' => 'IP del master antiguo no valida'];
        }

        // (a) Is its panel API answering?
        $panelUp = self::probe("https://{$oldMasterIp}:8444/", 5);
        $checks[] = ['name' => 'Panel del master antiguo', 'reachable' => $panelUp, 'detail' => $panelUp ? 'RESPONDE' : 'no responde'];

        // (b) Is it still serving web traffic?
        $webUp = self::probe("https://{$oldMasterIp}/", 5);
        $checks[] = ['name' => 'Web del master antiguo', 'reachable' => $webUp, 'detail' => $webUp ? 'RESPONDE' : 'no responde'];

        // (c) Is its database accepting connections?
        $pgUp = self::tcpProbe($oldMasterIp, 5432, 3) || self::tcpProbe($oldMasterIp, 5433, 3);
        $checks[] = ['name' => 'PostgreSQL del master antiguo', 'reachable' => $pgUp, 'detail' => $pgUp ? 'ACEPTA CONEXIONES' : 'no responde'];

        $anyAlive = $panelUp || $webUp || $pgUp;

        if (!$anyAlive) {
            return [
                'fenced'  => true,
                'checks'  => $checks,
                'method'  => 'down',
                'message' => 'El master antiguo no responde en panel, web ni BBDD: se considera aislado.',
            ];
        }

        // It IS alive. Try to fence it politely through the cluster API.
        $stopped = self::requestSelfFence($oldMasterIp);
        $checks[] = ['name' => 'Fencing remoto (parar servicios)', 'reachable' => $stopped['ok'], 'detail' => $stopped['message']];
        if ($stopped['ok']) {
            return ['fenced' => true, 'checks' => $checks, 'method' => 'remote-stop',
                    'message' => 'El master antiguo detuvo sus servicios a peticion nuestra.'];
        }

        if ($force) {
            return [
                'fenced'  => true,
                'checks'  => $checks,
                'method'  => 'forced',
                'message' => 'AVISO: forzado por el operador. El master antiguo puede seguir vivo y aceptando escrituras '
                           . '(riesgo real de split-brain). Asegurese manualmente de que esta apagado o aislado.',
            ];
        }

        return [
            'fenced'  => false,
            'checks'  => $checks,
            'error'   => 'El master antiguo SIGUE VIVO y no se pudo aislar. Promover ahora causaria split-brain '
                       . '(dos masters aceptando escrituras divergentes). Apaguelo manualmente o use force.',
        ];
    }

    /** Ask a node to stop serving (fence itself) via the cluster API. */
    private static function requestSelfFence(string $ip): array
    {
        $node = Database::fetchOne(
            "SELECT id FROM cluster_nodes WHERE api_url LIKE :u LIMIT 1",
            ['u' => '%' . $ip . '%']
        );
        if (!$node) {
            return ['ok' => false, 'message' => 'No esta registrado como nodo: no se puede pedir el fencing remoto'];
        }
        try {
            $r = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                'action'  => 'fence-self',
                'payload' => ['reason' => 'promotion of another node'],
            ]);
            $ok = !empty($r['ok']);
            return ['ok' => $ok, 'message' => $ok ? 'servicios detenidos' : ($r['error'] ?? 'sin respuesta')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Stop serving on THIS node: called when another node is being promoted.
     * Stops Caddy (web + panel routes) and puts databases in read-only so no
     * write can land here while another node is the master.
     */
    public static function fenceSelf(string $reason = ''): array
    {
        $steps = [];

        $out = shell_exec('systemctl stop caddy 2>&1');
        $stopped = trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) !== 'active';
        $steps[] = ['name' => 'Detener Caddy', 'ok' => $stopped, 'output' => $stopped ? 'detenido' : trim((string)$out)];

        // Make every PostgreSQL cluster read-only (defense in depth).
        foreach (PgClusterService::listClusters() as $c) {
            $sql = 'ALTER SYSTEM SET default_transaction_read_only = on';
            shell_exec('sudo -u postgres psql -p ' . (int)$c['port'] . ' -c ' . escapeshellarg($sql) . ' 2>&1');
            shell_exec('sudo -u postgres psql -p ' . (int)$c['port'] . ' -c ' . escapeshellarg('SELECT pg_reload_conf()') . ' 2>&1');
            $steps[] = ['name' => "PostgreSQL {$c['key']} read-only", 'ok' => true, 'output' => 'default_transaction_read_only=on'];
        }

        // MariaDB/MySQL read-only.
        try {
            $pdo = ReplicationService::getMysqlPdo();
            if ($pdo) {
                $pdo->exec('SET GLOBAL read_only = 1');
                $steps[] = ['name' => 'MySQL/MariaDB read-only', 'ok' => true, 'output' => 'read_only=1'];
            }
        } catch (\Throwable $e) {
            $steps[] = ['name' => 'MySQL/MariaDB read-only', 'ok' => false, 'output' => $e->getMessage()];
        }

        Settings::set('cluster_fenced', '1');
        Settings::set('cluster_fenced_at', date('Y-m-d H:i:s'));
        Settings::set('cluster_fenced_reason', $reason);
        LogService::log('cluster.failover', 'fence-self', 'Nodo aislado (fenced): ' . $reason);

        return ['ok' => true, 'steps' => $steps];
    }

    /** Undo fenceSelf once this node is legitimately a slave again. */
    public static function unfenceSelf(): array
    {
        $steps = [];
        foreach (PgClusterService::listClusters() as $c) {
            shell_exec('sudo -u postgres psql -p ' . (int)$c['port'] . ' -c '
                . escapeshellarg('ALTER SYSTEM RESET default_transaction_read_only') . ' 2>&1');
            shell_exec('sudo -u postgres psql -p ' . (int)$c['port'] . ' -c '
                . escapeshellarg('SELECT pg_reload_conf()') . ' 2>&1');
            $steps[] = ['name' => "PostgreSQL {$c['key']}", 'ok' => true, 'output' => 'read-only revertido'];
        }
        shell_exec('systemctl start caddy 2>&1');
        Settings::set('cluster_fenced', '0');
        LogService::log('cluster.failover', 'unfence-self', 'Nodo reactivado');
        return ['ok' => true, 'steps' => $steps];
    }

    // ─────────────────────────────────────────────────────────
    // 2 + 3. CLUSTER-EXPLICIT PROMOTION, PERSISTED
    // ─────────────────────────────────────────────────────────

    /**
     * Promote every PostgreSQL cluster that is actually in recovery, each via its
     * OWN (version, cluster) — never the hardcoded /main that the legacy code used
     * (it derived the version from the psql CLIENT and would target a nonexistent
     * or wrong data dir on a multi-cluster host).
     */
    public static function promoteAllPgClusters(bool $dryRun = false): array
    {
        $results = [];
        foreach (PgClusterService::listClusters() as $c) {
            $status = ReplicationService::getPgSlaveStatusForCluster($c);
            if ($status === null) {
                $results[$c['key']] = ['ok' => true, 'skipped' => true, 'message' => 'no esta en recovery (ya es primary)'];
                continue;
            }
            if ($dryRun) {
                $results[$c['key']] = ['ok' => true, 'dry_run' => true,
                    'message' => "pg_ctlcluster {$c['version']} {$c['cluster']} promote"];
                continue;
            }
            $r = ReplicationService::promotePgSlaveForCluster($c);
            $results[$c['key']] = ['ok' => $r['ok'], 'message' => $r['error'] ?? 'promovido'];
        }
        return $results;
    }

    /**
     * Promote MySQL/MariaDB AND persist it: the legacy path only ran STOP SLAVE +
     * SET GLOBAL read_only=0, leaving read_only=1 in my.cnf — so the next restart
     * silently turned the new master read-only again.
     */
    public static function promoteMysqlPersistent(bool $dryRun = false): array
    {
        $steps = [];
        $configPath = ReplicationService::getMysqlConfigPath();
        $vendor = ReplicationService::detectDbVendor();

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'plan' => [
                'STOP SLAVE / STOP REPLICA + RESET SLAVE ALL',
                'SET GLOBAL read_only = 0, super_read_only = 0',
                "quitar read_only de {$configPath} (persistencia tras reinicio)",
            ]];
        }

        try {
            $pdo = ReplicationService::getMysqlPdo();
            if (!$pdo) {
                return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo conectar a MySQL/MariaDB'];
            }
            // Vendor-correct stop.
            try {
                if ($vendor['vendor'] === 'mariadb') {
                    $pdo->exec('STOP SLAVE');
                    $pdo->exec('RESET SLAVE ALL');
                } else {
                    $pdo->exec('STOP REPLICA');
                    $pdo->exec('RESET REPLICA ALL');
                }
            } catch (\Throwable) {
                // Fall back to the other dialect.
                try { $pdo->exec('STOP SLAVE'); $pdo->exec('RESET SLAVE ALL'); } catch (\Throwable) {}
            }
            $steps[] = ['name' => 'Detener replicacion', 'ok' => true, 'output' => 'STOP/RESET SLAVE'];

            $pdo->exec('SET GLOBAL read_only = 0');
            try { $pdo->exec('SET GLOBAL super_read_only = 0'); } catch (\Throwable) {}
            $steps[] = ['name' => 'Runtime writable', 'ok' => true, 'output' => 'read_only=0'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'steps' => $steps, 'error' => $e->getMessage()];
        }

        // Persist: strip read_only so a restart does not re-apply it.
        $persisted = self::stripReadOnlyFromConfig($configPath);
        $steps[] = ['name' => 'Persistir en my.cnf', 'ok' => $persisted['ok'], 'output' => $persisted['message']];

        return ['ok' => true, 'steps' => $steps];
    }

    /**
     * Remove/disable read_only in the MySQL config file so the role survives a
     * restart. Keeps a timestamped backup.
     */
    public static function stripReadOnlyFromConfig(string $path): array
    {
        if (!file_exists($path)) {
            return ['ok' => false, 'message' => "No existe {$path}"];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return ['ok' => false, 'message' => "No se pudo leer {$path}"];
        }
        if (!preg_match('/^\s*(read_only|super_read_only)\s*=/mi', $content)) {
            return ['ok' => true, 'message' => 'read_only no estaba fijado en el fichero'];
        }

        ReplicationService::backupFile($path);
        // Comment it out rather than delete, so the change is auditable.
        $new = preg_replace(
            '/^(\s*)(read_only|super_read_only)(\s*=.*)$/mi',
            '$1# [musedock-failover] promovido a master: $2$3',
            $content
        );
        if ($new === null || file_put_contents($path, $new) === false) {
            return ['ok' => false, 'message' => 'No se pudo escribir la configuracion'];
        }
        return ['ok' => true, 'message' => 'read_only comentado (backup creado)'];
    }

    // ─────────────────────────────────────────────────────────
    // 4. LSYNCD EXCLUSION (anti split-brain for files)
    // ─────────────────────────────────────────────────────────

    /**
     * A promoted node must stop RECEIVING files (it is now the source of truth),
     * and the demoted/old master must stop PUSHING them. Marking the node as
     * standby removes it from generateLsyncdConfig()'s target list.
     */
    public static function excludeFromLsyncd(int $nodeId, string $reason = ''): array
    {
        try {
            Database::update('cluster_nodes', ['standby' => true], 'id = :id', ['id' => $nodeId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $gen = FileSyncService::generateLsyncdConfig();
        LogService::log('cluster.failover', 'lsyncd-exclude', "Nodo #{$nodeId} excluido de lsyncd: {$reason}");
        return ['ok' => true, 'lsyncd' => $gen];
    }

    /** Stop pushing files from THIS node (it is no longer the master). */
    public static function stopLocalFileSync(): array
    {
        shell_exec('systemctl stop lsyncd 2>&1');
        $stopped = trim((string)shell_exec('systemctl is-active lsyncd 2>/dev/null')) !== 'active';
        Settings::set('filesync_enabled', '0');
        LogService::log('cluster.failover', 'lsyncd-stop', 'lsyncd detenido en este nodo (ya no es master)');
        return ['ok' => $stopped, 'message' => $stopped ? 'lsyncd detenido' : 'no se pudo detener lsyncd'];
    }

    // ─────────────────────────────────────────────────────────
    // 5 + 6. REBUILD OLD MASTER / SWITCHOVER BACK
    // ─────────────────────────────────────────────────────────

    /**
     * Preflight for rebuilding a recovered old master AS A SLAVE of the current
     * master.
     *
     * Key point most people get wrong: once the new master accepted writes, the
     * old master's data is STALE AND DIVERGENT. There is no safe "copy back".
     * The only correct path is to rebuild the old node from the node that holds
     * the good data, then switch over later in a controlled window.
     */
    public static function planRebuildAsSlave(string $newMasterIp): array
    {
        $plan = [];
        $warnings = [];
        $blocking = [];

        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            $blocking[] = 'IP del nuevo master no valida.';
        }

        $clusters = PgClusterService::listClusters();
        foreach ($clusters as $c) {
            $plan[] = "PostgreSQL {$c['key']} (:{$c['port']}): pg_basebackup desde {$newMasterIp} → standby "
                    . '(directorio actual apartado, no borrado; rollback disponible)';
        }
        $plan[] = 'MySQL/MariaDB: CHANGE MASTER hacia ' . $newMasterIp . ' + read_only=1 (persistido)';
        $plan[] = 'Este nodo deja de empujar ficheros (lsyncd detenido) y pasa a recibirlos';
        $plan[] = 'Rol local → slave (cluster_role, repl_role, servers.role, .env)';

        $warnings[] = 'Los datos locales actuales se consideran OBSOLETOS y DIVERGENTES: '
                    . 'seran reemplazados por los del nuevo master. Haga un dump logico antes si necesita conservarlos.';
        $warnings[] = 'No existe una vuelta automatica: el retorno al master original es un switchover manual posterior.';

        return [
            'ok'        => empty($blocking),
            'plan'      => $plan,
            'warnings'  => $warnings,
            'blocking'  => $blocking,
            'clusters'  => array_map(fn($c) => $c['key'], $clusters),
        ];
    }

    /**
     * Preflight for switching back: only safe when the rebuilt node is a healthy,
     * fully caught-up standby of the current master.
     */
    public static function preflightSwitchover(int $targetNodeId): array
    {
        $checks = [];
        $blocking = [];

        $node = ClusterService::getNode($targetNodeId);
        if (!$node) {
            return ['ok' => false, 'checks' => [], 'blocking' => ['Nodo no encontrado']];
        }

        // Every local cluster must be a streaming standby with no lag.
        foreach (PgClusterService::listClusters() as $c) {
            $st = ReplicationService::getPgSlaveStatusForCluster($c);
            if ($st === null) {
                $checks[] = ['name' => "PostgreSQL {$c['key']}", 'ok' => false, 'detail' => 'no es standby'];
                $blocking[] = "El clúster {$c['key']} no es un standby: no se puede hacer switchover con seguridad.";
                continue;
            }
            $ok = $st['streaming'] && $st['lag_seconds'] <= 5;
            $checks[] = ['name' => "PostgreSQL {$c['key']}", 'ok' => $ok,
                         'detail' => ($st['streaming'] ? 'streaming' : $st['status']) . ", lag {$st['lag_seconds']}s"];
            if (!$ok) $blocking[] = "El clúster {$c['key']} no esta al dia (lag {$st['lag_seconds']}s).";
        }

        $checks[] = ['name' => 'Nodo destino', 'ok' => ($node['status'] ?? '') === 'online',
                     'detail' => $node['name'] . ' — ' . ($node['status'] ?? '?')];
        if (($node['status'] ?? '') !== 'online') {
            $blocking[] = 'El nodo destino no esta online.';
        }

        return [
            'ok'       => empty($blocking),
            'checks'   => $checks,
            'blocking' => $blocking,
            'note'     => 'El switchover es manual y requiere ventana: se fencea el master actual, se promueve el destino '
                        . 'y este nodo se reconstruye como slave del nuevo master.',
        ];
    }

    // ─────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────

    private static function probe(string $url, int $timeout): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0;
    }

    private static function tcpProbe(string $host, int $port, int $timeout): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) { fclose($fp); return true; }
        return false;
    }
}
