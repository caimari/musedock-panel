<?php use MuseDockPanel\View; ?>

<div class="row g-3">
    <!-- Account Info -->
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-server me-2"></i>Account Details</span>
                <div class="d-flex gap-2">
                    <a href="/accounts/<?= $account['id'] ?>/migrate" class="btn btn-outline-light btn-sm"><i class="bi bi-cloud-download me-1"></i>Migrate</a>
                    <a href="/accounts/<?= $account['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a>
                    <?php if ($account['status'] === 'active'): ?>
                        <form id="suspendForm" method="POST" action="/accounts/<?= $account['id'] ?>/suspend">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmAction(document.getElementById('suspendForm'), {
                                title: 'Suspend <?= View::e($account['domain']) ?>?',
                                html: '<p style=\'color:#94a3b8;\'>This will:</p><ul style=\'text-align:left;color:#94a3b8;font-size:0.9rem;\'><li>Block SSH/SFTP access</li><li>Stop PHP-FPM pool</li><li>Website will go offline</li></ul>',
                                icon: 'warning',
                                confirmText: 'Yes, suspend it'
                            })"><i class="bi bi-pause-circle"></i> Suspend</button>
                        </form>
                    <?php else: ?>
                        <form id="activateForm" method="POST" action="/accounts/<?= $account['id'] ?>/activate">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="confirmAction(document.getElementById('activateForm'), {
                                title: 'Activate <?= View::e($account['domain']) ?>?',
                                text: 'This will restore SSH/SFTP access, restart PHP-FPM, and bring the website back online.',
                                icon: 'question',
                                confirmText: 'Yes, activate it'
                            })"><i class="bi bi-play-circle"></i> Activate</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:35%">Domain</td><td><a href="https://<?= View::e($account['domain']) ?>" target="_blank" class="text-info"><?= View::e($account['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i></a></td></tr>
                    <tr><td class="ps-3 text-muted">System User</td><td><code><?= View::e($account['username']) ?></code> (UID: <?= $account['system_uid'] ?? 'N/A' ?>)</td></tr>
                    <tr><td class="ps-3 text-muted">Status</td><td><span class="badge badge-<?= $account['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $account['status'] ?></span></td></tr>
                    <tr><td class="ps-3 text-muted">Home Directory</td><td><code><?= View::e($account['home_dir']) ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">Document Root</td><td><code><?= View::e($account['document_root']) ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">PHP Version</td><td><?= View::e($account['php_version']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">FPM Socket</td><td><code><?= View::e($account['fpm_socket'] ?? 'N/A') ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">Caddy Route</td><td><code><?= View::e($account['caddy_route_id'] ?? 'N/A') ?></code></td></tr>
                    <tr>
                        <td class="ps-3 text-muted">Disk Usage</td>
                        <td>
                            <?php $diskPercent = $account['disk_quota_mb'] > 0 ? round(($account['disk_used_mb'] / $account['disk_quota_mb']) * 100) : 0; ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width: 120px;">
                                    <div class="progress-bar bg-<?= $diskPercent > 85 ? 'danger' : 'info' ?>" style="width: <?= $diskPercent ?>%"></div>
                                </div>
                                <?= $account['disk_used_mb'] ?> MB / <?= $account['disk_quota_mb'] ?> MB (<?= $diskPercent ?>%)
                            </div>
                        </td>
                    </tr>
                    <?php if ($account['description']): ?>
                    <tr><td class="ps-3 text-muted">Description</td><td><?= View::e($account['description']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="ps-3 text-muted">Created</td><td><?= date('d/m/Y H:i', strtotime($account['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Domains & SSL -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-globe me-2"></i>Domains & SSL</span>
                <form id="renewSslForm" method="POST" action="/accounts/<?= $account['id'] ?>/renew-ssl" style="display:inline;">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="confirmAction(document.getElementById('renewSslForm'), {
                        title: 'Renew SSL Certificate?',
                        text: 'This will remove and re-create the Caddy route, triggering a new certificate request. The domain must have DNS pointing to this server.',
                        icon: 'info',
                        confirmText: 'Renew SSL'
                    })" title="Force SSL certificate renewal"><i class="bi bi-arrow-clockwise me-1"></i> Renew SSL</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th class="ps-3">Domain</th><th>DNS</th><th>Primary</th><th>SSL</th></tr></thead>
                    <tbody>
                        <?php
                            $serverIp = trim(shell_exec('curl -s -4 ifconfig.me 2>/dev/null') ?: '');
                        ?>
                        <?php foreach ($domains as $d): ?>
                        <?php
                            $dnsRecords = @dns_get_record($d['domain'], DNS_A);
                            $dnsIps = [];
                            $pointsHere = false;
                            if ($dnsRecords) {
                                foreach ($dnsRecords as $r) {
                                    $ip = $r['ip'] ?? '';
                                    $dnsIps[] = $ip;
                                    if ($ip === $serverIp) $pointsHere = true;
                                }
                            }
                        ?>
                        <tr>
                            <td class="ps-3"><?= View::e($d['domain']) ?></td>
                            <td>
                                <?php if ($pointsHere): ?>
                                    <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle"></i> OK</span>
                                <?php elseif (!empty($dnsIps)): ?>
                                    <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;" title="Points to <?= implode(', ', $dnsIps) ?>"><i class="bi bi-exclamation-triangle"></i> <?= implode(', ', $dnsIps) ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle"></i> No DNS</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $d['is_primary'] ? '<span class="badge bg-info">Primary</span>' : '' ?></td>
                            <td>
                                <?php if ($pointsHere): ?>
                                    <i class="bi bi-lock-fill text-success" title="SSL active"></i>
                                <?php else: ?>
                                    <i class="bi bi-unlock text-warning" title="SSL pending — DNS must point to <?= $serverIp ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$pointsHere): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">
                    <small style="color:#fbbf24;"><i class="bi bi-info-circle me-1"></i>SSL certificate will be obtained automatically when DNS A record points to <code><?= $serverIp ?></code>. Update your DNS provider and click "Renew SSL".</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Databases -->
        <div class="card">
            <div class="card-header"><i class="bi bi-database me-2"></i>Databases</div>
            <div class="card-body p-0">
                <?php if (empty($databases)): ?>
                    <div class="p-3 text-center text-muted">No databases created yet.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Name</th><th>User</th><th>Type</th></tr></thead>
                        <tbody>
                            <?php foreach ($databases as $db): ?>
                            <tr>
                                <td class="ps-3"><code><?= View::e($db['db_name']) ?></code></td>
                                <td><code><?= View::e($db['db_user']) ?></code></td>
                                <td><?= View::e($db['db_type']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>Quick Actions</div>
            <div class="card-body d-grid gap-2">
                <a href="https://<?= View::e($account['domain']) ?>" target="_blank" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-globe me-1"></i> Visit Site
                </a>
                <a href="/accounts/<?= $account['id'] ?>/edit" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-gear me-1"></i> Settings
                </a>
            </div>
        </div>

        <!-- Danger Zone -->
        <?php if ($account['status'] === 'suspended'): ?>
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</div>
            <div class="card-body">
                <p class="small text-muted">Delete this account permanently. This removes the system user, FPM pool, and Caddy route. The home directory is kept for manual backup.</p>
                <form id="deleteForm" method="POST" action="/accounts/<?= $account['id'] ?>/delete">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="confirmAction(document.getElementById('deleteForm'), {
                        title: 'Delete <?= View::e($account['domain']) ?>?',
                        html: '<p style=\'color:#ef4444;font-weight:600;\'>This action is PERMANENT and cannot be undone!</p><p style=\'color:#94a3b8;font-size:0.9rem;\'>This will remove:<br>• System user<br>• PHP-FPM pool<br>• Caddy route<br><br>The home directory is kept for manual backup.</p>',
                        icon: 'error',
                        confirmText: 'Yes, DELETE permanently'
                    })"><i class="bi bi-trash me-1"></i> Delete Account</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
