<?php use MuseDockPanel\View; ?>

<!-- System Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['cpu']['percent'] ?>%</div>
                    <div class="stat-label">CPU (<?= $stats['cpu']['cores'] ?> cores)</div>
                </div>
                <i class="bi bi-cpu stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-info" style="width: <?= $stats['cpu']['percent'] ?>%"></div></div>
            <small class="text-muted">Load: <?= $stats['cpu']['load_1'] ?> / <?= $stats['cpu']['load_5'] ?> / <?= $stats['cpu']['load_15'] ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['memory']['percent'] ?>%</div>
                    <div class="stat-label">RAM (<?= $stats['memory']['used_gb'] ?> / <?= $stats['memory']['total_gb'] ?> GB)</div>
                </div>
                <i class="bi bi-memory stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-warning" style="width: <?= $stats['memory']['percent'] ?>%"></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $stats['disk']['percent'] ?>%</div>
                    <div class="stat-label">Disk (<?= $stats['disk']['used_gb'] ?> / <?= $stats['disk']['total_gb'] ?> GB)</div>
                </div>
                <i class="bi bi-hdd stat-icon"></i>
            </div>
            <div class="progress mt-2"><div class="progress-bar bg-<?= $stats['disk']['percent'] > 85 ? 'danger' : 'success' ?>" style="width: <?= $stats['disk']['percent'] ?>%"></div></div>
            <small class="text-muted"><?= $stats['disk']['free_gb'] ?> GB free</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value"><?= $accounts['total'] ?? 0 ?></div>
                    <div class="stat-label">Hosting Accounts</div>
                </div>
                <i class="bi bi-server stat-icon"></i>
            </div>
            <div class="mt-2">
                <span class="badge badge-active"><?= $accounts['active'] ?? 0 ?> active</span>
                <?php if (($accounts['suspended'] ?? 0) > 0): ?>
                    <span class="badge badge-suspended"><?= $accounts['suspended'] ?> suspended</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- System Info -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>System Info</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:40%">Hostname</td><td><?= View::e($stats['hostname']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">OS</td><td><?= View::e($stats['os']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">PHP</td><td><?= View::e($stats['php_version']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">Uptime</td><td><?= View::e($stats['uptime']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Activity</div>
            <div class="card-body p-0">
                <?php if (empty($recentLog)): ?>
                    <div class="p-3 text-muted text-center">No recent activity</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <?php foreach (array_slice($recentLog, 0, 8) as $log): ?>
                        <tr>
                            <td class="ps-3"><small class="text-muted"><?= date('d/m H:i', strtotime($log['created_at'])) ?></small></td>
                            <td><span class="badge bg-dark"><?= View::e($log['action']) ?></span></td>
                            <td><?= View::e($log['target'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
