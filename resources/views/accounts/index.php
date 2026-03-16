<?php
use MuseDockPanel\View;
$clusterRole = \MuseDockPanel\Settings::get('cluster_role', 'standalone');
?>

<?php if ($clusterRole === 'slave'): ?>
<div class="alert alert-info d-flex align-items-center mb-3">
    <i class="bi bi-info-circle me-2"></i>
    Este servidor es <strong class="ms-1">Slave</strong>. La creacion de hostings esta deshabilitada. Los hostings se reciben del servidor Master.
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <div class="d-flex gap-2">
        <?php if ($clusterRole !== 'slave'): ?>
        <a href="/accounts/import" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>Importar Existente</a>
        <a href="/accounts/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Account</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-server" style="font-size: 2rem;"></i>
                <p class="mt-2">No hosting accounts yet.</p>
                <a href="/accounts/create" class="btn btn-primary btn-sm">Create first account</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>Customer</th>
                        <th>User</th>
                        <th>PHP</th>
                        <th>Disk</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                        </td>
                        <td>
                            <?php if ($acc['customer_name']): ?>
                                <a href="/customers/<?= $acc['customer_id'] ?>" class="text-decoration-none text-light"><?= View::e($acc['customer_name']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= View::e($acc['username']) ?></code></td>
                        <td><?= View::e($acc['php_version']) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width: 60px;">
                                    <?php $diskPercent = $acc['disk_quota_mb'] > 0 ? round(($acc['disk_used_mb'] / $acc['disk_quota_mb']) * 100) : 0; ?>
                                    <div class="progress-bar bg-<?= $diskPercent > 85 ? 'danger' : 'info' ?>" style="width: <?= $diskPercent ?>%"></div>
                                </div>
                                <small class="text-muted"><?= $acc['disk_used_mb'] ?>MB / <?= $acc['disk_quota_mb'] ?>MB</small>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $acc['status'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/Y', strtotime($acc['created_at'])) ?></small></td>
                        <td>
                            <a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
