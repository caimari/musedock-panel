<?php use MuseDockPanel\View; ?>

<?php
// Get server IP for DNS check
$serverIp = trim(shell_exec('curl -s -4 ifconfig.me 2>/dev/null') ?: '');
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-globe2 me-2"></i>All Domains</span>
        <small class="text-muted">Server IP: <code><?= View::e($serverIp) ?></code></small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($accountDomains)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-globe2" style="font-size: 2rem;"></i>
                <p class="mt-2">No domains yet. Create a hosting account first.</p>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>DNS Status</th>
                        <th>Account</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accountDomains as $acc): ?>
                    <?php
                        // Check DNS
                        $dnsRecords = @dns_get_record($acc['domain'], DNS_A);
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
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                            <a href="https://<?= View::e($acc['domain']) ?>" target="_blank" class="ms-1" style="color:#64748b;font-size:0.75rem;" title="Open site"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                        <td>
                            <?php if ($pointsHere): ?>
                                <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                    <i class="bi bi-check-circle me-1"></i>OK — <?= implode(', ', $dnsIps) ?>
                                </span>
                            <?php elseif (!empty($dnsIps)): ?>
                                <span class="badge" style="background: rgba(251,191,36,0.15); color: #fbbf24;">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= implode(', ', $dnsIps) ?>
                                </span>
                                <small class="text-muted d-block">Points elsewhere</small>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(239,68,68,0.15); color: #ef4444;">
                                    <i class="bi bi-x-circle me-1"></i>No DNS
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-decoration-none text-light">
                                <code><?= View::e($acc['username']) ?></code>
                            </a>
                        </td>
                        <td><?= View::e($acc['customer_name'] ?? '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $acc['status'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/Y', strtotime($acc['created_at'])) ?></small></td>
                    </tr>

                    <?php
                        // Show alias domains for this account
                        $aliases = array_filter($domains, fn($d) => (int)$d['account_id'] === (int)$acc['id'] && $d['domain'] !== $acc['domain']);
                        foreach ($aliases as $alias):
                            $aliasRecords = @dns_get_record($alias['domain'], DNS_A);
                            $aliasIps = [];
                            $aliasPointsHere = false;
                            if ($aliasRecords) {
                                foreach ($aliasRecords as $r) {
                                    $ip = $r['ip'] ?? '';
                                    $aliasIps[] = $ip;
                                    if ($ip === $serverIp) $aliasPointsHere = true;
                                }
                            }
                    ?>
                    <tr style="background: rgba(56,189,248,0.03);">
                        <td class="ps-3">
                            <span class="text-muted me-2">↳</span>
                            <a href="https://<?= View::e($alias['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                                <?= View::e($alias['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <span class="badge bg-dark ms-1">alias</span>
                        </td>
                        <td>
                            <?php if ($aliasPointsHere): ?>
                                <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                    <i class="bi bi-check-circle me-1"></i>OK
                                </span>
                            <?php elseif (!empty($aliasIps)): ?>
                                <span class="badge" style="background: rgba(251,191,36,0.15); color: #fbbf24;">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= implode(', ', $aliasIps) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: rgba(239,68,68,0.15); color: #ef4444;">
                                    <i class="bi bi-x-circle me-1"></i>No DNS
                                </span>
                            <?php endif; ?>
                        </td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
    <small class="text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>DNS Status:</strong>
        <span style="color: #22c55e;">OK</span> = domain points to this server (<?= View::e($serverIp) ?>).
        <span style="color: #fbbf24;">Warning</span> = domain resolves but to a different IP.
        <span style="color: #ef4444;">No DNS</span> = domain has no A record (SSL certificate will fail until DNS is configured).
    </small>
</div>
