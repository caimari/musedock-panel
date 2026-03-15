<?php use MuseDockPanel\View; ?>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="p-4 text-center text-muted">No activity recorded yet.</div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Date</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="ps-3"><small><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small></td>
                        <td><?= View::e($log['admin_name'] ?? 'system') ?></td>
                        <td><span class="badge bg-dark"><?= View::e($log['action']) ?></span></td>
                        <td><?= View::e($log['target'] ?? '-') ?></td>
                        <td><small class="text-muted"><?= View::e($log['details'] ?? '') ?></small></td>
                        <td><small class="text-muted"><?= View::e($log['ip_address'] ?? '') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
