<?php use MuseDockPanel\View; ?>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person me-2"></i>Customer Details</span>
                <a href="/customers/<?= $customer['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:30%">Name</td><td><?= View::e($customer['name']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">Email</td><td><a href="mailto:<?= View::e($customer['email']) ?>" class="text-info"><?= View::e($customer['email']) ?></a></td></tr>
                    <tr><td class="ps-3 text-muted">Company</td><td><?= View::e($customer['company'] ?? '-') ?></td></tr>
                    <tr><td class="ps-3 text-muted">Phone</td><td><?= View::e($customer['phone'] ?? '-') ?></td></tr>
                    <tr><td class="ps-3 text-muted">Status</td><td><span class="badge badge-<?= $customer['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $customer['status'] ?></span></td></tr>
                    <?php if ($customer['notes']): ?>
                    <tr><td class="ps-3 text-muted">Notes</td><td><?= View::e($customer['notes']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="ps-3 text-muted">Created</td><td><?= date('d/m/Y H:i', strtotime($customer['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Customer's Hosting Accounts -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-server me-2"></i>Hosting Accounts</span>
                <a href="/accounts/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Account</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($accounts)): ?>
                    <div class="p-3 text-center text-muted">No hosting accounts for this customer.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Domain</th><th>User</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($accounts as $acc): ?>
                            <tr>
                                <td class="ps-3"><a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none"><?= View::e($acc['domain']) ?></a></td>
                                <td><code><?= View::e($acc['username']) ?></code></td>
                                <td><span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $acc['status'] ?></span></td>
                                <td><a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Accounts</span>
                    <span class="fw-semibold"><?= count($accounts) ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Total Disk</span>
                    <span class="fw-semibold"><?= array_sum(array_column($accounts, 'disk_used_mb')) ?> MB</span>
                </div>
            </div>
        </div>
    </div>
</div>
