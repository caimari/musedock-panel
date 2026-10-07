<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Caída de un SITIO entero (oficina sin Internet) que no afecta a las webs.
 *
 * Si el que manda está en otro sitio (p. ej. un VPS) y la copia se queda sin conexión
 * junto con todo su sitio (el router, la otra línea, sus vecinos), las webs siguen
 * funcionando: no tiene sentido recibir una ráfaga de avisos de nodo caído, réplica,
 * copia de ficheros, correo… de esas máquinas. En ese caso se manda UN aviso informativo
 * (sin sonido en Telegram) y otro al volver; los avisos de esos nodos se apuntan en el
 * registro pero no se envían.
 *
 * Solo se callan cuando:
 *  - este servidor manda y tiene Internet (si no, las webs sí están afectadas: aviso normal);
 *  - el nodo no responde Y no responde ninguno de sus vecinos (Failover → Vecinos, o la
 *    lista general). Si sus vecinos responden, es esa máquina la que ha caído: aviso normal.
 *    Un nodo sin vecinos configurados se considera del mismo corte solo si dejó de
 *    responder a la vez (±10 min) que otro cuyo sitio sí se ha visto caído;
 *  - el aviso es de un tipo de "algo no responde" y nombra a uno de esos nodos.
 *
 * En la copia que se quedó sin Internet (no manda), mientras no tiene conexión y unos
 * minutos después de volver, tampoco se envían esos avisos: los de recuperación de la
 * réplica, la copia de ficheros… son consecuencia del mismo corte.
 */
final class SiteOutageService
{
    /** Tipos de aviso que se callan (los de "algo no responde" entre nodos). */
    public const TYPES = ['node_down', 'replication', 'lsyncd', 'mail_node', 'mail_queue', 'witness', 'config_mirror'];

    private const SETTING = 'site_outage_state';
    /** Tras volver, los avisos de recuperación (réplica, ficheros…) aún son del mismo corte. */
    private const TAIL = 600;

    public static function state(): array
    {
        $s = json_decode((string)Settings::get(self::SETTING, ''), true);
        return (is_array($s) ? $s : []) + ['nodes' => [], 'since' => 0, 'local_offline_since' => 0, 'quiet_until' => 0, 'tail_nodes' => [], 'tail_all' => false];
    }

    private static function save(array $s): void
    {
        Settings::set(self::SETTING, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** ¿Se calla este aviso? (NotificationService::send) */
    public static function quiet(string $type, string $text): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }
        $s = self::state();
        $tail = (int)$s['quiet_until'] > time();
        // Este servidor (que no manda) se quedó sin Internet, o acaba de volver.
        if (!empty($s['local_offline_since']) || ($tail && !empty($s['tail_all']))) {
            return true;
        }
        // Otros nodos con su sitio caído (o que acaban de volver): solo los avisos que hablan de ellos.
        foreach (array_merge(array_values($s['nodes']), $tail ? array_values($s['tail_nodes'] ?? []) : []) as $n) {
            foreach (self::aliases($n) as $a) {
                if ($a !== '' && stripos($text, $a) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Cómo puede aparecer un nodo en un aviso: «Filemon (154)», «Filemon», su IP de VPN. */
    private static function aliases(array $n): array
    {
        $name = (string)($n['name'] ?? '');
        return array_unique(array_filter([$name, trim((string)preg_replace('/\s*\(.*\)\s*$/', '', $name)), (string)($n['vpn_ip'] ?? '')],
            static fn($x) => strlen($x) >= 3));
    }

    /** ¿Hay salida a Internet? Basta con que responda uno. */
    public static function hasInternet(): bool
    {
        foreach (['1.1.1.1', '8.8.8.8', 'api.cloudflare.com'] as $h) {
            $fp = @fsockopen($h, 443, $e, $s, 3);
            if ($fp) {
                fclose($fp);
                return true;
            }
        }
        return false;
    }

    private static function probesAlive(string $list): ?bool
    {
        $probes = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $list) ?: [])));
        $any = false;
        foreach ($probes as $p) {
            if (str_starts_with($p, 'ping:')) {
                exec('ping -c 2 -W 2 ' . escapeshellarg(substr($p, 5)) . ' >/dev/null 2>&1', $o, $rc);
                $ok = $rc === 0;
            } elseif (preg_match('/^\[?([^\]]+?)\]?:(\d+)$/', $p, $m)) {
                $fp = @fsockopen($m[1], (int)$m[2], $e, $s, 4);
                $ok = (bool)$fp;
                if ($fp) {
                    fclose($fp);
                }
            } else {
                continue;
            }
            $any = true;
            if ($ok) {
                return true;
            }
        }
        return $any ? false : null;   // null: no hay vecinos que mirar
    }

