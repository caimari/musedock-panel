<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;

/**
 * Reglas de los avisos (Ajustes → Avisos, MCP alerts_*): qué tipos se silencian,
 * controles de hardening dados por buenos, umbral propio o silencio por disco y
 * servidor, y cuánto debe durar un fallo del nodo de correo antes de avisar.
 *
 * Se configura en el master y se copia a sus nodos (acción de cluster
 * set-alert-policy, sin secretos). Silenciar solo quita el correo/Telegram: el aviso
 * sigue quedando en el monitor del panel.
 */
class AlertPolicyService
{
    /** Tipos que se pueden silenciar. Los del relevo (failover, cambio de rol) no: siempre avisan. */
    public const TYPES = [
        'CPU_HIGH'           => ['CPU alta', 'CPU por encima del umbral un rato seguido.'],
        'RAM_HIGH'           => ['RAM alta', 'Memoria por encima del umbral un rato seguido.'],
        'DISK_HIGH'          => ['Disco lleno', 'Un disco por encima del umbral. Mejor ajustar por disco abajo que silenciarlo entero.'],
        'NET_HIGH'           => ['Tráfico alto', 'Entrada de red por encima del umbral.'],
        'GPU_TEMP'           => ['Temperatura de GPU', 'GPU por encima de la temperatura máxima.'],
        'GPU_HIGH'           => ['Uso de GPU', 'GPU al máximo un rato seguido.'],
        'security_hardening' => ['Hardening degradado', 'Controles de seguridad del servidor fuera de lo recomendado. Mejor aceptar los controles que quieras así.'],
        'config_drift'       => ['Cambios en ficheros críticos', 'sshd_config, sudoers, etc. cambiados fuera del panel.'],
        'firewall_change'    => ['Cambio en el firewall', 'Reglas de ufw/iptables cambiadas fuera del panel.'],
        'public_exposure'    => ['Puerto expuesto', 'Un servicio sensible escucha abierto a internet.'],
        'login_anomaly'      => ['Acceso raro al panel', 'Entrada al panel desde un país o red poco habitual.'],
        'mail_node'          => ['Nodo de correo con problemas', 'Servicios de correo de un nodo sin responder (avisa solo si dura, ver abajo).'],
        'mail_queue'         => ['Cola de correo pausada', 'Altas/cambios de correo hacia un nodo llevan más de 24 h en pausa (el nodo no las recibe).'],
        'replication'        => ['Réplica con problemas', 'PostgreSQL, MariaDB o Redis de este nodo no replican bien (o se recuperan). Mejor no silenciarlo: un relevo podría perder datos.'],
        'lsyncd'             => ['Copia de ficheros (lsyncd)', 'La copia de ficheros a los nodos falla o se recupera.'],
        'config_mirror'      => ['Copia de configuración del master', 'En una copia: algo de la configuración del master no se pudo copiar (avisa solo cuando cambia la lista).'],
        'witness'            => ['Testigos', 'Un testigo externo no responde o vuelve.'],
    ];

    private static function json(string $key, $default)
    {
        $v = json_decode((string)Settings::get($key, ''), true);
        return is_array($v) ? $v : $default;
    }

    /** ¿Ese tipo está silenciado en este servidor? */
    public static function muted(string $type): bool
    {
        return $type !== '' && in_array($type, self::json('alerts_muted', []), true);
    }

    /** Títulos de controles de hardening dados por buenos. */
    public static function hardeningAccepted(): array
    {
        return array_values(array_map('strval', self::json('alerts_hardening_accepted', [])));
    }

    /** Nombre corto de este servidor para las reglas por servidor (p. ej. "nitro"). */
    public static function shortHost(?string $host = null): string
    {
        return strtolower(explode('.', $host ?? (gethostname() ?: 'localhost'))[0]);
    }

    /**
     * Umbral del disco montado en $mount de este servidor: el propio si lo tiene
     * (0 = silenciado) o $default. Reglas: {"nitro": {"/workspace": 99}, "*": {...}}.
     */
    public static function diskThreshold(string $mount, float $default): float
    {
        $rules = self::json('alerts_disk_overrides', []);
        foreach ([self::shortHost(), '*'] as $h) {
            if (isset($rules[$h][$mount]) && is_numeric($rules[$h][$mount])) {
                return (float)$rules[$h][$mount];
            }
        }
        return $default;
    }

    /** Minutos seguidos que debe fallar un nodo de correo antes de avisar. */
    public static function mailNodeAfterMinutes(): int
    {
        return max(1, min(120, (int)Settings::get('alerts_mail_node_after_minutes', '5')));
    }

    public static function export(): array
    {
        return [
            'muted' => self::json('alerts_muted', []),
            'hardening_accepted' => self::hardeningAccepted(),
            'disk_overrides' => self::json('alerts_disk_overrides', []),
            'mail_node_after_minutes' => self::mailNodeAfterMinutes(),
        ];
    }

    /** Guarda las reglas (validadas). Lo que no venga se deja como está. */
    public static function save(array $p): array
    {
        if (array_key_exists('muted', $p)) {
            $m = array_values(array_intersect(array_map('strval', (array)$p['muted']), array_keys(self::TYPES)));
            Settings::set('alerts_muted', json_encode($m));
        }
        if (array_key_exists('hardening_accepted', $p)) {
            $a = array_values(array_unique(array_filter(array_map(static fn($t) => trim((string)$t), (array)$p['hardening_accepted']))));
            Settings::set('alerts_hardening_accepted', json_encode($a, JSON_UNESCAPED_UNICODE));
        }
        if (array_key_exists('disk_overrides', $p)) {
            $out = [];
            foreach ((array)$p['disk_overrides'] as $host => $mounts) {
                $host = strtolower(trim((string)$host));
                if ($host === '' || !preg_match('/^[a-z0-9*][a-z0-9.-]*$/', $host)) {
                    continue;
                }
                foreach ((array)$mounts as $mount => $thr) {
                    $mount = trim((string)$mount);
                    if ($mount !== '' && $mount[0] === '/' && is_numeric($thr) && $thr >= 0 && $thr <= 100) {
                        $out[self::shortHost($host)][$mount] = (float)$thr;
                    }
                }
            }
            Settings::set('alerts_disk_overrides', json_encode($out, JSON_UNESCAPED_SLASHES));
        }
        if (array_key_exists('mail_node_after_minutes', $p)) {
            Settings::set('alerts_mail_node_after_minutes', (string)max(1, min(120, (int)$p['mail_node_after_minutes'])));
        }
        return self::export();
    }

    /** Acción de cluster set-alert-policy (en el nodo). */
    public static function import(array $p): array
    {
        $r = self::save($p);
        LogService::log('cluster.notify', 'alerts', 'Reglas de avisos recibidas del master');
        return ['ok' => true, 'policy' => $r];
    }

    /** En el master: copia las reglas a todos sus nodos. Devuelve el resultado por nodo. */
    public static function pushToNodes(): array
    {
        $out = [];
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            return $out;
        }
        foreach (ClusterService::getNodes() as $n) {
            $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'set-alert-policy', 'payload' => self::export()]);
            $out[(string)$n['name']] = !empty($r['ok']) && !empty($r['data']['ok']) ? 'copiadas' : 'ERROR: ' . ($r['data']['error'] ?? $r['error'] ?? '?');
        }
        return $out;
    }
}
