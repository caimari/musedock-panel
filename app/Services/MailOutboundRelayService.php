<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Relé de salida: el correo que envía este servidor (webmail, programas de correo, webs)
 * se entrega a otro servidor que lo manda por él (relayhost de Postfix). Para el usuario
 * es transparente; la firma DKIM la sigue poniendo este servidor.
 *
 * Para qué: una IP de salida sin DNS inverso que cuadre (una línea de casa, una línea de
 * reserva con IP dinámica, una IP a la que el proveedor aún no ha puesto el PTR) manda el
 * correo a spam. Por el relé sale con una IP buena.
 *
 * Modos: off | always | auto. En auto se usa solo cuando la IP por la que sale ahora este
 * servidor no tiene un DNS inverso que apunte de vuelta a ella (MailHeloService::check),
 * y se quita solo cuando vuelve a cuadrar.
 *
 * Varios relés en orden: el primero es relayhost y los demás smtp_fallback_relay (Postfix
 * pasa al siguiente si uno no responde). Cada uno: host, puerto, usuario y contraseña
 * (opcionales: un relé propio por la VPN puede aceptar sin usuario) y TLS. Sirve igual
 * para un servidor propio que para Sweego, Brevo, Amazon SES…
 *
 * La configuración se guarda en el master y se copia a sus nodos (acción de cluster
 * set-outbound-relay); cada nodo decide si la activa según SU IP de salida. Solo toca lo
 * que pone él: si Postfix ya tenía un relayhost que no es del panel, no se toca.
 */
final class MailOutboundRelayService
{
    public const MODES = ['off', 'auto', 'always'];
    private const PASSWD = '/etc/postfix/musedock_relay_passwd';
    private const TLS_POLICY = '/etc/postfix/musedock_relay_tls';
    private const KEYS = ['relayhost', 'smtp_fallback_relay', 'smtp_sasl_auth_enable', 'smtp_sasl_password_maps',
        'smtp_sasl_security_options', 'smtp_sasl_tls_security_options', 'smtp_tls_policy_maps'];

    public static function mode(): string
    {
        $m = (string)Settings::get('mail_outbound_relay_mode', 'off');
        return in_array($m, self::MODES, true) ? $m : 'off';
    }

    /** Relés guardados (contraseña cifrada en 'pass'). */
    public static function relays(): array
    {
        $l = json_decode((string)Settings::get('mail_outbound_relays', '[]'), true);
        return is_array($l) ? array_values(array_filter($l, static fn($r) => is_array($r) && !empty($r['host']))) : [];
    }

    /** Para mostrar: sin contraseñas. */
    public static function publicConfig(): array
    {
        return [
            'mode' => self::mode(),
            'relays' => array_map(static fn($r) => ['host' => $r['host'], 'port' => (int)$r['port'], 'user' => (string)($r['user'] ?? ''),
                'has_pass' => (string)($r['pass'] ?? '') !== '', 'tls' => (string)($r['tls'] ?? 'none'), 'label' => (string)($r['label'] ?? '')], self::relays()),
            'state' => json_decode((string)Settings::get('mail_outbound_relay_state', '{}'), true) ?: [],
        ];
    }

