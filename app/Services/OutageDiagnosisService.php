<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * "¿Por qué no llego a ese servidor?" en lenguaje llano, para los avisos de réplica
 * parada y de nodo caído. Antes el correo decía solo "la réplica no recibe" y no se
 * sabía si el principal estaba apagado (p. ej. reiniciándose), si fallaba la VPN o si
 * el problema era de la propia réplica.
 *
 * Comprueba: ping y panel por la VPN, la web (443) por su IP pública y lo que ven los
 * testigos externos. Solo lee; tarda unos segundos como mucho.
 */
class OutageDiagnosisService
{
    private static function tcp(string $ip, int $port, int $timeout = 3): bool
    {
        if ($ip === '') {
            return false;
        }
        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;
        $fp = @fsockopen($host, $port, $e, $s, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private static function ping(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        exec('ping -c 2 -W 2 ' . escapeshellarg($ip) . ' >/dev/null 2>&1', $o, $rc);
        return $rc === 0;
    }

    /** IP pública del principal según la configuración del relevo (si la hay). */
    public static function primaryPublicIp(): string
    {
        foreach (FailoverService::getServers() as $s) {
            if (($s['role'] ?? '') === FailoverService::ROLE_PRIMARY && !empty($s['ip'])) {
                return (string)$s['ip'];
            }
        }
        return '';
    }

    /**
     * @param string $name   nombre para el texto (p. ej. "Filemon (154)")
     * @param string $vpnIp  IP por la que hablan los paneles (la VPN)
     * @param string $publicIp IP pública de sus webs ('' si no se sabe)
     * @return array{verdict:string, text:string, checks:array}
     */
    public static function diagnose(string $name, string $vpnIp, string $publicIp = '', int $dbPort = 0): array
    {
        $panelPort = (int)(Settings::get('panel_port', '8444') ?: 8444);
        $c = [
            'ping VPN' => self::ping($vpnIp),
            'panel por la VPN' => self::tcp($vpnIp, $panelPort),
        ];
        if ($dbPort > 0) {
            $c["base de datos ({$dbPort}) por la VPN"] = self::tcp($vpnIp, $dbPort);
        }
        if ($publicIp !== '') {
            $c['webs (443) por su IP pública'] = self::tcp($publicIp, 443);
        }
        $seen = [];
        if ($publicIp !== '') {
            try {
                $seen = WitnessService::verdicts($publicIp);
            } catch (\Throwable) {
            }
        }
        $up = count(array_filter($seen, static fn($v) => $v === 'up'));
        $down = count(array_filter($seen, static fn($v) => $v === 'down'));
        $wtxt = $seen ? 'Testigos: ' . implode(', ', array_map(static fn($w, $v) => "{$w} lo ve " . ($v === 'up' ? 'bien' : ($v === 'down' ? 'CAÍDO' : 'con problemas')), array_keys($seen), $seen)) . '.' : '';

        $nothing = !$c['ping VPN'] && !$c['panel por la VPN'] && empty($c['webs (443) por su IP pública']);
        if ($nothing && $up === 0) {
            $verdict = 'down';
            $text = "{$name} no responde por ninguna vía (ni por la VPN ni por internet)" . ($down ? ' y los testigos tampoco lo ven' : '')
                . '. Está apagado, reiniciándose o sin conexión. Si es un reinicio o un mantenimiento, la réplica se reengancha sola al volver.';
        } elseif (!$c['ping VPN'] && !$c['panel por la VPN'] && (!empty($c['webs (443) por su IP pública']) || $up > 0)) {
            $verdict = 'vpn';
            $text = "{$name} está en marcha (responde por internet), pero la VPN entre los dos servidores no funciona. La réplica viaja por la VPN: revisa WireGuard.";
        } elseif ($dbPort > 0 && empty($c["base de datos ({$dbPort}) por la VPN"]) && $c['panel por la VPN']) {
            $verdict = 'db';
            $text = "{$name} responde, pero su base de datos no acepta conexiones (puede estar reiniciándose o parada).";
        } else {
            $verdict = 'up';
            $text = "{$name} responde con normalidad: el problema está en la propia réplica o en su configuración, no en que el principal esté caído.";
        }
        $lines = [];
        foreach ($c as $k => $ok) {
            $lines[] = ($ok ? '✓ ' : '✗ ') . $k;
        }
        return ['verdict' => $verdict, 'text' => $text . ($wtxt ? "\n{$wtxt}" : ''), 'checks' => $lines];
    }
}
