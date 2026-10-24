<?php
// Pure regression tests. No bootstrap, settings, network, database or service calls.
require dirname(__DIR__) . '/app/Services/FailoverSafetyService.php';
use MuseDockPanel\Services\FailoverSafetyService as S;
$checks = 0;
function check(bool $result, string $message): void {
    global $checks;
    $checks++;
    if (!$result) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
foreach ([null, [], ['cluster_fenced' => false], ['cluster_fenced' => true], ['cluster_fenced' => true, 'cluster_fence_verified' => '1'], ['cluster_fenced' => '0']] as $state) {
    foreach ([false, true] as $panel) foreach ([false, true] as $web) foreach ([false, true] as $pg) {
        check(!S::fenceConfirmed($state, $panel, $web, $pg), 'Silence/reachability cannot prove fencing');
    }
}
check(S::fenceConfirmed(['cluster_fenced' => true, 'cluster_fence_verified' => true], true, false, true), 'Authenticated fenced state accepted');
check(!S::freshnessVerdict(1800000001, 1800000000, 30)['ok'], 'Future timestamp blocks');
require dirname(__DIR__) . '/app/Services/HaReadinessService.php';
use MuseDockPanel\Services\HaReadinessService as R;
check(!R::stoppedAndGuarded([]), 'Missing service inventory blocks');
foreach (['active','activating','deactivating','reloading','unknown',''] as $state) {
    check(!R::stoppedAndGuarded([['state' => $state, 'guarded' => true]]), 'Writable or unknown service blocks');
}
check(!R::stoppedAndGuarded([['state' => 'inactive', 'guarded' => false]]), 'No reboot guard blocks');
check(R::stoppedAndGuarded([['state' => 'inactive', 'guarded' => true]]), 'Stopped guarded service accepted');
$now = 1800000000;
foreach ([null, 0, -1] as $fresh) check(!S::freshnessVerdict($fresh, $now, 30)['ok'], 'Unknown freshness blocks');
check(S::freshnessVerdict($now - 180, $now, 30)['ok'], 'Recent sample accepted');
check(!S::freshnessVerdict($now - 5400, $now, 30)['ok'], 'Old sample blocks');
echo "$checks pure checks passed\n";
foreach ([true, false] as $standby) {
    check(!R::bootVerdict('master', true, 1, [['cluster_role' => 'slave', 'cluster_fenced' => false]], $standby)['ok'], 'Fenced node cannot boot');
}
check(!R::bootVerdict('master', false, 0, [], true)['ok'], 'Master without peers blocks');
check(!R::bootVerdict('master', false, 2, [['cluster_role' => 'slave', 'cluster_fenced' => false]], true)['ok'], 'Partial peer reply blocks');
foreach (['master', 'standalone', ''] as $role) {
    check(!R::bootVerdict('master', false, 1, [['cluster_role' => $role, 'cluster_fenced' => false]], true)['ok'], 'Conflicting/unknown peer blocks');
}
check(R::bootVerdict('master', false, 1, [['cluster_role' => 'slave', 'cluster_fenced' => false]], true)['ok'], 'Explicit slave peer allows master boot');
check(!R::bootVerdict('master', false, 1, [['cluster_role' => 'slave']], true)['ok'], 'Missing fence state blocks');
check(!R::bootVerdict('slave', false, 0, [], false)['ok'], 'Replica without standby marker blocks');
check(R::bootVerdict('slave', false, 0, [], true)['ok'], 'Replica with standby marker allowed');
check(!R::bootVerdict('standalone', false, 0, [], true)['ok'], 'Strict HA guard does not infer standalone safety');
$unit = R::bootUnit('/opt/musedock-panel/bin/ha-boot-check.php', 'postgresql@14-panel.service');
check(!str_contains($unit, 'ExecStart=-'), 'Boot errors are not ignored');
check(str_contains($unit, 'TimeoutStartSec=120'), 'Bounded boot timeout fails service');
check(str_contains(R::consumerDropin(), 'Requires=musedock-ha-boot-guard.service'), 'Consumer requires successful guard');
echo "$checks total pure checks passed\n";

foreach ([null, '', 'unknown', -1, 16777217] as $gap) check(!S::replicaSampleHealthy('streaming', $gap, 16777216), 'Unknown/large replay gap blocks freshness');
check(!S::replicaSampleHealthy('stopped', 0, 16777216), 'Stopped WAL receiver blocks freshness');
check(S::replicaSampleHealthy('streaming', '0', 16777216), 'Streaming caught up sample accepted');
echo "$checks final pure checks passed\n";
