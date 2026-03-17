<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\MonitorService;

class MonitorController
{
    /**
     * GET /monitor — Main monitoring dashboard
     */
    public function index(): void
    {
        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $interfaces = MonitorService::getInterfaces();
        $status = MonitorService::getCurrentStatus($host);
        $healthScore = MonitorService::getHealthScore($host);
        $alertCount = MonitorService::getUnacknowledgedCount($host);

        $gpus = MonitorService::detectGpus();
        $panelTz = Settings::get('panel_timezone', 'UTC');

        View::render('monitor/index', [
            'layout'      => 'main',
            'pageTitle'   => 'Monitoring',
            'interfaces'  => $interfaces,
            'status'      => $status,
            'healthScore' => $healthScore,
            'alertCount'  => $alertCount,
            'host'        => $host,
            'gpus'        => $gpus,
            'panelTz'     => $panelTz,
        ]);
    }

    /**
     * GET /monitor/api/metrics — Chart data JSON
     */
    public function apiMetrics(): void
    {
        header('Content-Type: application/json');

        $host   = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $metric = $_GET['metric'] ?? 'net_eth0_rx';
        $range  = $_GET['range'] ?? '1h';

        // Validate range
        $allowed = ['1h', '6h', '24h', '7d', '30d', '1y'];
        if (!in_array($range, $allowed, true)) {
            $range = '1h';
        }

        // Sanitize metric name (alphanumeric + underscore only)
        $metric = preg_replace('/[^a-zA-Z0-9_]/', '', $metric);

        $data = MonitorService::getMetrics($host, $metric, $range);

        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    /**
     * GET /monitor/api/status — Current values for stat cards
     */
    public function apiStatus(): void
    {
        header('Content-Type: application/json');

        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $status = MonitorService::getCurrentStatus($host);
        $healthScore = MonitorService::getHealthScore($host);
        $alertCount = MonitorService::getUnacknowledgedCount($host);

        echo json_encode([
            'ok'          => true,
            'status'      => $status,
            'healthScore' => $healthScore,
            'alertCount'  => $alertCount,
        ]);
        exit;
    }

    /**
     * GET /monitor/api/alerts — Recent alerts JSON
     */
    public function apiAlerts(): void
    {
        header('Content-Type: application/json');

        $host = $_GET['host'] ?? (gethostname() ?: 'localhost');
        $alerts = MonitorService::getAlerts($host, 50);

        echo json_encode(['ok' => true, 'alerts' => $alerts]);
        exit;
    }

    /**
     * POST /monitor/api/alerts/ack — Acknowledge an alert
     */
    public function apiAckAlert(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            echo json_encode(['ok' => false, 'error' => 'Invalid alert ID']);
            exit;
        }

        $ok = MonitorService::acknowledgeAlert($id);
        echo json_encode(['ok' => $ok]);
        exit;
    }
}
