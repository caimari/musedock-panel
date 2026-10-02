<?php
namespace MuseDockPanel\Mcp;

use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\CloudflareService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\MailService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Settings;

/**
 * Herramientas MCP de CORREO (fase 1b): crear dominio, publicar su DNS en
 * Cloudflare, crear buzones y alias, y verificar.
 *
 * Reglas de seguridad (las aplica también McpTools::call):
 *  - Las de escritura exigen el interruptor "Permitir acciones que modifican"
 *    (Settings mcp_allow_write) y solo corren en el panel LOCAL, nunca en un slave
 *    ni reenviadas a otro nodo.
 *  - Sin `apply: true` solo devuelven el PLAN (qué harían). Además se anuncian como
 *    no-solo-lectura, así que el cliente MCP pide permiso al usuario en cada una.
 *  - Reutilizan el código del panel (MailService::createDomain/createAccount/
 *    createAlias), así que el DKIM, la carpeta del buzón y la réplica a los nodos de
 *    correo ocurren exactamente igual que desde la interfaz web.
 *  - Contraseñas: si no se pasa una, se genera y se guarda CIFRADA en "Credenciales
 *    pendientes" (Ajustes → MCP) para verla allí una vez. Nunca vuelve al chat.
 */
final class McpMailTools
{
    private const DOMAIN_RE = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/';
    private const LOCAL_RE  = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/';

