<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Reenvío temporal del tráfico web (80/443) de este servidor al nuevo master, durante y
 * después de un «Pasar el mando».
 *
 * Mientras las cachés de DNS de los visitantes siguen apuntando a la IP antigua, este
 * servidor (ya apartado, sin servir webs) les reenvía la conexión tal cual (TCP, sin
 * descifrar: el certificado lo presenta el nuevo master) por la VPN. Sin esto, quien
 * llegaba con la caché antigua veía la web caída, o servida desde la copia con la base
 * en solo lectura (errores 500).
 *
 * Con iptables (DNAT + MASQUERADE) en cadenas propias, fáciles de quitar; solo el
 * tráfico que entra por fuera de la VPN hacia una IP de esta máquina en 80/443. El
 * panel (su puerto), la VPN y la entrada alternativa (otro puerto) no se tocan. Se quita
 * solo al cumplirse el plazo (cluster-worker) y deja net.ipv4.ip_forward como estaba.
 */
final class TrafficForwardService
{
    private const SETTING = 'role_switch_forward';
    private const NAT_PRE = 'MUSEDOCK_FWD';
    private const NAT_POST = 'MUSEDOCK_FWD_POST';
    private const FILTER = 'MUSEDOCK_FWD_F';

    private static function ipt(string $args): int
    {
        exec('iptables -w 5 ' . $args . ' 2>/dev/null', $o, $rc);
        return $rc;
    }

    public static function active(): ?array
    {
        $s = json_decode((string)Settings::get(self::SETTING, ''), true);
        return is_array($s) && !empty($s['to']) ? $s : null;
    }

    /** Empieza (o alarga) el reenvío hacia $toVpnIp durante $minutes minutos. */
    public static function start(string $toVpnIp, int $minutes = 10): array
    {
        if (!filter_var($toVpnIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'error' => 'IP de destino no válida'];
        }
        if (trim((string)shell_exec('command -v iptables 2>/dev/null')) === '') {
            return ['ok' => false, 'error' => 'iptables no está instalado'];
        }
        $prev = self::active();
        if ($prev && $prev['to'] !== $toVpnIp) {
            self::stop();
            $prev = null;
        }
        $vpnIf = trim((string)shell_exec("ip -4 -o route get " . escapeshellarg($toVpnIp) . " 2>/dev/null | sed -n 's/.* dev \\([^ ]*\\).*/\\1/p'")) ?: 'wg0';
        $ipForwardPrev = $prev['ip_forward_prev'] ?? trim((string)@file_get_contents('/proc/sys/net/ipv4/ip_forward'));

        if (!$prev) {
            $d = escapeshellarg($toVpnIp);
            $i = escapeshellarg($vpnIf);
            $ok = true;
            // NAT: entrante por fuera de la VPN, a una IP local, puertos web → nuevo master.
            self::ipt('-t nat -N ' . self::NAT_PRE);
            self::ipt('-t nat -F ' . self::NAT_PRE);
            $ok = $ok && self::ipt('-t nat -A ' . self::NAT_PRE . " ! -i {$i} -p tcp -m multiport --dports 80,443 -m addrtype --dst-type LOCAL -j DNAT --to-destination {$d}") === 0;
            if (self::ipt('-t nat -C PREROUTING -j ' . self::NAT_PRE) !== 0) {
                $ok = $ok && self::ipt('-t nat -I PREROUTING 1 -j ' . self::NAT_PRE) === 0;
            }
            self::ipt('-t nat -N ' . self::NAT_POST);
            self::ipt('-t nat -F ' . self::NAT_POST);
            $ok = $ok && self::ipt('-t nat -A ' . self::NAT_POST . " -o {$i} -d {$d} -p tcp -m multiport --dports 80,443 -j MASQUERADE") === 0;
            if (self::ipt('-t nat -C POSTROUTING -j ' . self::NAT_POST) !== 0) {
                $ok = $ok && self::ipt('-t nat -I POSTROUTING 1 -j ' . self::NAT_POST) === 0;
            }
            // FORWARD (ufw lo deja en DROP por defecto): solo esto, en los dos sentidos.
            self::ipt('-N ' . self::FILTER);
            self::ipt('-F ' . self::FILTER);
            $ok = $ok && self::ipt('-A ' . self::FILTER . " -d {$d} -p tcp -m multiport --dports 80,443 -j ACCEPT") === 0;
            $ok = $ok && self::ipt('-A ' . self::FILTER . " -s {$d} -p tcp -m multiport --sports 80,443 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT") === 0;
            if (self::ipt('-C FORWARD -j ' . self::FILTER) !== 0) {
                $ok = $ok && self::ipt('-I FORWARD 1 -j ' . self::FILTER) === 0;
            }
            if (!$ok) {
                self::removeRules();
                return ['ok' => false, 'error' => 'no se pudieron poner las reglas de iptables'];
            }
            @file_put_contents('/proc/sys/net/ipv4/ip_forward', '1');
        }
        $until = time() + max(1, $minutes) * 60;
        Settings::set(self::SETTING, json_encode(['to' => $toVpnIp, 'until' => max($until, (int)($prev['until'] ?? 0)),
            'ip_forward_prev' => $ipForwardPrev, 'since' => (int)($prev['since'] ?? time())]));
        LogService::log('cluster.role-switch', 'forward-start', "Reenvío web 80/443 → {$toVpnIp} durante {$minutes} min");
        return ['ok' => true, 'to' => $toVpnIp, 'until' => date('H:i:s', $until)];
    }

    private static function removeRules(): void
    {
        while (self::ipt('-t nat -D PREROUTING -j ' . self::NAT_PRE) === 0) {
        }
        self::ipt('-t nat -F ' . self::NAT_PRE);
        self::ipt('-t nat -X ' . self::NAT_PRE);
        while (self::ipt('-t nat -D POSTROUTING -j ' . self::NAT_POST) === 0) {
        }
        self::ipt('-t nat -F ' . self::NAT_POST);
        self::ipt('-t nat -X ' . self::NAT_POST);
        while (self::ipt('-D FORWARD -j ' . self::FILTER) === 0) {
        }
        self::ipt('-F ' . self::FILTER);
        self::ipt('-X ' . self::FILTER);
    }

    /** Quita el reenvío y deja ip_forward como estaba. */
    public static function stop(): void
    {
        $s = self::active();
        self::removeRules();
        if ($s && (string)($s['ip_forward_prev'] ?? '') === '0') {
            @file_put_contents('/proc/sys/net/ipv4/ip_forward', '0');
        }
        Settings::set(self::SETTING, '');
        if ($s) {
            LogService::log('cluster.role-switch', 'forward-stop', 'Reenvío web al nuevo master terminado');
        }
    }

    /** cluster-worker: quitarlo al cumplirse el plazo. */
    public static function expire(): string
    {
        $s = self::active();
        if ($s && time() >= (int)$s['until']) {
            self::stop();
            return 'reenvío web al nuevo master terminado (plazo cumplido)';
        }
        return '';
    }
}
