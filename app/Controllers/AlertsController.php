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
        ]);
    }

    public function save(): void
    {
        View::verifyCsrf();
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'En una copia no se cambian los avisos: se hace en el master y se copian aquí.');
            Router::redirect('/settings/alerts');
            return;
        }
        $disk = [];
        foreach ((array)($_POST['disk_host'] ?? []) as $i => $h) {
            $mount = trim((string)($_POST['disk_mount'][$i] ?? ''));
            $mode = (string)($_POST['disk_mode'][$i] ?? 'threshold');
            $thr = $mode === 'mute' ? 0 : (float)($_POST['disk_threshold'][$i] ?? 0);
            if (trim((string)$h) !== '' && $mount !== '' && ($mode === 'mute' || $thr > 0)) {
                $disk[trim((string)$h)][$mount] = $thr;
            }
        }
        $p = AlertPolicyService::save([
            'muted' => (array)($_POST['muted'] ?? []),
            'hardening_accepted' => array_merge((array)($_POST['accepted'] ?? []),
                array_filter(array_map('trim', explode("\n", (string)($_POST['accepted_extra'] ?? ''))))),
            'disk_overrides' => $disk,
            'mail_node_after_minutes' => (int)($_POST['mail_node_after_minutes'] ?? 5),
        ]);
        $copied = AlertPolicyService::pushToNodes();
        LogService::log('alerts.policy', 'save', 'Silenciados: ' . (implode(', ', $p['muted']) ?: 'ninguno')
            . '; hardening aceptado: ' . count($p['hardening_accepted']) . '; reglas de disco: ' . count($p['disk_overrides']));
        $bad = array_filter($copied, static fn($v) => !str_starts_with($v, 'copiadas'));
        Flash::set($bad ? 'warning' : 'success', 'Avisos guardados' . ($copied ? '; nodos: ' . implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($copied), $copied)) : '') . '.');
        Router::redirect('/settings/alerts');
    }
}
