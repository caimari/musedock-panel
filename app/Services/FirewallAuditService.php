<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Auditoría del firewall (iptables/ip6tables, con aviso si hay ufw o nftables).
 *
 * No se fía de las reglas sueltas: SIMULA el recorrido de un paquete nuevo por la
 * cadena INPUT (siguiendo saltos a cadenas propias) para cada puerto en escucha:
 *   - desde una IP cualquiera de internet por la interfaz pública;
 *   - desde cada origen que alguna regla autoriza (-s ...).
 * Así distingue "abierto a todo internet", "solo desde estas IPs" y "cerrado", y
 * un "ACCEPT all" que en realidad es solo de lo/wg0 no se confunde con un agujero.
 *
 * Revisa además IPv6 (a menudo olvidado), Docker (sus puertos publicados no pasan
 * por INPUT), ufw activo junto a reglas propias y orígenes con acceso a TODOS los
 * puertos que no estén en la lista de confianza (nodos del cluster, ALLOWED_IPS
 * del panel, red de la VPN y firewall_trusted_sources).
 * Solo lectura.
 */
final class FirewallAuditService
{
    /** Servicios que nunca deberían estar abiertos a todo internet. */
    private const SENSITIVE = [
        2019 => 'API de administración de Caddy', 2375 => 'API de Docker', 2376 => 'API de Docker',
        3306 => 'MySQL/MariaDB', 33060 => 'MySQL X', 5432 => 'PostgreSQL', 5433 => 'PostgreSQL (panel)', 5434 => 'PostgreSQL',
        6379 => 'Redis', 11211 => 'Memcached', 27017 => 'MongoDB', 9200 => 'Elasticsearch', 9100 => 'node-exporter',
        9090 => 'Prometheus', 3000 => 'Grafana/app Node', 8445 => 'panel (interno)', 8444 => 'panel', 111 => 'rpcbind (NFS)',
        2049 => 'NFS', 445 => 'SMB', 139 => 'SMB', 6001 => 'websocket', 8001 => 'app (Octane)',
    ];
    /** Puertos que es normal ofrecer al público. */
    private const PUBLIC_OK = [80, 443, 25, 465, 587, 993, 995, 143, 110, 4190, 51820];

