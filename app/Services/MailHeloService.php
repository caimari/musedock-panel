<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Nombre con el que ESTE servidor se presenta al entregar correo (smtp_helo_name de
 * Postfix) y su DNS inverso. Para que Gmail y compañía no lo traten como spam deben
 * cuadrar tres cosas: la IP de salida tiene DNS inverso (PTR), ese nombre apunta de vuelta
 * a la misma IP, y Postfix se presenta con ese nombre.
 *
 * Es de cada servidor (no se copia a los demás): cada máquina tiene su IP y su PTR, por
 * ejemplo un nombre fijo por IP (154.dominio, 155.dominio). No cambia el nombre del
 * correo que se recibe (MX, IMAP, certificado), que sigue siendo el de mail_hostname.
 */
class MailHeloService
{
    public static function name(): string
    {
        return strtolower(trim((string)Settings::get('mail_helo_name', '')));
    }

    public static function valid(string $name): bool
    {
        return (bool)preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name);
    }

    private static function postfixInstalled(): bool
    {
        return is_executable('/usr/sbin/postconf') && is_file('/etc/postfix/main.cf');
    }

    /** IP pública por la que sale el correo de este servidor. */
    public static function outboundIp(): string
    {
        $ip = trim((string)@shell_exec('curl -4 -s --max-time 5 https://ifconfig.me 2>/dev/null'));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Comprueba las tres cosas. Solo lee.
     * @return array{ip:string, ptr:string, ptr_points_back:bool, helo:string, helo_matches:bool, ok:bool, advice:string}
     */
    public static function check(): array
    {
        $ip = self::outboundIp();
        $ptr = $ip !== '' ? self::publicPtr($ip) : '';
        if ($ptr === $ip) {
            $ptr = '';
        }
        $back = $ptr !== '' && in_array($ip, self::publicA($ptr), true);
        $helo = self::postfixInstalled() ? trim((string)@shell_exec('/usr/sbin/postconf -h smtp_helo_name 2>/dev/null')) : '';
        if ($helo === '$myhostname' || $helo === '') {
            $helo = self::postfixInstalled() ? trim((string)@shell_exec('/usr/sbin/postconf -h myhostname 2>/dev/null')) : '';
        }
        $match = $ptr !== '' && strcasecmp($helo, $ptr) === 0;
        $ok = $ptr !== '' && $back && $match;
        $advice = match (true) {
            $ip === '' => 'No se pudo saber la IP de salida de este servidor.',
            $ptr === '' => "La IP {$ip} no tiene DNS inverso: pídelo a tu proveedor (en Contabo y similares se pone en su panel).",
            !$back => "El DNS inverso de {$ip} es {$ptr}, pero {$ptr} apunta a " . (implode(', ', self::publicA($ptr)) ?: 'ninguna IP')
                . ". Si ese nombre se mueve en un relevo (como el del correo), usa uno fijo solo para esta IP (p. ej. "
                . self::suggest($ip) . "): registro A → {$ip} sin proxy, pide ese DNS inverso a tu proveedor y ponlo aquí como nombre de envío.",
            !$match => "Postfix se presenta como {$helo} y el DNS inverso es {$ptr}: pon {$ptr} como nombre de envío.",
            default => 'Todo cuadra: IP, DNS inverso y nombre de envío.',
        };
        return ['ip' => $ip, 'ptr' => $ptr, 'ptr_points_back' => $back, 'helo' => $helo, 'helo_matches' => $match, 'ok' => $ok, 'advice' => $advice];
    }

    /**
     * DNS inverso de una IP según el DNS público (no /etc/hosts, que puede asociar la IP
     * de la máquina a otro nombre: p. ej. «207.180.244.219 musedock.com»).
     */
    private static function publicPtr(string $ip): string
    {
        foreach (['1.1.1.1', '8.8.8.8'] as $ns) {
            $out = trim((string)@shell_exec('dig +short +time=3 +tries=1 @' . $ns . ' -x ' . escapeshellarg($ip) . ' 2>/dev/null'));
            $first = rtrim(strtolower((string)strtok($out, "\n")), '.');
            if ($first !== '' && !str_starts_with($first, ';') && preg_match('/^[a-z0-9.-]+$/', $first)) {
                return $first;
            }
        }
        return rtrim((string)@gethostbyaddr($ip), '.');
    }

    /**
     * IPs del registro A de un nombre según el DNS (no /etc/hosts: ahí el nombre de la
     * propia máquina apunta a 127.0.1.1 y el DNS inverso parecía no cuadrar nunca).
     */
    private static function publicA(string $name): array
    {
        // Un DNS público directamente: el del sistema (systemd-resolved) también lee /etc/hosts.
        foreach (['1.1.1.1', '8.8.8.8'] as $ns) {
            $out = (string)@shell_exec('dig +short +time=3 +tries=1 @' . $ns . ' A ' . escapeshellarg($name) . ' 2>/dev/null');
            $ips = array_values(array_filter(array_map('trim', explode("\n", $out)),
                static fn($x) => (bool)filter_var($x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
            if ($ips) {
                return $ips;
            }
        }
        $ips = [];
        foreach (@dns_get_record($name, DNS_A) ?: [] as $r) {
            if (!empty($r['ip']) && !str_starts_with((string)$r['ip'], '127.')) {
                $ips[] = (string)$r['ip'];
            }
        }
        return $ips;
    }

    /** Nombre fijo sugerido para una IP: último número de la IP + dominio del nombre del correo. */
    private static function suggest(string $ip): string
    {
        $mail = (string)Settings::get('mail_hostname', '');
        $parts = explode('.', $mail);
        $domain = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : 'tudominio.com';
        return substr($ip, strrpos($ip, '.') + 1) . '.' . $domain;
    }

    /** Guarda el nombre (vacío = automático: el DNS inverso de la IP, si cuadra) y lo aplica. */
    public static function set(string $name): array
    {
        $name = strtolower(trim($name));
        if ($name !== '' && !self::valid($name)) {
            return ['ok' => false, 'error' => 'Nombre no válido (p. ej. 154.tudominio.com).'];
        }
        Settings::set('mail_helo_name', $name);
        LogService::log('mail.helo', $name ?: '(por defecto)', 'Nombre de envío de este servidor');
        return self::ensure() + ['ok' => true];
    }

    /** Deja Postfix con el nombre guardado (idempotente). Lo llama el cluster-worker. */
    public static function ensure(): array
    {
        if (!self::postfixInstalled()) {
            return ['changed' => false, 'note' => 'Postfix no está instalado aquí'];
        }
        $want = self::name();
        if ($want === '') {
            // Automático: el DNS inverso de la IP de salida, si apunta de vuelta a ella. Si no
            // se puede comprobar ahora (red, DNS), no se toca lo que haya.
            $chk = self::check();
            if ($chk['ip'] === '') {
                return ['changed' => false, 'note' => 'sin IP de salida: no se toca'];
            }
            $want = ($chk['ptr'] !== '' && $chk['ptr_points_back'] && self::valid(strtolower($chk['ptr']))) ? strtolower($chk['ptr']) : '';
        }
        $cur = trim((string)@shell_exec('/usr/sbin/postconf -n smtp_helo_name 2>/dev/null'));
        $cur = $cur === '' ? '' : trim(substr($cur, strpos($cur, '=') + 1));
        if ($want === $cur) {
            return ['changed' => false];
        }
        if ($want === '') {
            @shell_exec('/usr/sbin/postconf -X smtp_helo_name 2>/dev/null');
        } else {
            @shell_exec('/usr/sbin/postconf -e ' . escapeshellarg('smtp_helo_name = ' . $want) . ' 2>/dev/null');
        }
        @shell_exec('/usr/sbin/postfix reload >/dev/null 2>&1 || systemctl reload postfix >/dev/null 2>&1');
        return ['changed' => true];
    }
}
