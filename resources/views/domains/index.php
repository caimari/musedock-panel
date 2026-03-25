<?php
use MuseDockPanel\View;
use MuseDockPanel\Services\CloudflareService;

// Get server IP for DNS check
$serverIp = trim(shell_exec('curl -s -4 ifconfig.me 2>/dev/null') ?: '');

// Helper: render DNS status badge
function renderDnsBadge(array $dns): string {
    $status = $dns['status'];
    $ips = implode(', ', $dns['ips']);

    return match ($status) {
        'ok' => '<span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                    <i class="bi bi-check-circle me-1"></i>OK — ' . $ips . '
                 </span>',
        'cloudflare' => '<span class="badge" style="background: rgba(249,115,22,0.15); color: #f97316;">
                            <i class="bi bi-cloud-fill me-1"></i>Cloudflare Proxy
                         </span>
                         <small class="text-muted d-block">SSL via CF — ' . $ips . '</small>',
        'elsewhere' => '<span class="badge" style="background: rgba(251,191,36,0.15); color: #fbbf24;">
                            <i class="bi bi-exclamation-triangle me-1"></i>' . $ips . '
                         </span>
                         <small class="text-muted d-block">Points elsewhere</small>',
        default => '<span class="badge" style="background: rgba(239,68,68,0.15); color: #ef4444;">
                        <i class="bi bi-x-circle me-1"></i>No DNS
                    </span>',
    };
}
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
                    <?php $dns = CloudflareService::checkDomainDns($acc['domain'], $serverIp); ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                            <a href="https://<?= View::e($acc['domain']) ?>" target="_blank" class="ms-1" style="color:#64748b;font-size:0.75rem;" title="Open site"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                        <td><?= renderDnsBadge($dns) ?></td>
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
                        // Show alias domains for this account (from hosting_domains table)
                        $accAliases = array_filter($domains, fn($d) => (int)$d['account_id'] === (int)$acc['id'] && $d['domain'] !== $acc['domain']);
                        foreach ($accAliases as $alias):
                            $aliasDns = CloudflareService::checkDomainDns($alias['domain'], $serverIp);
                    ?>
                    <tr style="background: rgba(56,189,248,0.03);">
                        <td class="ps-3">
                            <span class="text-muted me-2">&darr;</span>
                            <a href="https://<?= View::e($alias['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                                <?= View::e($alias['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                            <span class="badge bg-dark ms-1">alias</span>
                        </td>
                        <td><?= renderDnsBadge($aliasDns) ?></td>
                        <td colspan="4"></td>
                    </tr>
                    <?php endforeach; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php
    // Split aliases and redirects
    $aliasList = array_filter($aliasesAndRedirects, fn($r) => $r['type'] === 'alias');
    $redirectList = array_filter($aliasesAndRedirects, fn($r) => $r['type'] === 'redirect');
?>

<?php if (!empty($aliasList) || !empty($redirectList)): ?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-2"></i>Domain Aliases & Redirects</span>
        <small class="text-muted"><?= count($aliasList) ?> alias(es), <?= count($redirectList) ?> redirect(s)</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Domain</th>
                    <th>Type</th>
                    <th>DNS Status</th>
                    <th>Target Account</th>
                    <th>Customer</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aliasesAndRedirects as $item):
                    $itemDns = CloudflareService::checkDomainDns($item['domain'], $serverIp);
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="https://<?= View::e($item['domain']) ?>" target="_blank" class="text-info text-decoration-none">
                            <?= View::e($item['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </td>
                    <td>
                        <?php if ($item['type'] === 'alias'): ?>
                            <span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">
                                <i class="bi bi-files me-1"></i>Alias
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: rgba(251,146,60,0.15); color: #fb923c;">
                                <i class="bi bi-arrow-right-circle me-1"></i><?= $item['redirect_code'] ?> Redirect
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= renderDnsBadge($itemDns) ?></td>
                    <td>
                        <a href="/accounts/<?= $item['hosting_account_id'] ?>" class="text-decoration-none text-light">
                            <i class="bi bi-arrow-right me-1 text-muted"></i><?= View::e($item['account_domain']) ?>
                            <code class="ms-1"><?= View::e($item['username']) ?></code>
                        </a>
                    </td>
                    <td><?= View::e($item['customer_name'] ?? '-') ?></td>
                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($item['created_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
    <small class="text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>DNS Status:</strong>
        <span style="color: #22c55e;">OK</span> = domain points directly to this server (<?= View::e($serverIp) ?>).
        <span style="color: #f97316;"><i class="bi bi-cloud-fill"></i> Cloudflare Proxy</span> = domain goes through Cloudflare (SSL provided by CF, certificates auto-generated when proxy is disabled).
        <span style="color: #fbbf24;">Warning</span> = domain resolves but to a different IP.
        <span style="color: #ef4444;">No DNS</span> = domain has no A record.
        <br>
        <i class="bi bi-info-circle me-1 mt-1"></i>
        <strong>Alias</strong> = same content as the main domain (multiple domains, one site).
        <strong>Redirect</strong> = redirects visitors to the main domain (SEO migration, 301/302).
    </small>
</div>