    public static function audit(): array
    {
        $pubIf = self::sh("ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* dev \\([^ ]*\\).*/\\1/p' | head -1");
        $v4 = self::parse(self::lines(self::sh('iptables -S 2>/dev/null')));
        $v6 = self::parse(self::lines(self::sh('ip6tables -S 2>/dev/null')));
        $hasV6 = self::sh("ip -6 addr show scope global 2>/dev/null | grep -c inet6") > 0;
        $ufw = self::sh('ufw status 2>/dev/null | head -1');
        $findings = [];

        if (!$v4['policies']) {
            return ['ok' => false, 'error' => 'No se pudo leer iptables (¿el panel no corre como root?).'];
        }
        $trusted = self::trustedSources();
        $listening = self::listening();

        // ── Política y reglas amplias ──
        $pol = $v4['policies']['INPUT'] ?? 'ACCEPT';
        if ($pol === 'ACCEPT' && !self::hasCatchAllDrop($v4, 'INPUT')) {
            $findings[] = ['level' => 'critical', 'text' => 'La política de INPUT (IPv4) es ACCEPT y no hay regla final que bloquee: todo lo que no esté prohibido explícitamente entra.'];
        }
        foreach ($v4['rules']['INPUT'] ?? [] as $r) {
            if ($r['target'] === 'ACCEPT' && $r['src'] === null && $r['in'] === null && $r['dports'] === null && $r['proto'] === null && !$r['established_only'] && !$r['unknown']) {
                $findings[] = ['level' => 'critical', 'text' => "Regla que acepta TODO sin limitar interfaz ni origen: {$r['raw']}"];
            }
        }

        // ── Puertos en escucha: ¿quién llega? ──
        $ports = [];
        foreach ($listening as $l) {
            if (!$l['public_bind']) {
                continue; // solo en localhost o en una IP privada/VPN
            }
            $key = "{$l['port']}/{$l['proto']}";
            if (isset($ports[$key])) {
                continue;
            }
            $anyone = self::evaluate($v4, 'INPUT', ['if' => $pubIf, 'proto' => $l['proto'], 'port' => $l['port'], 'src' => null]);
            $from = [];
            foreach ($v4['sources'] as $src) {
                $v = self::evaluate($v4, 'INPUT', ['if' => $pubIf, 'proto' => $l['proto'], 'port' => $l['port'], 'src' => $src]);
                if ($v['verdict'] === 'ACCEPT') {
                    $from[] = $src;
                }
            }
            $state = $anyone['verdict'] === 'ACCEPT' ? 'abierto a todo internet' : ($from ? 'solo desde: ' . implode(', ', $from) : 'cerrado');
            $ports[$key] = ['port' => $l['port'], 'proto' => $l['proto'], 'process' => $l['process'], 'state' => $state,
                'conditional' => $anyone['conditional'] ?? false];
            if ($anyone['verdict'] === 'ACCEPT') {
                if (isset(self::SENSITIVE[$l['port']])) {
                    $findings[] = ['level' => 'critical', 'text' => self::SENSITIVE[$l['port']] . " ({$l['port']}/{$l['proto']}, {$l['process']}) está ABIERTO a todo internet."];
                } elseif ($l['port'] === 22) {
                    $findings[] = ['level' => 'warning', 'text' => 'SSH (22) está abierto a todo internet: mejor limitarlo a IPs de confianza o a la VPN.'];
                } elseif (!in_array($l['port'], self::PUBLIC_OK, true)) {
                    $findings[] = ['level' => 'warning', 'text' => "El puerto {$l['port']}/{$l['proto']} ({$l['process']}) está abierto a todo internet y no es de los habituales."];
                }
            }
        }

        // ── Orígenes con acceso a TODOS los puertos ──
        foreach ($v4['rules']['INPUT'] ?? [] as $r) {
            // Sin puertos = todos (aunque sea solo TCP o solo UDP).
            if ($r['target'] === 'ACCEPT' && $r['src'] !== null && !$r['src_neg'] && $r['dports'] === null && !$r['established_only']) {
                if (!self::isTrusted($r['src'], $trusted)) {
                    $findings[] = ['level' => 'warning', 'text' => "El origen {$r['src']} puede entrar a TODOS los puertos y no está en la lista de confianza (nodos del cluster, ALLOWED_IPS, VPN, firewall_trusted_sources)."];
                }
            }
        }

        // ── IPv6 ──
        if ($hasV6) {
            $pol6 = $v6['policies']['INPUT'] ?? 'ACCEPT';
            $open6 = [];
            foreach ($listening as $l) {
                if (!$l['public_bind'] || !$l['v6']) {
                    continue;
                }
                $v = self::evaluate($v6, 'INPUT', ['if' => $pubIf, 'proto' => $l['proto'], 'port' => $l['port'], 'src' => null]);
                if ($v['verdict'] === 'ACCEPT' && !in_array($l['port'], self::PUBLIC_OK, true)) {
                    $open6[] = "{$l['port']}/{$l['proto']} ({$l['process']})";
                }
            }
            if ($open6) {
                $findings[] = ['level' => 'critical', 'text' => 'Por IPv6 están abiertos a todo internet: ' . implode(', ', array_unique($open6)) . ". Política INPUT de ip6tables: {$pol6}."];
            }
        }

        // ── Docker ──
        $dockerPub = self::lines(self::sh("docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -E '0\\.0\\.0\\.0:|\\[::\\]:'"));
        if ($dockerPub) {
            $hasUserRules = count($v4['rules']['DOCKER-USER'] ?? []) > 1;
            $findings[] = ['level' => $hasUserRules ? 'info' : 'warning', 'text' => 'Docker publica puertos en todas las interfaces (' . implode('; ', $dockerPub) . '). Esos puertos NO pasan por INPUT: solo los filtra la cadena DOCKER-USER'
                . ($hasUserRules ? ' (tiene reglas: revísalas).' : ', que está vacía.')];
        }

        // ── ufw / nftables ──
        if (stripos($ufw, 'active') !== false && stripos($ufw, 'inactive') === false) {
            $findings[] = ['level' => 'warning', 'text' => 'ufw está ACTIVO junto a reglas iptables propias: mezclar los dos puede dejar reglas que no hacen lo que parece.'];
        }

        $crit = count(array_filter($findings, static fn($f) => $f['level'] === 'critical'));
        $warn = count(array_filter($findings, static fn($f) => $f['level'] === 'warning'));
        return [
            'ok' => true,
            'summary' => $crit ? "{$crit} problema(s) crítico(s)" : ($warn ? "{$warn} aviso(s)" : 'Protegido: nada expuesto que no deba'),
            'critical' => $crit,
            'warnings' => $warn,
            'public_interface' => $pubIf,
            'input_policy' => ['ipv4' => $pol, 'ipv6' => $hasV6 ? ($v6['policies']['INPUT'] ?? 'ACCEPT') : 'sin IPv6 pública'],
            'ufw' => $ufw !== '' ? $ufw : 'no instalado',
            'findings' => $findings,
            'ports' => array_values($ports),
            'trusted_sources' => $trusted,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** Auditoría guardada para el dashboard (la hace el cluster-worker cada 15 min). */
    public static function refreshStored(): array
    {
        $a = self::audit();
        if (!empty($a['ok'])) {
            Settings::set('firewall_audit_last', json_encode([
                'at' => $a['checked_at'], 'critical' => $a['critical'], 'warnings' => $a['warnings'],
                'summary' => $a['summary'],
                'top' => array_slice(array_map(static fn($f) => $f['text'], array_filter($a['findings'], static fn($f) => $f['level'] !== 'info')), 0, 5),
            ], JSON_UNESCAPED_UNICODE));
        }
        return $a;
    }

    // ── Utilidades ───────────────────────────────────────────────────────

    private static function sh(string $cmd): string
    {
        return trim((string)shell_exec('timeout 8 sh -c ' . escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function lines(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $s)), static fn($l) => $l !== ''));
    }

    /** Orígenes de confianza: nodos del cluster, servidores de failover, ALLOWED_IPS, VPN y la lista manual. */
    public static function trustedSources(): array
    {
        $t = ['10.10.70.0/24'];
        foreach (ClusterService::getNodes() as $n) {
            $h = parse_url((string)$n['api_url'], PHP_URL_HOST);
            if ($h) {
                $t[] = filter_var($h, FILTER_VALIDATE_IP) ? $h : (gethostbyname($h) ?: $h);
            }
        }
        foreach (FailoverService::getServers() as $s) {
            if (!empty($s['ip'])) {
                $t[] = $s['ip'];
            }
        }
        foreach (preg_split('/[\s,]+/', (string)\MuseDockPanel\Env::get('ALLOWED_IPS', '')) ?: [] as $ip) {
            $t[] = $ip;
        }
        foreach (preg_split('/[\s,]+/', Settings::get('firewall_trusted_sources', '')) ?: [] as $ip) {
            $t[] = $ip;
        }
        return array_values(array_unique(array_filter(array_map('trim', $t))));
    }

    private static function isTrusted(string $src, array $trusted): bool
    {
        foreach ($trusted as $t) {
            if ($t === $src || self::cidrContains($t, $src)) {
                return true;
            }
        }
        return false;
    }

    /** ¿El rango $outer contiene la IP/rango $inner? (IPv4) */
    public static function cidrContains(string $outer, string $inner): bool
    {
        [$oNet, $oBits] = array_pad(explode('/', $outer, 2), 2, '32');
        [$iNet, $iBits] = array_pad(explode('/', $inner, 2), 2, '32');
        if (!filter_var($oNet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($iNet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $outer === $inner;
        }
        $oBits = (int)$oBits;
        $iBits = (int)$iBits;
        if ($iBits < $oBits) {
            return false;
        }
        $mask = $oBits === 0 ? 0 : (~0 << (32 - $oBits)) & 0xFFFFFFFF;
        return (ip2long($oNet) & $mask) === (ip2long($iNet) & $mask);
    }

    /** Puertos en escucha (TCP y UDP) con si están en una dirección accesible desde fuera. */
    private static function listening(): array
    {
        $out = [];
        foreach (['tcp' => 'ss -ltnpH', 'udp' => 'ss -lunpH'] as $proto => $cmd) {
            foreach (self::lines(self::sh($cmd)) as $l) {
                $c = preg_split('/\s+/', $l);
                if (!preg_match('/^(.*):(\d+)$/', $c[3] ?? '', $m)) {
                    continue;
                }
                $addr = preg_replace('/%.*$/', '', trim($m[1], '[]'));
                $v6 = str_contains($addr, ':');
                $wild = in_array($addr, ['0.0.0.0', '*', '::', ''], true);
                $public = $wild || filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                preg_match_all('/\("([^"]+)"/', $l, $pm);
                $proc = '(desconocido)';
                foreach ($pm[1] ?? [] as $p) {
                    if (!in_array($p, ['ss', 'sh', 'timeout'], true)) {
                        $proc = $p;
                        break;
                    }
                }
                // WireGuard del kernel escucha sin proceso de usuario.
                if ($proc === '(desconocido)' && $proto === 'udp' && self::sh('wg show all listen-port 2>/dev/null | grep -wc ' . (int)$m[2]) > 0) {
                    $proc = 'WireGuard';
                }
                $out[] = ['port' => (int)$m[2], 'proto' => $proto, 'process' => $proc, 'public_bind' => $public, 'v6' => $v6 || $addr === '*'];
            }
        }
        return $out;
    }

    // ── Parser y simulador de iptables ───────────────────────────────────

    /** @return array{policies:array, rules:array, sources:array} */
    public static function parse(array $lines): array
    {
        $pol = [];
        $rules = [];
        $sources = [];
        foreach ($lines as $line) {
            if (preg_match('/^-P (\S+) (\S+)/', $line, $m)) {
                $pol[$m[1]] = $m[2];
                continue;
            }
            if (!preg_match('/^-A (\S+)\s*(.*)$/', $line, $m)) {
                continue;
            }
            $chain = $m[1];
            $tok = str_getcsv($m[2], ' ', '"', '\\');
            $r = ['raw' => $line, 'proto' => null, 'in' => null, 'in_neg' => false, 'src' => null, 'src_neg' => false,
                'dports' => null, 'target' => null, 'established_only' => false, 'unknown' => false, 'set' => null];
            for ($i = 0; $i < count($tok); $i++) {
                $t = $tok[$i];
                $neg = false;
                if ($t === '!') {
                    $neg = true;
                    $t = $tok[++$i] ?? '';
                }
                switch ($t) {
                    case '-p': $r['proto'] = strtolower($tok[++$i] ?? ''); break;
                    case '-i': $r['in'] = $tok[++$i] ?? ''; $r['in_neg'] = $neg; break;
                    case '-s': $r['src'] = $tok[++$i] ?? ''; $r['src_neg'] = $neg; break;
                    case '-d': ++$i; break;
                    case '-o': ++$i; break;
                    case '--dport': case '--dports':
                        $r['dports'] = explode(',', $tok[++$i] ?? ''); break;
                    case '--state': case '--ctstate':
                        $st = strtoupper($tok[++$i] ?? '');
                        $r['established_only'] = !str_contains($st, 'NEW') && (str_contains($st, 'ESTABLISHED') || str_contains($st, 'RELATED') || str_contains($st, 'INVALID'));
                        break;
                    case '--match-set':
                        $r['set'] = ($tok[++$i] ?? '') . ' ' . ($tok[++$i] ?? '');
                        break;
                    case '-j': case '-g': $r['target'] = $tok[++$i] ?? ''; break;
                    case '-m':
                        $mod = $tok[++$i] ?? '';
                        if (!in_array($mod, ['state', 'conntrack', 'tcp', 'udp', 'multiport', 'comment', 'set', 'icmp', 'icmp6', 'addrtype'], true)) {
                            $r['unknown'] = true; // limit, recent, hashlimit... → condición que no simulamos
                        }
                        break;
                    case '--comment': ++$i; break;
                    case '--sport': case '--sports': case '--icmp-type': case '--icmpv6-type': case '--dst-type': case '--src-type': case '--limit': case '--limit-burst': ++$i; break;
                    default: break;
                }
            }
            if ($r['src'] === '0.0.0.0/0' || $r['src'] === '::/0') {
                $r['src'] = null;
            }
            $rules[$chain][] = $r;
            if ($r['src'] !== null && !$r['src_neg'] && $r['target'] === 'ACCEPT') {
                $sources[$r['src']] = true;
            }
        }
        return ['policies' => $pol, 'rules' => $rules, 'sources' => array_keys($sources)];
    }

    private static function hasCatchAllDrop(array $fw, string $chain): bool
    {
        foreach ($fw['rules'][$chain] ?? [] as $r) {
            if (in_array($r['target'], ['DROP', 'REJECT'], true) && $r['src'] === null && $r['in'] === null && $r['dports'] === null && $r['proto'] === null && !$r['unknown']) {
                return true;
            }
        }
        return false;
    }

    private static function portMatches(?array $dports, int $port): bool
    {
        if ($dports === null) {
            return true;
        }
        foreach ($dports as $d) {
            if (str_contains($d, ':')) {
                [$a, $b] = array_map('intval', explode(':', $d, 2));
                if ($port >= $a && $port <= ($b ?: 65535)) {
                    return true;
                }
            } elseif ((int)$d === $port) {
                return true;
            }
        }
        return false;
    }

    /**
     * Veredicto para un paquete NUEVO. $pkt = [if, proto, port, src (null = IP cualquiera)].
     * @return array{verdict:string, by:?string, conditional?:bool}
     */
    public static function evaluate(array $fw, string $chain, array $pkt, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['verdict' => 'RETURN', 'by' => null];
        }
        $conditional = false;
        foreach ($fw['rules'][$chain] ?? [] as $r) {
            if ($r['established_only']) {
                continue;
            }
            if ($r['proto'] !== null && $r['proto'] !== 'all' && $r['proto'] !== $pkt['proto']) {
                continue;
            }
            if ($r['in'] !== null) {
                $ifMatch = $pkt['if'] !== '' && ($r['in'] === $pkt['if'] || (str_ends_with($r['in'], '+') && str_starts_with($pkt['if'], rtrim($r['in'], '+'))));
                if ($ifMatch === $r['in_neg']) {
                    continue;
                }
            }
            if ($r['src'] !== null) {
                $srcMatch = $pkt['src'] !== null && self::cidrContains($r['src'], $pkt['src']);
                if ($srcMatch === $r['src_neg']) {
                    continue;
                }
            }
            if ($r['set'] !== null && $pkt['src'] === null) {
                // Lista ipset: para una IP cualquiera no se sabe; un ACCEPT por lista no
                // abre a todo internet y un DROP por lista no cierra a todo internet.
                continue;
            }
            if (!self::portMatches($r['dports'], $pkt['port'])) {
                continue;
            }
            $t = (string)$r['target'];
            if ($r['unknown']) {
                if ($t !== 'ACCEPT') {
                    continue; // un bloqueo condicional no garantiza el cierre
                }
                $conditional = true; // un ACCEPT condicional (limit, recent...) se cuenta como abierto
            }
            if (in_array($t, ['ACCEPT', 'DROP', 'REJECT'], true)) {
                return ['verdict' => $t === 'ACCEPT' ? 'ACCEPT' : 'DROP', 'by' => $r['raw'], 'conditional' => $conditional];
            }
            if ($t === 'RETURN') {
                return ['verdict' => 'RETURN', 'by' => $r['raw']];
            }
            if ($t !== '' && isset($fw['rules'][$t])) {
                $sub = self::evaluate($fw, $t, $pkt, $depth + 1);
                if ($sub['verdict'] !== 'RETURN') {
                    return $sub;
                }
            }
            // LOG u otros objetivos que no deciden: se sigue.
        }
        if ($depth > 0) {
            return ['verdict' => 'RETURN', 'by' => null];
        }
        $p = $fw['policies'][$chain] ?? 'ACCEPT';
        return ['verdict' => $p === 'ACCEPT' ? 'ACCEPT' : 'DROP', 'by' => "política {$chain} {$p}"];
    }
}
