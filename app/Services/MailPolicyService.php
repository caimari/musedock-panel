<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;

/**
 * MailPolicyService — combinable anti-abuse send policies for the mail server.
 *
 * A compromised mailbox is the classic way a hosting server ends up on spam
 * blocklists. This layers four independent, combinable controls, all driven from
 * the panel:
 *
 *   1. Send mode (per mailbox/domain): normal | webmail_only | readonly
 *      Enforced DB-live via a Postfix smtpd_sender_login_maps lookup — the same
 *      instant, reload-free mechanism status='active' already uses. A mailbox in
 *      webmail_only/readonly is refused when it authenticates to submission (587/
 *      465), so a stolen password used by a spam bot cannot send.
 *   2. Rate limit (per mailbox/domain/global): N messages/hour, via Rspamd's
 *      ratelimit module (a drop-in in /etc/rspamd/local.d, which is empty today).
 *   3. Domain send whitelist: only domains with send_allowed=true may send.
 *   4. fail2ban jails for SMTP/IMAP auth brute force (stock postfix/dovecot
 *      filters, enabled through a jail file).
 *
 * Design: mailbox existence/passwords/quota are read live from PostgreSQL by
 * Postfix/Dovecot, so column changes are instant. Only the FILE-based pieces
 * (the sender-login lookup, Rspamd drop-in, fail2ban jail, postconf) need this
 * service to (re)generate them and reload. The panel runs as root — no sudo.
 */
class MailPolicyService
{
    private const SENDER_LOGIN_CF = '/etc/postfix/pgsql-sender-login.cf';
    public const COPY_MAP_CF = '/etc/postfix/pgsql-recipient-bcc.cf';
    private const RSPAMD_RATELIMIT = '/etc/rspamd/local.d/ratelimit.conf';
    private const FAIL2BAN_JAIL = '/etc/fail2ban/jail.d/musedock-mail.conf';

    // ─────────────────────────────────────────────────────────
    // Policy CRUD (DB-live — no reload needed for these)
    // ─────────────────────────────────────────────────────────

