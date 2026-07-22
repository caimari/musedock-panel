<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Env;
use MuseDockPanel\Settings;
use MuseDockPanel\Database;

/**
 * CardDAV/CalDAV (Baïkal) service.
 *
 * Publishes the Caddy route for the DAV host and exposes install state to the
 * panel. Contacts/calendars are stored in the `baikal` PostgreSQL DB on the 5433
 * cluster and replicate to slaves through the same logical queue as mail_accounts
 * (see MailService replication + resyncCardDavToNode).
 *
 * The DAV app (Baïkal) lives in BAIKAL_HTML and every request routes through its
 * PHP entrypoints (dav.php / card.php / cal.php). We also publish the
 * `.well-known/carddav` and `.well-known/caldav` redirects so iOS/Android
 * autodiscovery finds the collection root from just the bare hostname.
 */
class CardDavService
{
    public const BAIKAL_DIR  = '/opt/musedock-carddav/baikal';
    public const BAIKAL_HTML = '/opt/musedock-carddav/baikal/html';

    public static function isInstalled(): bool
    {
        return Settings::get('carddav_installed') === '1' && is_dir(self::BAIKAL_HTML);
    }

    public static function host(): string
    {
        return strtolower(trim((string) Settings::get('carddav_host', 'dav.musedock.com')));
    }

    public static function status(): array
    {
        return [
            'installed'       => self::isInstalled(),
            'host'            => self::host(),
            'version'         => Settings::get('carddav_version', ''),
            'db'              => Settings::get('carddav_db_name', 'baikal'),
            'roundcube_plugin' => Settings::get('carddav_roundcube_plugin') === '1',
            'discovery_url'   => 'https://' . self::host() . '/',
            'dav_url'         => 'https://' . self::host() . '/dav.php/',
        ];
    }

    private static function routeIdForHost(string $host): string
    {
        return 'carddav-' . preg_replace('/[^a-z0-9]/', '', strtolower($host));
    }

