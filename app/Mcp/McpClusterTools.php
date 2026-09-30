<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\FailoverSafetyService;
use MuseDockPanel\Services\FailoverService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\PgClusterService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

/**
 * Herramientas MCP de CLUSTER y FAILOVER.
 *
 * - failover_preflight (lectura): ¿está este servidor listo para un relevo? Rol,
 *   nodos y testigos, configuración de failover, cuentas de Cloudflare (sin tokens),
 *   estado de las réplicas de PostgreSQL y Redis, y una lista de problemas en llano.
 * - failover_configure (escritura, en el master): define primario + servidor de
 *   relevo con sus IPs públicas y el modo (manual/semiauto/auto), y lo propaga.
 * - replication_adopt (escritura): registra en el panel una réplica de PostgreSQL
 *   que ya funciona (montada a mano), SIN tocar datos, para que promover/degradar
 *   sepan con qué usuario y contra quién trabajar.
 * - cluster_node_services (escritura, en el master): qué hace cada nodo (web, mail).
 *
 * Mismas reglas que el resto de escrituras MCP: interruptor mcp_allow_write, solo
 * en el panel local, y sin `apply: true` solo devuelven el plan.
 */
final class McpClusterTools
{
    public static function definitions(): array
    {
        $apply = ['type' => 'boolean', 'description' => 'false (por defecto) = solo devuelve el plan. true = lo ejecuta. Muestra primero el plan al usuario y pide su confirmación.'];
        $o = static fn(array $props, array $req = []) => array_filter([
            'type' => 'object', 'properties' => (object)$props, 'required' => $req ?: null, 'additionalProperties' => false,
        ], static fn($v) => $v !== null);

        return [
            'failover_preflight' => [
                'write' => false,
                'title' => 'Comprobar si el failover está listo',
                'description' => 'Revisa si ESTE servidor está preparado para un relevo (failover): rol en el cluster, nodos y testigos disponibles (anti split-brain), servidores de failover y modo, cuentas de Cloudflare con sus zonas (sin tokens), qué clusters de PostgreSQL y Redis se promoverían (simulación), scripts de relevo de /etc/musedock/hooks/{promote,demote}.d y si son válidos, salud de los servidores vigilados y una lista de problemas en lenguaje llano. Ejecútalo en el master y en el slave. Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'failover_configure' => [
                'write' => true, 'destructive' => true,
                'title' => 'Configurar el failover',
                'description' => 'En el MASTER: define este servidor como primario y un nodo del cluster como servidor de relevo, con sus IPs PÚBLICAS (las del DNS) y el modo: manual (solo avisa), semiauto (el slave promueve y cambia el DNS si el master cae; la vuelta es manual) o auto. Guarda y propaga la configuración a los slaves. Cada panel usa SUS cuentas de Cloudflare para el cambio de DNS. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'failover_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes) que tomará el relevo'],
                    'primary_ip' => ['type' => 'string', 'description' => 'IP pública de ESTE servidor (la de los registros A actuales)'],
                    'failover_ip' => ['type' => 'string', 'description' => 'IP pública del servidor de relevo'],
                    'mode' => ['type' => 'string', 'enum' => ['manual', 'semiauto', 'auto'], 'description' => 'Por defecto semiauto'],
                    'port' => ['type' => 'integer', 'description' => 'Puerto que se vigila (por defecto 443)'],
                    'replace' => ['type' => 'boolean', 'description' => 'Sustituir una configuración de servidores que ya exista'],
                    'apply' => $apply,
                ], ['failover_node', 'primary_ip', 'failover_ip']),
            ],
            'replication_adopt' => [
                'write' => true,
                'title' => 'Adoptar una réplica de PostgreSQL existente',
                'description' => 'Registra en el panel una réplica en streaming de PostgreSQL que YA funciona (montada a mano), sin tocar datos ni reiniciar nada. Detecta el rol (master si tiene réplicas conectadas, slave si está en recuperación), el usuario de réplica y el otro extremo. La contraseña del usuario de réplica se lee de ~postgres/.pgpass (slave) o de password_file (master), se guarda cifrada y nunca se devuelve. Necesario para que promover/degradar funcionen. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'password_file' => ['type' => 'string', 'description' => 'Opcional. Fichero con la contraseña del usuario de réplica (p. ej. /root/pg-replicador.pass). Solo rutas bajo /root/ o /var/lib/postgresql/.'],
                    'apply' => $apply,
                ]),
            ],
            'cluster_pairing_open' => [
                'write' => true,
                'title' => 'Abrir emparejamiento (en el master)',
                'description' => 'PASO 1 para unir dos paneles sin pasar secretos por el chat. En el futuro MASTER: abre durante 30 minutos la recepción de solicitudes de emparejamiento. Después, en el futuro slave, cluster_pair_request. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o(['apply' => $apply]),
            ],
            'cluster_pair_request' => [
                'write' => true,
                'title' => 'Pedir unirse a un master (en el slave)',
                'description' => 'PASO 2. En el futuro SLAVE: envía al master una solicitud de emparejamiento con el token de cluster de este panel (viaja por TLS de panel a panel, nunca por el chat) y devuelve un CÓDIGO corto. Ese código se confirma en el master con cluster_pair_approve. El master debe tener abierta la ventana (cluster_pairing_open) y su 8444 debe admitir la IP de este servidor. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'master_url' => ['type' => 'string', 'description' => 'URL pública del panel master, p. ej. https://master.dominio.com:8444 (certificado válido)'],
                    'name' => ['type' => 'string', 'description' => 'Nombre con el que aparecerá este nodo (por defecto, el hostname)'],
                    'self_url' => ['type' => 'string', 'description' => 'URL por la que el master llegará a ESTE panel (por defecto la de su hostname de panel)'],
                    'services' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['web', 'mail']], 'description' => 'Por defecto ["web"]'],
                    'apply' => $apply,
                ], ['master_url']),
            ],
            'cluster_pair_pending' => [
                'write' => false,
                'title' => 'Solicitudes de emparejamiento pendientes',
                'description' => 'En el MASTER: muestra si la ventana de emparejamiento está abierta y las solicitudes recibidas (código, nombre, URL, IP de origen, servicios). No muestra tokens. Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'cluster_pair_approve' => [
                'write' => true, 'destructive' => true,
                'title' => 'Aprobar emparejamiento (en el master)',
                'description' => 'PASO 3. En el MASTER: aprueba la solicitud con ese código. Comprueba que llega a la API del slave con su token, lo registra como nodo y le envía el token del master; el slave queda con rol slave y este panel con rol master. Comprueba antes con el usuario que el código coincide con el que vio en el slave. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'code' => ['type' => 'string', 'description' => 'Código XXXX-XXXX que devolvió cluster_pair_request en el slave'],
                    'master_url' => ['type' => 'string', 'description' => 'URL de ESTE panel con el MISMO host que usó el slave en su master_url (por defecto la del hostname del panel)'],
                    'master_public_ip' => ['type' => 'string', 'description' => 'IP pública de este servidor (la que vigilará el failover del slave)'],
                    'apply' => $apply,
                ], ['code']),
            ],
            'cluster_queue' => [
                'write' => false,
                'title' => 'Cola de sincronización del cluster',
                'description' => 'En el MASTER: estado de la cola de operaciones hacia los nodos (pendientes, en curso, completadas, fallidas, canceladas) y las últimas operaciones con su nodo, acción, dominio, intentos y error. Sirve para ver por qué algo no llega a un slave. No muestra el contenido de las operaciones (puede llevar hashes). Solo lectura.',
                'inputSchema' => $o([
                    'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'processing', 'completed', 'failed', 'cancelled'], 'description' => 'Filtrar por estado (por defecto all)'],
                    'limit' => ['type' => 'integer', 'description' => 'Máximo de operaciones a listar (por defecto 30, máx. 200)'],
                ]),
            ],
            'cluster_sync_hostings' => [
                'write' => true,
                'title' => 'Sincronizar hostings a un nodo',
                'description' => 'En el MASTER: lo mismo que el botón "Sincronizar Todo". Encola hacia el nodo el alta de cada hosting (el slave lo crea, o lo ADOPTA si ya tiene el usuario con el mismo UID y su carpeta), sus alias/redirecciones y sus bases de datos registradas. NUNCA borra nada en el nodo. Opcional: solo un dominio. Luego se sigue con cluster_queue. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes)'],
                    'domain' => ['type' => 'string', 'description' => 'Opcional: sincronizar solo este hosting'],
                    'apply' => $apply,
                ], ['target_node']),
            ],
            'filesync_status' => [
                'write' => false,
                'title' => 'Estado de la sincronización de ficheros',
                'description' => 'En el MASTER: si está activa la copia de ficheros de /var/www/vhosts a los nodos, en qué modo (lsyncd = casi al instante; periodic = cada N minutos), a qué nodos, qué se excluye, y el estado de lsyncd (en marcha, salud, problemas y últimas líneas de su log). Solo lectura.',
                'inputSchema' => $o([]),
            ],
            'filesync_configure' => [
                'write' => true, 'destructive' => true,
                'title' => 'Activar la sincronización de ficheros',
                'description' => 'En el MASTER: activa la copia de /var/www/vhosts a los nodos web del cluster (no standby). Pasos: clave SSH de root del master (la crea si falta), la instala en cada nodo por la API del cluster, comprueba el SSH, instala lsyncd si hace falta, guarda la configuración y lo arranca. lsyncd copia cada cambio a los ~15 s y es un ESPEJO: al arrancar hace una pasada completa y borra en el nodo lo que no exista en el master (salvo exclusiones). Para un slave clon exacto, mirror_git y mirror_node_modules copian también .git y node_modules. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'mode' => ['type' => 'string', 'enum' => ['lsyncd', 'periodic'], 'description' => 'lsyncd (por defecto, casi al instante) o periodic (rsync cada interval_minutes)'],
                    'interval_minutes' => ['type' => 'integer', 'description' => 'Solo periodic (por defecto 10)'],
                    'mirror_git' => ['type' => 'boolean', 'description' => 'Copiar también las carpetas .git (por defecto true)'],
                    'mirror_node_modules' => ['type' => 'boolean', 'description' => 'Copiar también node_modules (por defecto true)'],
                    'apply' => $apply,
                ]),
            ],
            'cluster_node_services' => [
                'write' => true,
                'title' => 'Servicios de un nodo',
                'description' => 'En el MASTER: indica qué hace un nodo del cluster: web (hostings), mail (correo) o ambos. Afecta a qué se le sincroniza y a la elección de relevo. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo (list_nodes)'],
                    'services' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['web', 'mail']]],
                    'apply' => $apply,
                ], ['target_node', 'services']),
            ],
        ];
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    public static function run(string $name, array $args): array
    {
        return match ($name) {
            'failover_preflight'    => self::preflight(),
            'failover_configure'    => self::configure($args),
            'replication_adopt'     => self::adopt($args),
            'cluster_node_services' => self::nodeServices($args),
            'cluster_pairing_open'  => self::pairingOpen($args),
            'cluster_pair_request'  => self::pairRequest($args),
            'cluster_pair_pending'  => self::pairPending(),
            'cluster_pair_approve'  => self::pairApprove($args),
            'cluster_queue'         => self::queue($args),
            'cluster_sync_hostings' => self::syncHostings($args),
            'filesync_status'       => self::filesyncStatus(),
            'filesync_configure'    => self::filesyncConfigure($args),
            default                 => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
        };
    }

    // ── Utilidades ───────────────────────────────────────────────────────

    private static function sh(string $cmd, int $timeout = 5): string
    {
        return trim((string)shell_exec('timeout ' . (int)$timeout . ' sh -c ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function psql(int $port, string $sql): string
    {
        return self::sh('runuser -u postgres -- psql -p ' . $port . ' -XAtc ' . escapeshellarg($sql));
    }

    private static function role(): string
    {
        return Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
    }

    private static function localIps(): array
    {
        return array_values(array_filter(preg_split('/\s+/', self::sh('hostname -I')) ?: []));
    }

    private static function findNode(string $ref): array
    {
        foreach (ClusterService::getNodes() as $n) {
            if ((string)$n['id'] === $ref || strcasecmp((string)$n['name'], $ref) === 0) {
                return $n;
            }
        }
        throw new \RuntimeException("Nodo '{$ref}' no encontrado. Usa list_nodes.");
    }

    /** Clusters de PostgreSQL de datos (excluye la BD del panel). */
    private static function dataClusters(): array
    {
        $panelPort = (int)\MuseDockPanel\Env::int('DB_PORT', 5432);
        $all = PgClusterService::listClusters();
        return array_values(array_filter($all, static fn($c) => $c['cluster'] !== 'panel'
            && !(count($all) > 1 && (int)$c['port'] === $panelPort)));
    }

    private static function redisRole(): array
    {
        $pass = '';
        foreach (@file('/etc/redis/redis.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*requirepass\s+(\S+)/', $l, $m)) {
                $pass = trim($m[1], '"\'');
            }
        }
        if (self::sh('command -v redis-cli') === '') {
            return ['installed' => false];
        }
        $info = self::sh(($pass !== '' ? 'REDISCLI_AUTH=' . escapeshellarg($pass) . ' ' : '') . 'redis-cli --no-auth-warning INFO replication');
        $get = static fn(string $k) => preg_match('/^' . $k . ':(.*)$/m', $info, $m) ? trim($m[1]) : null;
        return ['installed' => true, 'role' => $get('role'), 'master_host' => $get('master_host'),
            'master_link_status' => $get('master_link_status'), 'connected_slaves' => $get('connected_slaves')];
    }

    // ── failover_preflight ───────────────────────────────────────────────

    private static function preflight(): array
    {
        $role = self::role();
        $issues = [];
        $myIps = self::localIps();
        $masterIp = Settings::get('cluster_master_ip', '');

        $nodes = [];
        foreach (ClusterService::getNodes() as $n) {
            $nodes[] = ['id' => (int)$n['id'], 'name' => $n['name'], 'host' => parse_url((string)$n['api_url'], PHP_URL_HOST),
                'role' => $n['role'], 'status' => $n['status'], 'services' => json_decode((string)$n['services'], true)];
        }
        // Testigos = nodos que no son este servidor ni el master (los consulta el
        // worker antes de auto-promover para no hacerlo por una partición de red).
        $witnesses = array_values(array_filter($nodes, static fn($n) => !in_array($n['host'], $myIps, true) && $n['host'] !== $masterIp
            && $n['role'] !== 'master'));

        $servers = FailoverService::getServers();
        $cfg = FailoverService::getConfig();
        $mode = $cfg['failover_mode'];

        $accounts = [];
        foreach (CloudflareService::getConfiguredAccounts() as $a) {
            $accounts[] = ['name' => $a['name'] ?? '', 'zones' => array_map(static fn($z) => $z['name'] ?? '', $a['zones'] ?? [])];
        }

        $pg = [];
        foreach (self::dataClusters() as $c) {
            $rec = self::psql((int)$c['port'], 'SELECT pg_is_in_recovery()');
            $pg[$c['key']] = [
                'port' => (int)$c['port'],
                'in_recovery' => $rec === 't',
                'upstream' => $rec === 't' ? (self::psql((int)$c['port'], "SELECT status||' '||coalesce(sender_host,'') FROM pg_stat_wal_receiver") ?: null) : null,
                'replicas' => $rec === 't' ? [] : array_values(array_filter(explode("\n", self::psql((int)$c['port'],
                    "SELECT coalesce(client_addr::text,'local')||' '||usename||' '||state FROM pg_stat_replication")))),
            ];
        }
        $promotePlan = [];
        try {
            $promotePlan = ['postgresql' => FailoverSafetyService::promoteAllPgClusters(true),
                'redis' => FailoverSafetyService::promoteRedis(true)];
        } catch (\Throwable $e) {
            $promotePlan = ['error' => $e->getMessage()];
        }
        $redis = self::redisRole();

        $health = [];
        try {
            foreach (FailoverService::checkAllEndpoints() as $h) {
                $health[] = ['name' => $h['name'] ?? '', 'role' => $h['role'] ?? '', 'ok' => !empty($h['ok'])];
            }
        } catch (\Throwable $e) {
            $health = ['error' => $e->getMessage()];
        }

        $hooks = ['promote' => \MuseDockPanel\Services\RoleHookService::list('promote'),
            'demote' => \MuseDockPanel\Services\RoleHookService::list('demote')];

        // ── Problemas en llano ──
        foreach ($hooks as $ev => $list) {
            foreach ($list as $h) {
                if (!$h['valid'] && empty($h['ignored'])) {
                    $issues[] = "Script de relevo {$ev}.d/{$h['file']} NO se ejecutaría: {$h['reason']}.";
                }
            }
        }
        if ($role === 'standalone') {
            $issues[] = 'Este panel es standalone: no está unido a ningún cluster.';
        }
        if (!FailoverService::isConfigured()) {
            $issues[] = 'Failover sin configurar (falta un servidor primario y uno de relevo con IPs públicas): failover_configure en el master.';
        }
        if ($mode === 'manual') {
            $issues[] = 'Modo manual: si el master cae, solo se avisa; nadie promueve ni cambia el DNS.';
        }
        if ($role === 'slave') {
            if (!$masterIp) {
                $issues[] = 'Este slave no conoce la IP del master (cluster_master_ip): no puede detectar su caída.';
            }
            if (!$accounts) {
                $issues[] = 'Este slave no tiene cuentas de Cloudflare: en un relevo no podría cambiar el DNS.';
            }
            if (!$witnesses && $mode !== 'manual') {
                $issues[] = 'Sin nodo testigo: el slave promovería con su sola visión del master (riesgo de split-brain si falla la red). Añade un tercer nodo como testigo o usa semiauto.';
            }
            $myServer = array_values(array_filter($servers, static fn($s) => in_array($s['ip'] ?? '', $myIps, true)));
            if ($servers && !$myServer) {
                $issues[] = 'Ninguna IP de este servidor figura en failover_servers: nunca ganaría la elección de relevo.';
            }
            foreach ($pg as $k => $p) {
                if (!$p['in_recovery']) {
                    $issues[] = "PostgreSQL {$k} NO está en réplica en este slave.";
                }
            }
            if (!empty($redis['installed']) && ($redis['role'] ?? '') === 'master') {
                $issues[] = 'Redis de este slave no replica del master (role:master).';
            }
            if (Settings::get('repl_pg_role', 'standalone') === 'standalone' && array_filter($pg, static fn($p) => $p['in_recovery'])) {
                $issues[] = 'La réplica de PostgreSQL funciona pero el panel no la tiene registrada: replication_adopt.';
            }
        }
        if ($role === 'master') {
            if (!$nodes) {
                $issues[] = 'Master sin nodos registrados.';
            }
            foreach ($pg as $k => $p) {
                if (!$p['in_recovery'] && !$p['replicas']) {
                    $issues[] = "PostgreSQL {$k} no tiene ninguna réplica conectada.";
                }
            }
            if (Settings::get('repl_pg_role', 'standalone') === 'standalone' && array_filter($pg, static fn($p) => $p['replicas'])) {
                $issues[] = 'Hay réplicas de PostgreSQL conectadas pero el panel no lo tiene registrado: replication_adopt.';
            }
        }

        return [
            'ready' => !$issues,
            'issues' => $issues,
            'role' => $role,
            'local_ips' => $myIps,
            'cluster_master_ip' => $masterIp ?: null,
            'nodes' => $nodes,
            'witnesses' => array_map(static fn($w) => $w['name'], $witnesses),
            'failover' => [
                'mode' => $mode,
                'state' => FailoverService::getState(),
                'servers' => array_map(static fn($s) => [
                    'name' => $s['name'] ?? '', 'ip' => $s['ip'] ?? '', 'role' => $s['role'] ?? '',
                    'priority' => $s['failover_priority'] ?? null, 'enabled' => $s['enabled'] ?? true,
                ], $servers),
            ],
            'cloudflare_accounts' => $accounts,
            'postgresql' => $pg,
            'promote_simulation' => $promotePlan,
            'hooks' => $hooks + ['dir' => \MuseDockPanel\Services\RoleHookService::BASE],
            'redis' => $redis,
            'replication_registered' => [
                'repl_pg_role' => Settings::get('repl_pg_role', 'standalone'),
                'repl_pg_user' => Settings::get('repl_pg_user', '') ?: null,
                'password_stored' => Settings::get('repl_pg_password', '') !== '',
                'repl_remote_ip' => Settings::get('repl_remote_ip', '') ?: null,
            ],
            'health' => $health,
        ];
    }

    // ── failover_configure ───────────────────────────────────────────────

    private static function configure(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('failover_configure se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['failover_node'] ?? '')));
        $pIp = trim((string)($args['primary_ip'] ?? ''));
        $fIp = trim((string)($args['failover_ip'] ?? ''));
        $pub = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($pIp, FILTER_VALIDATE_IP, $pub) || !filter_var($fIp, FILTER_VALIDATE_IP, $pub) || $pIp === $fIp) {
            throw new \InvalidArgumentException('primary_ip y failover_ip deben ser IPv4 PÚBLICAS y distintas (son las de los registros DNS).');
        }
        if (!in_array($pIp, self::localIps(), true)) {
            throw new \InvalidArgumentException("primary_ip {$pIp} no es una IP de este servidor (" . implode(', ', self::localIps()) . ').');
        }
        $mode = in_array($args['mode'] ?? '', ['manual', 'semiauto', 'auto'], true) ? $args['mode'] : 'semiauto';
        $port = (int)($args['port'] ?? 443) ?: 443;
        $current = FailoverService::getServers();

        $failId = 'fo-' . (int)$node['id'];
        $new = [
            ['id' => 'primary-local', 'name' => (string)gethostname(), 'ip' => $pIp, 'role' => FailoverService::ROLE_PRIMARY,
                'port' => $port, 'failover_to' => $failId, 'dyndns' => false, 'failover_priority' => 99, 'enabled' => true],
            ['id' => $failId, 'name' => (string)$node['name'], 'ip' => $fIp, 'role' => FailoverService::ROLE_FAILOVER,
                'port' => $port, 'failover_to' => '', 'dyndns' => false, 'failover_priority' => 1, 'enabled' => true],
        ];

        $warnings = [];
        if ($mode === 'auto') {
            $warnings[] = 'Modo auto: el slave promueve Y la vuelta también es automática. Recomendado semiauto (vuelta manual).';
        }
        $others = array_filter(ClusterService::getNodes(), static fn($n) => (int)$n['id'] !== (int)$node['id']);
        if ($mode !== 'manual' && !$others) {
            $warnings[] = 'No hay un tercer nodo que haga de testigo: el slave decidiría solo con lo que él ve.';
        }
        $warnings[] = "El cambio de DNS lo hace {$node['name']} con SUS cuentas de Cloudflare: comprueba con failover_preflight en él que tiene la cuenta de las zonas afectadas.";

        $plan = [
            'mode' => ['from' => Settings::get('failover_mode', 'manual'), 'to' => $mode],
            'servers_current' => $current,
            'servers_new' => $new,
            'propagate_to' => array_values(array_map(static fn($n) => $n['name'], ClusterService::getActiveNodes())),
            'warnings' => $warnings,
        ];
        if ($current && !empty(array_diff(array_column($current, 'ip'), [$pIp, $fIp])) && empty($args['replace'])) {
            return ['applied' => false, 'plan' => $plan,
                'blocked' => 'Ya hay servidores de failover distintos configurados. Revisa el plan y repite con replace=true para sustituirlos.'];
        }
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }

        FailoverService::saveServers($new);
        FailoverService::saveConfig(['failover_mode' => $mode]);
        $push = FailoverService::pushConfigToSlaves();
        LogService::log('mcp.failover', 'configure', "Failover: primario {$pIp} → relevo {$node['name']} {$fIp}, modo {$mode}");
        return ['applied' => true, 'plan' => $plan, 'push' => $push];
    }

    // ── replication_adopt ────────────────────────────────────────────────

    private static function adopt(array $args): array
    {
        $clusters = self::dataClusters();
        if (!$clusters) {
            throw new \RuntimeException('No hay clusters de PostgreSQL de datos en este servidor.');
        }
        $detected = [];
        foreach ($clusters as $c) {
            $port = (int)$c['port'];
            if (self::psql($port, 'SELECT pg_is_in_recovery()') === 't') {
                $up = self::psql($port, "SELECT coalesce(sender_host,'')||'|'||coalesce(sender_port::text,'')||'|'||status FROM pg_stat_wal_receiver");
                [$host, $sport, $st] = array_pad(explode('|', $up), 3, '');
                $conninfo = self::psql($port, 'SHOW primary_conninfo');
                $user = preg_match('/\buser=(\S+)/', $conninfo, $m) ? trim($m[1], "'") : '';
                $detected[] = ['cluster' => $c['key'], 'port' => $port, 'role' => 'slave', 'peer' => $host,
                    'peer_port' => (int)$sport, 'user' => $user, 'status' => $st];
            } else {
                $rows = array_filter(explode("\n", self::psql($port, "SELECT coalesce(client_addr::text,'')||'|'||usename||'|'||state FROM pg_stat_replication")));
                foreach ($rows as $r) {
                    [$addr, $user, $st] = array_pad(explode('|', $r), 3, '');
                    $detected[] = ['cluster' => $c['key'], 'port' => $port, 'role' => 'master', 'peer' => $addr,
                        'peer_port' => null, 'user' => $user, 'status' => $st];
                }
            }
        }
        $active = array_values(array_filter($detected, static fn($d) => in_array($d['status'], ['streaming', 'catchup'], true)));
        if (!$active) {
            throw new \RuntimeException('No se detecta ninguna réplica de PostgreSQL en marcha (ni como master ni como slave). Nada que adoptar.');
        }
        $roles = array_unique(array_column($active, 'role'));
        if (count($roles) > 1) {
            throw new \RuntimeException('Este servidor es master de un cluster y slave de otro: la adopción automática no cubre ese caso.');
        }
        $role = $roles[0];
        $users = array_unique(array_column($active, 'user'));
        $peers = array_unique(array_column($active, 'peer'));
        $user = count($users) === 1 ? $users[0] : '';

        // Contraseña del usuario de réplica (nunca se devuelve).
        $pass = '';
        $passSource = null;
        $pf = trim((string)($args['password_file'] ?? ''));
        if ($pf !== '') {
            $real = realpath($pf) ?: '';
            if ($real === '' || !is_file($real) || !(str_starts_with($real, '/root/') || str_starts_with($real, '/var/lib/postgresql/'))) {
                throw new \InvalidArgumentException('password_file debe ser un fichero existente bajo /root/ o /var/lib/postgresql/.');
            }
            $pass = trim((string)strtok((string)file_get_contents($real), "\n"));
            $passSource = $real;
        } elseif ($role === 'slave') {
            foreach (@file('/var/lib/postgresql/.pgpass', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $f = explode(':', $l, 5);
                if (count($f) === 5 && in_array($f[0], [$peers[0] ?? '', '*'], true) && in_array($f[3], [$user, '*'], true)) {
                    $pass = $f[4];
                    $passSource = '/var/lib/postgresql/.pgpass';
                    break;
                }
            }
        }

        $settings = [
            'repl_role' => $role,
            'repl_pg_role' => $role,
            'repl_pg_user' => $user,
            'repl_configured_at' => date('Y-m-d H:i:s'),
        ];
        if ($role === 'slave' && count($peers) === 1) {
            $settings['repl_remote_ip'] = $peers[0];
            $settings['repl_pg_remote_ip'] = $peers[0];
        }
        $warnings = [];
        if ($pass === '') {
            $warnings[] = $role === 'master'
                ? 'Sin contraseña del usuario de réplica: indica password_file. La necesitará este servidor si algún día tiene que volver como slave (pg_rewind).'
                : 'No se encontró la contraseña en ~postgres/.pgpass: indica password_file.';
        }
        if ($user === '') {
            $warnings[] = 'Varios usuarios de réplica distintos: no se registra ninguno.';
        }

        $plan = ['detected' => $detected, 'settings_to_write' => $settings,
            // "password_status" y no "password": redact() taparía este texto informativo.
            'password_status' => $pass !== '' ? "se guardará cifrada (leída de {$passSource})" : 'no disponible',
            'touches_data' => false, 'warnings' => $warnings];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        foreach ($settings as $k => $v) {
            Settings::set($k, (string)$v);
        }
        if ($pass !== '') {
            Settings::set('repl_pg_password', ReplicationService::encryptPassword($pass));
        }
        try {
            \MuseDockPanel\Database::update('servers', ['role' => $role], 'is_local = true');
        } catch (\Throwable) {
        }
        LogService::log('mcp.replication', 'adopt', "Réplica PG adoptada: rol {$role}, usuario {$user}, otro extremo " . implode(',', $peers));
        return ['applied' => true, 'plan' => $plan];
    }

    // ── Emparejamiento ───────────────────────────────────────────────────

    private static function pairingOpen(array $args): array
    {
        $role = self::role();
        $nodes = ClusterService::getNodes();
        if ($role === 'slave') {
            throw new \RuntimeException('Este panel es slave: el emparejamiento se abre en el master.');
        }
        $plan = [
            'this_panel_becomes' => 'master (al aprobar la primera solicitud)',
            'window_minutes' => 30,
            'master_url_for_slave' => \MuseDockPanel\Services\ClusterPairingService::localPanelUrl() ?: '(panel_hostname sin configurar: usa la URL pública del 8444)',
            'current_nodes' => array_map(static fn($n) => $n['name'], $nodes),
            'note' => 'Mientras está abierta, /api/pair/request acepta solicitudes (limitadas por IP). Nadie se une sin cluster_pair_approve.',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::openWindow();
        return ['applied' => true, 'open_until' => $r['open_until'], 'master_url' => $r['master_url'],
            'next' => 'En el futuro slave: cluster_pair_request con master_url=' . ($r['master_url'] ?: '<URL pública del 8444 de este panel>') . '.'];
    }

    private static function pairRequest(array $args): array
    {
        $masterUrl = rtrim(trim((string)($args['master_url'] ?? '')), '/');
        if (!filter_var($masterUrl, FILTER_VALIDATE_URL) || !str_starts_with($masterUrl, 'https://')) {
            throw new \InvalidArgumentException('master_url debe ser una URL https:// del panel master.');
        }
        if (self::role() === 'master' && ClusterService::getNodes()) {
            throw new \RuntimeException('Este panel ya es master con nodos: no puede unirse como slave.');
        }
        $name = trim((string)($args['name'] ?? '')) ?: (string)gethostname();
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('name: solo letras, números, punto, guion y guion bajo (máx. 64).');
        }
        $selfUrl = rtrim(trim((string)($args['self_url'] ?? '')), '/') ?: \MuseDockPanel\Services\ClusterPairingService::localPanelUrl();
        if (!filter_var($selfUrl, FILTER_VALIDATE_URL) || !str_starts_with($selfUrl, 'https://')) {
            throw new \InvalidArgumentException('No se conoce la URL de este panel (panel_hostname): indica self_url.');
        }
        $services = array_values(array_unique(array_intersect((array)($args['services'] ?? ['web']), ['web', 'mail']))) ?: ['web'];
        $plan = [
            'send_to' => $masterUrl . '/api/pair/request',
            'name' => $name,
            'self_url' => $selfUrl,
            'services' => $services,
            'sends' => 'nombre, URL, servicios y el token de cluster de este panel (por TLS, panel a panel; no se muestra aquí)',
            'after' => 'el master aprueba el código con cluster_pair_approve; entonces este panel pasa a rol slave',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::requestPairing($masterUrl, $name, $selfUrl, $services);
        return ['applied' => true, 'code' => $r['code'], 'expires' => $r['expires'],
            'next' => "Enseña el código {$r['code']} al usuario y apruébalo en el master con cluster_pair_approve (comprobando que coincide)."];
    }

    private static function pairPending(): array
    {
        $until = \MuseDockPanel\Services\ClusterPairingService::windowOpenUntil();
        return [
            'window_open' => $until > time(),
            'open_until' => $until > time() ? date('Y-m-d H:i:s', $until) : null,
            'pending' => \MuseDockPanel\Services\ClusterPairingService::listPending(),
        ];
    }

    private static function pairApprove(array $args): array
    {
        if (self::role() === 'slave') {
            throw new \RuntimeException('Este panel es slave: las solicitudes se aprueban en el master.');
        }
        $code = strtoupper(trim((string)($args['code'] ?? '')));
        $p = \MuseDockPanel\Services\ClusterPairingService::findPending($code);
        if (!$p) {
            throw new \RuntimeException('No hay ninguna solicitud pendiente con ese código (o ha caducado). Mira cluster_pair_pending.');
        }
        $masterUrl = rtrim(trim((string)($args['master_url'] ?? '')), '/') ?: \MuseDockPanel\Services\ClusterPairingService::localPanelUrl();
        if (!filter_var($masterUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Indica master_url (la URL de este panel con el mismo host que usó el slave).');
        }
        $pubIp = trim((string)($args['master_public_ip'] ?? ''));
        if ($pubIp !== '' && !filter_var($pubIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException('master_public_ip debe ser una IP pública.');
        }
        $plan = [
            'request' => ['code' => $p['code'], 'name' => $p['name'], 'api_url' => $p['api_url'], 'from_ip' => $p['from_ip'], 'services' => $p['services']],
            'steps' => [
                "probar la API de {$p['api_url']} con el token que envió",
                "registrar {$p['name']} como nodo del cluster (servicios: " . implode(',', $p['services']) . ')',
                "enviarle el token de este panel; el slave comprueba el nonce y se registra como slave de {$masterUrl}",
                'este panel pasa a rol master (si no lo era)',
            ],
            'master_public_ip' => $pubIp ?: '(sin indicar: el slave no sabrá qué IP vigilar para el failover hasta configurarlo)',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan,
                'next' => 'Confirma con el usuario que el código coincide con el que vio en el slave y repite con apply=true.'];
        }
        $r = \MuseDockPanel\Services\ClusterPairingService::approve($code, $masterUrl, $pubIp);
        return ['applied' => true, 'node_id' => $r['node_id'], 'steps' => $r['steps']];
    }

    // ── Cola y sincronización ────────────────────────────────────────────

    private static function queue(array $args): array
    {
        $status = (string)($args['status'] ?? 'all');
        $limit = max(1, min(200, (int)($args['limit'] ?? 30)));
        $where = in_array($status, ['pending', 'processing', 'completed', 'failed', 'cancelled'], true) ? 'WHERE q.status = :st' : '';
        $params = $where ? ['st' => $status, 'lim' => $limit] : ['lim' => $limit];
        $rows = \MuseDockPanel\Database::fetchAll(
            "SELECT q.id, n.name AS node, q.action, q.payload->>'hosting_action' AS hosting_action,
                    coalesce(q.payload->'hosting_data'->>'domain', q.payload->'hosting_data'->>'main_domain') AS domain,
                    q.status, q.attempts, q.max_attempts, q.error_message, q.created_at, q.completed_at
             FROM cluster_queue q LEFT JOIN cluster_nodes n ON n.id = q.node_id
             {$where} ORDER BY q.created_at DESC, q.id DESC LIMIT :lim", $params);
        $counts = [];
        foreach (\MuseDockPanel\Database::fetchAll("SELECT status, COUNT(*) AS c FROM cluster_queue GROUP BY status") as $r) {
            $counts[$r['status']] = (int)$r['c'];
        }
        return ['counts' => $counts, 'items' => $rows];
    }

    private static function syncHostings(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('cluster_sync_hostings se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['target_node'] ?? '')));
        $domain = trim((string)($args['domain'] ?? '')) ?: null;
        $accounts = \MuseDockPanel\Database::fetchAll("SELECT domain, username, system_uid FROM hosting_accounts WHERE status != 'deleted' ORDER BY id");
        if ($domain !== null) {
            $accounts = array_values(array_filter($accounts, static fn($a) => strcasecmp((string)$a['domain'], $domain) === 0));
            if (!$accounts) {
                throw new \InvalidArgumentException("No hay ningún hosting {$domain} en este master.");
            }
        }
        $plan = [
            'node' => $node['name'],
            'hostings' => array_map(static fn($a) => "{$a['domain']} (usuario {$a['username']}, uid {$a['system_uid']})", $accounts),
            'on_the_node' => 'crea cada hosting, o lo ADOPTA (solo registro en el panel) si ya existe el usuario con el mismo UID y su carpeta',
            'also' => 'alias/redirecciones y bases de datos registradas de cada hosting' . ($domain === null ? '; redirecciones sin hosting; las operaciones fallidas antiguas de este nodo se marcan canceladas' : ''),
            'deletes' => 'nada',
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        $r = ClusterService::enqueueFullHostingSync((int)$node['id'], $domain);
        LogService::log('mcp.cluster', 'sync-hostings', "Encolados {$r['hostings']} hostings + {$r['redirects']} redirects al nodo {$node['name']}");
        return ['applied' => true, 'enqueued' => $r, 'next' => 'El cluster-worker los procesa cada minuto: sigue el resultado con cluster_queue.'];
    }

    // ── Sincronización de ficheros ───────────────────────────────────────

    /** Nodos a los que lsyncd copia (mismo criterio que FileSyncService::generateLsyncdConfig). */
    private static function filesyncTargets(): array
    {
        $out = [];
        foreach (ClusterService::getWebNodes() as $n) {
            if (empty($n['standby'])) {
                $out[] = ['id' => (int)$n['id'], 'name' => $n['name'],
                    'host' => \MuseDockPanel\Services\FileSyncService::extractHostFromUrl((string)$n['api_url'])];
            }
        }
        return $out;
    }

    private static function filesyncStatus(): array
    {
        $cfg = \MuseDockPanel\Services\FileSyncService::getConfig();
        $st = \MuseDockPanel\Services\FileSyncService::getLsyncdStatus();
        $log = array_slice(explode("\n", (string)($st['log_tail'] ?? $st['logTail'] ?? '')), -10);
        return [
            'enabled' => $cfg['enabled'],
            'mode' => $cfg['sync_mode'],
            'method' => $cfg['method'],
            'interval_minutes' => $cfg['interval_minutes'],
            'ssh_key_path' => $cfg['ssh_key_path'],
            'targets' => self::filesyncTargets(),
            'excludes' => array_values(array_unique(array_merge(
                \MuseDockPanel\Services\FileSyncService::getLsyncdDefaultExcludes(),
                \MuseDockPanel\Services\FileSyncService::parseExcludePatterns((string)$cfg['exclude_patterns'])))),
            'lsyncd' => [
                'installed' => $st['installed'] ?? false,
                'running' => $st['running'] ?? false,
                'enabled' => $st['enabled'] ?? false,
                'health' => $st['health'] ?? null,
                'issues' => $st['issues'] ?? [],
                'log_tail' => array_values(array_filter($log)),
            ],
        ];
    }

    private static function filesyncConfigure(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('filesync_configure se ejecuta en el MASTER del cluster.');
        }
        $targets = self::filesyncTargets();
        if (!$targets) {
            throw new \RuntimeException('No hay nodos web activos (no standby) a los que copiar ficheros.');
        }
        $mode = ($args['mode'] ?? 'lsyncd') === 'periodic' ? 'periodic' : 'lsyncd';
        $interval = max(1, (int)($args['interval_minutes'] ?? 10));
        $mirrorGit = !array_key_exists('mirror_git', $args) || !empty($args['mirror_git']);
        $mirrorNm = !array_key_exists('mirror_node_modules', $args) || !empty($args['mirror_node_modules']);
        $cfg = \MuseDockPanel\Services\FileSyncService::getConfig();
        $keyPath = (string)$cfg['ssh_key_path'];

        $drop = array_filter([$mirrorGit ? '.git' : null, $mirrorNm ? 'node_modules' : null]);
        $lsyncdEx = array_values(array_diff(\MuseDockPanel\Services\FileSyncService::getLsyncdDefaultExcludes(), $drop));
        $rsyncEx = array_values(array_diff(\MuseDockPanel\Services\FileSyncService::getRsyncDefaultExcludes(), $drop));
        $userEx = \MuseDockPanel\Services\FileSyncService::parseExcludePatterns((string)$cfg['exclude_patterns']);
        $userEx = array_values(array_diff($userEx, $drop));

        $plan = [
            'mode' => $mode === 'lsyncd' ? 'lsyncd: cada cambio se copia a los ~15 s' : "periodic: rsync cada {$interval} min",
            'source' => '/var/www/vhosts/ (todos los hostings de este master)',
            'targets' => array_map(static fn($t) => "{$t['name']} (root@{$t['host']}:/var/www/vhosts/)", $targets),
            'steps' => [
                "clave SSH de root de este servidor en {$keyPath} (se crea si no existe)",
                'se instala su clave pública en /root/.ssh/authorized_keys de cada nodo (por la API del cluster): este master tendrá SSH de root en ellos, como en cualquier cluster del panel',
                'se comprueba el SSH a cada nodo; si alguno falla, se para aquí',
                $mode === 'lsyncd' ? 'se instala lsyncd si falta, se genera /etc/lsyncd/lsyncd.conf.lua y se arranca' : 'el filesync-worker hará la copia periódica',
            ],
            'mirror' => 'ESPEJO: la primera pasada copia todo y BORRA en el nodo lo que no exista aquí (salvo exclusiones). Después, cada cambio o borrado se replica.',
            'excludes' => array_values(array_unique(array_merge($mode === 'lsyncd' ? $lsyncdEx : $rsyncEx, $userEx))),
            'mirror_git' => $mirrorGit,
            'mirror_node_modules' => $mirrorNm,
        ];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }

        $steps = [];
        $key = \MuseDockPanel\Services\FileSyncService::generateSshKey($keyPath);
        if (empty($key['ok']) || empty($key['public_key'])) {
            throw new \RuntimeException('No se pudo preparar la clave SSH: ' . ($key['error'] ?? '?'));
        }
        $steps[] = !empty($key['exists']) ? "clave SSH existente ({$keyPath})" : "clave SSH creada ({$keyPath})";

        foreach ($targets as $t) {
            $r = ClusterService::callNode($t['id'], 'POST', 'api/cluster/action',
                ['action' => 'install-ssh-key', 'payload' => ['public_key' => $key['public_key']]]);
            if (empty($r['ok']) || empty($r['data']['ok'])) {
                throw new \RuntimeException("No se pudo instalar la clave en {$t['name']}: " . ($r['data']['error'] ?? $r['error'] ?? '?') . '. No se ha cambiado la configuración.');
            }
            $ssh = \MuseDockPanel\Services\FileSyncService::testSshConnection($t['host'], (int)$cfg['ssh_port'], $keyPath, 'root');
            if (empty($ssh['ok'])) {
                throw new \RuntimeException("La clave está instalada en {$t['name']}, pero el SSH a {$t['host']} falla: " . ($ssh['error'] ?? '?')
                    . '. Revisa que el puerto 22 del nodo admita este servidor (por la VPN). No se ha cambiado la configuración.');
            }
            $steps[] = "{$t['name']}: clave instalada y SSH comprobado";
        }

        if ($mode === 'lsyncd' && !\MuseDockPanel\Services\FileSyncService::isLsyncdInstalled()) {
            $inst = \MuseDockPanel\Services\FileSyncService::installLsyncd();
            if (empty($inst['ok'])) {
                throw new \RuntimeException('No se pudo instalar lsyncd.');
            }
            $steps[] = 'lsyncd instalado';
        }

        \MuseDockPanel\Services\FileSyncService::saveConfig([
            'filesync_enabled' => '1',
            'filesync_sync_mode' => $mode,
            'filesync_method' => 'ssh',
            'filesync_ssh_user' => 'root',
            'filesync_ssh_key_path' => $keyPath,
            'filesync_interval' => (string)$interval,
            'filesync_lsyncd_default_excludes' => implode("\n", $lsyncdEx),
            'filesync_rsync_default_excludes' => implode("\n", $rsyncEx),
            'filesync_exclude' => implode(',', $userEx),
        ]);
        $steps[] = 'configuración guardada';

        if ($mode === 'lsyncd') {
            $start = \MuseDockPanel\Services\FileSyncService::startLsyncd();
            $steps[] = !empty($start['ok']) ? 'lsyncd arrancado (hace ahora la primera pasada completa)' : 'lsyncd NO arrancó: ' . trim((string)($start['output'] ?? $start['error'] ?? ''));
        }
        LogService::log('mcp.filesync', 'configure', "Sincronización de ficheros {$mode} hacia " . implode(', ', array_column($targets, 'name')));
        return ['applied' => true, 'steps' => $steps, 'next' => 'Sigue la primera pasada con filesync_status.'];
    }

    // ── cluster_node_services ────────────────────────────────────────────

    private static function nodeServices(array $args): array
    {
        if (self::role() !== 'master') {
            throw new \RuntimeException('cluster_node_services se ejecuta en el MASTER del cluster.');
        }
        $node = self::findNode(trim((string)($args['target_node'] ?? '')));
        $services = array_values(array_unique(array_intersect((array)($args['services'] ?? []), ['web', 'mail'])));
        if (!$services) {
            throw new \InvalidArgumentException('services debe incluir web, mail o ambos.');
        }
        $plan = ['node' => $node['name'], 'from' => json_decode((string)$node['services'], true), 'to' => $services];
        if (empty($args['apply'])) {
            return ['applied' => false, 'plan' => $plan, 'next' => 'Muestra el plan al usuario y, si lo confirma, repite con apply=true.'];
        }
        ClusterService::updateNode((int)$node['id'], ['services' => json_encode($services)]);
        LogService::log('mcp.cluster', 'node-services', "Nodo {$node['name']}: servicios " . implode(',', $services));
        return ['applied' => true, 'plan' => $plan];
    }
}
