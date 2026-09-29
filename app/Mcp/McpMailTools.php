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
            'mail_domain_verify'  => self::domainVerify($args),
            default               => throw new \InvalidArgumentException("Herramienta desconocida: {$name}"),
        };
    }

    // ── Guardas y utilidades ─────────────────────────────────────────────

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

        $plan = ['domain' => $domain, 'actions' => [
            'Crear el dominio de correo en el panel (servidor de correo local)',
            'Generar su clave DKIM (selector "default") y cargarla en OpenDKIM',
            'Replicarlo a los nodos de correo del cluster (p. ej. Filemon)',
        ], 'next' => 'Después: mail_dns_publish para publicar MX/SPF/DKIM/DMARC en Cloudflare.'];

        if (empty($args['apply'])) {
            return ['status' => 'plan', 'apply' => false] + $plan;
        }

        $id = MailService::createDomain($domain, null, null, ['max_accounts' => 0]);
        LogService::log('mail.domain.create', $domain, 'Mail domain created (via MCP)');
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
                    if (array_intersect(['mx', '+mx'], $terms) || ($ourIp !== '' && in_array("ip4:{$ourIp}", $terms, true))) {
                        $plan['spf'] = ['name' => $e['name'], 'current' => [$curTxt], 'status' => 'ok',
                            'note' => 'El SPF actual ya autoriza a este servidor: se respeta tal cual (no se quita a otros remitentes).',
                            'conflicting' => [], 'create' => false];
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
            if (($p['status'] ?? '') === 'ampliar') { // SPF: añadir este servidor sin quitar nada
                if ($cf('ampliar SPF', CloudflareService::updateRecord($token, $zoneId, (string)$p['update_id'],
                        ['type' => 'TXT', 'name' => $p['name'], 'content' => $p['expected'], 'ttl' => 1]))) {
                    $done[] = 'SPF: ampliado (se añade este servidor, se mantienen los demás)';
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

        $password = $given !== '' ? $given : self::generatePassword();
        MailService::createAccount((int)$row['id'], $local, $password, [
            'display_name' => (string)($args['display_name'] ?? ''),
            'quota_mb' => $quota,
        ]);
        LogService::log('mail.account.create', $email, "Mailbox created via MCP (quota: {$quota}MB)");
        if ($given === '') {
            self::storePendingCredential($email, $password);
        }
        unset($password, $given);
        return ['status' => 'creado', 'email' => $email, 'quota_mb' => $quota,
            'password_mode' => $plan['password_mode'],
            'client_settings' => 'Servidor ' . self::mailHostFor($row) . ' — IMAP 993 SSL/TLS, SMTP 465 SSL/TLS, usuario = email completo.'];
    }

    private static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_.';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private static function storePendingCredential(string $email, string $password): void
    {
        $list = json_decode(Settings::get('mcp_pending_credentials', '[]'), true) ?: [];
        $list = array_values(array_filter($list, fn($c) => (int)($c['at'] ?? 0) > time() - 7 * 86400)); // caducan a los 7 días
        $list[] = ['email' => $email, 'enc' => ReplicationService::encryptPassword($password), 'at' => time()];
        Settings::set('mcp_pending_credentials', json_encode(array_slice($list, -30)));
    }

    /** Para la página de Ajustes → MCP. */
    public static function pendingCredentials(): array
    {
        $out = [];
        foreach (json_decode(Settings::get('mcp_pending_credentials', '[]'), true) ?: [] as $c) {
            if ((int)($c['at'] ?? 0) <= time() - 7 * 86400) {
                continue;
            }
            $out[] = ['email' => $c['email'], 'password' => ReplicationService::decryptPassword((string)$c['enc']), 'at' => gmdate('Y-m-d H:i', (int)$c['at']) . ' UTC'];
        }
        return $out;
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
        $checks['spf'] = ['ok' => count($spf) === 1 && $spf[0] === $exp['spf']['content'], 'found' => $spf, 'expected' => $exp['spf']['content'],
            'note' => count($spf) > 1 ? 'Hay más de un SPF: los receptores lo tratan como error.' : null];
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