    /**
     * cluster-worker, cada pasada y antes de los demás avisos. $unreachable: nodos del
     * cluster sin latido (ClusterService::getUnreachableNodes).
     */
    public static function evaluate(array $unreachable): array
    {
        $s = self::state();
        $log = [];
        $now = time();
        $role = (string)Settings::get('cluster_role', 'standalone');
        $online = self::hasInternet();

        // Fin del silencio: lo que siga roto debe avisar YA, con sonido. Los vigilantes
        // apuntaron "avisado" aunque el aviso se callara; se les borra esa marca.
        if (!empty($s['rearm_at']) && $now >= (int)$s['rearm_at'] && empty($s['nodes']) && empty($s['local_offline_since'])) {
            $s['rearm_at'] = 0;
            self::rearm();
            $log[] = 'fin del silencio: lo que siga roto avisará de forma normal';
        }

        // ── Este servidor sin Internet (y no manda): callar lo que provoca el corte. ──
        if ($role !== 'master') {
            if (!$online) {
                if (empty($s['local_offline_since'])) {
                    $s['local_offline_since'] = $now;
                    LogService::log('notify.site-outage', 'local-offline', 'Sin Internet: los avisos de nodos/réplica quedan en el registro');
                }
                $log[] = 'sin Internet desde ' . date('H:i', (int)$s['local_offline_since']) . ': avisos de nodos/réplica en silencio';
            } elseif (!empty($s['local_offline_since'])) {
                $mins = (int)round(($now - (int)$s['local_offline_since']) / 60);
                $s['local_offline_since'] = 0;
                $s['quiet_until'] = $now + self::TAIL;
                $s['rearm_at'] = $s['quiet_until'];
                $s['tail_nodes'] = [];
                $s['tail_all'] = true;
                LogService::log('notify.site-outage', 'local-online', "Internet de vuelta tras {$mins} min");
                $log[] = "Internet de vuelta tras {$mins} min";
            }
            if ($s['nodes']) {
                $s['nodes'] = [];
                $s['since'] = 0;
            }
            self::save($s);
            return $log;
        }

        // ── Este servidor manda: ¿algún nodo caído con todo su sitio? ──
        $s['local_offline_since'] = 0;
        if (!$online) {
            // Sin Internet en el que manda: las webs sí están afectadas. No se calla nada
            // nuevo ni se da por terminado un corte que ya estaba en curso.
            self::save($s);
            return ['este servidor no tiene Internet: no se evalúan sitios caídos'];
        }
        $quiet = [];
        $fo = FailoverService::getServers();
        $global = (string)Settings::get('failover_site_probes', '');
        $noProbes = [];
        foreach ($unreachable as $node) {
            if (!empty($node['standby'])) {
                continue;
            }
            $vpnIp = (string)parse_url((string)($node['api_url'] ?? ''), PHP_URL_HOST);
            $probes = '';
            foreach ($fo as $f) {
                if ((string)($f['name'] ?? '') === (string)$node['name'] || ($vpnIp !== '' && (string)($f['ip'] ?? '') === $vpnIp)) {
                    $probes = trim((string)($f['site_probes'] ?? ''));
                    break;
                }
            }
            $entry = ['name' => (string)$node['name'], 'vpn_ip' => $vpnIp,
                'last_seen' => $node['last_seen_at'] ? (int)strtotime((string)$node['last_seen_at']) : 0];
            $alive = self::probesAlive($probes !== '' ? $probes : $global);
            if ($alive === false) {
                $quiet[(string)$node['id']] = $entry;
            } elseif ($alive === null) {
                $noProbes[(string)$node['id']] = $entry;
            }
        }
        // Sin vecinos propios: del mismo corte si dejó de responder a la vez que otro.
        foreach ($noProbes as $id => $e) {
            foreach ($quiet as $q) {
                if ($e['last_seen'] && $q['last_seen'] && abs($e['last_seen'] - $q['last_seen']) <= 600) {
                    $quiet[$id] = $e;
                    break;
                }
            }
        }

        $host = (string)(Settings::get('panel_hostname', '') ?: gethostname());
        if ($quiet && !$s['nodes']) {
            $names = implode(', ', array_column($quiet, 'name'));
            $s['since'] = $now;
            self::inform("Sin conexión con {$names} (su sitio no responde)",
                "{$names} y las máquinas de su sitio (sus vecinos) no responden: parece un corte de conexión de ese sitio.\n\n"
                . "Las webs NO están afectadas: siguen funcionando desde {$host}, que es el que manda.\n"
                . "Mientras dure no se envían los avisos de nodo caído, réplica, copia de ficheros ni correo de esas máquinas (quedan en el registro). "
                . "Te llegará un único aviso cuando vuelvan.");
            LogService::log('notify.site-outage', 'start', $names);
        } elseif (!$quiet && $s['nodes']) {
            // El sitio vuelve a responder. Un nodo que siga sin latido es que ha caído él:
            // fuera del silencio y su aviso de nodo caído, desde cero y con sonido.
            $downIds = array_map(static fn($n) => (string)$n['id'], $unreachable);
            $back = array_diff_key($s['nodes'], array_flip($downIds));
            $still = array_intersect_key($s['nodes'], array_flip($downIds));
            $mins = (int)round(($now - (int)$s['since']) / 60);
            $s['since'] = 0;
            $s['quiet_until'] = $now + self::TAIL;
            $s['rearm_at'] = $s['quiet_until'];
            $s['tail_nodes'] = $back;
            $s['tail_all'] = false;
            if ($still) {
                $alertState = json_decode((string)Settings::get('cluster_node_alert_state', '{}'), true) ?: [];
                foreach (array_keys($still) as $id) {
                    if (isset($alertState[$id])) {
                        $alertState[$id]['alert_count'] = 0;
                        $alertState[$id]['last_alert'] = 0;
                    }
                }
                Settings::set('cluster_node_alert_state', json_encode($alertState));
            }
            $names = implode(', ', array_column($back, 'name'));
            $stillNames = implode(', ', array_column($still, 'name'));
            $vuelve = count($back) > 1 ? 'vuelven' : 'vuelve';
            self::inform($back ? "{$names} {$vuelve} a responder" : "El sitio de {$stillNames} vuelve a responder",
                ($back ? "{$names} {$vuelve} a responder tras unos {$mins} min sin conexión. Las webs no se vieron afectadas.\n\n" : '')
                . ($still ? "OJO: {$stillNames} sigue sin responder aunque su sitio ya responde: es esa máquina. Llega su aviso normal de nodo caído.\n\n" : '')
                . "Las réplicas y la copia de ficheros se ponen al día solas; lo que siga roto pasados 10 min avisará de forma normal.");
            LogService::log('notify.site-outage', 'end', "vuelven: {$names}; siguen caídos: {$stillNames} ({$mins} min)");
        } elseif ($quiet && array_diff_key($quiet, $s['nodes'])) {
            LogService::log('notify.site-outage', 'grow', implode(', ', array_column(array_diff_key($quiet, $s['nodes']), 'name')));
        }
        if ($quiet) {
            $log[] = 'sitio sin conexión: ' . implode(', ', array_column($quiet, 'name')) . ' (sus avisos en silencio)';
        }
        $s['nodes'] = $quiet;
        self::save($s);
        return $log;
    }

