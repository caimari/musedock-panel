<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Ataques que fail2ban ha visto y bloqueado: para consultarlos (MCP security_attacks) y
 * para el informe diario de seguridad (por la noche, correo y Telegram).
 *
 * Lee el registro de fail2ban (/var/log/fail2ban.log y el rotado .1) y el estado actual
 * (fail2ban-client). Por jail: bloqueos, intentos fallidos ("Found"), IPs distintas, las
 * que más insisten y las bloqueadas ahora. Solo lectura.
 *
 * Informe diario: lo manda el servidor que manda (o uno solo), con sus datos y los de sus
 * nodos (acción de cluster fail2ban-report), para no recibir uno por servidor. Tipo de
 * aviso 'security_report' (activo por defecto; se silencia en Ajustes → Avisos).
 */
final class Fail2banReportService
{
    /** Hora (UTC) a partir de la cual se manda el informe del día. */
    private const REPORT_HOUR_UTC = 21;

    public static function installed(): bool
    {
        return trim((string)shell_exec('command -v fail2ban-client 2>/dev/null')) !== '';
    }

    /** Resumen de las últimas $hours horas de ESTE servidor. */
    public static function summary(int $hours = 24, int $top = 10): array
    {
        $hours = max(1, min(168, $hours));
        $host = (string)(Settings::get('panel_hostname', '') ?: gethostname());
        if (!self::installed()) {
            return ['host' => $host, 'installed' => false];
        }
        $since = time() - $hours * 3600;
        $jails = [];
        $ipBans = [];
        $ipFound = [];
        foreach (['/var/log/fail2ban.log.1', '/var/log/fail2ban.log'] as $f) {
            $fh = @fopen($f, 'r');
            if (!$fh) {
                continue;
            }
            while (($l = fgets($fh)) !== false) {
                if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\S*\s.*?\[([^\]]+)\]\s+(Ban|Found|Unban)\s+(\S+)/', $l, $m)) {
                    continue;
                }
                $ts = strtotime($m[1]);
                if ($ts === false || $ts < $since) {
                    continue;
                }
                [$jail, $what, $ip] = [$m[2], $m[3], $m[4]];
                $jails[$jail] ??= ['bans' => 0, 'attempts' => 0, 'ips' => []];
                if ($what === 'Ban') {
                    $jails[$jail]['bans']++;
                    $ipBans[$ip] = ($ipBans[$ip] ?? 0) + 1;
                } elseif ($what === 'Found') {
                    $jails[$jail]['attempts']++;
                    $ipFound[$ip] = ($ipFound[$ip] ?? 0) + 1;
                }
                if ($what !== 'Unban') {
                    $jails[$jail]['ips'][$ip] = ($jails[$jail]['ips'][$ip] ?? 0) + 1;
                }
            }
            fclose($fh);
        }

        // Bloqueadas ahora.
        $bannedNow = [];
        preg_match('/Jail list:\s*(.+)$/m', (string)shell_exec('fail2ban-client status 2>&1'), $jm);
        foreach (array_filter(array_map('trim', explode(',', $jm[1] ?? ''))) as $j) {
            preg_match('/Banned IP list:\s*(.*)$/m', (string)shell_exec('fail2ban-client status ' . escapeshellarg($j) . ' 2>&1'), $bm);
            $list = array_values(array_filter(preg_split('/\s+/', trim($bm[1] ?? '')) ?: []));
            if ($list) {
                $bannedNow[$j] = $list;
            }
            $jails[$j] ??= ['bans' => 0, 'attempts' => 0, 'ips' => []];
        }

        $out = [];
        $tot = ['bans' => 0, 'attempts' => 0, 'ips' => 0, 'banned_now' => 0];
        $allIps = [];
        foreach ($jails as $j => $d) {
            arsort($d['ips']);
            $out[$j] = ['bans' => $d['bans'], 'attempts' => $d['attempts'], 'distinct_ips' => count($d['ips']),
                'banned_now' => count($bannedNow[$j] ?? []), 'top_ips' => array_slice($d['ips'], 0, 5, true)];
            $tot['bans'] += $d['bans'];
            $tot['attempts'] += $d['attempts'];
            $tot['banned_now'] += count($bannedNow[$j] ?? []);
            $allIps += $d['ips'];
        }
        $tot['ips'] = count($allIps);
        ksort($out);
        $topIps = [];
        foreach (array_keys($allIps) as $ip) {
            $topIps[$ip] = ['bans' => $ipBans[$ip] ?? 0, 'attempts' => $ipFound[$ip] ?? 0];
        }
        uasort($topIps, static fn($a, $b) => [$b['bans'], $b['attempts']] <=> [$a['bans'], $a['attempts']]);
        return ['host' => $host, 'installed' => true, 'hours' => $hours, 'totals' => $tot, 'jails' => $out,
            'top_ips' => array_slice($topIps, 0, max(1, $top), true), 'banned_now' => $bannedNow];
    }

    /** Este servidor y, si manda, sus nodos (por la API del cluster). */
    public static function clusterSummaries(int $hours = 24): array
    {
        $all = [self::summary($hours)];
        if (Settings::get('cluster_role', 'standalone') === 'master') {
            foreach (ClusterService::getActiveNodes() as $n) {
                try {
                    $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'fail2ban-report', 'payload' => ['hours' => $hours]]);
                    $all[] = !empty($r['ok']) && is_array($r['data']['report'] ?? null)
                        ? $r['data']['report']
                        : ['host' => (string)$n['name'], 'error' => (string)($r['data']['error'] ?? $r['error'] ?? 'sin respuesta')];
                } catch (\Throwable $e) {
                    $all[] = ['host' => (string)$n['name'], 'error' => $e->getMessage()];
                }
            }
        }
        return $all;
    }

    /** Texto del informe (correo y Telegram). */
    public static function reportText(array $summaries): string
    {
        $lines = [];
        foreach ($summaries as $s) {
            $h = (string)($s['host'] ?? '?');
            if (!empty($s['error'])) {
                $lines[] = "■ {$h}: no se pudo consultar ({$s['error']})";
                continue;
            }
            if (empty($s['installed'])) {
                $lines[] = "■ {$h}: fail2ban no está instalado";
                continue;
            }
            $t = $s['totals'];
            $lines[] = "■ {$h}: {$t['bans']} bloqueos, {$t['attempts']} intentos fallidos de {$t['ips']} IPs; bloqueadas ahora: {$t['banned_now']}";
            foreach ($s['jails'] as $j => $d) {
                if ($d['bans'] || $d['attempts'] || $d['banned_now']) {
                    $lines[] = "   · {$j}: {$d['bans']} bloqueos, {$d['attempts']} intentos, {$d['distinct_ips']} IPs" . ($d['banned_now'] ? ", {$d['banned_now']} bloqueadas ahora" : '');
                }
            }
            $top = array_slice($s['top_ips'] ?? [], 0, 5, true);
            if ($top) {
                $lines[] = '   Las que más insisten: ' . implode(', ', array_map(static fn($ip, $v) => "{$ip} ({$v['bans']}b/{$v['attempts']}i)", array_keys($top), $top));
            }
        }
        return "Ataques vistos y bloqueados por fail2ban en las últimas 24 h:\n\n" . implode("\n", $lines)
            . "\n\nb = bloqueos, i = intentos fallidos. No hay que hacer nada: fail2ban ya bloqueó a quien insistía. "
            . "Si una IP propia aparece bloqueada, desbloquéala (MCP fail2ban_manage).";
    }

    /** cluster-worker: el informe del día, una vez, a partir de las 21:00 UTC. */
    public static function maybeSendDaily(): string
    {
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            return '';   // lo manda el que manda, con los datos de este
        }
        $today = gmdate('Y-m-d');
        if ((int)gmdate('G') < self::REPORT_HOUR_UTC || Settings::get('fail2ban_report_sent', '') === $today) {
            return '';
        }
        Settings::set('fail2ban_report_sent', $today);
        if (AlertPolicyService::muted('security_report')) {
            return '';
        }
        $sum = self::clusterSummaries(24);
        $bans = array_sum(array_map(static fn($s) => (int)($s['totals']['bans'] ?? 0), $sum));
        $tries = array_sum(array_map(static fn($s) => (int)($s['totals']['attempts'] ?? 0), $sum));
        NotificationService::send("Informe diario de seguridad: {$bans} bloqueos, {$tries} intentos", self::reportText($sum), 'security_report');
        return "informe diario de seguridad enviado ({$bans} bloqueos, {$tries} intentos)";
    }
}