    /**
     * Guarda la configuración. $relays: [{label, host, port, user, pass ('' = conservar la
     * guardada para ese host:puerto), tls: none|starttls}].
     */
    public static function save(string $mode, array $relays): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return ['ok' => false, 'error' => 'Modo no válido'];
        }
        $old = [];
        foreach (self::relays() as $r) {
            $old[strtolower($r['host']) . ':' . (int)$r['port']] = (string)($r['pass'] ?? '');
        }
        $clean = [];
        foreach ($relays as $r) {
            $host = strtolower(trim((string)($r['host'] ?? '')));
            if ($host === '') {
                continue;
            }
            if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
                return ['ok' => false, 'error' => "Servidor no válido: {$host}"];
            }
            $port = (int)($r['port'] ?? 587) ?: 587;
            if ($port < 1 || $port > 65535) {
                return ['ok' => false, 'error' => "Puerto no válido para {$host}"];
            }
            $user = trim((string)($r['user'] ?? ''));
            $pass = (string)($r['pass'] ?? '');
            $passEnc = $pass !== '' ? ReplicationService::encryptPassword($pass) : ($user !== '' ? ($old["{$host}:{$port}"] ?? '') : '');
            $clean[] = ['label' => trim((string)($r['label'] ?? '')), 'host' => $host, 'port' => $port, 'user' => $user,
                'pass' => $passEnc, 'tls' => in_array($r['tls'] ?? '', ['none', 'starttls'], true) ? $r['tls'] : 'starttls'];
        }
        if ($mode !== 'off' && !$clean) {
            return ['ok' => false, 'error' => 'Para activarlo hace falta al menos un relé'];
        }
        Settings::set('mail_outbound_relay_mode', $mode);
        Settings::set('mail_outbound_relays', json_encode($clean, JSON_UNESCAPED_SLASHES));
        LogService::log('mail.outbound-relay', $mode, 'Relé de salida: ' . ($clean ? implode(', ', array_map(static fn($r) => "{$r['host']}:{$r['port']}", $clean)) : 'ninguno'));
        // Aplicar ya si se puede (el panel corre como root); si no, lo hace el cluster-worker.
        $canApply = !function_exists('posix_geteuid') || posix_geteuid() === 0;
        return ['ok' => true] + ($canApply ? self::ensure(true) : ['note' => 'se aplicará en la próxima pasada del cluster-worker']);
    }

    /** Para copiar a un nodo: contraseñas en claro (canal autenticado del cluster). */
    public static function exportConfig(): array
    {
        return ['mode' => self::mode(), 'relays' => array_map(static fn($r) => array_merge($r,
            ['pass' => ($r['pass'] ?? '') !== '' ? ReplicationService::decryptPassword((string)$r['pass']) : '']), self::relays())];
    }

    /** Acción de cluster set-outbound-relay (en el nodo). */
    public static function importConfig(array $p): array
    {
        return self::save((string)($p['mode'] ?? 'off'), (array)($p['relays'] ?? []));
    }

    /** Copia la configuración a los nodos del cluster. */
    public static function pushToNodes(): array
    {
        $out = [];
        foreach (ClusterService::getNodes() as $n) {
            try {
                $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'set-outbound-relay', 'payload' => self::exportConfig()]);
                $out[(string)$n['name']] = !empty($r['ok']) && !empty($r['data']['ok']) ? 'copiado' : 'ERROR: ' . ($r['data']['error'] ?? $r['error'] ?? '?');
            } catch (\Throwable $e) {
                $out[(string)$n['name']] = 'ERROR: ' . $e->getMessage();
            }
        }
        return $out;
    }

    private static function postfixReady(): bool
    {
        return is_executable('/usr/sbin/postconf') && is_file('/etc/postfix/main.cf');
    }

    private static function pc(string $key): string
    {
        $v = trim((string)@shell_exec('/usr/sbin/postconf -n ' . escapeshellarg($key) . ' 2>/dev/null'));
        return $v === '' ? '' : trim(substr($v, strpos($v, '=') + 1));
    }

    /**
     * Deja Postfix como toca (idempotente). La lleva el cluster-worker; la comprobación de
     * la IP de salida (curl + DNS) como mucho cada 5 min, salvo $force.
     */
    public static function ensure(bool $force = false): array
    {
        if (!self::postfixReady()) {
            return ['changed' => false, 'active' => false, 'note' => 'Postfix no está instalado aquí'];
        }
        // Un relay (modo relay) es el que reenvía: no se encadena a otro.
        if (Settings::get('mail_mode', '') === 'relay') {
            return ['changed' => false, 'active' => false, 'note' => 'este servidor es un relay'];
        }
        $mode = self::mode();
        $relays = self::relays();
        $st = json_decode((string)Settings::get('mail_outbound_relay_state', '{}'), true) ?: [];
        $applied = !empty($st['applied']);

        // Un relayhost puesto a mano (no por el panel): no se toca. Si coincide con uno de
        // los relés configurados, es del panel (p. ej. el estado se perdió).
        $curRelay = self::pc('relayhost');
        if (!$applied && $curRelay !== '' && in_array($curRelay, array_map([self::class, 'hostPort'], $relays), true)) {
            $applied = true;
            $st['applied'] = true;
            $st['sig'] = md5(json_encode($relays));
        }
        if (!$applied && $curRelay !== '') {
            return ['changed' => false, 'active' => false, 'note' => "Postfix ya tiene un relayhost propio ({$curRelay}): el panel no lo toca"];
        }

        $want = false;
        $why = '';
        if ($mode === 'always' && $relays) {
            $want = true;
            $why = 'siempre';
        } elseif ($mode === 'auto' && $relays) {
            if (!$force && time() - (int)($st['checked_at'] ?? 0) < 300) {
                $want = $applied;   // sin comprobar todavía: se queda como está
                $why = (string)($st['why'] ?? '');
            } else {
                $chk = MailHeloService::check();
                $st['checked_at'] = time();
                if ($chk['ip'] === '') {
                    $want = $applied;   // no se sabe la IP: no se cambia nada
                    $why = (string)($st['why'] ?? '');
                } else {
                    $want = !$chk['ptr_points_back'];
                    $why = $want ? "la IP de salida {$chk['ip']} no tiene un DNS inverso que cuadre (" . ($chk['ptr'] ?: 'ninguno') . ')'
                        : "la IP de salida {$chk['ip']} cuadra ({$chk['ptr']})";
                    $st['ip'] = $chk['ip'];
                }
            }
        }

        $sig = $want ? md5(json_encode($relays)) : '';
        if ($want === $applied && ($st['sig'] ?? '') === $sig) {
            $st['why'] = $why;
            Settings::set('mail_outbound_relay_state', json_encode($st));
            return ['changed' => false, 'active' => $want, 'why' => $why];
        }

        if ($want) {
            $err = self::apply($relays);
            if ($err !== '') {
                LogService::log('mail.outbound-relay', 'error', $err);
                return ['changed' => false, 'active' => $applied, 'error' => $err];
            }
        } else {
            self::remove();
        }
        // Solo se apunta si Postfix lo tiene de verdad (sin root, postconf no escribe).
        if ((self::pc('relayhost') !== '') !== $want) {
            return ['changed' => false, 'active' => $applied, 'error' => 'Postfix no ha aplicado el cambio (¿sin permisos de root?): lo hará el cluster-worker'];
        }
        @shell_exec('/usr/sbin/postfix reload >/dev/null 2>&1');
        $st = array_merge($st, ['applied' => $want, 'sig' => $sig, 'why' => $why, 'since' => time()]);
        Settings::set('mail_outbound_relay_state', json_encode($st));
        $msg = $want ? 'El correo sale por el relé ' . $relays[0]['host'] . " ({$why})" : 'El correo vuelve a salir directamente' . ($why ? " ({$why})" : '');
        LogService::log('mail.outbound-relay', $want ? 'on' : 'off', $msg);
        return ['changed' => true, 'active' => $want, 'why' => $why, 'message' => $msg];
    }

    private static function hostPort(array $r): string
    {
        return '[' . $r['host'] . ']:' . (int)$r['port'];
    }

    /** Pone relayhost, reservas, usuarios y TLS. Devuelve '' o el error. */
    private static function apply(array $relays): string
    {
        $passwd = [];
        $tls = [];
        foreach ($relays as $r) {
            $hp = self::hostPort($r);
            if (($r['user'] ?? '') !== '') {
                $pass = ReplicationService::decryptPassword((string)($r['pass'] ?? ''));
                $passwd[] = "{$hp} {$r['user']}:{$pass}";
            }
            $tls[] = $hp . ' ' . (($r['tls'] ?? 'none') === 'starttls' ? 'encrypt' : 'none');
        }
        $old = umask(0077);
        $okP = file_put_contents(self::PASSWD, $passwd ? implode("\n", $passwd) . "\n" : '') !== false;
        $okT = file_put_contents(self::TLS_POLICY, implode("\n", $tls) . "\n") !== false;
        umask($old);
        if (!$okP || !$okT) {
            return 'no se pudieron escribir los ficheros del relé en /etc/postfix';
        }
        @chmod(self::PASSWD, 0600);
        exec('/usr/sbin/postmap ' . escapeshellarg(self::PASSWD) . ' 2>&1 && /usr/sbin/postmap ' . escapeshellarg(self::TLS_POLICY) . ' 2>&1', $o, $rc);
        if ($rc !== 0) {
            return 'postmap falló: ' . implode(' ', $o);
        }
        $set = [
            'relayhost' => self::hostPort($relays[0]),
            'smtp_fallback_relay' => implode(' ', array_map([self::class, 'hostPort'], array_slice($relays, 1))),
            'smtp_tls_policy_maps' => 'hash:' . self::TLS_POLICY,
        ];
        if ($passwd) {
            $set += ['smtp_sasl_auth_enable' => 'yes', 'smtp_sasl_password_maps' => 'hash:' . self::PASSWD,
                'smtp_sasl_security_options' => 'noanonymous', 'smtp_sasl_tls_security_options' => 'noanonymous'];
        }
        foreach (self::KEYS as $k) {
            if (isset($set[$k]) && $set[$k] !== '') {
                @shell_exec('/usr/sbin/postconf -e ' . escapeshellarg("{$k} = {$set[$k]}") . ' 2>&1');
            } else {
                @shell_exec('/usr/sbin/postconf -X ' . escapeshellarg($k) . ' 2>&1');
            }
        }
        exec('postfix check 2>&1', $o2, $rc2);
        if ($rc2 !== 0) {
            self::remove();
            return 'postfix check falló, relé quitado: ' . implode(' ', $o2);
        }
        return '';
    }

    /** Quita solo lo que pone el panel. */
    private static function remove(): void
    {
        foreach (self::KEYS as $k) {
            @shell_exec('/usr/sbin/postconf -X ' . escapeshellarg($k) . ' 2>&1');
        }
    }
}
