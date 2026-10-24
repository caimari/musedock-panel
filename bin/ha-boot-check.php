<?php
// Strict, read-only: failure blocks protected consumers. No stop/promote/demote/DNS actions.
require dirname(__DIR__) . '/app/bootstrap.php';
use MuseDockPanel\Settings;
use MuseDockPanel\Services\{ClusterService, ClusterGuardService, PgClusterService, FailoverSafetyService, HaReadinessService};
try {
    $role = (string)Settings::get('cluster_role', '');
    $standby = true;
    $dataCount = 0;
    $panelPort = (int)MuseDockPanel\Env::get('DB_PORT', 5433);
    foreach (PgClusterService::listClusters() as $c) {
        if ((int)$c['port'] === $panelPort) continue;
        $dataCount++;
        $standby = $standby && is_file($c['data_dir'] . '/standby.signal');
    }
    if ($dataCount < 1) throw new RuntimeException('No se identifican clústeres de datos separados del panel.');
    $expected = 0;
    $states = [];
    if ($role === 'master') foreach (ClusterService::getNodes() as $n) {
        $meta = json_decode((string)($n['metadata'] ?? '{}'), true) ?: [];
        $host = (string)parse_url((string)$n['api_url'], PHP_URL_HOST);
        if (!empty($meta['witness']) || ($host !== '' && ClusterGuardService::hostIsSelf($host))) continue;
        $expected++;
        $r = ClusterService::callNode((int)$n['id'], 'POST', 'api/cluster/action', ['action' => 'query-local-state', 'payload' => []]);
        if (!empty($r['ok']) && is_array($r['data']['state'] ?? null)) $states[] = $r['data']['state'];
    }
    $result = HaReadinessService::bootVerdict($role, is_file(FailoverSafetyService::FENCE_FLAG)
        || Settings::get('cluster_fenced', '0') === '1', $expected, $states, $standby);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "HA boot check bloqueado: " . $e->getMessage() . "\n");
    exit(1);
}
