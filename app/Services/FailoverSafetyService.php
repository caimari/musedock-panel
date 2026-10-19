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
        // Un servidor no puede «aislar al master antiguo» si esa IP es la suya: sería pedirse a sí mismo que se aparte
        // (o, peor, apartar a otro por una búsqueda imprecisa). Casi siempre es un cluster_master_ip mal guardado.
        if (!$force && ClusterGuardService::hostIsSelf($oldMasterIp)) {
            return ['fenced' => false, 'checks' => $checks,
                'error' => "La IP del master antiguo ({$oldMasterIp}) es de ESTE servidor: no se aísla a sí mismo y promover así dejaría dos masters (split-brain). Revisa cluster_master_ip."];
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
        // Interruptor de seguridad: con MUSEDOCK_DISABLE_REMOTE_FENCE=1 (lo pone la batería de pruebas) NUNCA se
        // ordena aislarse a otro servidor. Una prueba que llamó a esta función en producción apartó a Filemon dos
        // veces el 2026-10-18.
        if (getenv('MUSEDOCK_DISABLE_REMOTE_FENCE') === '1') {
            return ['ok' => false, 'message' => 'aislamiento remoto desactivado (MUSEDOCK_DISABLE_REMOTE_FENCE=1)'];
        }
        // Nodo EXACTO por su host (no por subcadena: «10.10.70.1» casaba también con 10.10.70.154 y .156)
        $node = ClusterService::getNodeByHost($ip);
        if (!$node) {
            return ['ok' => false, 'message' => 'No esta registrado como nodo (o hay más de uno con esa dirección): no se puede pedir el fencing remoto'];
        }
        try {
            $r = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', [
                'action'  => 'fence-self',
                'payload' => ['reason' => 'promotion of another node'],
            ]);
            // La respuesta NO es fiable: quien se aísla para su Caddy, y por ese Caddy llegó esta petición, así
            // que la respuesta puede perderse («Connection reset») aunque el aislamiento se haga (relevo
            // 2026-10-18: Filemon se apartó y mortadelo, sin respuesta, abortó la promoción y no deshizo nada).
            // Se mira de verdad: el otro nodo dice que está apartado, o ya no responde por ninguna parte.
            $answered = !empty($r['ok']);
            $deadline = time() + 45;
            do {
                $st = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
                $state = is_array($st['data']['state'] ?? null) && !empty($st['ok']) ? $st['data']['state'] : null;
                if (self::fenceConfirmed($state, self::probe("https://{$ip}:8444/", 4), self::probe("https://{$ip}/", 4), self::tcpProbe($ip, 5432, 2) || self::tcpProbe($ip, 5433, 2))) {
                    return ['ok' => true, 'message' => 'servicios detenidos (comprobado' . ($answered ? '' : ': la respuesta se perdió, pero el nodo ya está apartado') . ')'];
                }
                sleep(3);
            } while (time() < $deadline);
            return ['ok' => false, 'message' => 'no se pudo confirmar que el nodo se haya apartado en 45 s' . ($answered ? '' : ' (sin respuesta: ' . ($r['error'] ?? '?') . ')')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * ¿Está apartado el otro nodo? Sí si lo dice él (cluster_fenced) o si no responde por ninguna parte
     * (panel, web y base de datos). Pura.
     */
    public static function fenceConfirmed(?array $remoteState, bool $panelUp, bool $webUp, bool $pgUp): bool
    {
        if ($remoteState !== null && !empty($remoteState['cluster_fenced'])) {
            return true;
        }
        return !$panelUp && !$webUp && !$pgUp;
    }

    /** ¿Responde el master como sano (panel y web)? Un master apartado responde por el panel de rescate pero no sirve webs. */
    public static function masterIsHealthy(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && self::probe("https://{$ip}:8444/", 5) && self::probe("https://{$ip}/", 5);
    }

    /**
     * Por qué NO promover ahora (null = se puede). Un master vivo y sano se aparta primero para promover otro:
     * si algo falla a medias se quedan los dos sin servir. Para eso está «Pasar el mando a…» (cambio planificado,
     * con comprobaciones y vuelta atrás). Pura.
     */
    public static function promoteBlockReason(string $oldMasterIp, bool $masterHealthy, bool $force, bool $allowAlive): ?string
    {
        if ($oldMasterIp === '' || $force || $allowAlive || !$masterHealthy) {
            return null;
        }
        return "El master actual ({$oldMasterIp}) responde y está sano: promover este servidor lo apartaría y, si algo falla a medias, "
            . 'se quedarían los dos sin servir (pasó el 2026-10-18). Para cambiar de master a propósito usa «Pasar el mando a…» en el Dashboard '
            . '(con comprobaciones y vuelta atrás). Si de verdad quieres promover sin ese cambio: php bin/cluster-switch.php promote --allow-alive';
    }

    /**
     * ¿Las bases de datos de esta réplica están recibiendo AHORA del master? PostgreSQL (cada clúster en recuperación con el
     * receptor en «streaming») y MariaDB/MySQL (IO y SQL en marcha, poco retraso). Solo lectura y barato: lo anota el vigilante
     * del relevo cada minuto en las réplicas (replica_fresh_at). Si no hay ninguna base en recuperación no hay nada que medir (true).
     */
    public static function replicaDbHealthyNow(): bool
    {
        try {
            foreach (PgClusterService::listClusters() as $c) {
                $rec = ReplicationService::queryCluster($c, 'SELECT pg_is_in_recovery()');
                if (($rec[0][0] ?? '') !== 't') {
                    continue;
                }
                $wr = ReplicationService::queryCluster($c, 'SELECT status FROM pg_stat_wal_receiver');
                if (($wr[0][0] ?? '') !== 'streaming') {
                    return false;
                }
            }
            $ss = ReplicationService::getMysqlSlaveStatus();
            if ($ss) {
                $io = $ss['Slave_IO_Running'] ?? $ss['Replica_IO_Running'] ?? '';
                $sql = $ss['Slave_SQL_Running'] ?? $ss['Replica_SQL_Running'] ?? '';
                if ($io !== 'Yes' || $sql !== 'Yes' || (int)($ss['Seconds_Behind_Master'] ?? 0) > 300) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /**
     * ¿Esta réplica puede promoverse SOLA sin perder lo escrito en el master? Se promueve solo si sus bases estaban al día hace
     * poco (replica_fresh_at, anotado mientras el master vivía). Una réplica que acaba de volver y aún se está reconstruyendo, o cuya
     * réplica lleva rato rota, no tiene lo escrito por el master: promoverla pierde esos cambios. Sin dato (versión recién
     * instalada) se deja pasar como antes. Pura. @return array{ok:bool,reason:string}
     */
    public static function freshnessVerdict(?int $freshAt, int $now, int $maxMinutes, bool $required = true, int $downMinutes = 0): array
    {
        if (!$required) {
            return ['ok' => true, 'reason' => 'comprobación desactivada (failover_require_fresh = 0)'];
        }
        if ($freshAt === null || $freshAt <= 0) {
            return ['ok' => true, 'reason' => 'sin dato de la copia (aún no se ha anotado): se permite como antes'];
        }
        // El dato deja de renovarse cuando cae el master: se cuenta el margen desde que cayó (la espera por los vecinos y los testigos no la atrasa).
        $age = (int)floor(($now - $freshAt) / 60);
        $limit = max(1, $maxMinutes) + max(0, $downMinutes);
        if ($age > $limit) {
            return ['ok' => false, 'reason' => "la copia de las bases de datos no estaba al día: la última vez que lo estuvo fue hace {$age} min (límite {$limit})"];
        }
        return ['ok' => true, 'reason' => "copia al día hace {$age} min"];
    }

    /**
     * Deshace el apartado de otro nodo (si estaba apartado) y comprueba que ya no lo está. Es lo que hay que hacer si
     * se le pidió apartarse y la promoción no llegó a hacerse.
     */
    public static function requestUnfence(string $ip): array
    {
        if (getenv('MUSEDOCK_DISABLE_REMOTE_FENCE') === '1') {
            return ['ok' => false, 'message' => 'reactivación remota desactivada (MUSEDOCK_DISABLE_REMOTE_FENCE=1)'];
        }
        $node = ClusterService::getNodeByHost($ip);
        if (!$node) {
            return ['ok' => false, 'message' => 'no está registrado como nodo (o hay más de uno con esa dirección)'];
        }
        $st = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
        $state = $st['data']['state'] ?? null;
        if (!empty($st['ok']) && is_array($state) && empty($state['cluster_fenced'])) {
            return ['ok' => true, 'message' => 'no estaba apartado'];
        }
        ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', ['action' => 'unfence-self', 'payload' => []]);
        $deadline = time() + 45;
        do {
            sleep(3);
            $st = ClusterService::callNode((int)$node['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
            $state = $st['data']['state'] ?? null;
            if (!empty($st['ok']) && is_array($state) && empty($state['cluster_fenced'])) {
                return ['ok' => true, 'message' => 'reactivado (comprobado)'];
            }
        } while (time() < $deadline);
        return ['ok' => false, 'message' => 'no se pudo confirmar que se haya reactivado'];
    }

    /**
     * Stop serving on THIS node: called when another node is being promoted.
     * Stops Caddy (web + panel routes) and puts databases in read-only so no
     * write can land here while another node is the master.
     */
    public const FENCE_FLAG = '/var/lib/musedock/fenced';

    /**
     * Que el aislamiento sobreviva a un reinicio: mientras exista FENCE_FLAG, systemd
     * no arranca el Caddy principal (ExecCondition) y sí el panel de rescate. Sin esto,
     * un nodo apartado que se reinicia (corte de red, proveedor…) volvería a servir
     * webs con datos viejos hasta que el cluster-worker lo apartara otra vez.
     */
    public static function installFenceGuard(): void
    {
        $panel = dirname(__DIR__, 2);
        $dropin = "/etc/systemd/system/caddy.service.d/zz-musedock-fence.conf";
        $unit = "/etc/systemd/system/musedock-fence-guard.service";
        $cond = "[Service]\nExecCondition=/bin/sh -c 'test ! -e " . self::FENCE_FLAG . "'\n";
        $want = [
            $dropin => "# MuseDock: con el nodo apartado (" . self::FENCE_FLAG . ") el Caddy principal no arranca.\n" . $cond,
            $unit => "# MuseDock: al arrancar, si el nodo está apartado, panel de rescate en el puerto del panel.\n"
                . "[Unit]\nDescription=MuseDock: panel de rescate si el nodo esta apartado\n"
                . "ConditionPathExists=" . self::FENCE_FLAG . "\nAfter=network-online.target musedock-panel.service\nWants=network-online.target\n\n"
                . "[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/php {$panel}/bin/panel-rescue.php start\n\n"
                . "[Install]\nWantedBy=multi-user.target\n",
        ];
        // Y el correo: apartado, tampoco acepta correo ni conexiones de buzones al reiniciar.
        foreach (self::mailUnits() as $u) {
            $want["/etc/systemd/system/{$u}.d/zz-musedock-fence.conf"] = "# MuseDock: con el nodo apartado (" . self::FENCE_FLAG . ") el correo no arranca.\n" . $cond;
        }
        $changed = false;
        foreach ($want as $file => $content) {
            @mkdir(dirname($file), 0755, true);
            if (!is_file($file) || file_get_contents($file) !== $content) {
                file_put_contents($file, $content);
                $changed = true;
            }
        }
        if ($changed) {
            shell_exec('systemctl daemon-reload 2>&1; systemctl enable musedock-fence-guard.service 2>&1');
        }
    }

    /**
     * Unidades systemd del correo de este nodo (Postfix recibe, Dovecot sirve los
     * buzones). En Ubuntu el Postfix real es postfix@-.service. Vacío si no hay correo.
     */
    private static function mailUnits(): array
    {
        $units = [];
        if (is_dir('/etc/postfix')) {
            $units[] = 'postfix@-.service';
        }
        if (is_dir('/etc/dovecot')) {
            $units[] = 'dovecot.service';
        }
        return $units;
    }

    private const MAIL_STOPPED = '/var/lib/musedock/fenced-mail';

    /**
     * Apartado, el nodo deja de aceptar correo y conexiones de buzones. Si siguiera,
     * los remitentes con el DNS viejo en caché y los móviles conectados por IMAP/POP3
     * escribirían aquí mientras el nuevo master recibe lo demás: al juntarse los dos
     * buzones, la réplica (dsync) renumera mensajes y los clientes ven "este correo
     * ya no está" (2026-10-03). Postfix parado no pierde nada: el remitente reintenta
     * y acaba entregando en el nuevo master; lo que hubiera en cola aquí sale al volver.
     */
    public static function stopMailIntake(): array
    {
        $stopped = [];
        foreach (self::mailUnits() as $u) {
            if (trim((string)shell_exec('systemctl is-active ' . escapeshellarg($u) . ' 2>/dev/null')) === 'active') {
                shell_exec('systemctl stop ' . escapeshellarg($u) . ' 2>&1');
                $stopped[] = $u;
            }
        }
        if ($stopped) {
            self::rememberMailStopped($stopped);
        }
        return $stopped;
    }

    /** Anota unidades de correo paradas (suma a las ya anotadas: no pisa lo que paró otro paso). */
    private static function rememberMailStopped(array $units): void
    {
        $prev = array_filter(array_map('trim', (array)@file(self::MAIL_STOPPED)));
        @mkdir(dirname(self::MAIL_STOPPED), 0755, true);
        file_put_contents(self::MAIL_STOPPED, implode("\n", array_values(array_unique(array_merge($prev, $units)))) . "\n");
    }

    /**
     * Cambio de mando: para SOLO Postfix (no entra correo nuevo) para la última sincronización de buzones, con
     * Dovecot y las webs aún funcionando. Queda anotado para que reactivar el nodo (o el demote) lo vuelva a arrancar:
     * sin esa nota, el cambio Filemon → Mortadelo del 19-10 dejó Postfix parado en el nodo viejo.
     */
    public static function stopPostfixForSync(): bool
    {
        $u = 'postfix@-.service';
        if (!in_array($u, self::mailUnits(), true)
            || trim((string)shell_exec('systemctl is-active ' . escapeshellarg($u) . ' 2>/dev/null')) !== 'active') {
            return false;
        }
        shell_exec('systemctl stop ' . escapeshellarg($u) . ' 2>&1');
        self::rememberMailStopped([$u]);
        return true;
    }

    /** Si la sincronización falla y no se aparta nada: Postfix vuelve a arrancar. */
    public static function resumePostfixAfterSync(): void
    {
        self::resumeMailIntake();
    }

    /** Vuelve a arrancar lo que paró stopMailIntake (solo eso). */
    public static function resumeMailIntake(): array
    {
        $started = [];
        foreach (array_filter(array_map('trim', (array)@file(self::MAIL_STOPPED))) as $u) {
            if (in_array($u, self::mailUnits(), true)) {
                shell_exec('systemctl start ' . escapeshellarg($u) . ' 2>&1');
                $started[] = $u . ': ' . trim((string)shell_exec('systemctl is-active ' . escapeshellarg($u) . ' 2>/dev/null'));
            }
        }
        @unlink(self::MAIL_STOPPED);
        return $started;
    }

    public static function fenceSelf(string $reason = ''): array
    {
        $steps = [];

        // Primero la marca persistente: si el servidor se reinicia a partir de aquí,
        // sigue apartado.
        @mkdir(dirname(self::FENCE_FLAG), 0755, true);
        file_put_contents(self::FENCE_FLAG, date('c') . ' ' . $reason . "\n");
        self::installFenceGuard();

        $out = shell_exec('systemctl stop caddy 2>&1');
        $stopped = trim((string)shell_exec('systemctl is-active caddy 2>/dev/null')) !== 'active';
        $steps[] = ['name' => 'Detener Caddy', 'ok' => $stopped, 'output' => $stopped ? 'detenido' : trim((string)$out)];

        // El panel sigue accesible (https://IP:puerto-del-panel) con un Caddy mínimo de
        // rescate que solo lleva al panel: si algo va mal, se puede entrar sin túneles.
        if ($stopped) {
            $r = trim((string)shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/panel-rescue.php') . ' start 2>&1'));
            $steps[] = ['name' => 'Panel de rescate', 'ok' => str_contains($r, 'ACTIVO'), 'output' => $r];
        }

        // Dejar de empujar ficheros al nuevo master. lsyncd copia en ESPEJO: si siguiera
        // en marcha, borraría en el nuevo master lo que se suba allí mientras este nodo
        // está apartado. No se toca filesync_enabled: al reactivar (unfenceSelf) o al
        // volver a ser master se vuelve a arrancar.
        shell_exec('systemctl stop lsyncd 2>&1');
        $lsyncdStopped = trim((string)shell_exec('systemctl is-active lsyncd 2>/dev/null')) !== 'active';
        $steps[] = ['name' => 'Detener lsyncd (copia de ficheros)', 'ok' => $lsyncdStopped, 'output' => $lsyncdStopped ? 'detenido' : 'sigue activo'];

        $mail = self::stopMailIntake();
        if ($mail) {
            $steps[] = ['name' => 'Detener el correo (no recibe ni sirve buzones)', 'ok' => true, 'output' => implode(', ', $mail)];
        }

        // El portal de clientes escribe en la base del panel: un nodo apartado no lo sirve.
        try {
            $portal = PortalService::stop();
            if ($portal) {
                $steps[] = ['name' => 'Detener el portal de clientes', 'ok' => true, 'output' => implode(', ', $portal)];
            }
        } catch (\Throwable) {
        }

        // Make every PostgreSQL cluster read-only (defense in depth) — menos el del
        // propio panel: es de este nodo (no se replica) y, en solo lectura, el panel no
        // podría ni guardar su rol al reconstruirse como slave del nuevo master.
        $panelPort = (int)\MuseDockPanel\Env::get('DB_PORT', 5433);
        foreach (PgClusterService::listClusters() as $c) {
            if ((int)$c['port'] === $panelPort) {
                $steps[] = ['name' => "PostgreSQL {$c['key']}", 'ok' => true, 'output' => 'es la base del panel: se deja con escritura'];
                continue;
            }
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

    /**
     * ¿Soy un master "caducado"? Caso: este servidor era el master, se cayó (o lo
     * reinició el proveedor), otro nodo se promovió mientras tanto y ahora vuelvo
     * creyéndome master. Si sigo aceptando escrituras, los clientes con el DNS viejo
     * en caché escriben aquí y el resto en el nuevo master: dos masters, datos que
     * divergen. Se pregunta a cada nodo del cluster; si alguno es master y se
     * promovió DESPUÉS que yo, me aíslo (fenceStaleMaster).
     *
     * Si no llego a ningún nodo no se decide nada (no se puede saber) y se reintenta
     * en la siguiente pasada del cluster-worker.
     */
    public static function checkStaleMaster(bool $apply = true): array
    {
        $role = Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        if ($role !== 'master') {
            return ['stale' => false, 'skipped' => "rol {$role}"];
        }
        if (Settings::get('cluster_fenced', '0') === '1') {
            return ['stale' => true, 'skipped' => 'ya aislado'];
        }
        $mine = Settings::get('cluster_promoted_at', '');
        $mineTs = $mine !== '' ? (int)strtotime($mine) : 0;
        $asked = 0;
        foreach (ClusterService::getNodes() as $n) {
            $meta = json_decode((string)($n['metadata'] ?? '{}'), true) ?: [];
            if (!empty($meta['witness'])) {
                continue; // un testigo puede ser master de OTRO cluster
            }
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
            $st = $r['data']['state'] ?? null;
            if (empty($r['ok']) || !is_array($st)) {
                continue;
            }
            $asked++;
            $theirs = (string)($st['cluster_promoted_at'] ?? '');
            if (($st['cluster_role'] ?? '') === 'master' && empty($st['cluster_fenced'])
                && $theirs !== '' && (int)strtotime($theirs) > $mineTs) {
                $reason = "El nodo {$n['name']} se promovió a master el {$theirs}"
                    . ($mine !== '' ? ", después que este (promovido el {$mine})" : ' mientras este no respondía');
                if (!$apply) {
                    return ['stale' => true, 'winner' => $n['name'], 'reason' => $reason];
                }
                $host = (string)(parse_url((string)$n['api_url'], PHP_URL_HOST) ?: '');
                $fence = self::fenceStaleMaster($reason, $host);
                // Y, si es seguro, pasar a ser espejo del nuevo master sin esperar a nadie.
                $rejoin = self::autoRejoinAsSlave($host);
                try {
                    if (!empty($rejoin['done'])) {
                        self::clearRejoinPending();
                        NotificationService::send('Failover: este servidor ya es espejo del nuevo master',
                            "Tras apartarse, se ha convertido en réplica de {$n['name']} ({$host}): no tenía escrituras propias tras el relevo. Volver a ser principal es manual.");
                    } else {
                        $why = (string)($rejoin['reason'] ?? 'motivo desconocido');
                        self::markRejoinPending((string)$n['name'], $host, $why);
                        [$subject, $body] = self::rejoinEmail((string)$n['name'], $host, $why);
                        NotificationService::send($subject, $body);
                    }
                } catch (\Throwable) {
                }
                return ['stale' => true, 'winner' => $n['name'], 'reason' => $reason, 'rejoin' => $rejoin] + $fence;
            }
        }
        return ['stale' => false, 'nodes_asked' => $asked];
    }

    /**
     * Aísla un master caducado SIN cortar el acceso al panel (a diferencia de
     * fenceSelf, que para Caddy entero): las bases de datos de datos pasan a solo
     * lectura (la del panel no, para poder seguir gestionándolo) y se ejecutan los
     * scripts de relevo demote.d (parar la app, soltar la IP flotante...). El rol no
     * se cambia: devolver este nodo como slave es una decisión (demoteToSlave).
     */
    public static function fenceStaleMaster(string $reason, string $newMasterHost = ''): array
    {
        $steps = [];
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $all = PgClusterService::listClusters();
        foreach ($all as $c) {
            if ($c['cluster'] === 'panel' || (count($all) > 1 && (int)$c['port'] === $panelPort)) {
                continue;
            }
            $p = (int)$c['port'];
            shell_exec('runuser -u postgres -- psql -p ' . $p . ' -Xc ' . escapeshellarg('ALTER SYSTEM SET default_transaction_read_only = on') . ' 2>&1');
            shell_exec('runuser -u postgres -- psql -p ' . $p . ' -Xc ' . escapeshellarg('SELECT pg_reload_conf()') . ' 2>&1');
            $steps[] = "PostgreSQL {$c['key']} en solo lectura";
        }
        try {
            $mirrorOff = ConfigMirrorService::deactivate();
            if ($mirrorOff) {
                $steps[] = 'Configuración copiada del master apagada (' . count($mirrorOff) . ' elementos)';
            }
        } catch (\Throwable) {
        }
        $hookEnv = ['MUSEDOCK_REASON' => 'stale-master'];
        if (filter_var($newMasterHost, FILTER_VALIDATE_IP) || preg_match('/^[A-Za-z0-9.-]+$/', $newMasterHost)) {
            $hookEnv['MUSEDOCK_NEW_MASTER_IP'] = $newMasterHost;
        }
        $hooks = RoleHookService::run('demote', $hookEnv);

        // Igual que fenceSelf: marca persistente (sobrevive a reinicios), sin servir webs
        // ni copiar ficheros, y el panel accesible por el de rescate.
        @mkdir(dirname(self::FENCE_FLAG), 0755, true);
        file_put_contents(self::FENCE_FLAG, date('c') . ' ' . $reason . "\n");
        self::installFenceGuard();
        shell_exec('systemctl stop lsyncd 2>&1');
        $steps[] = 'lsyncd detenido';
        shell_exec('systemctl stop caddy 2>&1');
        $steps[] = 'Caddy detenido (no sirve webs con datos viejos)';
        if ($mail = self::stopMailIntake()) {
            $steps[] = 'Correo detenido (no recibe ni sirve buzones): ' . implode(', ', $mail);
        }
        try {
            if ($portal = PortalService::stop()) {
                $steps[] = 'Portal de clientes detenido: ' . implode(', ', $portal);
            }
        } catch (\Throwable) {
        }
        $steps[] = trim((string)shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/panel-rescue.php') . ' start 2>&1'));

        Settings::set('cluster_fenced', '1');
        Settings::set('cluster_fenced_at', date('Y-m-d H:i:s'));
        Settings::set('cluster_fenced_reason', $reason);
        LogService::log('cluster.failover', 'stale-master', 'Master caducado aislado: ' . $reason);
        try {
            NotificationService::send('Failover: este servidor se ha aislado (master caducado)',
                "{$reason}.\n\nPara evitar dos masters, sus bases de datos de clientes están en solo lectura y se han ejecutado "
                . "los scripts demote.d; Caddy, lsyncd y el correo parados. El panel sigue accesible por IP (panel de rescate). Siguiente paso: devolverlo como slave del nuevo master.");
        } catch (\Throwable) {
        }
        return ['fenced' => true, 'steps' => $steps, 'hooks' => $hooks];
    }

    // ── Apartado desde el arranque (guardián de arranque, bin/boot-guard.php) ─────────
    // Un master que vuelve tras un reinicio creyéndose master arrancaba sus bases de datos
    // ESCRIBIBLES y las apps escribían en ellas hasta que el cluster-worker (cron, tras la red) lo
    // aislaba: 2,5 minutos el 2026-10-18, ~48 KB de WAL que impidieron reincorporarlo solo. ALTER
    // SYSTEM no sirve con PostgreSQL parado; este fichero de conf.d sí vale desde el primer arranque.
    public const FENCED_PG_CONF = '90-musedock-fenced.conf';

    /** Clústeres de datos (no el del panel) con su carpeta conf.d. */
    private static function dataClusterConfDirs(): array
    {
        $out = [];
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $all = PgClusterService::listClusters();
        foreach ($all as $c) {
            if ($c['cluster'] === 'panel' || (count($all) > 1 && (int)$c['port'] === $panelPort)) {
                continue;
            }
            $dir = dirname((string)($c['config_file'] ?? '')) . '/conf.d';
            if (is_dir($dir)) {
                $out[$c['key']] = $dir;
            }
        }
        return $out;
    }

    /** Pone default_transaction_read_only = on para el próximo arranque de cada clúster de datos. */
    public static function writeFencedReadOnlyConf(): array
    {
        $done = [];
        foreach (self::dataClusterConfDirs() as $key => $dir) {
            $f = $dir . '/' . self::FENCED_PG_CONF;
            $body = "# MuseDock: este nodo está apartado (master caducado): sin escrituras hasta que se reincorpore.\n"
                  . "# Lo quita el panel al reactivarlo o convertirlo en réplica; no lo borres a mano antes.\ndefault_transaction_read_only = on\n";
            if (!is_file($f) || file_get_contents($f) !== $body) {
                @file_put_contents($f, $body);
                @chmod($f, 0644);
            }
            $done[] = $key;
        }
        return $done;
    }

    /** Quita ese fichero (y recarga los clústeres en marcha). Devuelve los clústeres tocados. */
    public static function clearFencedReadOnlyConf(bool $reload = true): array
    {
        $cleared = [];
        foreach (self::dataClusterConfDirs() as $key => $dir) {
            $f = $dir . '/' . self::FENCED_PG_CONF;
            if (is_file($f) && @unlink($f)) {
                $cleared[] = $key;
            }
        }
        if ($cleared && $reload) {
            foreach (PgClusterService::listClusters() as $c) {
                if (in_array($c['key'], $cleared, true) && PgClusterService::isRunning($c)) {
                    shell_exec('runuser -u postgres -- psql -p ' . (int)$c['port'] . ' -Xc ' . escapeshellarg('SELECT pg_reload_conf()') . ' 2>&1');
                }
            }
        }
        return $cleared;
    }

    /**
     * Versión MÍNIMA del aislamiento para el arranque (la llama bin/boot-guard.php ANTES de que
     * arranquen las bases de datos, Caddy y el correo): marca el nodo como apartado, deja a Caddy y
     * al correo sin arrancar y a las bases de datos de datos en solo lectura. No hace demote ni toca
     * PostgreSQL en marcha (bloquearía el propio arranque): el aislamiento completo, los avisos y la
     * reincorporación los hace el cluster-worker en cuanto corre.
     */
    public static function fenceAtBoot(string $reason): array
    {
        @mkdir(dirname(self::FENCE_FLAG), 0755, true);
        file_put_contents(self::FENCE_FLAG, date('c') . ' boot-guard: ' . $reason . "\n");
        self::installFenceGuard();
        shell_exec('systemctl daemon-reload 2>&1');
        $pg = self::writeFencedReadOnlyConf();
        LogService::log('cluster.failover', 'boot-fence', 'Apartado desde el arranque: ' . $reason . ($pg ? ' (PostgreSQL de solo lectura: ' . implode(', ', $pg) . ')' : ''));
        return ['fenced' => true, 'pg_read_only' => $pg];
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
        // MariaDB/MySQL: fenceSelf lo puso en solo lectura y reactivar el nodo no lo devolvía a escritura
        // (relevo 2026-10-18: «reactivar» dejaba MariaDB sin escribir). Solo si este nodo manda: una
        // réplica debe seguir en solo lectura.
        if ((Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone')) !== 'slave') {
            try {
                $pdo = ReplicationService::getMysqlPdo();
                if ($pdo) {
                    $pdo->exec('SET GLOBAL read_only = 0');
                    $steps[] = ['name' => 'MariaDB/MySQL', 'ok' => true, 'output' => 'read_only=0 (vuelve a escribir)'];
                }
            } catch (\Throwable $e) {
                $steps[] = ['name' => 'MariaDB/MySQL', 'ok' => false, 'output' => $e->getMessage()];
            }
        }
        // Quitar la marca ANTES de arrancar Caddy (si no, su ExecCondition lo impide) y
        // liberar el puerto del panel (lo tiene el Caddy de rescate).
        @unlink(self::FENCE_FLAG);
        self::clearFencedReadOnlyConf();
        shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/panel-rescue.php') . ' stop 2>&1');
        shell_exec('systemctl start caddy 2>&1');
        if ($mail = self::resumeMailIntake()) {
            $steps[] = ['name' => 'Correo', 'ok' => true, 'output' => implode(', ', $mail)];
        }
        if (Settings::get('filesync_enabled', '0') === '1' && Settings::get('cluster_role', '') === 'master') {
            shell_exec('systemctl start lsyncd 2>&1');
            $steps[] = ['name' => 'lsyncd', 'ok' => true, 'output' => 'arrancado de nuevo'];
        }
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
            // Si la consulta falla, getPgSlaveStatusForCluster devuelve null y antes se
            // daba por "ya es primary": una réplica se quedaba sin promover y las webs en
            // solo lectura (2026-10-03). standby.signal es la prueba definitiva.
            $standbyFile = is_file(rtrim((string)($c['data_dir'] ?? ''), '/') . '/standby.signal');
            if ($status === null && !$standbyFile) {
                $results[$c['key']] = ['ok' => true, 'skipped' => true, 'message' => 'no esta en recovery (ya es primary)'];
                continue;
            }
            if ($dryRun) {
                $results[$c['key']] = ['ok' => true, 'dry_run' => true,
                    'message' => "pg_ctlcluster {$c['version']} {$c['cluster']} promote"];
                continue;
            }
            $r = ReplicationService::promotePgSlaveForCluster($c);
            // Comprobar de verdad que ya acepta escrituras (hasta ~30 s).
            $promoted = false;
            for ($i = 0; $i < 15 && !$promoted; $i++) {
                $rec = ReplicationService::queryCluster($c, 'SELECT pg_is_in_recovery()');
                $promoted = ($rec[0][0] ?? '') === 'f' && !is_file(rtrim((string)($c['data_dir'] ?? ''), '/') . '/standby.signal');
                if (!$promoted) {
                    sleep(2);
                }
            }
            if ($promoted) {
                // Por si venía de un nodo apartado: que no se quede en solo lectura.
                ReplicationService::queryCluster($c, 'ALTER SYSTEM RESET default_transaction_read_only');
                ReplicationService::queryCluster($c, 'SELECT pg_reload_conf()');
            }
            $results[$c['key']] = ['ok' => $promoted, 'message' => $promoted ? 'promovido (comprobado: acepta escrituras)'
                : 'NO quedó promovido: ' . ($r['error'] ?? 'sigue en recuperación')];
        }
        return $results;
    }

    /**
     * Tras promover: ¿las bases de este nodo ACEPTAN ESCRITURAS? (PostgreSQL fuera de recuperación y MariaDB sin
     * read_only). Devuelve los problemas; vacío = todo bien. Si hay alguno, la promoción cuenta como fallida y el
     * cambio de mando se deshace ANTES de mover el DNS (19-10: la MariaDB de Mortadelo se quedó en solo lectura y
     * dos webs dieron 500 con el cambio dado por bueno).
     */
    public static function verifyDatabasesWritable(): array
    {
        $problems = [];
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        try {
            foreach (PgClusterService::listClusters() as $c) {
                if ($c['cluster'] === 'panel' || (int)$c['port'] === $panelPort) {
                    continue;
                }
                // Recién promovido puede tardar unos segundos en salir de recuperación: se reintenta.
                $inRecovery = true;
                for ($try = 0; $try < 6 && $inRecovery; $try++) {
                    $r = ReplicationService::queryCluster($c, 'select pg_is_in_recovery()');
                    $v = $r[0][0] ?? null;
                    $inRecovery = $v === null ? false : ($v === 't' || $v === true || $v === '1');   // sin dato = no se bloquea
                    if ($inRecovery) {
                        sleep(2);
                    }
                }
                if ($inRecovery) {
                    $problems[] = "PostgreSQL {$c['key']} sigue en recuperación (no acepta escrituras)";
                }
            }
        } catch (\Throwable $e) {
            $problems[] = 'no se pudo comprobar PostgreSQL: ' . $e->getMessage();
        }
        try {
            $pdo = ReplicationService::getMysqlPdo();
            if ($pdo && (string)$pdo->query('SELECT @@read_only')->fetchColumn() === '1') {
                $problems[] = 'MariaDB/MySQL sigue en solo lectura (read_only=1)';
            }
        } catch (\Throwable $e) {
            $problems[] = 'no se pudo comprobar MariaDB: ' . $e->getMessage();
        }
        return $problems;
    }

    /**
     * Promueve Redis si es réplica (REPLICAOF NO ONE) y lo persiste con CONFIG
     * REWRITE, que quita la línea replicaof de redis.conf: si no, el siguiente
     * reinicio lo volvería a convertir en réplica del master caído. Si Redis no
     * está instalado o ya es master, no hace nada. La contraseña (requirepass) se
     * lee de redis.conf y se pasa por entorno, nunca en la línea de comandos.
     */
    public static function promoteRedis(bool $dryRun = false): array
    {
        if (trim((string)shell_exec('command -v redis-cli 2>/dev/null')) === '') {
            return ['ok' => true, 'skipped' => 'Redis no instalado'];
        }
        $pass = '';
        foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                $pass = trim($m[1], '"\'');
            }
        }
        $cli = ($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'timeout 10 redis-cli --no-auth-warning ';
        $info = (string)shell_exec($cli . 'INFO replication 2>/dev/null');
        if (!preg_match('/^role:(\w+)/m', $info, $m)) {
            return ['ok' => false, 'error' => 'Redis no responde (¿caído o contraseña distinta?)'];
        }
        if ($m[1] !== 'slave') {
            return ['ok' => true, 'skipped' => 'Redis ya es master'];
        }
        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'message' => 'REPLICAOF NO ONE + CONFIG REWRITE'];
        }
        $r1 = trim((string)shell_exec($cli . 'REPLICAOF NO ONE 2>&1'));
        $r2 = trim((string)shell_exec($cli . 'CONFIG REWRITE 2>&1'));
        $ok = $r1 === 'OK';
        return ['ok' => $ok, 'message' => $ok ? 'Redis promovido a master' . ($r2 === 'OK' ? ' y persistido' : " (CONFIG REWRITE: {$r2})") : "REPLICAOF NO ONE: {$r1}"];
    }

    /**
     * Lo contrario de promoteRedis: este nodo pasa a ser réplica del Redis del nuevo
     * master (misma contraseña en los dos: masterauth = requirepass local) y se
     * persiste. Sin esto, un antiguo master degradado seguía con Redis de principal.
     */
    public static function demoteRedis(string $masterIp, int $port = 6379): array
    {
        if (trim((string)shell_exec('command -v redis-cli 2>/dev/null')) === '') {
            return ['ok' => true, 'skipped' => 'Redis no instalado'];
        }
        if (!filter_var($masterIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP del nuevo master no válida'];
        }
        $pass = '';
        foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                $pass = trim($m[1], '"\'');
            }
        }
        $cli = ($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'timeout 10 redis-cli --no-auth-warning ';
        if ($pass !== '') {
            shell_exec($cli . 'CONFIG SET masterauth ' . escapeshellarg($pass) . ' 2>&1');
        }
        $r1 = trim((string)shell_exec($cli . 'REPLICAOF ' . escapeshellarg($masterIp) . ' ' . (int)$port . ' 2>&1'));
        $r2 = trim((string)shell_exec($cli . 'CONFIG REWRITE 2>&1'));
        $ok = str_starts_with($r1, 'OK');
        return ['ok' => $ok, 'message' => $ok ? "Redis réplica de {$masterIp}" . ($r2 === 'OK' ? ' (persistido)' : " (CONFIG REWRITE: {$r2})") : "REPLICAOF: {$r1}"];
    }

    /**
     * shell_exec con variables de entorno extra (p. ej. PGPASSWORD) y/o stdin, pasados al proceso
     * hijo fuera de la línea de comandos (que es visible con `ps`). Devuelve la salida ('' si vacía).
     */
    private static function shellEnv(string $cmd, array $env = [], ?string $stdin = null): string
    {
        $full = [];
        foreach (array_merge(getenv() ?: [], $env) as $k => $v) {
            $full[(string)$k] = (string)$v;
        }
        $proc = @proc_open(['/bin/sh', '-c', $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, null, $full);
        if (!is_resource($proc)) {
            return '';
        }
        if ($stdin !== null && $stdin !== '') {
            @fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
        return $out === false ? '' : $out;
    }

    /**
     * PostgreSQL: ¿escribió este antiguo master algo DESPUÉS de que el nuevo tomara el
     * relevo? Se compara su posición actual de WAL con el punto en que el nuevo master
     * abrió su línea de tiempo (historial del nuevo timeline, por el protocolo de
     * réplica). Si está por detrás o igual, no hay nada propio que perder. Ante la duda
     * (no se puede leer algo), se considera que SÍ divergió: nunca se decide a ciegas.
     */
    public static function pgDivergedFrom(array $cluster, string $masterIp, int $port, string $user, string $pass): array
    {
        $local = static fn(string $sql) => trim((string)shell_exec('cd /tmp && runuser -u postgres -- psql -p ' . (int)$cluster['port']
            . ' -XAtc ' . escapeshellarg($sql) . ' 2>/dev/null'));
        if ($local('select pg_is_in_recovery()') !== 'f') {
            return ['diverged' => false, 'reason' => 'ya es réplica'];
        }
        $myLsn = $local('select pg_current_wal_lsn()');
        $myTli = (int)$local('select timeline_id from pg_control_checkpoint()');
        $env = ['PGPASSWORD' => $pass, 'PGCONNECT_TIMEOUT' => '5'];
        $conn = escapeshellarg("host={$masterIp} port={$port} user={$user} dbname=postgres replication=database");
        $ident = trim(self::shellEnv('psql ' . $conn . ' -XAt -c "IDENTIFY_SYSTEM" 2>/dev/null', $env));
        $theirTli = (int)(explode('|', $ident)[1] ?? 0);
        if ($myLsn === '' || $myTli < 1 || $theirTli < 1) {
            return ['diverged' => true, 'reason' => 'no se pudo leer la posición de WAL o el timeline'];
        }
        if ($theirTli <= $myTli) {
            return ['diverged' => true, 'reason' => "el nuevo master no tiene un timeline posterior ({$theirTli} vs {$myTli})"];
        }
        $hist = self::shellEnv('psql ' . $conn . ' -XAt -c ' . escapeshellarg("TIMELINE_HISTORY {$theirTli}") . ' 2>/dev/null', $env);
        $switch = null;
        foreach (preg_split('/\R/', $hist) as $line) {
            if (preg_match('/^\s*(\d+)\s+([0-9A-F]+\/[0-9A-F]+)/i', $line, $m) && (int)$m[1] === $myTli) {
                $switch = $m[2];
            }
        }
        if ($switch === null) {
            return ['diverged' => true, 'reason' => "el historial del nuevo master no incluye nuestro timeline {$myTli}"];
        }
        $toInt = static fn(string $l) => (static function ($p) { return (hexdec($p[0]) << 32) + hexdec($p[1]); })(explode('/', $l));
        $diverged = $toInt($myLsn) > $toInt($switch);
        return ['diverged' => $diverged, 'local_lsn' => $myLsn, 'switch_lsn' => $switch,
                'reason' => $diverged ? "este nodo escribió después del relevo ({$myLsn} > {$switch})" : 'sin escrituras propias tras el relevo'];
    }

    public const REJOIN_KEY = 'cluster_rejoin_pending';

    /** El nuevo master al que este nodo apartado debe reincorporarse como copia, si queda pendiente. @return array|null */
    public static function rejoinPending(): ?array
    {
        $r = json_decode((string)Settings::get(self::REJOIN_KEY, ''), true);
        return is_array($r) && !empty($r['host']) ? $r : null;
    }

    /** Anota que este nodo está apartado y NO se ha reincorporado solo (para el aviso permanente, el botón y los recordatorios). */
    public static function markRejoinPending(string $winnerName, string $host, string $reason): void
    {
        $prev = self::rejoinPending();
        $since = ($prev && ($prev['host'] ?? '') === $host) ? (int)($prev['since'] ?? time()) : time();
        Settings::set(self::REJOIN_KEY, json_encode(['name' => $winnerName, 'host' => $host, 'reason' => $reason, 'since' => $since,
            'last_try' => (int)($prev['last_try'] ?? 0), 'last_mail' => (int)($prev['last_mail'] ?? time())], JSON_UNESCAPED_UNICODE));
    }

    public static function clearRejoinPending(): void
    {
        Settings::set(self::REJOIN_KEY, '');
        Settings::set('cluster_rejoin_running_at', '0');
    }

    /** ¿Toca reintentar la reincorporación automática? (cada $minSeconds; el primer intento ya se hizo al apartarse). Pura. */
    public static function rejoinRetryDue(?array $pending, int $now, int $minSeconds = 300): bool
    {
        return $pending !== null && ($now - (int)($pending['last_try'] ?? 0)) >= $minSeconds;
    }

    /** ¿Toca recordar por correo que sigue sin reincorporarse? (la primera vez ya se avisó al apartarse). Pura. */
    public static function rejoinReminderDue(?array $pending, int $now, int $everySeconds = 21600): bool
    {
        return $pending !== null && ($now - (int)($pending['last_mail'] ?? $now)) >= $everySeconds;
    }

    /**
     * Asunto y cuerpo del correo cuando este nodo apartado no se reincorpora solo: qué pasa, por qué, qué hacer (botón del
     * Dashboard o terminal) y qué se pierde. Pura. @return array{0:string,1:string}
     */
    public static function rejoinEmail(string $winnerName, string $host, string $reason, bool $reminder = false): array
    {
        $diverged = (bool)preg_match('/escritura|diverg|GTID|copia completa|timeline/i', $reason);
        $subject = ($reminder ? 'Recordatorio: ' : '') . 'Failover: este servidor sigue apartado y hay que reincorporarlo como copia (decide tú)';
        $body = "Este servidor se ha apartado porque {$winnerName} manda ahora: no sirve webs y sus bases de datos de clientes están en solo lectura.\n\n"
            . "El panel NO lo ha reincorporado solo como copia de {$winnerName} ({$host}). Motivo: {$reason}\n\n"
            . "QUÉ HACER (cuando te venga bien; mientras tanto el sitio funciona con {$winnerName}):\n"
            . "  1. Entra al panel de ESTE servidor (por su IP o su dirección del panel): arriba de cualquier página hay un aviso rojo con el botón "
            . "«Reincorporar como copia de {$winnerName}». Púlsalo y espera unos minutos (copia lo que falta del nuevo master).\n"
            . "  2. O por terminal, como root: php /opt/musedock-panel/bin/cluster-switch.php demote {$host}\n\n"
            . ($diverged
                ? "OJO: este servidor tiene datos que el nuevo master no tiene (escrituras hechas aquí después del relevo, o un historial que no cuadra). "
                  . "Reincorporarlo los DESCARTA y los sustituye por una copia del nuevo master. Si hay algo que quieras conservar, cópialo antes.\n\n"
                : "No se han detectado escrituras propias: la copia solo trae lo que le falta.\n\n")
            . "El panel lo reintenta solo cada 5 minutos por si el motivo desaparece (red, servicio parado…) y te lo recuerda por correo cada 6 horas mientras siga así.";
        return [$subject, $body];
    }

    /**
     * Lanza en segundo plano la reincorporación de este nodo apartado como copia del nuevo master (lo que hace el botón del Dashboard).
     * Tarda minutos (copia de bases de datos): no se espera. @return array{ok:bool,message:string}
     */
    public static function startRejoinInBackground(): array
    {
        $p = self::rejoinPending();
        if ($p === null) {
            return ['ok' => false, 'message' => 'No hay nada pendiente: este servidor no está esperando reincorporarse.'];
        }
        if (Settings::get('cluster_role', '') === 'slave') {
            self::clearRejoinPending();
            return ['ok' => false, 'message' => 'Este servidor ya es copia.'];
        }
        $running = (int)Settings::get('cluster_rejoin_running_at', '0');
        if ($running > 0 && time() - $running < 3600) {
            return ['ok' => false, 'message' => 'Ya hay una reincorporación en curso desde hace ' . (int)round((time() - $running) / 60) . ' min.'];
        }
        $host = (string)$p['host'];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'message' => 'La dirección del nuevo master no es válida.'];
        }
        Settings::set('cluster_rejoin_running_at', (string)time());
        $root = defined('PANEL_ROOT') ? PANEL_ROOT : dirname(__DIR__, 2);
        $log = $root . '/storage/logs/rejoin.log';
        @shell_exec('echo "[$(date -u +%FT%TZ)] reincorporación pedida desde el panel" >> ' . escapeshellarg($log) . ' 2>&1');
        @shell_exec('nohup /usr/bin/php ' . escapeshellarg($root . '/bin/cluster-switch.php') . ' demote ' . escapeshellarg($host)
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
        try {
            LogService::log('cluster.rejoin', $host, 'Reincorporación como copia lanzada desde el panel');
        } catch (\Throwable) {
        }
        return ['ok' => true, 'message' => 'Reincorporación iniciada en segundo plano: tarda unos minutos. Se ve en storage/logs/rejoin.log.'];
    }

    /**
     * Antiguo master que vuelve y ve que otro es el principal: tras apartarse, se
     * convierte SOLO en espejo del nuevo master si es seguro (ni PostgreSQL ni MariaDB
     * tienen escrituras que el nuevo no tenga). Si no, se queda apartado y avisa.
     * Desactivable con cluster_auto_rejoin = 0. Volver a ser principal nunca es automático.
     */
    public static function autoRejoinAsSlave(string $newMasterIp): array
    {
        if (Settings::get('cluster_auto_rejoin', '1') === '0') {
            return ['done' => false, 'reason' => 'desactivado (cluster_auto_rejoin = 0)'];
        }
        if (!filter_var($newMasterIp, FILTER_VALIDATE_IP)) {
            return ['done' => false, 'reason' => 'IP del nuevo master desconocida'];
        }
        $checks = [];
        $pgUser = Settings::get('repl_pg_user', 'replicator');
        $pgPass = ReplicationService::decryptPassword(Settings::get('repl_pg_password', Settings::get('repl_pg_pass', '')));
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        foreach (PgClusterService::listClusters() as $c) {
            if ($c['cluster'] === 'panel' || (int)$c['port'] === $panelPort) {
                continue;
            }
            $d = self::pgDivergedFrom($c, $newMasterIp, (int)$c['port'], $pgUser, $pgPass);
            $checks["PostgreSQL {$c['key']}"] = $d['reason'];
            if (!empty($d['diverged'])) {
                return ['done' => false, 'reason' => "PostgreSQL {$c['key']}: {$d['reason']}", 'checks' => $checks];
            }
        }
        if (Settings::get('repl_mysql_role', 'standalone') !== 'standalone') {
            $g = self::mysqlCanFollowByGtid($newMasterIp, (int)Settings::get('repl_mysql_port', '3306'),
                Settings::get('repl_mysql_user', 'repl_user'), ReplicationService::decryptPassword(Settings::get('repl_mysql_pass', '')));
            $checks['MariaDB'] = $g['ok'] ? 'sin escrituras propias' : ($g['reason'] ?? '?');
            if (empty($g['ok'])) {
                return ['done' => false, 'reason' => 'MariaDB: ' . ($g['reason'] ?? '?'), 'checks' => $checks];
            }
        }
        $r = ClusterService::demoteToSlave($newMasterIp);
        return ['done' => !empty($r['ok']), 'checks' => $checks, 'demote' => $r];
    }

    /**
     * MariaDB/MySQL: ¿puede este antiguo master seguir replicando del nuevo SOLO con lo
     * que le falta (por GTID), sin copia completa? Sí cuando no tiene nada que el
     * nuevo no tenga: cada dominio de su gtid_binlog_pos está en el del nuevo master
     * con secuencia igual o mayor. Es el caso de un cambio de roles limpio (este nodo
     * se puso en solo lectura antes de promover al otro). Si escribió algo después,
     * no cuadra y hace falta la copia completa.
     */
    public static function mysqlCanFollowByGtid(string $masterIp, int $port, string $user, string $pass): array
    {
        try {
            $local = ReplicationService::getMysqlPdo();
            if (!$local) {
                return ['ok' => false, 'reason' => 'sin conexión a la MariaDB local'];
            }
            if (stripos((string)$local->query('SELECT VERSION()')->fetchColumn(), 'mariadb') === false) {
                return ['ok' => false, 'reason' => 'no es MariaDB (el seguimiento por GTID solo está programado para MariaDB)'];
            }
            $remote = new \PDO("mysql:host={$masterIp};port={$port}", $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
            $mine = (string)$local->query('SELECT @@GLOBAL.gtid_binlog_pos')->fetchColumn();
            // Del nuevo master, gtid_current_pos (lo escrito por él y lo que recibió como
            // réplica): justo tras un sembrado su binlog puede no tener aún lo replicado y
            // gtid_binlog_pos se quedaba corto → se creía que faltaba algo y se copiaba todo.
            $theirs = (string)$remote->query('SELECT @@GLOBAL.gtid_current_pos')->fetchColumn();
            // El nuevo master tiene que haber guardado en su binlog lo que replicaba de
            // éste (log_slave_updates); si no, no puede servirle "desde aquí".
            $lsu = (string)$remote->query('SELECT @@GLOBAL.log_slave_updates')->fetchColumn();
            if ($lsu !== '1' && strtoupper($lsu) !== 'ON') {
                return ['ok' => false, 'reason' => 'el nuevo master tiene log_slave_updates desactivado (su binlog no guarda lo que replicaba); se necesita la copia completa. Actívalo en los dos nodos para que la próxima vez sea solo lo nuevo',
                        'local' => $mine, 'master' => $theirs];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'no se pudo comparar el GTID: ' . $e->getMessage()];
        }
        $parse = static function (string $g): array {
            $o = [];
            foreach (array_filter(array_map('trim', explode(',', $g))) as $part) {
                if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $part, $m)) {
                    $o[(int)$m[1]] = (int)$m[3];
                }
            }
            return $o;
        };
        $m = $parse($mine);
        $t = $parse($theirs);
        foreach ($m as $domain => $seq) {
            if (!isset($t[$domain]) || $t[$domain] < $seq) {
                return ['ok' => false, 'reason' => "este nodo tiene escrituras que el nuevo master no tiene (dominio {$domain}: aquí {$seq}, allí " . ($t[$domain] ?? 'nada') . ')',
                        'local' => $mine, 'master' => $theirs];
            }
        }
        return ['ok' => true, 'local' => $mine, 'master' => $theirs];
    }

    /**
     * Promote MySQL/MariaDB AND persist it: the legacy path only ran STOP SLAVE +
     * SET GLOBAL read_only=0, leaving read_only=1 in my.cnf — so the next restart
     * silently turned the new master read-only again.
     */
    public static function mysqlReplicationConfigured(): bool
    {
        // Por lo que HAY, no solo por los ajustes: en Mortadelo repl_mysql_enabled=0 pero su MariaDB era una réplica
        // real, y al omitirla tras un cambio de mando se quedó en solo lectura (19-10: musescript.com y telehub.tv, 500).
        if ((string)Settings::get('repl_mysql_role', 'standalone') !== 'standalone') {
            return true;
        }
        try {
            $pdo = ReplicationService::getMysqlPdo();
            return $pdo !== null && !empty($pdo->query('SHOW SLAVE STATUS')->fetch(\PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return false;
        }
    }

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

        if (!self::mysqlReplicationConfigured()) {
            return ['ok' => true, 'skipped' => true, 'steps' => $steps, 'message' => 'MySQL/MariaDB no está en replicación: nada que promover'];
        }
        try {
            $pdo = ReplicationService::getMysqlPdo();
            if (!$pdo) {
                // Sin replicación de MySQL/MariaDB configurada no hay nada que promover:
                // no es un fallo (el cambio de rol de 2026-10-19 se deshizo por esto).
                if (!self::mysqlReplicationConfigured()) {
                    return ['ok' => true, 'skipped' => true, 'steps' => $steps, 'message' => 'MySQL/MariaDB no está en replicación: nada que promover'];
                }
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

        // Per cluster, prefer pg_rewind (incremental: absorbs only what diverged
        // while this node was down) when its prerequisite is met; fall back to a
        // full pg_basebackup otherwise. This is what lets the old master rejoin
        // WITHOUT recopying the whole cluster.
        $clusters = PgClusterService::listClusters();
        $methods  = [];
        foreach ($clusters as $c) {
            $wlh = ReplicationService::currentPgSetting($c, 'wal_log_hints');
            $chk = ReplicationService::currentPgSetting($c, 'data_checksums');
            $rewindOk = ($wlh === 'on' || $chk === 'on');
            if ($rewindOk) {
                $methods[$c['key']] = 'pg_rewind';
                $plan[] = "PostgreSQL {$c['key']} (:{$c['port']}): pg_rewind desde {$newMasterIp} → standby "
                        . '(incremental; solo rebobina lo divergente; copia pre-rewind conservada, rollback disponible)';
            } else {
                $methods[$c['key']] = 'pg_basebackup';
                $plan[] = "PostgreSQL {$c['key']} (:{$c['port']}): pg_basebackup desde {$newMasterIp} → standby "
                        . '(recopia total; directorio actual apartado, no borrado; rollback disponible)';
                $warnings[] = "El clúster {$c['key']} no tiene wal_log_hints/checksums: se usará pg_basebackup (recopia total). "
                            . 'Active wal_log_hints (G2) para habilitar el reingreso incremental por pg_rewind.';
            }
        }
        $plan[] = 'MySQL/MariaDB: CHANGE MASTER hacia ' . $newMasterIp . ' + read_only=1 (persistido)';
        $plan[] = 'Este nodo deja de empujar ficheros (lsyncd detenido) y pasa a recibirlos';
        $plan[] = 'Rol local → slave (cluster_role, repl_role, servers.role, .env)';

        $warnings[] = 'pg_basebackup reemplaza los datos locales por completo; pg_rewind los conserva y solo '
                    . 'aplica lo divergente. En ambos casos se guarda una copia antes de actuar.';
        $warnings[] = 'No existe una vuelta automatica: el retorno al master original es un switchover manual posterior.';

        return [
            'ok'        => empty($blocking),
            'plan'      => $plan,
            'warnings'  => $warnings,
            'blocking'  => $blocking,
            'clusters'  => array_map(fn($c) => $c['key'], $clusters),
            'methods'   => $methods,
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
