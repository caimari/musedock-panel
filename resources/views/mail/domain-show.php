<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/mail" class="text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Mail</a>
    </div>
    <div class="d-flex gap-2">
        <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> New Mailbox
        </a>
        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/delete" class="d-inline"
              onsubmit="return confirm('Delete domain <?= View::e($domain['domain']) ?> and all its accounts?')">
            <?= View::csrf() ?>
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
        </form>
    </div>
</div>

<!-- Domain info -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-globe2 me-2"></i><?= View::e($domain['domain']) ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="text-muted small">Status</div>
                        <span class="badge badge-<?= $domain['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $domain['status'] ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Mail Node</div>
                        <span class="fw-semibold"><?= View::e($domain['node_name'] ?? 'Local') ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Customer</div>
                        <span><?= View::e($domain['customer_name'] ?? '-') ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Max Accounts</div>
                        <span><?= $domain['max_accounts'] ?: 'Unlimited' ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">DKIM</div>
                        <?php if ($domain['dkim_public_key']): ?>
                            <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Not generated</span>
                        <?php endif; ?>
                        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/regenerate-dkim" class="d-inline ms-1">
                            <?= View::csrf() ?>
                            <button class="btn btn-outline-light btn-sm py-0 px-1" title="Regenerate DKIM"><i class="bi bi-arrow-clockwise"></i></button>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Created</div>
                        <span class="text-muted"><?= $domain['created_at'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-signpost me-2"></i>DNS Records</div>
            <div class="card-body p-0">
                <?php if (empty($dnsRecords)): ?>
                    <div class="p-3 text-muted">No DNS records available.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size: 0.78rem;">
                            <thead>
                                <tr><th class="ps-3">Type</th><th>Name</th><th>Value</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dnsRecords as $r): ?>
                                <tr>
                                    <td class="ps-3"><code><?= $r['type'] ?></code></td>
                                    <td class="text-break"><?= View::e($r['name']) ?></td>
                                    <td class="text-break" style="max-width:200px; overflow:hidden; text-overflow:ellipsis;">
                                        <?= View::e(isset($r['priority']) ? $r['priority'] . ' ' : '') ?><?= View::e(substr($r['value'], 0, 80)) ?><?= strlen($r['value']) > 80 ? '...' : '' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Mailboxes -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-mailbox me-2"></i>Mailboxes</span>
        <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm py-0 px-2">
            <i class="bi bi-plus-lg"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="p-4 text-center text-muted">
                <p>No mailboxes yet.</p>
                <a href="/mail/domains/<?= $domain['id'] ?>/accounts/create" class="btn btn-primary btn-sm">Create first mailbox</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Email</th>
                        <th>Display Name</th>
                        <th>Quota</th>
                        <th>Used</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= View::e($a['email']) ?></td>
                        <td><?= View::e($a['display_name'] ?: '-') ?></td>
                        <td><?= $a['quota_mb'] ?> MB</td>
                        <td>
                            <?= $a['used_mb'] ?> MB
                            <?php if ($a['quota_mb'] > 0): ?>
                                <div class="progress mt-1" style="height: 3px; width: 60px;">
                                    <?php $pct = min(100, round($a['used_mb'] / $a['quota_mb'] * 100)); ?>
                                    <div class="progress-bar <?= $pct > 90 ? 'bg-danger' : ($pct > 70 ? 'bg-warning' : 'bg-info') ?>"
                                         style="width: <?= $pct ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $a['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $a['status'] ?></span>
                        </td>
                        <td class="text-muted small"><?= $a['last_login_at'] ?? 'Never' ?></td>
                        <td>
                            <a href="/mail/accounts/<?= $a['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="/mail/accounts/<?= $a['id'] ?>/delete" class="d-inline"
                                  onsubmit="return confirm('Delete <?= View::e($a['email']) ?>?')">
                                <?= View::csrf() ?>
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Aliases -->
<div class="card">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Aliases & Forwards</div>
    <div class="card-body">
        <?php if (!empty($aliases)): ?>
            <table class="table table-sm mb-3">
                <thead>
                    <tr><th>Source</th><th>Destination</th><th>Catchall</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($aliases as $al): ?>
                    <tr>
                        <td><?= View::e($al['source']) ?></td>
                        <td><?= View::e($al['destination']) ?></td>
                        <td><?= $al['is_catchall'] ? '<span class="badge bg-info">Yes</span>' : '-' ?></td>
                        <td>
                            <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/aliases/<?= $al['id'] ?>/delete" class="d-inline">
                                <?= View::csrf() ?>
                                <button class="btn btn-outline-danger btn-sm py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="POST" action="/mail/domains/<?= $domain['id'] ?>/aliases/store" class="row g-2 align-items-end">
            <?= View::csrf() ?>
            <div class="col-md-4">
                <label class="form-label small">Source</label>
                <input type="text" name="source" class="form-control form-control-sm" placeholder="alias@<?= View::e($domain['domain']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Destination</label>
                <input type="text" name="destination" class="form-control form-control-sm" placeholder="user@example.com" required>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_catchall" id="catchall">
                    <label class="form-check-label small" for="catchall">Catchall</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i> Add</button>
            </div>
        </form>
    </div>
</div>