    /** Set the send policy of a single mailbox. */
    public static function setMailboxPolicy(int $accountId, array $policy): array
    {
        $update = [];
        if (isset($policy['send_mode'])) {
            $update['send_mode'] = self::normalizeMode($policy['send_mode']);
        }
        if (array_key_exists('rate_limit_per_hour', $policy)) {
            $update['rate_limit_per_hour'] = max(0, (int)$policy['rate_limit_per_hour']);
        }
        if (isset($policy['can_send'])) {
            $update['can_send'] = (bool)$policy['can_send'];
        }
        if (empty($update)) {
            return ['ok' => false, 'error' => 'Sin cambios de política'];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_accounts', $update, 'id = :id', ['id' => $accountId]);
        // The sender-login map is DB-driven, so this is already live. Rate limits
        // live in Rspamd's map, so refresh that if rate limiting is on.
        if (($policy['refresh_ratelimit'] ?? true) && self::rateLimitEnabled()) {
            self::applyRateLimit();
        }
        LogService::log('mail.policy', 'mailbox', "Política de buzón #{$accountId} actualizada: " . json_encode($update));
        return ['ok' => true, 'applied' => $update];
    }

    /** Set the default send policy for a domain (inherited by new mailboxes). */
    public static function setDomainPolicy(int $domainId, array $policy): array
    {
        $update = [];
        if (isset($policy['default_send_mode'])) {
            $update['default_send_mode'] = self::normalizeMode($policy['default_send_mode']);
        }
        if (array_key_exists('default_rate_limit_per_hour', $policy)) {
            $update['default_rate_limit_per_hour'] = max(0, (int)$policy['default_rate_limit_per_hour']);
        }
        if (isset($policy['send_allowed'])) {
            $update['send_allowed'] = (bool)$policy['send_allowed'];
        }
        if (empty($update)) {
            return ['ok' => false, 'error' => 'Sin cambios'];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_domains', $update, 'id = :id', ['id' => $domainId]);
        LogService::log('mail.policy', 'domain', "Política de dominio #{$domainId} actualizada: " . json_encode($update));
        return ['ok' => true, 'applied' => $update];
    }

    private static function normalizeMode(string $m): string
    {
        return in_array($m, ['normal', 'webmail_only', 'readonly'], true) ? $m : 'normal';
    }

    // ─────────────────────────────────────────────────────────
    // Layer 1 + 3: sender-permission Postfix lookup (file + reload)
    // ─────────────────────────────────────────────────────────

    /**
     * Install the Postfix smtpd_sender_login_maps lookup that enforces send_mode,
     * can_send and the domain whitelist for submission/smtps.
     *
     * The lookup returns the owning login for an address ONLY when that mailbox is
     * allowed to send externally; when it is not (readonly, webmail_only, can_send
     * =false, suspended, or its domain is not whitelisted), it returns nothing, and
     * `reject_sender_login_mismatch` then refuses the send. Webmail sends bypass
     * this because they are injected locally (permit_mynetworks), not via the
     * authenticated submission port — so webmail_only still works.
     */
    /**
     * Servidores ya instalados: quita la línea `hosts` sin puerto cuando hay otra con
     * puerto (la que usaba Postfix) y cierra los permisos de las copias de los mapas
     * pgsql-*.cf, que llevan la contraseña de la base de datos. Devuelve '' si nada cambia.
     */
    public static function ensureSenderLoginCf(): string
    {
        $done = [];
        $cf = self::SENDER_LOGIN_CF;
        $txt = is_file($cf) ? (string)@file_get_contents($cf) : '';
        if ($txt !== '' && preg_match_all('/^hosts\s*=\s*(\S+)\s*$/m', $txt, $m) >= 2) {
            $withPort = array_values(array_filter($m[1], static fn($h) => str_contains($h, ':')));
            if ($withPort) {
                $lines = array_filter(explode("\n", $txt), static fn($l) => !preg_match('/^hosts\s*=/', $l));
                $new = 'hosts = ' . end($withPort) . "\n" . implode("\n", $lines);
                $bak = $cf . '.bak.' . date('Ymd_His');
                if (@copy($cf, $bak)) {
                    @chmod($bak, 0600);
                    file_put_contents($cf, $new);
                    exec('postfix check 2>&1', $out, $rc);
                    if ($rc !== 0) {
                        @copy($bak, $cf);
                        LogService::log('mail.policy', 'rollback', 'postfix check falló, pgsql-sender-login.cf restaurado');
                        return 'postfix check falló: pgsql-sender-login.cf restaurado';
                    }
                    shell_exec('postfix reload 2>&1');
                    $done[] = 'línea hosts repetida quitada de pgsql-sender-login.cf';
                }
            }
        }
        $opened = 0;
        foreach (glob('/etc/postfix/pgsql-*.cf.bak*') ?: [] as $b) {
            if ((fileperms($b) & 0077) !== 0 && @chmod($b, 0600)) {
                $opened++;
            }
        }
        if ($opened) {
            $done[] = "{$opened} copias de mapas pgsql cerradas a 600";
        }
        // Tras actualizar el panel, el mapa de remitentes instalado se pone al día solo (en el
        // master y en cada nodo): así no hay que guardar una política a mano. Si la prueba
        // falla y se vuelve atrás, no se reintenta hasta pasada una hora.
        if (is_file($cf) && time() - (int)Settings::get('mail_sender_map_rollback_at', '0') >= 3600) {
            $up = self::upgradeSenderQuery();
            if (!empty($up['changed'])) {
                $done[] = $up['output'];
            } elseif (!empty($up['rolled_back']) || (!$up['ok'] && $up['probe'] !== null)) {
                Settings::set('mail_sender_map_rollback_at', (string)time());
                $done[] = 'mapa de remitentes: ' . $up['output'];
            }
        }
        if ($done) {
            LogService::log('mail.policy', 'sender-map', implode('; ', $done));
        }
        return implode('; ', $done);
    }

    /**
     * Consulta (una sola línea) del mapa de remitentes de Postfix.
     *
     * Un buzón puede enviar como sí mismo y como cualquier alias concreto (no el catch-all)
     * que, siguiendo los reenvíos, acabe llegándole: así quien recibe en un buzón «master»
     * puede contestar con la dirección a la que escribieron. La consulta recorre los alias
     * desde la dirección del remitente hasta los buzones finales (UNION corta los ciclos).
     * Solo 'normal' envía desde clientes; webmail_only/readonly no (externo).
     * Con la lista blanca activa, el dominio del buzón y el del remitente deben tener el
     * envío permitido.
     * Ojo: Postfix sustituye los porcentajes de esta consulta, no escribir ninguno más.
     */
    public static function senderQuery(): string
    {
        $whitelist = self::whitelistEnabled();
        $domainGate = $whitelist ? " AND md.send_allowed = true" : "";
        $sourceGate = $whitelist
            ? " AND EXISTS (SELECT 1 FROM mail_domains sd WHERE sd.domain = split_part('%s', '@', 2) AND sd.send_allowed = true)"
            : "";
        return "WITH RECURSIVE t(addr) AS ("
             . "SELECT lower('%s')::text "
             . "UNION SELECT lower(trim(u.d)) FROM t "
             . "JOIN mail_aliases al ON al.source = t.addr AND al.is_active = true AND al.is_catchall = false "
             . "CROSS JOIN LATERAL unnest(string_to_array(al.destination, ',')) AS u(d)) "
             . "SELECT DISTINCT ma.email FROM t "
             . "JOIN mail_accounts ma ON ma.email = t.addr "
             . "JOIN mail_domains md ON md.id = ma.mail_domain_id "
             . "WHERE ma.status = 'active' AND ma.can_send = true "
             . "AND ma.send_mode = 'normal'{$domainGate}{$sourceGate}";
    }

    /**
     * Prueba real del mapa instalado: un buzón con envío permitido debe resolverse a sí mismo
     * con postmap. Devuelve ['ok','probe','output']; sin buzón que probar cuenta como correcto.
     */
    private static function probeSenderMap(): array
    {
        $gate = self::whitelistEnabled() ? ' AND md.send_allowed = true' : '';
        $probe = Database::fetchOne(
            "SELECT ma.email FROM mail_accounts ma JOIN mail_domains md ON md.id = ma.mail_domain_id "
            . "WHERE ma.status = 'active' AND ma.can_send = true AND ma.send_mode = 'normal'{$gate} ORDER BY ma.id LIMIT 1"
        );
        if (!$probe) {
            return ['ok' => true, 'probe' => null, 'output' => 'no hay buzón con envío permitido para probarlo'];
        }
        $email = (string)$probe['email'];
        $t = self::runCmd('postmap -q ' . escapeshellarg($email) . ' pgsql:' . escapeshellarg(self::SENDER_LOGIN_CF));
        $ok = str_contains($t['output'], $email) && !preg_match('/fatal|error/i', $t['output']);
        return ['ok' => $ok, 'probe' => $email, 'output' => $ok ? 'comprobado' : substr($t['output'], 0, 300)];
    }

    /**
     * Pone al día SOLO la línea `query` del mapa de remitentes ya instalado, conservando el
     * resto (servidor, usuario, contraseña, base de datos). Sirve en el master y en cualquier
     * nodo sin conocer sus credenciales. Hace copia, recarga Postfix y prueba con postmap; si
     * la prueba falla, restaura la copia. Devuelve ['changed','ok','probe','rolled_back','output'].
     */
    public static function upgradeSenderQuery(bool $forceProbe = false): array
    {
        $cf = self::SENDER_LOGIN_CF;
        $txt = is_file($cf) && is_readable($cf) ? (string)@file_get_contents($cf) : '';
        if ($txt === '' || !preg_match('/^query\s*=.*$/m', $txt)) {
            return ['changed' => false, 'ok' => false, 'probe' => null, 'rolled_back' => false, 'output' => 'no hay mapa de remitentes que actualizar (o no se puede leer)'];
        }
        $line = 'query = ' . self::senderQuery();
        $new = preg_replace_callback('/^query\s*=.*$/m', static fn() => $line, $txt);
        if ($new === $txt) {
            $p = $forceProbe ? self::probeSenderMap() : ['ok' => true, 'probe' => null, 'output' => 'ya estaba al día'];
            return ['changed' => false, 'ok' => $p['ok'], 'probe' => $p['probe'], 'rolled_back' => false, 'output' => $forceProbe ? 'Ya estaba aplicado; ' . $p['output'] : $p['output']];
        }

        $bak = $cf . '.bak.' . date('Ymd_His');
        if (!@copy($cf, $bak)) {
            return ['changed' => false, 'ok' => false, 'probe' => null, 'rolled_back' => false, 'output' => 'no se pudo hacer la copia de seguridad: no se toca el mapa'];
        }
        @chmod($bak, 0600); // lleva la contraseña de la base de datos del correo
        if (@file_put_contents($cf, $new) === false) {
            return ['changed' => false, 'ok' => false, 'probe' => null, 'rolled_back' => false, 'output' => 'no se pudo escribir ' . $cf];
        }
        @chmod($cf, 0640);
        @chgrp($cf, 'postfix');
        self::runCmd('postfix reload');

        $p = self::probeSenderMap();
        if ($p['ok']) {
            LogService::log('mail.policy', 'sender-map', 'Consulta del mapa de remitentes puesta al día (alias → buzón final)' . ($p['probe'] ? ", comprobada con {$p['probe']}" : ''));
            return ['changed' => true, 'ok' => true, 'probe' => $p['probe'], 'rolled_back' => false, 'output' => 'Mapa puesto al día y ' . $p['output']];
        }
        // La prueba falló: un mapa roto bloquearía todo el envío autenticado → volver atrás
        $rolled = @copy($bak, $cf);
        if ($rolled) {
            @chmod($cf, 0640);
            @chgrp($cf, 'postfix');
            self::runCmd('postfix reload');
        }
        LogService::log('mail.policy', 'sender-map', 'La prueba del mapa nuevo falló' . ($rolled ? ': copia anterior restaurada' : ': SIN copia que restaurar') . ' · ' . $p['output']);
        return ['changed' => false, 'ok' => false, 'probe' => $p['probe'], 'rolled_back' => $rolled, 'output' => 'La comprobación con postmap falló: ' . $p['output']];
    }

    /**
     * Regenera el mapa de remitentes de ESTE servidor (lo que usa la herramienta MCP
     * mail_sender_map_apply). Con el fichero ya instalado solo cambia la consulta; si no
     * existe, lo instala entero con las credenciales del panel y lo comprueba.
     * Devuelve ['ok','probe','rolled_back','output'].
     */
    public static function reapplySenderMap(): array
    {
        if (is_file(self::SENDER_LOGIN_CF)) {
            return self::upgradeSenderQuery(true);
        }
        $enc = Settings::get('mail_db_password_enc', '');
        $cfg = [
            'host'     => \MuseDockPanel\Env::get('DB_HOST', '127.0.0.1'),
            'port'     => (int)\MuseDockPanel\Env::get('DB_PORT', '5433'),
            'dbname'   => \MuseDockPanel\Env::get('DB_NAME', 'musedock_panel'),
            'user'     => 'musedock_mail',
            'password' => $enc !== '' ? ReplicationService::decryptPassword($enc) : '',
        ];
        $res = self::installSenderPolicy($cfg);
        if (empty($res['ok'])) {
            return ['ok' => false, 'probe' => null, 'rolled_back' => false, 'output' => (string)($res['error'] ?? $res['output'] ?? 'No se pudo instalar el mapa')];
        }
        $p = self::probeSenderMap();
        return ['ok' => $p['ok'], 'probe' => $p['probe'], 'rolled_back' => false, 'output' => $p['ok'] ? 'Mapa instalado y ' . $p['output'] : 'Mapa instalado pero la comprobación falló: ' . $p['output']];
    }

    // ─────────────────────────────────────────────────────
    // «Enviar copia a…»: recipient_bcc_maps (copia real, el buzón conserva el original)
    // ─────────────────────────────────────────────────────

    /** Consulta del mapa: la dirección de copia de un buzón activo, o nada. Pura. */
    public static function copyMapQuery(): string
    {
        // Postfix consulta con la dirección ORIGINAL del destinatario, antes de expandir alias: por eso, además del
        // buzón, se miran los alias que apuntan directamente a un buzón con copia (info@ → support@ con copia).
        // Los catch-all se excluyen: su clave «@dominio» copiaría TODO el correo del dominio.
        return "SELECT copy_to FROM ("
            . "SELECT copy_to, 1 AS pri FROM mail_accounts WHERE email = '%s' AND status = 'active' AND COALESCE(copy_to, '') <> '' "
            . "UNION ALL "
            . "SELECT m.copy_to, 2 AS pri FROM mail_aliases a JOIN mail_accounts m ON lower(m.email) = lower(a.destination) "
            . "WHERE lower(a.source) = lower('%s') AND a.is_catchall = false AND COALESCE(a.is_active, true) "
            . "AND m.status = 'active' AND COALESCE(m.copy_to, '') <> ''"
            . ") t ORDER BY pri LIMIT 1";
    }

    /** Contenido del .cf: las líneas de conexión del mapa de remitentes + la consulta de copia. Pura. */
    public static function copyMapConf(string $senderCf): ?string
    {
        $keep = [];
        foreach (preg_split('/\R/', $senderCf) ?: [] as $l) {
            if (preg_match('/^\s*(hosts|user|password|dbname)\s*=\s*\S/', $l)) {
                $keep[] = trim($l);
            }
        }
        $keys = array_map(static fn($l) => strtolower(trim(explode('=', $l, 2)[0])), $keep);
        if (array_diff(['hosts', 'user', 'password', 'dbname'], $keys)) {
            return null;   // sin contraseña o sin base: no se escribe un mapa que rompería la entrega
        }
        return implode("\n", $keep) . "\nquery = " . self::copyMapQuery() . "\n";
    }

    /** ¿El mapa de copias ya está conectado a Postfix? Pura. */
    public static function copyMapInstalled(string $recipientBccMaps, bool $fileExists): bool
    {
        return $fileExists && str_contains($recipientBccMaps, 'pgsql:' . self::COPY_MAP_CF);
    }

    /**
     * Autocuración (cada minuto, worker de cada nodo): si algún buzón activo tiene copia y el mapa no está conectado
     * a Postfix, lo instala. Cubre el caso de guardar sin cambiar nada, un fallo anterior, y las réplicas de correo.
     * Tras un intento fallido espera 10 min. Devuelve lo hecho o ''.
     */
    public static function ensureCopyMapIfNeeded(): string
    {
        if (trim((string)shell_exec('command -v postconf 2>/dev/null')) === '') {
            return '';
        }
        try {
            $n = (int)(\MuseDockPanel\Database::fetchOne("SELECT COUNT(*) AS n FROM mail_accounts WHERE COALESCE(copy_to, '') <> '' AND status = 'active'")['n'] ?? 0);
        } catch (\Throwable) {
            return '';   // columna aún sin migrar
        }
        if ($n === 0) {
            return '';
        }
        $cur = trim((string)shell_exec('postconf -h recipient_bcc_maps 2>/dev/null'));
        $desired = is_file(self::SENDER_LOGIN_CF) ? self::copyMapConf((string)@file_get_contents(self::SENDER_LOGIN_CF)) : null;
        $upToDate = self::copyMapInstalled($cur, is_file(self::COPY_MAP_CF))
            && ($desired === null || (string)@file_get_contents(self::COPY_MAP_CF) === $desired);   // también si cambió la consulta
        if ($upToDate) {
            return '';
        }
        if (time() - (int)Settings::get('mail_copy_map_try_at', '0') < 600) {
            return '';
        }
        Settings::set('mail_copy_map_try_at', (string)time());
        $r = self::ensureCopyMap();
        return !empty($r['ok']) ? 'mapa de copias instalado (' . $n . ' buzón/es con copia)' : 'mapa de copias NO instalado: ' . ($r['error'] ?? '?');
    }

    /**
     * ¿La prueba `postmap -q` del mapa de copias es buena? postmap devuelve 1 cuando la clave NO está (lo normal con una
     * dirección de prueba), así que 1 sin salida vale; un error de conexión o de consulta sale con otro código o con texto.
     * (20-10: la prueba daba por fallido el mapa y lo deshacía siempre.) Pura.
     */
    public static function copyProbeOk(int $code, string $output): bool
    {
        if ($code === 0) {
            return true;
        }
        return $code === 1 && trim($output) === '';
    }

    /** Valor nuevo de recipient_bcc_maps con nuestro mapa añadido sin pisar lo que ya hubiera. Pura. */
    public static function withCopyMap(string $current): string
    {
        $ours = 'pgsql:' . self::COPY_MAP_CF;
        $current = trim($current);
        if ($current === '') {
            return $ours;
        }
        return str_contains($current, $ours) ? $current : $current . ', ' . $ours;
    }

    /** Dirección de copia válida: un correo, distinto del propio buzón. '' si no. Pura. */
    public static function normalizeCopyTo(string $copyTo, string $mailbox): string
    {
        $c = strtolower(trim($copyTo));
        if ($c === '' || !filter_var($c, FILTER_VALIDATE_EMAIL) || $c === strtolower($mailbox)) {
            return '';
        }
        return $c;
    }

    /**
     * Instala (si falta) el mapa de copias de ESTE servidor y lo conecta a Postfix. Idempotente. Con las mismas
     * credenciales que el mapa de remitentes, copia de seguridad de main.cf, comprobación con postfix check y vuelta
     * atrás si falla. No hace nada mientras ningún buzón tenga copia (se llama solo cuando se configura una).
     */
    public static function ensureCopyMap(): array
    {
        $current = trim((string)shell_exec('postconf -h recipient_bcc_maps 2>/dev/null'));
        $ours = 'pgsql:' . self::COPY_MAP_CF;
        if (!is_file(self::SENDER_LOGIN_CF)) {
            return ['ok' => false, 'changed' => false, 'error' => 'No hay mapa de remitentes del que copiar las credenciales (Correo → política de envío).'];
        }
        $conf = self::copyMapConf((string)@file_get_contents(self::SENDER_LOGIN_CF));
        if ($conf === null) {
            return ['ok' => false, 'changed' => false, 'error' => 'El mapa de remitentes no tiene credenciales completas: no se instala el de copias.'];
        }
        $have = is_file(self::COPY_MAP_CF) ? (string)@file_get_contents(self::COPY_MAP_CF) : null;
        if ($have === $conf && self::copyMapInstalled($current, true)) {
            return ['ok' => true, 'changed' => false];
        }
        // Copia de lo anterior para poder volver atrás (consulta nueva, contraseña nueva…)
        $mainBak = '/etc/postfix/main.cf.bak.copymap.' . date('Ymd_His');
        @copy('/etc/postfix/main.cf', $mainBak);
        if ($have !== null) {
            @copy(self::COPY_MAP_CF, self::COPY_MAP_CF . '.bak.' . date('Ymd_His'));
            @chmod(self::COPY_MAP_CF . '.bak.' . date('Ymd_His'), 0600);
        }
        if (@file_put_contents(self::COPY_MAP_CF, $conf) === false) {
            return ['ok' => false, 'changed' => false, 'error' => 'No se pudo escribir ' . self::COPY_MAP_CF];
        }
        @chmod(self::COPY_MAP_CF, 0640);
        @chgrp(self::COPY_MAP_CF, 'postfix');
        static::runCmd('postconf -e ' . escapeshellarg('recipient_bcc_maps = ' . self::withCopyMap($current)));
        $chk = static::runCmd('postfix check');
        $probe = static::runCmd('postmap -q probe@invalid.test ' . escapeshellarg($ours));
        if (!$chk['ok'] || !self::copyProbeOk($probe['code'], $probe['output'])) {
            if (is_file($mainBak)) {
                @copy($mainBak, '/etc/postfix/main.cf');
            }
            if ($have !== null) {
                @file_put_contents(self::COPY_MAP_CF, $have);
            } else {
                @unlink(self::COPY_MAP_CF);
            }
            static::runCmd('postfix reload');
            LogService::log('mail.policy', 'copy-map-failed', 'Mapa de copias: ' . ($chk['ok'] ? $probe['output'] : $chk['output']));
            return ['ok' => false, 'changed' => false, 'rolled_back' => true, 'error' => 'La comprobación de Postfix falló; se ha dejado como estaba.'];
        }
        static::runCmd('postfix reload');
        LogService::log('mail.policy', 'copy-map', 'recipient_bcc_maps instalado/actualizado (copias por buzón y por alias)');
        return ['ok' => true, 'changed' => true];
    }


    /**
     * ¿Un fichero pgsql-*.cf de Postfix tiene la contraseña vacía? (con una base por red, Postfix no puede consultar
     * y rechaza TODO con «Temporary lookup failure»; caso filemon 2026-10-19: 43 envíos autenticados rechazados). Puro.
     */
    public static function cfHasEmptyPassword(string $text): bool
    {
        $hasPw = (bool)preg_match('/^\s*password\s*=\s*$/m', $text);
        $host = preg_match('/^\s*hosts\s*=\s*(\S+)/m', $text, $m) ? $m[1] : '';
        return $hasPw && ($host === '' || $host[0] !== '/' && !str_starts_with($host, 'unix:'));
    }

    /** Ficheros pgsql-*.cf de Postfix con la contraseña vacía en este nodo (hay que poder leerlos: root o grupo postfix). */
    public static function cfFilesWithEmptyPassword(): array
    {
        $bad = [];
        foreach (glob('/etc/postfix/pgsql-*.cf') ?: [] as $f) {
            $t = @file_get_contents($f);
            if (is_string($t) && self::cfHasEmptyPassword($t)) {
                $bad[] = $f;
            }
        }
        return $bad;
    }

    public static function installSenderPolicy(array $dbCfg): array
    {
        // Nunca escribir un mapa sin contraseña: rompería el envío autenticado de todo el servidor.
        $probe = "password = " . ($dbCfg['password'] ?? '') . "\nhosts = " . ($dbCfg['host'] ?? '127.0.0.1') . "\n";
        if (self::cfHasEmptyPassword($probe)) {
            return ['ok' => false, 'error' => 'La contraseña de la base de datos del correo no está disponible (mail_db_password_enc vacío): no se escribe un mapa de remitentes que rompería el envío autenticado. Rota o restablece esa contraseña desde Correo y vuelve a aplicarlo.'];
        }
        $whitelist = self::whitelistEnabled();
        $query = self::senderQuery();

        // Una sola línea hosts (con el puerto si lo hay): antes salían dos y Postfix avisaba
        // «overriding earlier entry» en cada consulta.
        $cf = "hosts = " . ($dbCfg['host'] ?? '127.0.0.1') . (isset($dbCfg['port']) ? ':' . $dbCfg['port'] : '') . "\n"
            . "user = " . ($dbCfg['user'] ?? 'musedock_mail') . "\n"
            . "password = " . ($dbCfg['password'] ?? '') . "\n"
            . "dbname = " . ($dbCfg['dbname'] ?? 'musedock_panel') . "\n"
            . "query = " . $query . "\n";

        $bak = self::SENDER_LOGIN_CF . '.bak.' . date('Ymd_His');
        if (file_exists(self::SENDER_LOGIN_CF) && @copy(self::SENDER_LOGIN_CF, $bak)) {
            @chmod($bak, 0600); // lleva la contraseña de la base de datos del correo
        }
        if (@file_put_contents(self::SENDER_LOGIN_CF, $cf) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::SENDER_LOGIN_CF];
        }
        @chmod(self::SENDER_LOGIN_CF, 0640);
        @chgrp(self::SENDER_LOGIN_CF, 'postfix');

        // Wire it into submission + smtps (not port 25 — that's inbound).
        // reject_sender_login_mismatch after permit_mynetworks so local/webmail is allowed.
        static::runCmd("postconf -e " . escapeshellarg("smtpd_sender_login_maps = pgsql:" . self::SENDER_LOGIN_CF));
        static::runCmd("postconf -P " . escapeshellarg("submission/inet/smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated,reject"));
        static::runCmd("postconf -P " . escapeshellarg("smtps/inet/smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated,reject"));

        $r = static::runCmd("postfix reload 2>&1");
        LogService::log('mail.policy', 'sender-map', 'smtpd_sender_login_maps instalado (whitelist=' . ($whitelist ? 'on' : 'off') . ')');
        return ['ok' => $r['ok'], 'whitelist' => $whitelist, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Layer 2: per-mailbox rate limit via Rspamd
    // ─────────────────────────────────────────────────────────

    /**
     * Generate the Rspamd ratelimit drop-in. Uses the authenticated user as the
     * key so the limit is per mailbox. The bucket rate is the global default;
     * per-mailbox overrides are handled by Rspamd's per-user settings if set, but
     * the common case (one global cap) is covered here cleanly.
     */
    public static function applyRateLimit(): array
    {
        if (!self::rateLimitEnabled()) {
            // Disabled: remove the drop-in so Rspamd stops limiting.
            if (file_exists(self::RSPAMD_RATELIMIT)) {
                @unlink(self::RSPAMD_RATELIMIT);
                static::runCmd('systemctl reload rspamd 2>&1');
            }
            return ['ok' => true, 'enabled' => false];
        }

        $perHour = max(1, (int)Settings::get('mail_policy_default_rate_limit', '100'));

        // Rspamd ratelimit: rate as "N / 1h", keyed by authenticated username, and
        // only for authenticated outbound (user is set). Bounce/known-good exempt.
        $conf = <<<CONF
# MuseDock — per-mailbox outbound rate limit (auto-generated)
rates {
    user {
        selector = 'user';
        bucket = {
            burst = {$perHour};
            rate = "{$perHour} / 1h";
        }
    }
}
# Only rate-limit authenticated senders (outbound from our mailboxes).
whitelisted_rcpts = "postmaster,mailer-daemon";
max_rcpt = 50;
CONF;

        if (!is_dir('/etc/rspamd/local.d')) {
            return ['ok' => false, 'error' => 'Rspamd no está instalado (no existe /etc/rspamd/local.d)'];
        }
        $bak = self::RSPAMD_RATELIMIT . '.bak.' . date('Ymd_His');
        if (file_exists(self::RSPAMD_RATELIMIT)) @copy(self::RSPAMD_RATELIMIT, $bak);
        if (@file_put_contents(self::RSPAMD_RATELIMIT, $conf) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::RSPAMD_RATELIMIT];
        }
        @chmod(self::RSPAMD_RATELIMIT, 0644);
        $r = static::runCmd('systemctl reload rspamd 2>&1');
        LogService::log('mail.policy', 'ratelimit', "Rate limit activado: {$perHour}/hora por buzón");
        return ['ok' => $r['ok'], 'enabled' => true, 'rate_per_hour' => $perHour, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Layer 4: fail2ban jails for SMTP/IMAP brute force
    // ─────────────────────────────────────────────────────────

    /**
     * Enable/disable the fail2ban mail jails (stock postfix + dovecot filters).
     * Bans IPs that repeatedly fail auth — the other half of stopping abuse.
     */
    /**
     * Jaulas de correo. Además de las normales (5 fallos en 10 min), las «lentas»: más de
     * 15 fallos en 24 h. Un ataque a 4 intentos por hora nunca llegaba al límite normal
     * (mortadelo, 2026-10-08: 98 intentos en un día de la misma IP sin ningún bloqueo).
     */
    private static function fail2banJailContent(): string
    {
        $base = static fn(string $name, string $filter, string $port, int $retry, int $find, int $ban) => "[{$name}]\n"
            . "enabled  = true\nfilter   = {$filter}\nport     = {$port}\nbackend  = systemd\n"
            . "maxretry = {$retry}\nfindtime = {$find}\nbantime  = {$ban}\n";
        $smtp = 'smtp,submission,submissions';
        $imap = 'imap,imaps,pop3,pop3s,submission,submissions';
        return "# MuseDock — mail auth brute-force protection (auto-generated)\n"
            . $base('postfix-sasl', 'postfix[mode=auth]', $smtp, 5, 600, 3600) . "\n"
            . $base('dovecot', 'dovecot', $imap, 5, 600, 3600) . "\n"
            . "# Ataques lentos (pocos intentos por hora): más de 15 fallos en 24 h.\n"
            . $base('postfix-sasl-slow', 'postfix[mode=auth]', $smtp, 15, 86400, 86400) . "\n"
            . $base('dovecot-slow', 'dovecot', $imap, 15, 86400, 86400);
    }

    /** cluster-worker: si las jaulas de correo están activas pero desfasadas, rehacerlas. */
    public static function ensureFail2banCurrent(): string
    {
        // Solo donde ya están instaladas (en un nodo las pone el master, aunque su propio
        // ajuste figure apagado). Nunca se borran ni se activan aquí: solo se ponen al día.
        if (!is_file(self::FAIL2BAN_JAIL)) {
            return '';
        }
        $old = (string)@file_get_contents(self::FAIL2BAN_JAIL);
        $new = self::fail2banJailContent();
        if ($old === $new) {
            return '';
        }
        @copy(self::FAIL2BAN_JAIL, self::FAIL2BAN_JAIL . '.bak.' . date('Ymd_His'));
        if (@file_put_contents(self::FAIL2BAN_JAIL, $new) === false) {
            return 'jaulas de correo: no se pudo escribir ' . self::FAIL2BAN_JAIL;
        }
        $t = static::runCmd('fail2ban-client -t 2>&1');
        if (!$t['ok']) {
            @file_put_contents(self::FAIL2BAN_JAIL, $old);
            return 'jaulas de correo: la configuración nueva no pasó la prueba; se deja la anterior';
        }
        static::runCmd('fail2ban-client reload 2>&1');
        LogService::log('mail.policy', 'fail2ban', 'Jaulas de correo puestas al día (incluidas las de ataques lentos)');
        return 'jaulas de correo de fail2ban actualizadas (incluidas las de ataques lentos)';
    }

    public static function applyFail2ban(): array
    {
        if (!is_dir('/etc/fail2ban')) {
            return ['ok' => false, 'error' => 'fail2ban no está instalado'];
        }
        if (!self::fail2banEnabled()) {
            if (file_exists(self::FAIL2BAN_JAIL)) {
                @unlink(self::FAIL2BAN_JAIL);
                static::runCmd('systemctl reload fail2ban 2>&1');
            }
            return ['ok' => true, 'enabled' => false];
        }

        $jail = self::fail2banJailContent();

        $bak = self::FAIL2BAN_JAIL . '.bak.' . date('Ymd_His');
        if (file_exists(self::FAIL2BAN_JAIL)) @copy(self::FAIL2BAN_JAIL, $bak);
        if (@file_put_contents(self::FAIL2BAN_JAIL, $jail) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::FAIL2BAN_JAIL];
        }
        @chmod(self::FAIL2BAN_JAIL, 0644);
        $r = static::runCmd('systemctl reload fail2ban 2>&1');
        if (!$r['ok']) {
            // reload can fail if fail2ban wasn't running; try restart.
            $r = static::runCmd('systemctl restart fail2ban 2>&1');
        }
        LogService::log('mail.policy', 'fail2ban', 'Jails de correo (postfix-sasl, dovecot y sus versiones lentas) activados');
        return ['ok' => $r['ok'], 'enabled' => true, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Orchestration + status
    // ─────────────────────────────────────────────────────────

    /**
     * Re-apply every file-based policy layer from current settings. Call this
     * after toggling any master switch. Idempotent.
     */
    public static function applyAll(array $dbCfg): array
    {
        return [
            'sender'   => self::installSenderPolicy($dbCfg),
            'ratelimit'=> self::applyRateLimit(),
            'fail2ban' => self::applyFail2ban(),
        ];
    }

    /** Current policy state for the UI. */
    public static function status(): array
    {
        // Count mailboxes by send mode.
        $modes = ['normal' => 0, 'webmail_only' => 0, 'readonly' => 0];
        try {
            $rows = Database::fetchAll("SELECT send_mode, COUNT(*) AS c FROM mail_accounts GROUP BY send_mode");
            foreach ($rows as $r) {
                $modes[$r['send_mode']] = (int)$r['c'];
            }
        } catch (\Throwable) {}

        return [
            'whitelist_enabled' => self::whitelistEnabled(),
            'ratelimit_enabled' => self::rateLimitEnabled(),
            'fail2ban_enabled'  => self::fail2banEnabled(),
            'default_rate'      => (int)Settings::get('mail_policy_default_rate_limit', '100'),
            'modes'             => $modes,
            'sender_map_active' => is_file(self::SENDER_LOGIN_CF),
        ];
    }

    public static function whitelistEnabled(): bool { return Settings::get('mail_policy_whitelist_enabled', '0') === '1'; }
    public static function rateLimitEnabled(): bool { return Settings::get('mail_policy_ratelimit_enabled', '0') === '1'; }
    public static function fail2banEnabled(): bool  { return Settings::get('mail_policy_fail2ban_enabled', '0') === '1'; }

    /**
     * NODE-side: apply a policy toggle pushed from the master, so every mail node
     * runs the SAME anti-abuse protections (fail2ban/rate-limit/whitelist). fail2ban
     * and the Rspamd rate-limit are OS/Rspamd config, not DB, so they must be applied
     * per node. Invoked via the cluster action mail_apply_policy.
     *
     * @param array $payload ['key' => whitelist|ratelimit|fail2ban, 'value' => '0'|'1']
     */
    public static function nodeApplyPolicy(array $payload): array
    {
        $keys = [
            'whitelist' => 'mail_policy_whitelist_enabled',
            'ratelimit' => 'mail_policy_ratelimit_enabled',
            'fail2ban'  => 'mail_policy_fail2ban_enabled',
        ];
        $key = (string)($payload['key'] ?? '');
        if (!isset($keys[$key])) {
            return ['ok' => false, 'error' => "Política desconocida: {$key}"];
        }
        Settings::set($keys[$key], ($payload['value'] ?? '0') === '1' ? '1' : '0');
        // Re-apply the affected layer on THIS node.
        $res = match ($key) {
            'whitelist' => self::installSenderPolicy(self::nodeMailDbCfg()),
            'ratelimit' => self::applyRateLimit(),
            'fail2ban'  => self::applyFail2ban(),
        };
        LogService::log('mail.policy', 'node-apply', "{$key} = {$payload['value']} (aplicado en este nodo)");
        return ['ok' => $res['ok'] ?? true, 'key' => $key, 'result' => $res];
    }

    /** NODE-side: set the default rate limit pushed from the master and re-apply. */
    public static function nodeSetRate(array $payload): array
    {
        $rate = max(1, (int)($payload['rate'] ?? 100));
        Settings::set('mail_policy_default_rate_limit', (string)$rate);
        $res = self::applyRateLimit();
        LogService::log('mail.policy', 'node-rate', "default_rate = {$rate} (aplicado en este nodo)");
        return ['ok' => $res['ok'] ?? true, 'rate' => $rate];
    }

    /** Local mail DB config for the sender-policy lookup on this node. */
    private static function nodeMailDbCfg(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => (int)\MuseDockPanel\Env::get('DB_PORT', '5433'),
            'name' => \MuseDockPanel\Env::get('DB_NAME', 'musedock_panel'),
            'user' => 'musedock_mail',
        ];
    }

    private static function runCmd(string $cmd): array
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        return ['ok' => $code === 0, 'code' => $code, 'output' => trim(implode("\n", $out))];
    }
}