    /**
     * Olvida las marcas de "ya avisado" de los vigilantes, para que lo que siga roto tras el
     * corte (réplica, buzones, lsyncd, nodo de correo, testigos, copia de configuración)
     * se avise en su siguiente pasada como algo nuevo. Lo que ya se arregló no avisa de
     * nada (tampoco de "recuperado": no llegó a avisarse de la caída).
     */
    private static function rearm(): void
    {
        $j = static fn(string $k): array => is_array($v = json_decode((string)Settings::get($k, ''), true)) ? $v : [];
        $rh = $j('replication_health_state');
        if ($rh) {
            $rh['notified'] = [];
            Settings::set('replication_health_state', json_encode($rh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $ls = $j('cluster_lsyncd_alert_state');
        if ($ls) {
            unset($ls['active'], $ls['last_alert']);
            Settings::set('cluster_lsyncd_alert_state', json_encode($ls));
        }
        $w = $j('witness_health');
        foreach ($w as $n => $st) {
            $w[$n]['alerted'] = false;
        }
        if ($w) {
            Settings::set('witness_health', json_encode($w));
        }
        foreach (\MuseDockPanel\Database::fetchAll("SELECT key FROM panel_settings WHERE key LIKE 'mail_node_health_%'") as $r) {
            $m = $j((string)$r['key']);
            if (!empty($m['alerted_at'])) {
                $m['alerted_at'] = 0;
                Settings::set((string)$r['key'], json_encode($m));
            }
        }
        Settings::set('cluster_config_mirror_alert_hash', '');
        LogService::log('notify.site-outage', 'rearm', 'Fin del silencio: los avisos que sigan pendientes se enviarán de forma normal');
    }

    /** Aviso informativo: por Telegram sin sonido si está activado; si no, por correo. */
    private static function inform(string $subject, string $msg): void
    {
        if (AlertPolicyService::muted('site_outage')) {
            return;
        }
        $subject = NotificationService::tagSubject($subject);
        if (Settings::get('monitor_notify_telegram', '0') === '1') {
            if (NotificationService::sendTelegram("{$subject}\n\n{$msg}", null, null, $err, true)) {
                return;
            }
        }
        if (Settings::get('monitor_notify_email', '0') === '1') {
            NotificationService::sendEmail($subject, $msg . AlertPolicyService::emailFooter('site_outage'));
        }
    }
}
