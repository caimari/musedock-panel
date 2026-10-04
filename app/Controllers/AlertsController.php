<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\AlertPolicyService;
use MuseDockPanel\Services\ClusterService;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Services\SecurityService;
use MuseDockPanel\Settings;
use MuseDockPanel\View;

/**
 * Ajustes → Avisos: silenciar tipos de aviso, dar por buenos controles de hardening,
 * umbral propio o silencio por disco y servidor, y cuánto debe durar un fallo del nodo
 * de correo antes de avisar. Se guarda en el master y se copia a sus nodos.
 */
class AlertsController
{
    public function index(): void
    {
        $failed = [];
        try {
            foreach ((array)(SecurityService::getHardeningAudit()['checks'] ?? []) as $c) {
                if (empty($c['ok'])) {
                    $failed[] = ['title' => (string)($c['title'] ?? ''), 'current' => (string)($c['current'] ?? ''), 'recommended' => (string)($c['recommended'] ?? '')];
                }
            }
        } catch (\Throwable) {
        }
        $disks = [];
        foreach (preg_split('/\n/', trim((string)shell_exec("df -P -x tmpfs -x devtmpfs -x overlay -x squashfs 2>/dev/null | awk 'NR>1{print $6\" \"$5}'"))) as $l) {
            if (preg_match('#^(/\S*) (\d+)%$#', trim($l), $m)) {
                $disks[$m[1]] = (int)$m[2];
            }
        }
        $hosts = [AlertPolicyService::shortHost()];
        foreach (ClusterService::getNodes() as $n) {
            $hosts[] = strtolower(preg_replace('/[^a-z0-9-].*$/i', '', (string)$n['name']));
        }
        View::render('settings/alerts', [
            'layout' => 'main',
            'pageTitle' => 'Avisos',
            'policy' => AlertPolicyService::export(),
            'types' => AlertPolicyService::TYPES,
            'failedHardening' => $failed,
            'localDisks' => $disks,
            'thisHost' => AlertPolicyService::shortHost(),
            'hostHints' => array_values(array_unique(array_filter(array_merge($hosts, ['*'])))),
            'diskDefault' => (float)Settings::get('monitor_alert_disk', '90'),
            'isSlave' => Settings::get('cluster_role', 'standalone') === 'slave',
            'masterUrl' => self::masterUrl(),
        ]);
    }

    /** URL del panel del master (para el enlace desde una copia), si está registrado. */
    private static function masterUrl(): string
    {
        foreach (ClusterService::getNodes() as $n) {
            if (($n['role'] ?? '') === 'master') {
                $u = parse_url((string)$n['api_url']);
                // Mejor el nombre del panel del master (certificado válido) que la IP de la VPN.
                try {
                    $st = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
                    $host = (string)($st['data']['state']['panel_hostname'] ?? '');
                } catch (\Throwable) {
                    $host = '';
                }
                return 'https://' . ($host !== '' ? $host : (string)($u['host'] ?? '')) . ':' . (int)($u['port'] ?? 8444);
            }
        }
        return '';
    }

    public function save(): void
    {
        View::verifyCsrf();
        $disk = [];
        foreach ((array)($_POST['disk_host'] ?? []) as $i => $h) {
            $mount = trim((string)($_POST['disk_mount'][$i] ?? ''));
            $mode = (string)($_POST['disk_mode'][$i] ?? 'threshold');
            $thr = $mode === 'mute' ? 0 : (float)($_POST['disk_threshold'][$i] ?? 0);
            if (trim((string)$h) !== '' && $mount !== '' && ($mode === 'mute' || $thr > 0)) {
                $disk[trim((string)$h)][$mount] = $thr;
            }
        }
        $muted = (array)($_POST['muted'] ?? []);
        foreach ((array)($_POST['mute_host'] ?? []) as $i => $h) {
            $t = (string)($_POST['mute_type'][$i] ?? '');
            if (trim((string)$h) !== '' && $t !== '') {
                $muted[] = strtolower(trim((string)$h)) . ':' . $t;
            }
        }
        $policy = [
            'muted' => $muted,
            'hardening_accepted' => array_merge((array)($_POST['accepted'] ?? []),
                array_filter(array_map('trim', explode("\n", (string)($_POST['accepted_extra'] ?? ''))))),
            'disk_overrides' => $disk,
            'mail_node_after_minutes' => (int)($_POST['mail_node_after_minutes'] ?? 5),
        ];
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            // Desde una copia: lo guarda el master y lo reparte a todos (también aquí).
            $r = AlertPolicyService::saveViaMaster($policy);
            Flash::set(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
                ? 'Avisos guardados en el master y copiados a los nodos: ' . implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($r['copied']), $r['copied'])) . '.'
                : $r['error']);
            Router::redirect('/settings/alerts');
            return;
        }
        $p = AlertPolicyService::save($policy);
        $copied = AlertPolicyService::pushToNodes();
        LogService::log('alerts.policy', 'save', 'Silenciados: ' . (implode(', ', $p['muted']) ?: 'ninguno')
            . '; hardening aceptado: ' . count($p['hardening_accepted']) . '; reglas de disco: ' . count($p['disk_overrides']));
        $bad = array_filter($copied, static fn($v) => !str_starts_with($v, 'copiadas'));
        Flash::set($bad ? 'warning' : 'success', 'Avisos guardados' . ($copied ? '; nodos: ' . implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($copied), $copied)) : '') . '.');
        Router::redirect('/settings/alerts');
    }
}
