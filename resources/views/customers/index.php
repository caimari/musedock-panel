<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <a href="/customers/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Customer</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-people" style="font-size: 2rem;"></i>
                <p class="mt-2">No customers yet.</p>
                <a href="/customers/create" class="btn btn-primary btn-sm">Add first customer</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Accounts</th>
                        <th>Disk Used</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/customers/<?= $c['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($c['name']) ?></a>
                        </td>
                        <td><?= View::e($c['email']) ?></td>
                        <td><?= View::e($c['company'] ?? '-') ?></td>
                        <td><?= $c['account_count'] ?></td>
                        <td><?= $c['total_disk_used'] ?> MB</td>
                        <td>
                            <span class="badge badge-<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $c['status'] ?>
                            </span>
                        </td>
                        <td>
                            <a href="/customers/<?= $c['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