    public static function definitions(): array
    {
        $apply = ['type' => 'boolean', 'description' => 'false (por defecto) = solo devuelve el plan. true = lo ejecuta. Muestra primero el plan al usuario y pide su confirmación.'];
        $o = static fn(array $props, array $req) => ['type' => 'object', 'properties' => (object)$props, 'required' => $req, 'additionalProperties' => false];

        return [
            'mail_domain_create' => [
                'write' => true,
                'title' => 'Crear dominio de correo',
                'description' => 'Da de alta un dominio de correo en este panel (con su clave DKIM), igual que desde Mail → Dominios. Siguiente paso: mail_dns_publish. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o(['domain' => ['type' => 'string', 'description' => 'p. ej. midominio.com'], 'apply' => $apply], ['domain']),
            ],
            'mail_dns_publish' => [
                'write' => true, 'destructive' => true,
                'title' => 'Publicar DNS de correo en Cloudflare',
                'description' => 'Publica en Cloudflare los registros que necesita un dominio de correo de este panel: MX, SPF, DKIM y DMARC. Detecta conflictos (MX de otro proveedor, Enrutamiento de correo de Cloudflare, otro SPF/DKIM/DMARC): por defecto NO los toca y no añade el MX propio si hay otro MX, para no repartir el correo entre dos servidores. Con replace_conflicts=true sustituye los conflictivos. El dominio debe estar en una cuenta de Cloudflare del panel. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string'],
                    'replace_conflicts' => ['type' => 'boolean', 'description' => 'Sustituir/borrar registros que chocan (revisa antes el plan)'],
                    'spf_add' => ['type' => 'string', 'description' => 'Opcional: términos a AÑADIR al SPF existente, separados por espacios (p. ej. "include:spf.proveedor-de-envio.com"). Edita el único registro SPF, nunca crea otro.'],
                    'apply' => $apply,
                ], ['domain']),
            ],
            'mail_mailbox_create' => [
                'write' => true,
                'title' => 'Crear buzón',
                'description' => 'Crea un buzón en un dominio de correo existente. Si no se indica contraseña, se genera una segura que el usuario verá UNA vez en Ajustes → MCP → Credenciales pendientes (nunca se devuelve en el chat). No pidas ni escribas contraseñas en la conversación. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'email' => ['type' => 'string', 'description' => 'usuario@dominio'],
                    'display_name' => ['type' => 'string'],
                    'quota_mb' => ['type' => 'integer', 'description' => '0 = sin límite. Por defecto, la cuota por defecto del panel.'],
                    'password' => ['type' => 'string', 'description' => 'Opcional y desaconsejado (quedaría en el historial del chat). Mejor omitirla.'],
                    'apply' => $apply,
                ], ['email']),
            ],
            'mail_alias_create' => [
                'write' => true,
                'title' => 'Crear alias o catch-all',
                'description' => 'Crea un alias (reenvío) en un dominio de correo existente. source="alias@dominio" para un alias, o "@dominio" para el catch-all (todo lo que no exista va a destination; si ya había un catch-all, se sustituye). destination puede ser un buzón local u otra dirección. Requiere "Permitir acciones que modifican".',
                'inputSchema' => $o([
                    'source' => ['type' => 'string', 'description' => 'alias@dominio o @dominio (catch-all)'],
                    'destination' => ['type' => 'string', 'description' => 'buzón o dirección de destino'],
                    'apply' => $apply,
                ], ['source', 'destination']),
            ],
            'mail_domain_alias' => [
                'write' => true,
                'title' => 'Dominio alias (sinónimo) de otro dominio de correo',
                'description' => 'Hace que un dominio sea sinónimo de otro: todo lo que llegue a X@alias se entrega en X@destino (mismos buzones y alias; se conserva el nombre de usuario). Da de alta el dominio alias en el correo del panel si no existe (con su DKIM, para cuando se publique su DNS) y crea su catch-all "@alias" → "@destino" (Postfix reescribe @otrodominio conservando el usuario). Se niega si el dominio alias ya tiene buzones propios (el catch-all les quitaría el correo). No toca el DNS: después, mail_dns_publish del dominio alias. Primero sin apply.',
                'inputSchema' => $o([
                    'alias_domain' => ['type' => 'string', 'description' => 'p. ej. ejemplo.net'],
                    'target_domain' => ['type' => 'string', 'description' => 'Dominio de correo existente en el panel, p. ej. ejemplo.com'],
                    'apply' => $apply,
                ], ['alias_domain', 'target_domain']),
            ],
            'mail_dkim_selector' => [
                'write' => true,
                'title' => 'Cambiar el selector DKIM de un dominio de correo',
                'description' => 'Para cuando otro remitente (un proveedor de envío, la web…) ya publica su clave en default._domainkey del dominio y mail_dns_publish da DKIM "conflicto": el panel pasa a firmar con otro selector (por defecto "musedock") con la MISMA clave, y las dos conviven sin tocar la del otro. Reinstala la clave en OpenDKIM aquí y en las réplicas. No toca el DNS: después, mail_dns_publish (crea el registro del nuevo selector). Primero sin apply.',
                'inputSchema' => $o([
                    'domain' => ['type' => 'string'],
                    'selector' => ['type' => 'string', 'description' => 'Nuevo selector (por defecto musedock)'],
                    'apply' => $apply,
                ], ['domain']),
            ],
            'mail_replication_status' => [
                'write' => false,
                'title' => 'Copia en vivo de los buzones (dsync) con el otro nodo de correo',
                'description' => 'Solo lectura. Dice si este nodo replica el CONTENIDO de los buzones (Dovecot dsync) con su pareja, y por buzón: si el replicador lo conoce, última sincronización correcta y si falló. Avisa de los buzones del panel que el replicador aún no tiene. Con `node` se consulta el otro nodo. Sirve para confirmar que el correo recibido también está en el slave antes de un relevo.',
                'inputSchema' => $o(['domain' => ['type' => 'string', 'description' => 'Opcional: solo los buzones de este dominio']], []),
            ],
            'mail_resync_node' => [
                'write' => true,
                'title' => 'Reenviar todo el correo (dominios, buzones, alias) a un nodo réplica',
                'description' => 'En el MASTER. Reenvía a un nodo de correo réplica (el slave de correo) TODOS los dominios de correo, buzones (con su hash de contraseña) y alias del panel: lo que le falte se crea y lo que tenga se actualiza; en el nodo NUNCA se borra nada. Es el botón "Sincronizar" de Mail → Infraestructura. Úsalo cuando mail_replication_status diga que al nodo le faltan dominios o buzones. Con copy_mail=true pide además a Dovecot una copia completa de todos los buzones hacia la pareja (dsync) — mejor en una segunda llamada, cuando la cola ya haya creado los buzones en el nodo. Primero sin apply.',
                'inputSchema' => $o([
                    'target_node' => ['type' => 'string', 'description' => 'Id o nombre del nodo réplica (list_nodes)'],
                    'copy_mail' => ['type' => 'boolean', 'description' => 'Forzar además la copia completa del contenido de los buzones (dsync)'],
                    'apply' => $apply,
                ], ['target_node']),
            ],
            'mail_domain_verify' => [
                'write' => false,
                'title' => 'Verificar correo de un dominio',
                'description' => 'Comprueba desde DNS público (1.1.1.1 y 8.8.8.8) que MX, SPF, DKIM (clave completa) y DMARC coinciden con lo que espera el panel, y que OpenDKIM valida la clave (opendkim-testkey). Solo lectura.',
                'inputSchema' => $o(['domain' => ['type' => 'string']], ['domain']),
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
            'mail_domain_create'  => self::domainCreate($args),
            'mail_dns_publish'    => self::dnsPublish($args),
            'mail_mailbox_create' => self::mailboxCreate($args),
            'mail_alias_create'   => self::aliasCreate($args),
            'mail_domain_alias'   => self::domainAlias($args),
            'mail_domain_verify'  => self::domainVerify($args),
            'mail_replication_status' => self::replicationStatus($args),
            'mail_resync_node'    => self::resyncNode($args),
            'mail_dkim_selector'  => self::dkimSelector($args),
            default               => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
        };
    }

    // ── Guardas y utilidades ─────────────────────────────────────────────

    private static function replicationStatus(array $args): array
    {
        $st = \MuseDockPanel\Services\MailReplicationService::status();
        if (empty($st['configured'])) {
            return $st;
        }
        $only = strtolower(trim((string)($args['domain'] ?? '')));
        // doveadm replicator status '*': username, priority, fast sync, full sync, success sync, failed
        $users = [];
        foreach (preg_split('/\R/', (string)shell_exec("doveadm replicator status '*' 2>/dev/null")) as $line) {
            $c = preg_split('/\s{2,}|\t/', trim($line));
            if (count($c) < 5 || strtolower($c[0]) === 'username') {
                continue;
            }
            $users[strtolower($c[0])] = ['last_ok' => $c[4] ?? '', 'failed' => strtolower(trim((string)end($c))) === 'y'];
        }
        $boxes = [];
        $missing = [];
        foreach (\MuseDockPanel\Database::fetchAll("SELECT lower(email) AS email FROM mail_accounts ORDER BY email") as $r) {
            if ($only !== '' && !str_ends_with($r['email'], '@' . $only)) {
                continue;
            }
            if (!isset($users[$r['email']])) {
                $missing[] = $r['email'];
                continue;
            }
            $boxes[$r['email']] = $users[$r['email']];
        }
        unset($st['replicator']);
        $st['nodes_compared'] = self::compareWithReplicas();
        return $st + [
            'mailboxes' => $boxes,
            'failed' => array_keys(array_filter($boxes, fn($b) => $b['failed'])),
            'not_in_replicator' => $missing,
            'note' => $missing
                ? 'Los buzones de not_in_replicator aún no se han replicado nunca: Dovecot los añade al recibir o al tocar su primer correo. Sin correo todavía, no es un fallo.'
                : null,
        ];
    }

    /** Compara dominios/buzones/alias de este master con cada réplica de correo. */
    private static function compareWithReplicas(): array
    {
        $out = [];
        // Mismas cifras que devuelve mail_domains en el nodo (igual con igual).
        $mine = [];
        foreach (\MuseDockPanel\Database::fetchAll(
            "SELECT d.domain,
                    (SELECT COUNT(*) FROM mail_accounts a WHERE a.mail_domain_id = d.id) AS mailboxes,
                    (SELECT COUNT(*) FROM mail_aliases al WHERE al.mail_domain_id = d.id) AS aliases
             FROM mail_domains d") as $d) {
            $mine[strtolower($d['domain'])] = [(int)$d['mailboxes'], (int)$d['aliases']];
        }
        foreach (MailService::getMailReplicaNodes() as $n) {
            try {
                $r = \MuseDockPanel\Services\ClusterService::callNode((int)$n['id'], 'POST', '/api/cluster/action',
                    ['action' => 'mcp-call', 'payload' => ['tool' => 'mail_domains', 'arguments' => []]]);
                $body = $r['data'] ?? [];
                if (empty($r['ok']) || empty($body['ok'])) {
                    $out[$n['name']] = ['error' => (string)($body['error'] ?? $r['error'] ?? 'sin respuesta')];
                    continue;
                }
                $theirs = [];
                foreach (($body['data']['domains'] ?? []) as $d) {
                    $theirs[strtolower($d['domain'])] = [(int)$d['mailboxes'], (int)$d['aliases']];
                }
                $missing = array_values(array_diff(array_keys($mine), array_keys($theirs)));
                $short = [];
                foreach ($mine as $dom => [$mb, $al]) {
                    if (isset($theirs[$dom]) && ($theirs[$dom][0] < $mb || $theirs[$dom][1] < $al)) {
                        $short[$dom] = "buzones {$theirs[$dom][0]}/{$mb}, alias {$theirs[$dom][1]}/{$al}";
                    }
                }
                $out[$n['name']] = ($missing || $short)
                    ? ['ok' => false, 'missing_domains' => $missing, 'incomplete_domains' => $short,
                       'fix' => "mail_resync_node target_node=\"{$n['name']}\""]
                    : ['ok' => true, 'note' => 'Tiene los mismos dominios, buzones y alias.'];
            } catch (\Throwable $e) {
                $out[$n['name']] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }

    private static function resyncNode(array $args): array
    {
        self::guardWritable();
        $want = trim((string)($args['target_node'] ?? ''));
        $node = null;
        foreach (MailService::getMailReplicaNodes() as $n) {
            if ((string)$n['id'] === $want || strcasecmp((string)$n['name'], $want) === 0 || stripos((string)$n['name'], $want) !== false) {
                $node = $n;
                break;
            }
        }
        if (!$node) {
            throw new \InvalidArgumentException("'{$want}' no es un nodo réplica de correo online. Mira mail_replication_status.");
        }
        $copy = !empty($args['copy_mail']);
        $nd = \MuseDockPanel\Database::fetchOne("SELECT COUNT(*) AS n FROM mail_domains WHERE status != 'deleted'")['n'] ?? 0;
        $nm = \MuseDockPanel\Database::fetchOne("SELECT COUNT(*) AS n FROM mail_accounts WHERE status != 'deleted'")['n'] ?? 0;
        $plan = ['node' => $node['name'], 'actions' => [
            "Encolar hacia {$node['name']}: {$nd} dominios y {$nm} buzones con sus alias (crea lo que falte, actualiza lo que haya; nunca borra en el nodo)",
        ]];
        if ($copy) {
            $plan['actions'][] = 'Pedir a Dovecot una copia completa de todos los buzones hacia la pareja (doveadm replicator replicate -f)';
        }
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = MailService::resyncMailToNode((int)$node['id']);
        $res = ['status' => 'encolado', 'queued' => $r] + $plan;
        if ($copy) {
            $o = trim((string)shell_exec("doveadm replicator replicate -f '*' 2>&1"));
            $res['copy_mail'] = $o !== '' ? $o : 'pedida';
        }
        $res['next'] = 'La cola lo procesa en ~1 min. Luego mail_replication_status (y, si los buzones aún no copian, otra llamada con copy_mail=true).';
        return $res;
    }

    private static function dkimSelector(array $args): array
    {
        $domain = self::domainArg($args);
        $row = self::requireDomain($domain);
        self::guardWritable();
        $new = strtolower(trim((string)($args['selector'] ?? ''))) ?: 'musedock';
        $old = (string)($row['dkim_selector'] ?? '') ?: 'default';
        if ($new === $old) {
            return ['status' => 'ya_esta', 'domain' => $domain, 'selector' => $old];
        }
        $plan = [
            'domain' => $domain,
            'actions' => [
                "El panel firmará con {$new}._domainkey.{$domain} (misma clave; antes {$old})",
                'Reinstalar la clave en OpenDKIM aquí y en las réplicas de correo (el slave de correo)',
                "No se toca el registro {$old}._domainkey existente (es de otro remitente)",
            ],
            'next' => "mail_dns_publish {$domain} para crear {$new}._domainkey",
        ];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        $r = MailService::setDkimSelector($domain, $new);
        if (empty($r['ok'])) {
            throw new \RuntimeException($r['error'] ?? 'No se pudo cambiar el selector.');
        }
        return ['status' => 'cambiado', 'old' => $r['old'], 'new' => $r['new'], 'replicas' => $r['replicas']] + $plan;
    }

    private static function domainAlias(array $args): array
    {
        $alias = self::domainArg(['domain' => $args['alias_domain'] ?? '']);
        $target = self::domainArg(['domain' => $args['target_domain'] ?? '']);
        if ($alias === $target) {
            throw new \InvalidArgumentException('El dominio alias y el de destino no pueden ser el mismo.');
        }
        $t = self::requireDomain($target);
        self::guardWritable();
        $a = MailService::getDomainByName($alias);
        $plan = ['alias_domain' => $alias, 'target_domain' => $target, 'actions' => []];
        if ($a) {
            $boxes = (int)(\MuseDockPanel\Database::fetchOne("SELECT COUNT(*) AS n FROM mail_accounts WHERE mail_domain_id = :d", ['d' => (int)$a['id']])['n'] ?? 0);
            if ($boxes > 0) {
                throw new \RuntimeException("{$alias} ya tiene {$boxes} buzón(es) propio(s): un catch-all hacia {$target} les quitaría el correo. No se hace nada; revisa esos buzones antes.");
            }
            $cur = \MuseDockPanel\Database::fetchOne("SELECT destination FROM mail_aliases WHERE mail_domain_id = :d AND is_catchall = true AND is_active = true", ['d' => (int)$a['id']]);
            if ($cur && strtolower((string)$cur['destination']) === '@' . $target) {
                return ['status' => 'ya_existe', 'note' => "{$alias} ya es sinónimo de {$target}."] + $plan;
            }
            if ($cur) {
                $plan['actions'][] = "Sustituir su catch-all actual (→ {$cur['destination']}) por → @{$target}";
            }
        } else {
            $plan['actions'][] = "Dar de alta {$alias} en el correo del panel (con su clave DKIM; cliente: el de {$target})";
        }
        $plan['actions'][] = "Catch-all @{$alias} → @{$target}: X@{$alias} se entrega en X@{$target}";
        $plan['actions'][] = 'Replicarlo a los nodos de correo (el slave de correo)';
        $plan['note'] = "Ojo: una dirección que no exista en {$target} se acepta y luego se devuelve con un rebote (el catch-all acepta cualquier nombre). El DNS no se toca: después, mail_dns_publish {$alias} (si el dominio recibe correo por el Email Routing de Cloudflare, lo detecta como conflicto).";
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        if (!$a) {
            $id = MailService::createDomain($alias, !empty($t['customer_id']) ? (int)$t['customer_id'] : null, null, ['max_accounts' => 0]);
            $a = MailService::getDomain($id) ?? ['id' => $id];
        }
        MailService::createAlias((int)$a['id'], '@' . $alias, '@' . $target, true);
        LogService::log('mail.domain.alias', $alias, "Dominio alias de {$target} (via MCP)");
        return ['status' => 'hecho'] + $plan;
    }

    private static function guardWritable(): void
    {
        $role = Settings::get('cluster_role', '') ?: \MuseDockPanel\Env::get('PANEL_ROLE', 'standalone');
        if ($role === 'slave') {
            throw new \RuntimeException('Este servidor es slave: los cambios de correo se hacen en el master.');
        }
    }

    private static function domainArg(array $args): string
    {
        $d = strtolower(trim((string)($args['domain'] ?? '')));
        if (!preg_match(self::DOMAIN_RE, $d)) {
            throw new \InvalidArgumentException('Dominio no válido.');
        }
        return $d;
    }

    private static function requireDomain(string $domain): array
    {
        $row = MailService::getDomainByName($domain);
        if (!$row) {
            throw new \RuntimeException("El dominio de correo {$domain} no existe en este panel. Créalo antes con mail_domain_create.");
        }
        return $row;
    }

    /** Hostname del servidor que recibe el correo de este dominio. */
    private static function mailHostFor(array $domainRow): string
    {
        $nodeId = (int)($domainRow['mail_node_id'] ?? 0);
        if ($nodeId > 0) {
            $node = ClusterService::getNode($nodeId);
            if ($node && !empty($node['mail_hostname'])) {
                return strtolower((string)$node['mail_hostname']);
            }
        }
        return strtolower(Settings::get('mail_local_hostname', '') ?: Settings::get('mail_hostname', ''));
    }

    /** Registros esperados para el dominio (lo que publicamos y verificamos). */
    private static function expectedRecords(array $d): array
    {
        $domain = strtolower((string)$d['domain']);
        $host = self::mailHostFor($d);
        if ($host === '') {
            throw new \RuntimeException('No hay hostname de correo configurado en el panel (mail_local_hostname).');
        }
        $ip = gethostbyname($host);
        $ip = ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
        $sel = (string)($d['dkim_selector'] ?? 'default') ?: 'default';
        $pub = (string)($d['dkim_public_key'] ?? '');
        $policy = (string)($d['dmarc_policy'] ?? '') ?: 'quarantine';

        $recs = [
            'mx' => ['type' => 'MX', 'name' => $domain, 'content' => $host, 'priority' => 10],
            'spf' => ['type' => 'TXT', 'name' => $domain, 'content' => 'v=spf1 mx' . ($ip ? " ip4:{$ip}" : '') . ' ~all'],
            'dmarc' => ['type' => 'TXT', 'name' => "_dmarc.{$domain}", 'content' => "v=DMARC1; p={$policy}; rua=mailto:postmaster@{$domain}"],
        ];
        if ($pub !== '') {
            $recs['dkim'] = ['type' => 'TXT', 'name' => "{$sel}._domainkey.{$domain}", 'content' => "v=DKIM1; k=rsa; p={$pub}"];
        }
        return $recs;
    }

    private static function txtNorm(string $v): string
    {
        // Cloudflare/dig devuelven TXT entre comillas y a veces troceado ("a" "b").
        $v = trim($v);
        if (str_starts_with($v, '"')) {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $v, $m);
            $v = implode('', $m[1]);
        }
        return trim(preg_replace('/\s+/', ' ', $v));
    }

    private static function hostNorm(string $h): string
    {
        return rtrim(strtolower(trim($h)), '.');
    }

    // ── mail_domain_create ───────────────────────────────────────────────

    private static function domainCreate(array $args): array
    {
        $domain = self::domainArg($args);
        if ($row = MailService::getDomainByName($domain)) {
            return ['status' => 'ya_existe', 'domain' => $domain, 'id' => (int)$row['id'],
                'dkim' => !empty($row['dkim_public_key']) ? 'configurado' : 'SIN clave DKIM',
                'next' => 'Publica su DNS con mail_dns_publish.'];
        }
        self::guardWritable();
        if (MailService::getCurrentMailMode() !== 'full') {
            throw new \RuntimeException('El modo de correo del panel no es "correo completo": no se pueden alojar dominios aquí.');
        }
        $backend = Settings::get('mail_local_configured', '') === '1';
        foreach (MailService::getMailNodes() as $n) {
            $backend = $backend || (string)($n['status'] ?? '') === 'online';
        }
        if (!$backend) {
            throw new \RuntimeException('No hay servidor de correo disponible (ni local configurado ni nodo de correo online).');
        }

        $hosting = MailService::hostingForDomain($domain);
        $plan = ['domain' => $domain, 'actions' => [
            'Crear el dominio de correo en el panel (servidor de correo local)',
            'Generar su clave DKIM (selector "default") y cargarla en OpenDKIM',
            'Replicarlo a los nodos de correo del cluster (el slave de correo)',
        ], 'next' => 'Después: mail_dns_publish para publicar MX/SPF/DKIM/DMARC en Cloudflare.'];
        if ($hosting) {
            $plan['hosting'] = "Este dominio ya es una web del panel ({$hosting['kind']} del hosting {$hosting['domain']}). "
                . 'El hosting no se toca; el correo se verá también en la ficha de ese hosting'
                . (!empty($hosting['customer_id']) ? ' y el dominio de correo hereda su cliente.' : '.');
        }

        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }

        $id = MailService::createDomain($domain, !empty($hosting['customer_id']) ? (int)$hosting['customer_id'] : null, null, ['max_accounts' => 0]);
        LogService::log('mail.domain.create', $domain, 'Mail domain created (via MCP)' . ($hosting ? " (hosting {$hosting['domain']}, {$hosting['kind']})" : ''));
        $row = MailService::getDomain($id) ?? [];
        return ['status' => 'creado', 'domain' => $domain, 'id' => $id,
            'dkim' => !empty($row['dkim_public_key']) ? 'generado (selector ' . ($row['dkim_selector'] ?? 'default') . ')' : 'NO generado (revisa mail_dkim_auto)',
            'next' => 'Publica su DNS con mail_dns_publish (primero sin apply para ver el plan).'];
    }

    // ── mail_dns_publish ─────────────────────────────────────────────────

    private static function dnsPublish(array $args): array
    {
        $domain = self::domainArg($args);
        $row = self::requireDomain($domain);
        self::guardWritable();
        $expected = self::expectedRecords($row);
        $replace = !empty($args['replace_conflicts']);
        $apply = !empty($args['apply']);

        $zone = CloudflareService::findZoneForDomain($domain);
        if (!$zone && CloudflareService::refreshZones()) {
            $zone = CloudflareService::findZoneForDomain($domain);
        }
        if (!$zone) {
            throw new \RuntimeException("{$domain} no está en ninguna cuenta de Cloudflare del panel (Ajustes → Cloudflare DNS). Publica los registros a mano o añade la zona.");
        }
        $token = (string)$zone['token'];
        $zoneId = (string)$zone['zone_id'];

        $existing = static function (string $name, string $type) use ($token, $zoneId): array {
            $r = CloudflareService::listRecords($token, $zoneId, ['name' => $name, 'type' => $type]);
            if (empty($r['ok'])) {
                throw new \RuntimeException('Cloudflare no respondió al listar registros: ' . ($r['error'] ?? 'error'));
            }
            return is_array($r['result']) ? $r['result'] : [];
        };

        $plan = [];
        $emailRouting = false;

        // MX
        $mx = $expected['mx'];
        $curMx = $existing($mx['name'], 'MX');
        $ours = array_values(array_filter($curMx, fn($r) => self::hostNorm((string)$r['content']) === self::hostNorm($mx['content'])));
        $others = array_values(array_filter($curMx, fn($r) => self::hostNorm((string)$r['content']) !== self::hostNorm($mx['content'])));
        foreach ($others as $r) {
            $emailRouting = $emailRouting || preg_match('/^route\d+\.mx\.cloudflare\.net$/', self::hostNorm((string)$r['content']));
        }
        $plan['mx'] = [
            'expected' => "10 {$mx['content']}",
            'current' => array_map(fn($r) => ($r['priority'] ?? '') . ' ' . $r['content'], $curMx),
            'status' => $ours && !$others ? 'ok' : ($others ? 'conflicto' : 'crear'),
            'conflicting' => $others,
            'create' => !$ours,
        ];

        // TXT: SPF (apex, solo los v=spf1), DKIM, DMARC
        foreach (['spf', 'dkim', 'dmarc'] as $k) {
            if (!isset($expected[$k])) {
                $plan[$k] = ['status' => 'omitido', 'reason' => 'El dominio no tiene clave DKIM generada.'];
                continue;
            }
            $e = $expected[$k];
            $cur = $existing($e['name'], 'TXT');
            if ($k === 'spf') {
                $cur = array_values(array_filter($cur, fn($r) => str_starts_with(strtolower(self::txtNorm((string)$r['content'])), 'v=spf1')));
                // SPF: SUMAR, nunca sustituir. Un SPF existente suele autorizar a más
                // remitentes (otro servidor, un servicio de envío…); reemplazarlo por el
                // nuestro les quitaría el permiso y sus correos acabarían en spam.
                if (count($cur) === 1) {
                    $curTxt = self::txtNorm((string)$cur[0]['content']);
                    $terms = preg_split('/\s+/', strtolower($curTxt)) ?: [];
                    $ourIp = preg_match('/ip4:(\S+)/', $e['content'], $ipm) ? $ipm[1] : '';
                    // Restos del Email Routing de Cloudflare: si el MX ya no va a Cloudflare,
                    // su include sobra. Se EDITA el registro quitando solo ese término.
                    $cfInc = 'include:_spf.mx.cloudflare.net';
                    $mxOnCf = (bool)array_filter($curMx, fn($r) => str_contains(strtolower((string)$r['content']), '.mx.cloudflare.net'));
                    if (in_array($cfInc, $terms, true) && !$mxOnCf
                        && (array_intersect(['mx', '+mx'], $terms) || ($ourIp !== '' && in_array("ip4:{$ourIp}", $terms, true)))) {
                        $cleaned = trim(preg_replace('/\s+/', ' ', preg_replace('/(^|\s)' . preg_quote($cfInc, '/') . '(?=\s|$)/i', ' ', $curTxt)));
                        $plan['spf'] = ['name' => $e['name'], 'current' => [$curTxt], 'expected' => $cleaned, 'status' => 'limpiar',
                            'note' => 'Se quita solo include:_spf.mx.cloudflare.net (era del Email Routing de Cloudflare; el MX ya no va allí). El resto se mantiene.',
                            'conflicting' => [], 'create' => false, 'update_id' => (string)$cur[0]['id']];
                        continue;
                    }
                    if (array_intersect(['mx', '+mx'], $terms) || ($ourIp !== '' && in_array("ip4:{$ourIp}", $terms, true))) {
                        $plan['spf'] = ['name' => $e['name'], 'current' => [$curTxt], 'status' => 'ok',
                            'note' => 'El SPF actual ya autoriza a este servidor: se respeta tal cual (no se quita a otros remitentes).',
                            'conflicting' => [], 'create' => false, 'update_id' => (string)$cur[0]['id']];
                        continue;
                    }
                    $add = 'mx' . ($ourIp !== '' ? " ip4:{$ourIp}" : '');
                    $merged = preg_match('/\s[~+?-]?all$/i', $curTxt)
                        ? preg_replace('/\s([~+?-]?all)$/i', " {$add} $1", $curTxt)
                        : "{$curTxt} {$add}";
                    $plan['spf'] = ['name' => $e['name'], 'current' => [$curTxt], 'expected' => $merged, 'status' => 'ampliar',
                        'note' => 'Se AÑADE este servidor al SPF existente, sin quitar a nadie.',
                        'conflicting' => [], 'create' => false, 'update_id' => (string)$cur[0]['id']];
                    continue;
                }
            }
            if ($k === 'dmarc') {
                $cur = array_values(array_filter($cur, fn($r) => str_starts_with(strtolower(self::txtNorm((string)$r['content'])), 'v=dmarc1')));
            }
            $match = array_values(array_filter($cur, fn($r) => self::txtNorm((string)$r['content']) === $e['content']));
            $diff = array_values(array_filter($cur, fn($r) => self::txtNorm((string)$r['content']) !== $e['content']));
            $plan[$k] = [
                'name' => $e['name'],
                'expected' => $k === 'dkim' ? substr($e['content'], 0, 40) . '…' : $e['content'],
                'current' => array_map(fn($r) => mb_substr(self::txtNorm((string)$r['content']), 0, 90), $cur),
                'status' => $match && !$diff ? 'ok' : ($diff ? 'conflicto' : 'crear'),
                'conflicting' => $diff,
                'create' => !$match,
            ];
        }

        $notes = [];
        if ($emailRouting) {
            $notes[] = 'Detectado el Enrutamiento de correo de Cloudflare (MX route*.mx.cloudflare.net). Mientras esté activo, Cloudflare bloquea esos registros: desactívalo en Cloudflare → Correo electrónico → Enrutamiento de correo → Deshabilitar, y vuelve a lanzar esta herramienta. Si queda su DKIM (cf2024-1._domainkey), puedes borrarlo: no molesta, pero ya no se usa.';
        }
        if ($plan['mx']['status'] === 'conflicto' && !$replace) {
            $notes[] = 'Hay MX de otro servidor. NO se añade el MX propio: con dos MX el correo se repartiría entre ambos. Revisa el plan y relanza con replace_conflicts=true para sustituirlos.';
        }

        // spf_add: términos extra (otro proveedor de envío…) en el MISMO registro SPF.
        $spfAdd = array_values(array_filter(preg_split('/\s+/', strtolower(trim((string)($args['spf_add'] ?? '')))) ?: []));
        if ($spfAdd) {
            foreach ($spfAdd as $t) {
                if (!preg_match('/^[+~?-]?(include|a|mx|ip4|ip6|exists):[a-z0-9._:\/-]+$/', $t)) {
                    throw new \InvalidArgumentException("spf_add: \"{$t}\" no es un término SPF válido (p. ej. include:spf.proveedor.com, ip4:1.2.3.4).");
                }
            }
            $sp = $plan['spf'] ?? [];
            $base = (string)($sp['expected'] ?? ($sp['current'][0] ?? ''));
            if (empty($sp['update_id']) || !str_starts_with($base, 'v=spf1')) {
                throw new \RuntimeException('spf_add necesita que el dominio tenga ya UN registro SPF (publica primero sin spf_add).');
            }
            $terms = preg_split('/\s+/', strtolower($base)) ?: [];
            $missing = array_values(array_filter($spfAdd, fn($t) => !in_array($t, $terms, true)));
            if ($missing) {
                $add = implode(' ', $missing);
                $merged = preg_match('/\s[~+?-]?all$/i', $base) ? preg_replace('/\s([~+?-]?all)$/i', " {$add} $1", $base) : "{$base} {$add}";
                // mx/ip4 de este servidor, si los hay, al final (antes de all): solo estética, no cambia el resultado.
                $plan['spf']['expected'] = $merged;
                $plan['spf']['note'] = trim(($sp['status'] === 'limpiar' ? 'Se quita include:_spf.mx.cloudflare.net. ' : '') . "Se AÑADE {$add} al SPF existente (mismo registro, sin quitar a nadie).");
                if ($sp['status'] === 'ok') {
                    $plan['spf']['status'] = 'ampliar';
                }
            }
        }

        $summary = array_map(fn($p) => $p['status'], $plan);
        if (!$apply) {
            return ['status' => 'plan', 'apply' => false, 'domain' => $domain, 'zone' => $zone['zone'],
                'cloudflare_account' => $zone['account'] ?? null, 'summary' => $summary,
                'records' => self::stripIds($plan), 'notes' => $notes,
                'on_apply' => $replace ? 'Crea lo que falta y SUSTITUYE los conflictos.' : 'Crea lo que falta; los conflictos se dejan como están.'];
        }

        // ── Aplicar ──
        $done = [];
        $errors = [];
        $cf = static function (string $op, array $r) use (&$errors): bool {
            if (!empty($r['ok'])) {
                return true;
            }
            $errors[] = "{$op}: " . ($r['error'] ?? json_encode($r['errors'] ?? []));
            return false;
        };

        // MX: si hay conflicto y no se autoriza reemplazar, no se toca nada del MX.
        if ($plan['mx']['status'] !== 'ok') {
            if ($plan['mx']['conflicting'] && !$replace) {
                $done[] = 'MX: omitido por conflicto (no se añade el MX propio).';
            } else {
                $allDeleted = true;
                foreach ($plan['mx']['conflicting'] as $r) {
                    if ($cf("borrar MX {$r['content']}", CloudflareService::deleteRecord($token, $zoneId, (string)$r['id']))) {
                        $done[] = "MX {$r['content']}: borrado";
                    } else {
                        $allDeleted = false;
                    }
                }
                // Si algún MX ajeno no se pudo borrar (p. ej. Enrutamiento de correo de
                // Cloudflare activo), NO añadir el nuestro: dos MX = correo repartido.
                if (!$allDeleted) {
                    $done[] = 'MX: el propio NO se ha creado porque quedan MX de otro servidor.';
                } elseif ($plan['mx']['create'] && $cf('crear MX', CloudflareService::createRecord($token, $zoneId,
                        ['type' => 'MX', 'name' => $mx['name'], 'content' => $mx['content'], 'priority' => 10, 'ttl' => 1, 'proxied' => false]))) {
                    $done[] = "MX 10 {$mx['content']}: creado";
                }
            }
        }

        foreach (['spf', 'dkim', 'dmarc'] as $k) {
            $p = $plan[$k];
            if (($p['status'] ?? '') === 'ok' || ($p['status'] ?? '') === 'omitido') {
                continue;
            }
            if (($p['status'] ?? '') === 'limpiar') { // SPF: quitar el include sobrante de Cloudflare
                if ($cf('limpiar SPF', CloudflareService::updateRecord($token, $zoneId, (string)$p['update_id'],
                        ['type' => 'TXT', 'name' => $p['name'], 'content' => $p['expected'], 'ttl' => 1]))) {
                    $done[] = 'SPF: quitado include:_spf.mx.cloudflare.net (el resto se mantiene)';
                }
                continue;
            }
            if (($p['status'] ?? '') === 'ampliar') { // SPF: añadir este servidor sin quitar nada
                if ($cf('ampliar SPF', CloudflareService::updateRecord($token, $zoneId, (string)$p['update_id'],
                        ['type' => 'TXT', 'name' => $p['name'], 'content' => $p['expected'], 'ttl' => 1]))) {
                    $done[] = 'SPF: ampliado (' . ($p['expected'] ?? '') . ')';
                }
                continue;
            }
            $e = $expected[$k];
            if ($p['conflicting'] && !$replace) {
                $done[] = strtoupper($k) . ': omitido por conflicto (existe otro valor).';
                continue;
            }
            $conf = $p['conflicting'];
            if ($conf && $p['create']) {
                // Reutiliza el primero (PATCH) y borra el resto: nunca dos SPF/DKIM/DMARC.
                $first = array_shift($conf);
                if ($cf("actualizar {$k}", CloudflareService::updateRecord($token, $zoneId, (string)$first['id'], ['type' => 'TXT', 'name' => $e['name'], 'content' => $e['content'], 'ttl' => 1]))) {
                    $done[] = strtoupper($k) . ': sustituido';
                }
                foreach ($conf as $r) {
                    $cf("borrar {$k} duplicado", CloudflareService::deleteRecord($token, $zoneId, (string)$r['id']));
                }
            } elseif ($p['create']) {
                if ($cf("crear {$k}", CloudflareService::createRecord($token, $zoneId, ['type' => 'TXT', 'name' => $e['name'], 'content' => $e['content'], 'ttl' => 1, 'proxied' => false]))) {
                    $done[] = strtoupper($k) . ': creado';
                }
            } else {
                foreach ($conf as $r) { // ya existe el correcto: sobran los distintos
                    if ($cf("borrar {$k} sobrante", CloudflareService::deleteRecord($token, $zoneId, (string)$r['id']))) {
                        $done[] = strtoupper($k) . ': borrado valor sobrante';
                    }
                }
            }
        }

        LogService::log('mail.dns.publish', $domain, 'DNS de correo publicado en Cloudflare vía MCP: ' . implode('; ', $done) . ($errors ? ' | errores: ' . implode('; ', $errors) : ''));
        return ['status' => $errors ? 'con_errores' : 'publicado', 'domain' => $domain, 'zone' => $zone['zone'],
            'done' => $done, 'errors' => $errors, 'notes' => $notes,
            'next' => 'Espera 1-5 minutos y comprueba con mail_domain_verify.'];
    }

    private static function stripIds(array $plan): array
    {
        foreach ($plan as $k => $p) {
            unset($plan[$k]['update_id']);
            if (!empty($p['conflicting'])) {
                $plan[$k]['conflicting'] = array_map(fn($r) => trim(($r['priority'] ?? '') . ' ' . mb_substr(self::txtNorm((string)$r['content']), 0, 90)), $p['conflicting']);
            }
        }
        return $plan;
    }

    // ── mail_mailbox_create ──────────────────────────────────────────────

    private static function mailboxCreate(array $args): array
    {
        $email = strtolower(trim((string)($args['email'] ?? '')));
        if (!preg_match('/^([^@]+)@(.+)$/', $email, $m) || !preg_match(self::LOCAL_RE, $m[1]) || !preg_match(self::DOMAIN_RE, $m[2])) {
            throw new \InvalidArgumentException('Email no válido.');
        }
        [$local, $domain] = [$m[1], $m[2]];
        $row = self::requireDomain($domain);
        if (MailService::getAccountByEmail($email)) {
            return ['status' => 'ya_existe', 'email' => $email];
        }
        self::guardWritable();

        $quota = array_key_exists('quota_mb', $args) ? max(0, (int)$args['quota_mb']) : (int)Settings::get('mail_default_quota_mb', '1024');
        $given = (string)($args['password'] ?? '');
        if ($given !== '' && strlen($given) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }
        $plan = ['email' => $email, 'display_name' => (string)($args['display_name'] ?? ''), 'quota_mb' => $quota,
            'password_mode' => $given !== '' ? 'la indicada (queda en el historial del chat: cámbiala luego desde el panel)' : 'se generará y se verá una vez en Ajustes → MCP → Credenciales pendientes'];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }

        $password = $given !== '' ? $given : McpCredentials::generate();
        MailService::createAccount((int)$row['id'], $local, $password, [
            'display_name' => (string)($args['display_name'] ?? ''),
            'quota_mb' => $quota,
        ]);
        LogService::log('mail.account.create', $email, "Mailbox created via MCP (quota: {$quota}MB)");
        if ($given === '') {
            $host = self::mailHostFor($row);
            McpCredentials::store('mail', $email, $password, ['imap' => "{$host}:993 SSL/TLS", 'smtp' => "{$host}:587 STARTTLS o 465 SSL/TLS", 'usuario' => $email]);
        }
        unset($password, $given);
        return ['status' => 'creado', 'email' => $email, 'quota_mb' => $quota,
            'password_mode' => $plan['password_mode'],
            'client_settings' => 'Servidor ' . self::mailHostFor($row) . ' — IMAP 993 SSL/TLS, SMTP 465 SSL/TLS, usuario = email completo.'];
    }

    /** Compatibilidad: Ajustes → MCP ahora usa McpCredentials::pending(). */
    public static function pendingCredentials(): array
    {
        return McpCredentials::pending();
    }

    // ── mail_alias_create ────────────────────────────────────────────────

    private static function aliasCreate(array $args): array
    {
        $source = strtolower(trim((string)($args['source'] ?? '')));
        $dest = strtolower(trim((string)($args['destination'] ?? '')));
        if (!preg_match('/^([^@]*)@(.+)$/', $source, $m) || !preg_match(self::DOMAIN_RE, $m[2]) || ($m[1] !== '' && !preg_match(self::LOCAL_RE, $m[1]))) {
            throw new \InvalidArgumentException('source no válido: usa alias@dominio o @dominio para el catch-all.');
        }
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('destination debe ser una dirección de correo válida.');
        }
        $catchall = $m[1] === '';
        $row = self::requireDomain($m[2]);

        $current = \MuseDockPanel\Database::fetchAll("SELECT source, destination, is_catchall FROM mail_aliases WHERE mail_domain_id = :i", ['i' => $row['id']]);
        foreach ($current as $a) {
            if ($a['source'] === $source && $a['destination'] === $dest) {
                return ['status' => 'ya_existe', 'source' => $source, 'destination' => $dest];
            }
        }
        if (!$catchall && MailService::getAccountByEmail($source)) {
            $warn = "Ya existe un buzón {$source}: el alias hará que además se reenvíe a {$dest}.";
        }
        $replaces = $catchall ? array_values(array_filter($current, fn($a) => !empty($a['is_catchall']) && $a['is_catchall'] !== 'f')) : [];
        self::guardWritable();

        $plan = ['source' => $source, 'destination' => $dest, 'catch_all' => $catchall,
            'replaces_catch_all' => $replaces ? array_map(fn($a) => "{$a['source']} → {$a['destination']}", $replaces) : null,
            'warning' => $warn ?? null];
        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }
        MailService::createAlias((int)$row['id'], $source, $dest, $catchall);
        LogService::log('mail.alias.create', $source, "Alias {$source} -> {$dest}" . ($catchall ? ' (catch-all)' : '') . ' via MCP');
        return ['status' => 'creado'] + $plan;
    }

    // ── mail_domain_verify ───────────────────────────────────────────────

    private static function dig(string $type, string $name, string $resolver): array
    {
        $out = (string)shell_exec('timeout 6 dig +short +time=2 +tries=1 ' . escapeshellarg($type) . ' ' . escapeshellarg($name) . ' @' . escapeshellarg($resolver) . ' 2>/dev/null');
        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    private static function domainVerify(array $args): array
    {
        $domain = self::domainArg($args);
        $row = self::requireDomain($domain);
        $exp = self::expectedRecords($row);
        $checks = [];

        foreach (['1.1.1.1', '8.8.8.8'] as $res) {
            $mx = array_map(fn($l) => self::hostNorm(preg_replace('/^\d+\s+/', '', $l)), self::dig('MX', $domain, $res));
            $checks["mx@{$res}"] = ['ok' => $mx === [self::hostNorm($exp['mx']['content'])], 'found' => $mx, 'expected' => [$exp['mx']['content']]];
        }
        $spf = array_values(array_filter(array_map([self::class, 'txtNorm'], self::dig('TXT', $domain, '1.1.1.1')), fn($v) => str_starts_with(strtolower($v), 'v=spf1')));
        // Igual que mail_dns_publish: vale cualquier SPF que autorice a este servidor
        // (mx o su ip4), aunque autorice también a otros remitentes.
        $spfTerms = count($spf) === 1 ? (preg_split('/\s+/', strtolower($spf[0])) ?: []) : [];
        $spfIp = preg_match('/ip4:(\S+)/', $exp['spf']['content'], $ipm) ? $ipm[1] : '';
        $spfOk = count($spf) === 1 && (array_intersect(['mx', '+mx'], $spfTerms) || ($spfIp !== '' && in_array("ip4:{$spfIp}", $spfTerms, true)));
        $spfNote = count($spf) > 1 ? 'Hay más de un SPF: los receptores lo tratan como error.' : null;
        if ($spfOk && in_array('include:_spf.mx.cloudflare.net', $spfTerms, true) && !array_filter(self::dig('MX', $domain, '1.1.1.1'), fn($l) => str_contains($l, '.mx.cloudflare.net'))) {
            $spfNote = 'Sobra include:_spf.mx.cloudflare.net (era del Email Routing de Cloudflare, ya no se usa). No molesta; se puede quitar.';
        }
        $checks['spf'] = ['ok' => $spfOk, 'found' => $spf, 'expected' => 'que autorice a este servidor (' . $exp['spf']['content'] . ')', 'note' => $spfNote];
        $dmarc = array_values(array_filter(array_map([self::class, 'txtNorm'], self::dig('TXT', "_dmarc.{$domain}", '1.1.1.1')), fn($v) => str_starts_with(strtolower($v), 'v=dmarc1')));
        $checks['dmarc'] = ['ok' => count($dmarc) === 1, 'found' => $dmarc, 'expected' => $exp['dmarc']['content']];
        if (isset($exp['dkim'])) {
            $dk = array_map([self::class, 'txtNorm'], self::dig('TXT', $exp['dkim']['name'], '1.1.1.1'));
            $pubFound = preg_match('/p=([A-Za-z0-9+\/=]+)/', str_replace(' ', '', implode('', $dk)), $pm) ? $pm[1] : '';
            $checks['dkim'] = ['ok' => $pubFound !== '' && $pubFound === (string)$row['dkim_public_key'],
                'name' => $exp['dkim']['name'], 'found' => $pubFound ? substr($pubFound, 0, 24) . '…' : null,
                'note' => $pubFound && $pubFound !== (string)$row['dkim_public_key'] ? 'La clave publicada NO coincide con la del panel.' : null];
            $tk = (string)shell_exec('timeout 10 opendkim-testkey -d ' . escapeshellarg($domain) . ' -s ' . escapeshellarg((string)($row['dkim_selector'] ?: 'default')) . ' -vvv 2>&1');
            $checks['opendkim_testkey'] = ['ok' => str_contains($tk, 'key OK'),
                'output' => trim(implode(' | ', array_slice(array_filter(explode("\n", $tk)), -2))) ?: 'sin salida (¿opendkim-tools instalado? ¿permisos?)',
                'note' => str_contains($tk, 'key not secure') ? '"key not secure" = sin DNSSEC; es normal y no afecta.' : null];
        } else {
            $checks['dkim'] = ['ok' => false, 'note' => 'El dominio no tiene clave DKIM en el panel.'];
        }
        $hostIp = gethostbyname($exp['mx']['content']);
        $checks['mail_host_resolves'] = ['ok' => filter_var($hostIp, FILTER_VALIDATE_IP) !== false, 'host' => $exp['mx']['content'], 'ip' => $hostIp];

        $failed = array_keys(array_filter($checks, fn($c) => empty($c['ok'])));
        return ['domain' => $domain, 'all_ok' => !$failed, 'failed' => $failed, 'checks' => $checks,
            'hint' => $failed ? 'Si acabas de publicar el DNS, espera unos minutos (TTL/propagación) y repite.' : 'Todo correcto: el dominio recibe y firma correo en este servidor.'];
    }
}