    /**
     * Launch the Baïkal installer in the background (same async pattern as the
     * webmail installer). Returns immediately with a task id; the UI polls
     * installStatus() for progress.
     */
    public static function startInstall(string $host, string $imapHost = '', int $imapPort = 143): array
    {
        $host = strtolower(trim($host)) ?: 'dav.musedock.com';
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return ['ok' => false, 'error' => 'Host DAV inválido: ' . $host];
        }
        $taskId = 'carddav-' . time() . '-' . bin2hex(random_bytes(4));
        $payload = [
            'host'        => $host,
            'imap_host'   => $imapHost ?: '127.0.0.1',
            'imap_port'   => $imapPort ?: 143,
            'php_version' => Env::get('FPM_PHP_VERSION', '8.3'),
        ];
        Settings::set('carddav_install_task_id', $taskId);
        Settings::set('carddav_install_status', 'running');
        $encoded = base64_encode(json_encode($payload));
        $cmd = sprintf(
            'cd %s && nohup php bin/carddav-setup-run.php %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT),
            escapeshellarg($taskId),
            escapeshellarg($encoded)
        );
        shell_exec($cmd);
        return ['ok' => true, 'task_id' => $taskId];
    }

    /** Ordered install stages → human label, for the progress modal. */
    private const STAGE_LABELS = [
        'start'           => 'Iniciando…',
        'download'        => 'Descargando Baïkal…',
        'database'        => 'Creando base de datos (PostgreSQL)…',
        'auth-backend'    => 'Instalando autenticación por buzón…',
        'config'          => 'Escribiendo configuración…',
        'fpm-env'         => 'Configurando PHP-FPM…',
        'permissions'     => 'Ajustando permisos…',
        'roundcube-plugin'=> 'Integrando con el webmail…',
        'cron'            => 'Instalando la réplica (cron)…',
        'caddy-route'     => 'Publicando en Caddy + certificado…',
        'complete'        => 'Completado',
        'failed'          => 'Error',
    ];

    public static function installStatus(?string $taskId = null): array
    {
        $taskId = $taskId ?: Settings::get('carddav_install_task_id', '');
        if ($taskId === '') return ['status' => 'idle'];
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $file = PANEL_ROOT . '/storage/carddav-setup-' . $safe . '.json';
        if (!is_file($file)) {
            return ['status' => Settings::get('carddav_install_status', 'running') ?: 'running', 'task_id' => $safe, 'percent' => 5, 'label' => 'Iniciando…'];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) return ['status' => 'unknown', 'task_id' => $safe];
        // Add a percent + friendly label so the UI can drive a progress bar.
        $stages = array_keys(self::STAGE_LABELS);
        $stage  = (string)($data['stage'] ?? 'start');
        $status = (string)($data['status'] ?? 'running');
        $idx = array_search($stage, $stages, true);
        $idx = $idx === false ? 0 : $idx;
        $percent = $status === 'done' || $stage === 'complete' ? 100
            : (int) round(($idx / (count($stages) - 1)) * 100);
        $data['percent'] = $percent;
        $data['label']   = self::STAGE_LABELS[$stage] ?? $stage;
        return $data + ['task_id' => $safe];
    }

    /**
     * Publish (or refresh) the Caddy route that serves Baïkal at $host.
     * Idempotent: deletes any prior route with the same @id then re-inserts at
     * index 0 so the exact-host match wins over the wildcard fallback.
     *
     * TLS: the DAV host is a Cloudflare zone subdomain, so the panel's catch-all
     * DNS-01 policy (SystemService::ensureTlsCatchAllPolicy) issues its cert
     * on-demand — no dedicated TLS subject needed here.
     */
    public static function ensureCaddyRoute(string $host, string $phpVersion = '8.3'): array
    {
        $host = strtolower(trim($host));
        if ($host === '') return ['ok' => false, 'error' => 'Host DAV vacío'];
        $docRoot = self::BAIKAL_HTML;
        if (!is_dir($docRoot)) return ['ok' => false, 'error' => 'Baïkal no instalado en ' . $docRoot];

        $config = require PANEL_ROOT . '/config/panel.php';
        $caddyApi = rtrim($config['caddy']['api_url'], '/');
        if (!SystemService::ensureCaddyHttpServerReady($caddyApi)) {
            return ['ok' => false, 'error' => 'Caddy API no disponible'];
        }

        // Locate a PHP-FPM socket (a Unix socket, so use filetype()).
        $sockOk = static fn(string $p): bool => @file_exists($p) && @filetype($p) === 'socket';
        $candidates = ["/run/php/php{$phpVersion}-fpm-musedock.sock", "/run/php/php{$phpVersion}-fpm.sock", '/run/php/php-fpm.sock'];
        foreach (glob('/run/php/php*-fpm*.sock') ?: [] as $g) { $candidates[] = $g; }
        $socket = '';
        foreach ($candidates as $c) { if ($sockOk($c)) { $socket = $c; break; } }
        if ($socket === '') return ['ok' => false, 'error' => 'No se encontró socket PHP-FPM para publicar CardDAV'];

        $routeId = self::routeIdForHost($host);
        $fastcgi = [
            'handler' => 'reverse_proxy',
            'transport' => ['protocol' => 'fastcgi', 'root' => $docRoot, 'split_path' => ['.php']],
            'upstreams' => [['dial' => 'unix/' . $socket]],
        ];

        $route = [
            '@id' => $routeId,
            'match' => [['host' => [$host]]],
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [
                    ['handle' => [['handler' => 'vars', 'root' => $docRoot]]],
                    // Block Baïkal internals that must never be web-served.
                    [
                        'match' => [['path' => ['/Core/*', '/Specific/*', '/config/*', '/vendor/*', '/.git/*']]],
                        'handle' => [['handler' => 'static_response', 'status_code' => 403]],
                    ],
                    // Autodiscovery: iOS/Android hit /.well-known/carddav|caldav on
                    // the bare host; redirect to the DAV entrypoint so clients find
                    // the collection root with just "dav.<domain>" + credentials.
                    [
                        'match' => [['path' => ['/.well-known/carddav', '/.well-known/caldav']]],
                        'handle' => [['handler' => 'static_response', 'status_code' => 301, 'headers' => ['Location' => ['/dav.php/']]]],
                    ],
                    // Real static files inside html/ (robots.txt, res/…) → file_server.
                    [
                        'match' => [['file' => ['try_files' => ['{http.request.uri.path}']]]],
                        'handle' => [['handler' => 'file_server', 'root' => $docRoot]],
                    ],
                    // Everything else → dav.php (SabreDAV front controller). Baïkal
                    // sets base_uri to dav.php/, so route the full request into it.
                    [
                        'handle' => [
                            ['handler' => 'rewrite', 'uri' => '/dav.php{http.request.uri}'],
                            $fastcgi,
                        ],
                    ],
                ],
            ]],
            'terminal' => true,
        ];

        // Drop any prior route with this id, then insert at index 0.
        $del = curl_init("{$caddyApi}/id/{$routeId}");
        curl_setopt_array($del, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($del);
        curl_close($del);

        $ch = curl_init("{$caddyApi}/config/apps/http/servers/srv0/routes/0");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($route),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => "Caddy route failed HTTP {$code}: {$response}"];
        }
        Settings::set('carddav_route_published', '1');
        return ['ok' => true, 'route_id' => $routeId];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Failover replication (periodic push, master → slave)
    // ─────────────────────────────────────────────────────────────────────
    //
    //  Contacts/calendars are written by end-users DIRECTLY into Baïkal (via
    //  webmail or a phone), so the panel never sees those writes and can't
    //  replicate them per-operation the way it does mailboxes. Instead, the node
    //  that is CURRENTLY master periodically pushes a full snapshot of the DAV
    //  data tables to the other node, which UPSERTs them into its own local
    //  `baikal` DB. Direction follows cluster_role: whoever is master pushes.
    //  When the slave is promoted it becomes the pusher (reverse direction);
    //  when the old master returns as slave it receives again. This matches the
    //  cluster's "last promotion wins" model.
    //
    //  We replicate identity + data tables but NOT the ephemeral change-log /
    //  lock tables (addressbookchanges, calendarchanges, locks) — those are
    //  sync-token bookkeeping that each node rebuilds; copying them across nodes
    //  with different serial sequences would corrupt sync state. Clients do a
    //  full re-sync against the new node after failover, which is correct.

    /** Tables replicated, in FK-safe insert order. */
    private const SYNC_TABLES = [
        'principals',
        'users',
        'addressbooks',
        'cards',
        'calendars',
        'calendarinstances',
        'calendarobjects',
        'calendarsubscriptions',
        'schedulingobjects',
        'propertystorage',
    ];

    /**
     * Open a PDO connection to the LOCAL baikal DB (5433 cluster).
     * Returns null if CardDAV isn't installed / credentials missing.
     */
    private static function pdo(): ?\PDO
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '5433');
        $db   = Settings::get('carddav_db_name', 'baikal');
        $user = Settings::get('carddav_db_user', 'baikal');
        $pass = Settings::get('carddav_db_pass', '');
        if ($pass === '') return null;
        try {
            $pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            return $pdo;
        } catch (\Throwable $e) {
            error_log('CardDavService::pdo failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Export a full snapshot of the DAV data tables as a portable array.
     * Shape: ['tables' => ['cards' => [ [col=>val,...], ... ], ...]].
     */
    public static function exportSnapshot(): ?array
    {
        $pdo = self::pdo();
        if (!$pdo) return null;
        $out = ['tables' => [], 'complete' => true];
        foreach (self::SYNC_TABLES as $t) {
            try {
                $rows = $pdo->query("SELECT * FROM {$t}")->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // C1 fix: a PARTIAL snapshot is dangerous — the receiver would
                // TRUNCATE some tables and leave orphans. If ANY replicated table
                // can't be read, abort the whole export (return null) so the
                // worker sends nothing rather than a half-snapshot that wipes
                // the peer's data. Better to skip a cycle than corrupt the slave.
                error_log("CardDavService::exportSnapshot ABORT en {$t}: " . $e->getMessage());
                return null;
            }
            $out['tables'][$t] = $rows;
        }
        // Row total lets the receiver apply the "never replace data with empty"
        // guard (C1) without re-counting.
        $total = 0;
        foreach ($out['tables'] as $rows) { $total += count($rows); }
        $out['row_total'] = $total;
        // Stamp the source node's last promotion time so the receiver can reject
        // a stale snapshot (C2 / last-promotion-wins).
        $out['promoted_at'] = (string) Settings::get('cluster_promoted_at', '');
        return $out;
    }

    /**
     * Apply a snapshot into the LOCAL baikal DB (receiver side, on the slave).
     *
     * Strategy: authoritative replace. The pushing master is the source of
     * truth for this cycle, so we mirror it exactly: inside one transaction we
     * TRUNCATE the replicated tables and re-insert the snapshot rows verbatim
     * (preserving primary keys so FK links stay intact), then fix the sequences.
     *
     * Authoritative-replace is the correct model here (not merge): the master
     * that pushes is, by "last promotion wins", the single source of truth. A
     * two-way merge across nodes with independent SERIAL ids would double-insert
     * and mis-link contacts — the exact failure that sank the earlier naive
     * contacts-sync attempt. This one-way, whole-snapshot replace can't do that.
     */
    public static function applySnapshot(array $snapshot): array
    {
        // ── C2: a master must NEVER let a peer overwrite its data ──────────
        // The receiver, not just the pusher, enforces direction. If THIS node
        // is currently master, reject the snapshot — a master owns the truth.
        // This prevents split-brain (both think they're master) from silently
        // replacing the newer master's contacts with a stale peer's copy.
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            return ['ok' => false, 'error' => 'rechazado: este nodo es master (no acepta reemplazo de datos DAV)'];
        }

        $pdo = self::pdo();
        if (!$pdo) return ['ok' => false, 'error' => 'baikal DB no disponible en este nodo'];
        $tables = $snapshot['tables'] ?? null;
        if (!is_array($tables)) return ['ok' => false, 'error' => 'snapshot inválido'];

        // ── C1: refuse an incomplete snapshot ──────────────────────────────
        // exportSnapshot() only sets complete=true when it read EVERY table.
        // A missing flag or a missing identity table means a half-export that
        // would truncate our tables and leave orphans. Reject, keep our data.
        if (empty($snapshot['complete'])) {
            return ['ok' => false, 'error' => 'rechazado: snapshot incompleto (export parcial en el emisor)'];
        }
        foreach (['principals', 'addressbooks'] as $must) {
            if (!array_key_exists($must, $tables) || !is_array($tables[$must])) {
                return ['ok' => false, 'error' => "rechazado: snapshot sin tabla '{$must}'"];
            }
        }

        // ── C1: never replace existing data with an empty snapshot ─────────
        // If the incoming snapshot is empty but WE already hold contacts, this
        // is almost certainly a master that came up with an empty/half-started
        // Baïkal DB. Refuse rather than wipe. (An empty→empty apply is a no-op
        // and harmless, so we only block empty-over-nonempty.)
        $incomingTotal = (int) ($snapshot['row_total'] ?? -1);
        if ($incomingTotal < 0) {
            $incomingTotal = 0;
            foreach ($tables as $rows) { $incomingTotal += is_array($rows) ? count($rows) : 0; }
        }
        if ($incomingTotal === 0) {
            $localTotal = 0;
            foreach (['principals', 'addressbooks', 'cards'] as $t) {
                try { $localTotal += (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
                catch (\Throwable $e) { /* ignore */ }
            }
            if ($localTotal > 0) {
                return ['ok' => false, 'error' => 'rechazado: snapshot vacío sobre datos existentes (posible master a medio arrancar)'];
            }
        }

        // ── M5: whitelist column names against the real schema ─────────────
        // Column names are interpolated into the INSERT; a manipulated payload
        // could smuggle SQL through a bogus column name. Only allow columns
        // that actually exist in each target table.
        $allowedCols = static function (\PDO $pdo, string $t): array {
            $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name = ?");
            $st->execute([$t]);
            return array_flip($st->fetchAll(\PDO::FETCH_COLUMN));
        };

        $counts = [];
        try {
            $pdo->beginTransaction();

            // No FKs in Baïkal's schema (M3), so order is governed only by
            // SYNC_TABLES; TRUNCATE ... RESTART IDENTITY is enough. Truncate in
            // reverse order for tidiness.
            foreach (array_reverse(self::SYNC_TABLES) as $t) {
                if (!array_key_exists($t, $tables)) continue;
                try { $pdo->exec("TRUNCATE TABLE {$t} RESTART IDENTITY"); }
                catch (\Throwable $e) { /* table absent (e.g. cal disabled); ignore */ }
            }

            foreach (self::SYNC_TABLES as $t) {
                $rows = $tables[$t] ?? null;
                if (!is_array($rows)) continue;
                $allowed = $allowedCols($pdo, $t);
                if (!$allowed) continue; // table doesn't exist here; skip
                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row) || !$row) continue;
                    // M5: keep only columns that exist in this table's schema.
                    $cols = array_values(array_filter(array_keys($row), static fn($c) => isset($allowed[$c])));
                    if (!$cols) continue;
                    $ph  = array_map(static fn($c) => ':' . $c, $cols);
                    $sql = "INSERT INTO {$t} (" . implode(',', array_map(static fn($c) => '"' . $c . '"', $cols))
                         . ') VALUES (' . implode(',', $ph) . ')';
                    $stmt = $pdo->prepare($sql);
                    foreach ($cols as $c) { $stmt->bindValue(':' . $c, $row[$c]); }
                    $stmt->execute();
                    $n++;
                }
                $counts[$t] = $n;

                // M4: realign the SERIAL sequence correctly. Using is_called =
                // (MAX(id) IS NOT NULL) means: with rows, next id = MAX+1; with
                // an empty table, the sequence stays so the next id is 1 (not 2).
                try {
                    $pdo->exec("SELECT setval(pg_get_serial_sequence('{$t}','id'), "
                        . "GREATEST((SELECT COALESCE(MAX(id),1) FROM {$t}), 1), "
                        . "(SELECT COUNT(*) FROM {$t}) > 0)");
                } catch (\Throwable $e) { /* no id/sequence; ignore */ }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true, 'counts' => $counts];
    }

    /**
     * Push our snapshot to one cluster node (called on the master for each peer).
     */
    public static function syncToNode(int $nodeId): array
    {
        $snap = self::exportSnapshot();
        if ($snap === null) return ['ok' => false, 'error' => 'no se pudo exportar el snapshot local'];
        return ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
            'action'  => 'carddav_apply_snapshot',
            'payload' => $snap,
        ]);
    }

    /**
     * MASTER-side orchestration: install Baïkal on a slave node so it can RECEIVE
     * the snapshot pushes and serve DAV after a failover. Mirrors the mail model
     * (MailService::prepareMailReplicaOnNode) but simpler — there's no dsync/pg_hba
     * to set up, because DAV is a self-contained app with its OWN local `baikal`
     * DB that the sync worker fills. The slave just needs: Baïkal installed + an
     * empty `baikal` DB + the SAME db credentials as the master (so when the master
     * pushes a snapshot, the slave writes it with its local role).
     *
     * Idempotent: safe to re-run to (re)prepare or add a new slave.
     */
    public static function prepareReplicaOnNode(int $slaveNodeId): array
    {
        $node = ClusterService::getNode($slaveNodeId);
        if (!$node) return ['ok' => false, 'error' => "Nodo #{$slaveNodeId} no encontrado."];
        if (!self::isInstalled()) {
            return ['ok' => false, 'error' => 'CardDAV no está instalado en este master; instálalo aquí primero.'];
        }

        // Ship the master's DAV DB credentials so the slave's baikal role matches.
        // Sent over the authenticated TLS cluster channel; excluded from panel_log
        // via SECRET_KEYS (carddav_db_pass).
        $dbPass = (string) Settings::get('carddav_db_pass', '');
        if ($dbPass === '') return ['ok' => false, 'error' => 'Falta carddav_db_pass en el master.'];

        $res = ClusterService::callNode($slaveNodeId, 'POST', 'api/cluster/action', [
            'action'  => 'carddav_setup_replica',
            'payload' => [
                'host'          => self::host(),
                'db_pass'       => $dbPass,
                'db_name'       => Settings::get('carddav_db_name', 'baikal'),
                'db_user'       => Settings::get('carddav_db_user', 'baikal'),
                'enc_key'       => (string) Settings::get('carddav_enc_key', ''),
                'admin_hash_pw' => (string) Settings::get('carddav_admin_pass', ''),
            ],
        ]);
        $transportOk = !empty($res['ok']);
        $remote = $res['result'] ?? $res['data'] ?? [];
        $remoteOk = !empty($remote['ok']) || !empty($remote['task_id']);
        if (!$transportOk) {
            return ['ok' => false, 'error' => 'No se pudo contactar con el slave: ' . ($res['error'] ?? 'error de red/TLS')];
        }
        if (!$remoteOk) {
            return ['ok' => false, 'error' => 'El slave rechazó la instalación: ' . ($remote['error'] ?? 'error remoto')];
        }

        // Kick an immediate first snapshot so the slave isn't empty until the cron.
        // Best-effort: the slave install may still be running, in which case the
        // cron retries next minute.
        try { self::syncToNode($slaveNodeId); } catch (\Throwable $e) { /* cron will retry */ }

        return ['ok' => true, 'result' => $remote, 'message' => 'Réplica CardDAV preparada en el slave (instalación lanzada; el primer sync llegará en ~1 min).'];
    }

    /**
     * SLAVE-side handler for 'carddav_setup_replica'. Persist the master's DAV
     * credentials locally, then launch the SAME installer as the master. The
     * installer creates the `baikal` DB + app; the sync worker (no-op on slaves
     * for pushing) receives snapshots via applySnapshot. Runs async, returns a
     * task_id the master treats as success.
     */
    public static function nodeSetupReplica(array $payload): array
    {
        $host   = strtolower(trim((string)($payload['host'] ?? 'dav.musedock.com')));
        $dbPass = (string)($payload['db_pass'] ?? '');
        if ($dbPass === '') return ['ok' => false, 'error' => 'Sin db_pass del master.'];

        // Persist the credentials the installer will reuse (it reads these Settings
        // instead of generating new ones, so master and slave share the same role
        // password → the pushed snapshot applies cleanly).
        Settings::set('carddav_db_pass', $dbPass);
        Settings::set('carddav_host', $host);
        Settings::set('carddav_db_name', (string)($payload['db_name'] ?? 'baikal'));
        Settings::set('carddav_db_user', (string)($payload['db_user'] ?? 'baikal'));
        if (!empty($payload['enc_key']))       Settings::set('carddav_enc_key', (string)$payload['enc_key']);
        if (!empty($payload['admin_hash_pw']))  Settings::set('carddav_admin_pass', (string)$payload['admin_hash_pw']);

        // Launch the installer locally (async). Same script as the master install.
        $taskId = 'carddav-replica-' . time() . '-' . bin2hex(random_bytes(4));
        Settings::set('carddav_install_task_id', $taskId);
        Settings::set('carddav_install_status', 'running');
        $ip = trim((string) shell_exec("hostname -I 2>/dev/null"));
        $installPayload = [
            'host'        => $host,
            'imap_host'   => '127.0.0.1',
            'imap_port'   => 143,
            'php_version' => Env::get('FPM_PHP_VERSION', '8.3'),
        ];
        $encoded = base64_encode(json_encode($installPayload));
        $cmd = sprintf(
            'cd %s && nohup php bin/carddav-setup-run.php %s %s > /dev/null 2>&1 &',
            escapeshellarg(PANEL_ROOT),
            escapeshellarg($taskId),
            escapeshellarg($encoded)
        );
        shell_exec($cmd);
        return ['ok' => true, 'task_id' => $taskId];
    }

    /**
     * Peers that should receive our DAV snapshot: online cluster nodes running
     * mail (DAV rides alongside mail), excluding this local node.
     */
    public static function replicaNodes(): array
    {
        $nodes = Database::fetchAll(
            "SELECT * FROM cluster_nodes WHERE status = 'online' AND services::text LIKE '%mail%' ORDER BY name"
        );
        // Exclude self (mirror MailService::getMailReplicaNodes self-exclusion).
        $localIps = [];
        foreach (preg_split('/\s+/', trim((string) shell_exec('hostname -I 2>/dev/null'))) as $ip) {
            if ($ip !== '') $localIps[$ip] = true;
        }
        return array_values(array_filter($nodes, static function ($n) use ($localIps) {
            foreach (['ip', 'wg_ip', 'address'] as $k) {
                if (!empty($n[$k]) && isset($localIps[$n[$k]])) return false;
            }
            return true;
        }));
    }
}
