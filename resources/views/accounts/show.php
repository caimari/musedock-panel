<?php
use MuseDockPanel\View;
use MuseDockPanel\Services\CloudflareService;
?>

<?php if ($isSlave ?? false): ?>
<div class="alert mb-3 py-2 px-3 small d-flex align-items-center" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);color:#94a3b8;">
    <i class="bi bi-lock me-2" style="color:#38bdf8;"></i>
    <span><strong style="color:#38bdf8;">Servidor Slave</strong> — Modo solo lectura. Los cambios deben realizarse en el Master.</span>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Account Info -->
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-server me-2"></i>Account Details</span>
                <div class="d-flex gap-2">
                    <?php if (!($isSlave ?? false)): ?>
                    <a href="/accounts/<?= $account['id'] ?>/files" class="btn btn-outline-light btn-sm"><i class="bi bi-folder me-1"></i>Files</a>
                    <a href="/accounts/<?= $account['id'] ?>/stats" class="btn btn-outline-info btn-sm"><i class="bi bi-bar-chart me-1"></i>Stats</a>
                    <a href="/accounts/<?= $account['id'] ?>/migrate" class="btn btn-outline-light btn-sm"><i class="bi bi-cloud-download me-1"></i>Migrate</a>
                    <a href="/accounts/<?= $account['id'] ?>/federation-migrate" class="btn btn-outline-light btn-sm" style="border-color:#10b981;color:#10b981;"><i class="bi bi-arrow-left-right me-1"></i>Migrar a...</a>
                    <a href="/accounts/<?= $account['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a>
                    <?php if ($account['status'] === 'active'): ?>
                        <?php $hasActiveMail = !empty($mailDomain) && $mailDomain['status'] === 'active'; ?>
                        <form id="suspendForm" method="POST" action="/accounts/<?= $account['id'] ?>/suspend">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <input type="hidden" name="suspend_mail" id="suspend-mail-input" value="0">
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmAction(document.getElementById('suspendForm'), {
                                title: 'Suspend <?= View::e($account['domain']) ?>?',
                                html: '<p style=\'color:#94a3b8;\'>This will:</p><ul style=\'text-align:left;color:#94a3b8;font-size:0.9rem;\'><li>Block SSH/SFTP access</li><li>Stop PHP-FPM pool</li><li>Website will go offline</li></ul><?= $hasActiveMail ? "<div style=\"margin-top:12px;padding:10px;background:rgba(251,191,36,0.1);border-radius:6px;text-align:left;\"><label style=\"color:#fbbf24;font-size:0.85rem;cursor:pointer;\"><input type=\"checkbox\" id=\"swal-suspend-mail\" style=\"margin-right:6px;\">Suspender tambien el correo (" . count($mailAccounts) . " cuenta/s)</label></div>" : "" ?>',
                                icon: 'warning',
                                confirmText: 'Yes, suspend it'
                            }, function() { <?= $hasActiveMail ? "document.getElementById('suspend-mail-input').value = document.getElementById('swal-suspend-mail')?.checked ? '1' : '0';" : "" ?> })"><i class="bi bi-pause-circle"></i> Suspend</button>
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
                    <?php endif; /* isSlave */ ?>
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
                            <?php if ($account['disk_quota_mb'] > 0): ?>
                                <?php $diskPercent = round(($account['disk_used_mb'] / $account['disk_quota_mb']) * 100); ?>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress" style="width: 120px;">
                                        <div class="progress-bar bg-<?= $diskPercent > 85 ? 'danger' : 'info' ?>" style="width: <?= min($diskPercent, 100) ?>%"></div>
                                    </div>
                                    <?= number_format($account['disk_used_mb']) ?> MB / <?= number_format($account['disk_quota_mb']) ?> MB (<?= $diskPercent ?>%)
                                </div>
                            <?php else: ?>
                                <div class="d-flex align-items-center gap-2">
                                    <?= number_format($account['disk_used_mb']) ?> MB
                                    <span class="badge bg-dark" style="font-size:0.75rem;">&#8734; Ilimitado</span>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-3 text-muted">Bandwidth</td>
                        <td>
                            <?php
                            $bwOut = (int)($bwMonthly['bytes_out'] ?? 0);
                            $bwIn = (int)($bwMonthly['bytes_in'] ?? 0);
                            $bwR = (int)($bwMonthly['requests'] ?? 0);
                            $fmtBw = function($b) { if ($b >= 1073741824) return round($b/1073741824, 2) . ' GB'; if ($b >= 1048576) return round($b/1048576, 1) . ' MB'; if ($b > 0) return round($b/1024, 1) . ' KB'; return '0'; };
                            ?>
                            <i class="bi bi-arrow-up text-info" style="font-size:0.75rem;"></i> <span class="text-info"><?= $fmtBw($bwOut) ?></span>
                            <i class="bi bi-arrow-down text-success ms-2" style="font-size:0.75rem;"></i> <span class="text-success"><?= $fmtBw($bwIn) ?></span>
                            <small class="text-muted ms-2"><?= number_format($bwR) ?> requests (este mes)</small>
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
                <?php if (!($isSlave ?? false)): ?>
                <form id="renewSslForm" method="POST" action="/accounts/<?= $account['id'] ?>/renew-ssl" style="display:inline;">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="confirmAction(document.getElementById('renewSslForm'), {
                        title: 'Renew SSL Certificate?',
                        text: 'This will remove and re-create the Caddy route, triggering a new certificate request. The domain must have DNS pointing to this server.',
                        icon: 'info',
                        confirmText: 'Renew SSL'
                    })" title="Force SSL certificate renewal"><i class="bi bi-arrow-clockwise me-1"></i> Renew SSL</button>
                </form>
                <?php endif; ?>
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
                            $dns = CloudflareService::checkDomainDns($d['domain'], $serverIp);
                            $pointsHere = $dns['status'] === 'ok';
                            $isCf = $dns['status'] === 'cloudflare';
                        ?>
                        <tr>
                            <td class="ps-3"><?= View::e($d['domain']) ?></td>
                            <td>
                                <?php if ($pointsHere): ?>
                                    <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle"></i> OK</span>
                                <?php elseif ($isCf): ?>
                                    <span class="badge" style="background:rgba(249,115,22,0.15);color:#f97316;"><i class="bi bi-cloud-fill"></i> Cloudflare</span>
                                <?php elseif (!empty($dns['ips'])): ?>
                                    <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;" title="Points to <?= implode(', ', $dns['ips']) ?>"><i class="bi bi-exclamation-triangle"></i> <?= implode(', ', $dns['ips']) ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle"></i> No DNS</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $d['is_primary'] ? '<span class="badge bg-info">Primary</span>' : '' ?></td>
                            <td>
                                <?php
                                    $hasCertOnDisk = false;
                                    if (!$pointsHere && !$isCf) {
                                        $hasCertOnDisk = \MuseDockPanel\Services\FileSyncService::hasCertForDomain($d['domain']);
                                    }
                                ?>
                                <?php if ($pointsHere): ?>
                                    <i class="bi bi-lock-fill text-success" title="SSL activo"></i>
                                <?php elseif ($isCf): ?>
                                    <i class="bi bi-lock-fill" style="color:#f97316;" title="SSL via Cloudflare"></i>
                                <?php elseif ($hasCertOnDisk): ?>
                                    <i class="bi bi-lock-fill text-info" title="SSL copiado del master (cert en disco)"></i>
                                <?php else: ?>
                                    <i class="bi bi-unlock text-warning" title="SSL pendiente — DNS debe apuntar a <?= $serverIp ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                    // Use last domain's DNS for the info box
                    $lastDns = $dns ?? ['status' => 'none'];
                    $lastPointsHere = ($lastDns['status'] === 'ok');
                    $lastIsCf = ($lastDns['status'] === 'cloudflare');
                    $lastHasCert = $hasCertOnDisk ?? false;
                ?>
                <?php if ($lastIsCf): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);">
                    <small style="color:#f97316;"><i class="bi bi-cloud-fill me-1"></i>Dominio a traves de Cloudflare Proxy. SSL lo proporciona Cloudflare. Si desactivas el proxy (DNS Only), Caddy generara el certificado automaticamente.</small>
                </div>
                <?php elseif (!$lastPointsHere && !$lastHasCert): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">
                    <small style="color:#fbbf24;"><i class="bi bi-info-circle me-1"></i>El certificado SSL se obtendra automaticamente cuando el DNS A apunte a <code><?= $serverIp ?></code>. Actualiza tu proveedor DNS y pulsa "Renew SSL".</small>
                </div>
                <?php elseif (!$lastPointsHere && $lastHasCert): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(13,202,240,0.08);border:1px solid rgba(13,202,240,0.2);">
                    <small style="color:#0dcaf0;"><i class="bi bi-info-circle me-1"></i>El certificado SSL fue copiado del master. HTTPS funciona aunque el DNS no apunte a este servidor. Cuando el DNS cambie, Caddy renovara el certificado automaticamente.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bandwidth -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-speedometer2 me-2"></i>Ancho de Banda</span>
                <div class="btn-group btn-group-sm" id="bwRangeButtons">
                    <button class="btn btn-outline-light bw-range" data-range="1h">1h</button>
                    <button class="btn btn-outline-light bw-range" data-range="6h">6h</button>
                    <button class="btn btn-outline-light bw-range active" data-range="24h">24h</button>
                    <button class="btn btn-outline-light bw-range" data-range="7d">7d</button>
                    <button class="btn btn-outline-light bw-range" data-range="30d">30d</button>
                    <button class="btn btn-outline-light bw-range" data-range="1y">1y</button>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex gap-4 mb-3">
                    <div>
                        <span class="fs-5 fw-bold text-info"><i class="bi bi-arrow-up" style="font-size:0.8rem;"></i> <?= $fmtBw($bwOut) ?></span>
                        <small class="text-muted d-block">Salida (este mes)</small>
                    </div>
                    <div>
                        <span class="fs-5 fw-bold text-success"><i class="bi bi-arrow-down" style="font-size:0.8rem;"></i> <?= $fmtBw($bwIn) ?></span>
                        <small class="text-muted d-block">Entrada (este mes)</small>
                    </div>
                    <div>
                        <span class="fs-5 fw-bold"><?= number_format($bwR) ?></span>
                        <small class="text-muted d-block">Requests</small>
                    </div>
                </div>
                <div style="height:200px;position:relative;">
                    <canvas id="bwChart"></canvas>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script>
        (function() {
            let bwChart = null;
            const ctx = document.getElementById('bwChart');
            const accountId = <?= (int)$account['id'] ?>;

            function fmtBytes(b) {
                if (b >= 1073741824) return (b/1073741824).toFixed(2) + ' GB';
                if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
                if (b >= 1024) return (b/1024).toFixed(1) + ' KB';
                return b + ' B';
            }

            function fmtLabel(epoch, range) {
                if (!epoch) return '';
                const d = new Date(Number(epoch) * 1000);
                if (['1h','6h','24h'].includes(range)) return d.toLocaleTimeString('es-ES', {hour:'2-digit', minute:'2-digit'});
                if (range === '7d') return d.toLocaleDateString('es-ES', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
                if (range === '30d') return d.toLocaleDateString('es-ES', {day:'2-digit', month:'short'});
                return d.toLocaleDateString('es-ES', {month:'short', year:'numeric'});
            }

            async function loadBandwidth(range) {
                const resp = await fetch(`/accounts/${accountId}/bandwidth?range=${range}`);
                const json = await resp.json();
                if (!json.ok) return;

                const labels = json.data.map(d => fmtLabel(d.period, range));
                const bytesOut = json.data.map(d => parseInt(d.bytes_out));
                const bytesIn = json.data.map(d => parseInt(d.bytes_in || 0));
                const reqs = json.data.map(d => parseInt(d.requests));

                const useBar = ['30d', '1y'].includes(range);

                if (bwChart) bwChart.destroy();
                bwChart = new Chart(ctx, {
                    type: useBar ? 'bar' : 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'OUT (salida)',
                                data: bytesOut,
                                backgroundColor: useBar ? 'rgba(56,189,248,0.4)' : 'rgba(56,189,248,0.08)',
                                borderColor: '#38bdf8',
                                borderWidth: useBar ? 1 : 1.5,
                                fill: !useBar,
                                tension: 0.3,
                                pointRadius: 0,
                                yAxisID: 'y',
                            },
                            {
                                label: 'IN (entrada)',
                                data: bytesIn,
                                backgroundColor: useBar ? 'rgba(34,197,94,0.4)' : 'rgba(34,197,94,0.08)',
                                borderColor: '#22c55e',
                                borderWidth: useBar ? 1 : 1.5,
                                fill: !useBar,
                                tension: 0.3,
                                pointRadius: 0,
                                yAxisID: 'y',
                            },
                            {
                                label: 'Requests',
                                data: reqs,
                                type: 'line',
                                borderColor: '#a855f7',
                                backgroundColor: 'rgba(168,85,247,0.1)',
                                tension: 0.3,
                                pointRadius: 0,
                                borderWidth: 1.5,
                                fill: true,
                                yAxisID: 'y1',
                            }
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { labels: { color: '#94a3b8', font: { size: 11 } } },
                            tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + (ctx.datasetIndex === 0 ? fmtBytes(ctx.raw) : ctx.raw) } }
                        },
                        scales: {
                            x: { ticks: { color: '#64748b', font: { size: 9 }, maxTicksLimit: 12 }, grid: { color: 'rgba(51,65,85,0.3)' } },
                            y: {
                                position: 'left',
                                ticks: { color: '#38bdf8', font: { size: 10 }, callback: v => fmtBytes(v) },
                                grid: { color: 'rgba(51,65,85,0.3)' },
                            },
                            y1: {
                                position: 'right',
                                ticks: { color: '#a855f7', font: { size: 10 } },
                                grid: { display: false },
                            },
                        },
                    },
                });
            }

            loadBandwidth('24h');

            document.querySelectorAll('.bw-range').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.bw-range').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    loadBandwidth(this.dataset.range);
                });
            });
        })();
        </script>

        <!-- Cloudflare DNS for this domain -->
        <?php
            $cfZone = CloudflareService::findZoneForDomain($account['domain']);
        ?>
        <?php if ($cfZone): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cloud-fill me-2" style="color:#f97316;"></i>Cloudflare DNS — <?= View::e($cfZone['zone']) ?></span>
                <a href="/settings/cloudflare-dns?domain=<?= urlencode($cfZone['zone']) ?>" class="btn btn-outline-light btn-sm py-0 px-2" style="font-size:0.75rem;">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Full Manager
                </a>
            </div>
            <div class="card-body p-0">
                <div id="cfDnsLoading" class="text-center text-muted py-3">
                    <div class="spinner-border spinner-border-sm me-1"></div> Loading DNS records...
                </div>
                <table class="table table-sm table-hover mb-0" id="cfDnsTable" style="display:none;">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width:55px;">Type</th>
                            <th>Name</th>
                            <th>Content</th>
                            <th style="width:55px;">TTL</th>
                            <th style="width:65px;">Proxy</th>
                        </tr>
                    </thead>
                    <tbody id="cfDnsBody"></tbody>
                </table>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const domain = <?= json_encode($account['domain']) ?>;
            const zoneId = <?= json_encode($cfZone['zone_id']) ?>;
            const zoneName = <?= json_encode($cfZone['zone']) ?>;

            // Account index (resolved server-side, no tokens exposed)
            <?php
                $cfAccIdx = 0;
                foreach (CloudflareService::getConfiguredAccounts() as $ci => $ca) {
                    if (($ca['token'] ?? '') === $cfZone['token']) { $cfAccIdx = $ci; break; }
                }
            ?>
            const accIdx = <?= $cfAccIdx ?>;

            fetch(`/settings/cloudflare-dns/records?account=${accIdx}&zone_id=${zoneId}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('cfDnsLoading').style.display = 'none';
                    if (!data.ok) {
                        document.getElementById('cfDnsLoading').style.display = '';
                        document.getElementById('cfDnsLoading').innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${data.error}</span>`;
                        return;
                    }

                    // Filter records relevant to this domain (domain itself, www, subdomains)
                    const relevant = data.records.filter(r =>
                        r.name === domain || r.name === 'www.' + domain ||
                        r.name.endsWith('.' + domain) || r.name === zoneName
                    );

                    const tbody = document.getElementById('cfDnsBody');
                    if (!relevant.length) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-2">No DNS records for this domain</td></tr>';
                    } else {
                        tbody.innerHTML = relevant.map(r => {
                            const isProxyable = ['A', 'AAAA', 'CNAME'].includes(r.type);
                            const escapedName = shortName.replace(/'/g, "\\'");
                            const proxyIcon = !isProxyable ? '<span class="text-muted">—</span>' :
                                (r.proxied
                                    ? `<i class="bi bi-cloud-fill" style="color:#f97316;cursor:pointer;" title="Proxied (click to toggle)" onclick="cfToggleProxy('${r.id}', false, ${accIdx}, '${zoneId}', '${escapedName}', '${r.type}')"></i>`
                                    : `<i class="bi bi-cloud" style="color:#94a3b8;cursor:pointer;" title="DNS Only (click to toggle)" onclick="cfToggleProxy('${r.id}', true, ${accIdx}, '${zoneId}', '${escapedName}', '${r.type}')"></i>`);
                            const shortName = r.name.replace('.' + zoneName, '').replace(zoneName, '@');
                            const ttl = r.ttl === 1 ? 'Auto' : (r.ttl >= 3600 ? (r.ttl/3600)+'h' : (r.ttl >= 60 ? (r.ttl/60)+'m' : r.ttl+'s'));
                            const shortContent = r.content.length > 40 ? r.content.substring(0, 37) + '...' : r.content;
                            const badge = {'A':'bg-info','AAAA':'bg-info','CNAME':'bg-warning text-dark','MX':'bg-success','TXT':'bg-secondary'}[r.type] || 'bg-secondary';
                            return `<tr>
                                <td class="ps-3"><span class="badge ${badge}" style="font-size:0.65rem;">${r.type}</span></td>
                                <td><code style="font-size:0.75rem;">${shortName}</code></td>
                                <td><small class="text-muted" title="${r.content}">${shortContent}</small></td>
                                <td><small class="text-muted">${ttl}</small></td>
                                <td class="text-center">${proxyIcon}</td>
                            </tr>`;
                        }).join('');
                    }
                    document.getElementById('cfDnsTable').style.display = '';
                })
                .catch(err => {
                    document.getElementById('cfDnsLoading').innerHTML = `<span class="text-danger">Error: ${err.message}</span>`;
                });
        });

        function cfToggleProxy(recordId, proxied, accIdx, zoneId, recordName, recordType) {
            const action = proxied ? 'activar' : 'desactivar';
            const icon = proxied
                ? '<i class="bi bi-cloud-fill" style="color:#f97316;font-size:1.5rem;"></i>'
                : '<i class="bi bi-cloud" style="color:#94a3b8;font-size:1.5rem;"></i>';
            const desc = proxied
                ? 'El tráfico pasará a través de Cloudflare (proxy activo). Cloudflare proporcionará SSL y protección DDoS.'
                : 'El tráfico irá directamente al servidor (DNS Only). Caddy generará el certificado SSL automáticamente.';

            Swal.fire({
                title: `${action.charAt(0).toUpperCase() + action.slice(1)} proxy?`,
                html: `<div class="text-start">
                    <div class="text-center mb-3">${icon} <i class="bi bi-arrow-right mx-2 text-muted"></i> ${proxied ? '<i class="bi bi-cloud-fill" style="color:#f97316;font-size:1.5rem;"></i>' : '<i class="bi bi-cloud" style="color:#94a3b8;font-size:1.5rem;"></i>'}</div>
                    <p><strong>${recordType}</strong> <code>${recordName || ''}</code></p>
                    <p class="small text-muted mb-0">${desc}</p>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: proxied ? '#f97316' : '#6c757d',
                cancelButtonColor: '#334155',
                confirmButtonText: proxied ? '<i class="bi bi-cloud-fill me-1"></i>Activar proxy' : '<i class="bi bi-cloud me-1"></i>DNS Only',
                cancelButtonText: 'Cancelar',
            }).then(function(result) {
                if (!result.isConfirmed) return;
                const csrf = document.querySelector('input[name="_csrf_token"]').value;
                fetch('/settings/cloudflare-dns/toggle-proxy', {
                    method: 'POST',
                    body: new URLSearchParams({
                        _csrf_token: csrf,
                        account: accIdx,
                        zone_id: zoneId,
                        record_id: recordId,
                        proxied: proxied ? '1' : '',
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        location.reload();
                    } else {
                        Swal.fire({icon: 'error', title: 'Error', text: data.error});
                    }
                })
                .catch(err => {
                    Swal.fire({icon: 'error', title: 'Error', text: err.message});
                });
            });
        }
        </script>
        <?php endif; ?>

        <!-- Domain Aliases -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 me-2"></i>Alias de Dominio</span>
                <span class="badge bg-info"><?= count($aliases ?? []) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Dominios que sirven el <strong>mismo contenido</strong> que <?= View::e($account['domain']) ?>. Caddy genera SSL para cada alias.
                </p>
                <?php if (!empty($aliases)): ?>
                <table class="table table-sm mb-3">
                    <thead><tr><th>Dominio</th><?php if (!($isSlave ?? false)): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($aliases as $alias): ?>
                        <tr>
                            <td>
                                <i class="bi bi-circle-fill text-success me-1" style="font-size:0.4rem;vertical-align:middle;"></i>
                                <?= View::e($alias['domain']) ?>
                                <span class="text-muted small ms-1">+ www</span>
                            </td>
                            <?php if (!($isSlave ?? false)): ?>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    onclick="confirmDeleteAlias(<?= (int)$account['id'] ?>, <?= (int)$alias['id'] ?>, '<?= View::e($alias['domain']) ?>', 'aliases')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if (!($isSlave ?? false)): ?>
                <form method="post" action="/accounts/<?= (int)$account['id'] ?>/aliases/add" class="d-flex gap-2 align-items-end">
                    <?= View::csrf() ?>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Nuevo alias</label>
                        <input type="text" name="domain" class="form-control form-control-sm" placeholder="ejemplo.net" required pattern="[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Añadir</button>
                </form>
                <?php elseif (empty($aliases)): ?>
                <p class="text-muted small mb-0"><i class="bi bi-lock me-1"></i>Servidor Slave — los alias se gestionan desde el Master.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Domain Redirects -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-signpost me-2"></i>Redirecciones de Dominio</span>
                <span class="badge bg-warning text-dark"><?= count($redirects ?? []) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Dominios que <strong>redirigen (301/302)</strong> a <?= View::e($account['domain']) ?>. Google transfiere el posicionamiento SEO con 301.
                </p>
                <?php if (!empty($redirects)): ?>
                <table class="table table-sm mb-3">
                    <thead><tr><th>Dominio</th><th>Código</th><th>Ruta</th><?php if (!($isSlave ?? false)): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($redirects as $redir): ?>
                        <tr>
                            <td>
                                <i class="bi bi-arrow-right-circle text-warning me-1"></i>
                                <?= View::e($redir['domain']) ?>
                                <span class="text-muted small">→ <?= View::e($account['domain']) ?></span>
                            </td>
                            <td><span class="badge <?= $redir['redirect_code'] == 301 ? 'bg-success' : 'bg-info' ?>"><?= (int)$redir['redirect_code'] ?></span></td>
                            <td><?= $redir['preserve_path'] ? '<i class="bi bi-check-lg text-success"></i> Conserva ruta' : '<i class="bi bi-x-lg text-muted"></i> Solo raíz' ?></td>
                            <?php if (!($isSlave ?? false)): ?>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    onclick="confirmDeleteAlias(<?= (int)$account['id'] ?>, <?= (int)$redir['id'] ?>, '<?= View::e($redir['domain']) ?>', 'redirects')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if (!($isSlave ?? false)): ?>
                <form method="post" action="/accounts/<?= (int)$account['id'] ?>/redirects/add" class="d-flex gap-2 align-items-end flex-wrap">
                    <?= View::csrf() ?>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Dominio a redirigir</label>
                        <input type="text" name="domain" class="form-control form-control-sm" placeholder="musedock.net" required pattern="[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">
                    </div>
                    <div>
                        <label class="form-label small text-muted mb-1">Código</label>
                        <select name="redirect_code" class="form-select form-select-sm">
                            <option value="301">301 Permanente (SEO)</option>
                            <option value="302">302 Temporal</option>
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-1 pb-1" title="Si está marcado: .net/blog/articulo → .com/blog/articulo. Si no: .net/blog/articulo → .com/">
                        <input type="checkbox" name="preserve_path" value="1" checked class="form-check-input" id="preserve-path-<?= (int)$account['id'] ?>">
                        <label class="form-check-label small" for="preserve-path-<?= (int)$account['id'] ?>">Conservar ruta <i class="bi bi-question-circle text-muted" style="font-size:0.7rem;"></i></label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-plus-circle me-1"></i>Añadir</button>
                </form>
                <?php else: ?>
                <p class="text-muted small mb-0"><i class="bi bi-lock me-1"></i>Servidor Slave — los alias y redirecciones se gestionan desde el Master.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Subdomains -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-layers me-2"></i>Subdominios</span>
                <span class="badge bg-primary"><?= count($subdomains ?? []) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Subdominios con su propia carpeta y ruta Caddy. Comparten usuario y PHP-FPM con la cuenta principal.
                </p>
                <?php if (!empty($subdomains)): ?>
                <table class="table table-sm mb-3" style="table-layout:fixed;width:100%;">
                    <thead><tr><th style="width:30%;">Subdominio</th><th style="width:auto;">Document Root</th><th style="width:60px;">Estado</th><?php if (!($isSlave ?? false)): ?><th class="text-end" style="width:120px;">Acciones</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($subdomains as $sub): ?>
                        <tr>
                            <td class="text-nowrap">
                                <i class="bi bi-globe2 text-primary me-1"></i>
                                <a href="/accounts/<?= (int)$account['id'] ?>/subdomains/<?= (int)$sub['id'] ?>/edit" class="text-decoration-none"><?= View::e($sub['subdomain']) ?></a>
                                <a href="https://<?= View::e($sub['subdomain']) ?>" target="_blank" class="text-muted ms-1" style="font-size:0.75rem;" title="Abrir en navegador"><i class="bi bi-box-arrow-up-right"></i></a>
                            </td>
                            <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= View::e($sub['document_root']) ?>"><code class="small"><?= View::e($sub['document_root']) ?></code></td>
                            <td><span class="badge badge-<?= $sub['status'] === 'active' ? 'active' : 'suspended' ?>"><?= View::e($sub['status']) ?></span></td>
                            <?php if (!($isSlave ?? false)): ?>
                            <td class="text-end text-nowrap">
                                <a href="/accounts/<?= (int)$account['id'] ?>/subdomains/<?= (int)$sub['id'] ?>/edit" class="btn btn-outline-light btn-sm py-0 px-2 me-1" title="Editar subdominio">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($sub['status'] === 'active'): ?>
                                <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 me-1" title="Suspender subdominio"
                                    onclick="confirmToggleSubdomain(<?= (int)$account['id'] ?>, <?= (int)$sub['id'] ?>, '<?= View::e($sub['subdomain']) ?>', 'suspend')">
                                    <i class="bi bi-pause-circle"></i>
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm py-0 px-2" title="Promover a cuenta independiente"
                                    onclick="confirmPromoteSubdomain(<?= (int)$account['id'] ?>, <?= (int)$sub['id'] ?>, '<?= View::e($sub['subdomain']) ?>')">
                                    <i class="bi bi-box-arrow-up"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-outline-success btn-sm py-0 px-2 me-1" title="Activar subdominio"
                                    onclick="confirmToggleSubdomain(<?= (int)$account['id'] ?>, <?= (int)$sub['id'] ?>, '<?= View::e($sub['subdomain']) ?>', 'activate')">
                                    <i class="bi bi-play-circle"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    onclick="confirmDeleteSubdomain(<?= (int)$account['id'] ?>, <?= (int)$sub['id'] ?>, '<?= View::e($sub['subdomain']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if (!($isSlave ?? false)): ?>
                <form method="post" action="/accounts/<?= (int)$account['id'] ?>/subdomains/add" class="d-flex gap-2 align-items-end">
                    <?= View::csrf() ?>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Nuevo subdominio</label>
                        <input type="text" name="subdomain" class="form-control form-control-sm" placeholder="blog.<?= View::e($account['domain']) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Crear</button>
                </form>
                <?php if (!empty($adoptableAccounts)): ?>
                <div class="mt-2 pt-2" style="border-top: 1px solid #334155;">
                    <small class="text-muted d-block mb-1"><i class="bi bi-box-arrow-in-down me-1"></i>Cuentas independientes que son subdominios de <?= View::e($account['domain']) ?>:</small>
                    <?php foreach ($adoptableAccounts as $adoptable): ?>
                    <div class="d-flex justify-content-between align-items-center mb-1 p-1 rounded" style="background:rgba(56,189,248,0.05);">
                        <span class="small">
                            <i class="bi bi-globe2 text-info me-1"></i>
                            <a href="/accounts/<?= (int)$adoptable['id'] ?>" class="text-decoration-none"><?= View::e($adoptable['domain']) ?></a>
                            <span class="text-muted">(<?= View::e($adoptable['username']) ?>)</span>
                            <span class="badge badge-<?= $adoptable['status'] === 'active' ? 'active' : 'suspended' ?> ms-1"><?= View::e($adoptable['status']) ?></span>
                        </span>
                        <button type="button" class="btn btn-outline-info btn-sm py-0 px-2"
                            onclick="confirmAdoptSubdomain(<?= (int)$account['id'] ?>, <?= (int)$adoptable['id'] ?>, '<?= View::e($adoptable['domain']) ?>', '<?= View::e($account['domain']) ?>')">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Adoptar
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted small mb-0"><i class="bi bi-lock me-1"></i>Servidor Slave — los subdominios se gestionan desde el Master.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mail -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-envelope me-2"></i>Mail</span>
                <?php if (!empty($mailDomain)): ?>
                    <a href="/mail/domains/<?= $mailDomain['id'] ?>" class="btn btn-outline-light btn-sm py-0 px-2"><i class="bi bi-eye me-1"></i>Gestionar</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($mailDomain)): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>
                            <span class="badge badge-<?= $mailDomain['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $mailDomain['status'] ?></span>
                            <?php if (!empty($mailDomain['node_name'])): ?>
                                <span class="badge bg-secondary ms-1"><i class="bi bi-hdd-network me-1"></i><?= View::e($mailDomain['node_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-1"><i class="bi bi-pc-display me-1"></i>Local</span>
                            <?php endif; ?>
                            <?php if ($mailDomain['dkim_public_key']): ?>
                                <span class="badge bg-success ms-1">DKIM</span>
                            <?php endif; ?>
                        </span>
                        <span class="text-muted small"><?= count($mailAccounts) ?> cuenta(s)</span>
                    </div>
                    <?php if (!empty($mailAccounts)): ?>
                        <div class="list-group list-group-flush" style="background:transparent;">
                            <?php foreach ($mailAccounts as $ma): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-1" style="background:transparent;border-color:#334155;">
                                <span class="small"><i class="bi bi-person me-1 text-muted"></i><?= View::e($ma['email']) ?></span>
                                <span>
                                    <span class="badge <?= $ma['status'] === 'active' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:0.65rem;"><?= $ma['status'] ?></span>
                                    <?php
                                        $usedMb = (int)($ma['used_mb'] ?? 0);
                                        $quotaMb = (int)$ma['quota_mb'];
                                        $usagePct = $quotaMb > 0 ? round(($usedMb / $quotaMb) * 100) : 0;
                                    ?>
                                    <span class="text-muted small ms-1"><?= $usedMb ?>/<?= $quotaMb ?> MB</span>
                                    <?php if ($usagePct > 85): ?>
                                        <i class="bi bi-exclamation-triangle text-warning ms-1" title="<?= $usagePct ?>% usado" style="font-size:0.7rem;"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Dominio de correo activo pero sin cuentas creadas.
                            <a href="/mail/domains/<?= $mailDomain['id'] ?>/accounts/create" class="text-info">Crear cuenta</a>
                        </p>
                    <?php endif; ?>
                <?php elseif ($mailEnabled && $hasMailNodes): ?>
                    <div class="text-center py-2">
                        <p class="text-muted small mb-2">Este dominio no tiene correo configurado.</p>
                        <a href="/mail/domains/create?domain=<?= urlencode($account['domain']) ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>Activar correo para <?= View::e($account['domain']) ?>
                        </a>
                    </div>
                <?php elseif ($mailEnabled && !$hasMailNodes): ?>
                    <div class="text-center py-2">
                        <p class="text-muted small mb-2">No hay servidor de mail configurado.</p>
                        <a href="/mail?setup=1" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-envelope me-1"></i>Configurar servidor de mail
                        </a>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0 text-center py-1">
                        <i class="bi bi-info-circle me-1"></i>Mail no habilitado.
                        <a href="/mail?setup=1" class="text-info">Configurar</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- WordPress Info -->
        <?php if (!empty($wpInfo) && $wpInfo['is_wordpress']): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-wordpress me-2"></i>WordPress</span>
                <?php if ($wpInfo['wp_cron_disabled']): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>WP-Cron desactivado</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>WP-Cron activo</span>
                <?php endif; ?>
            </div>
            <div class="card-body py-2">
                <?php if (!$wpInfo['wp_cron_disabled']): ?>
                <div class="mb-2 py-2 px-3 rounded" style="background:rgba(251,191,36,0.1);color:#fbbf24;font-size:0.85rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>WP-Cron activo:</strong> WordPress ejecuta tareas programadas (actualizaciones, emails, limpieza) en cada visita web.
                    Esto consume CPU innecesariamente. Recomendado: desactivar WP-Cron y usar un cron del sistema.
                </div>
                <?php else: ?>
                <div class="mb-2 py-2 px-3 rounded" style="background:rgba(34,197,94,0.1);color:#22c55e;font-size:0.85rem;">
                    <i class="bi bi-check-circle me-1"></i>
                    WP-Cron desactivado. WordPress no ejecuta tareas en cada visita. Si necesitas tareas programadas, configura un cron del sistema:
                    <code style="color:#38bdf8;">*/15 * * * * php <?= View::e($wpInfo['wp_config_path'] ? dirname($wpInfo['wp_config_path']) : '') ?>/wp-cron.php</code>
                </div>
                <?php endif; ?>
                <?php if (!($isSlave ?? false)): ?>
                <form method="POST" action="/accounts/<?= $account['id'] ?>/toggle-wp-cron" class="d-inline">
                    <?= View::csrf() ?>
                    <?php if ($wpInfo['wp_cron_disabled']): ?>
                        <button type="submit" class="btn btn-outline-warning btn-sm"><i class="bi bi-play-circle me-1"></i>Reactivar WP-Cron</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-pause-circle me-1"></i>Desactivar WP-Cron</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Databases -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-database me-2"></i>Databases</span>
                <?php if (!($isSlave ?? false)): ?>
                <button class="btn btn-outline-success btn-sm" onclick="showCreateDbModal()"><i class="bi bi-plus me-1"></i>Nueva BD</button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($databases)): ?>
                    <div class="p-3 text-center text-muted">No databases created yet.</div>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th class="ps-3">Name</th><th>User</th><th>Type</th><th class="text-end pe-3"></th></tr></thead>
                        <tbody>
                            <?php foreach ($databases as $db): ?>
                            <tr style="cursor:pointer;" onclick="showDbDetail(<?= (int)$db['id'] ?>, <?= htmlspecialchars(json_encode($db), ENT_QUOTES) ?>)">
                                <td class="ps-3">
                                    <code><?= View::e($db['db_name']) ?></code>
                                </td>
                                <td><code><?= View::e($db['db_user']) ?></code></td>
                                <td>
                                    <?php if (($db['db_type'] ?? 'mysql') === 'pgsql'): ?>
                                        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><i class="bi bi-database me-1"></i>PostgreSQL</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(249,115,22,0.15);color:#f97316;"><i class="bi bi-database me-1"></i>MySQL</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <i class="bi bi-chevron-right text-muted"></i>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- DB Detail / Edit Modal -->
        <div class="modal fade" id="dbDetailModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="dbDetailTitle"></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Connection Info -->
                        <div class="mb-3 p-3 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);">
                            <div class="row small">
                                <div class="col-4 text-muted">Host</div>
                                <div class="col-8"><code id="dbInfoHost">localhost</code></div>
                                <div class="col-4 text-muted">Puerto</div>
                                <div class="col-8"><code id="dbInfoPort"></code></div>
                                <div class="col-4 text-muted">Base de datos</div>
                                <div class="col-8"><code id="dbInfoName"></code></div>
                                <div class="col-4 text-muted">Usuario</div>
                                <div class="col-8"><code id="dbInfoUser"></code></div>
                                <div class="col-4 text-muted">Tipo</div>
                                <div class="col-8"><span id="dbInfoType"></span></div>
                            </div>
                        </div>

                        <?php if (!($isSlave ?? false)): ?>
                        <!-- Edit Credentials -->
                        <h6 class="text-muted mb-2"><i class="bi bi-pencil me-1"></i>Cambiar credenciales</h6>
                        <form id="dbEditForm" method="POST">
                            <?= View::csrf() ?>
                            <input type="hidden" name="redirect_to" value="/accounts/<?= $account['id'] ?>">
                            <div class="mb-2">
                                <label class="form-label small">Nuevo usuario <small class="text-muted">(dejar vacio para no cambiar)</small></label>
                                <input type="text" name="new_db_user" id="dbNewUser" class="form-control form-control-sm bg-dark text-light border-secondary">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Nueva contrasenya <small class="text-muted">(dejar vacio para no cambiar)</small></label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="new_db_pass" id="dbNewPass" class="form-control bg-dark text-light border-secondary">
                                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('dbNewPass').value=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(36)).join('').slice(0,20)" title="Generar"><i class="bi bi-shuffle"></i></button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Contrasenya admin <small class="text-danger">(obligatoria)</small></label>
                                <input type="password" name="admin_password" class="form-control form-control-sm bg-dark text-light border-secondary" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create DB Modal -->
        <?php if (!($isSlave ?? false)): ?>
        <div class="modal fade" id="createDbModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i>Crear Base de Datos</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="/databases/store" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
                        <?= View::csrf() ?>
                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                        <input type="hidden" name="redirect_to" value="/accounts/<?= $account['id'] ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label small">Tipo</label>
                                <select name="db_type" class="form-select form-select-sm bg-dark text-light border-secondary" onchange="updateDbDefaults(this.value)">
                                    <option value="mysql">MySQL / MariaDB</option>
                                    <option value="pgsql">PostgreSQL</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Nombre (sufijo)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text" style="background:#1e293b;border-color:#334155;color:#94a3b8;"><?= View::e($account['username']) ?>_</span>
                                    <input type="text" name="db_name" id="createDbName" class="form-control bg-dark text-light border-secondary" required
                                           value="db" placeholder="sufijo">
                                </div>
                                <small class="text-muted">BD: <code id="createDbFullName"><?= View::e($account['username']) ?>_db</code> | Usuario: <code id="createDbFullUser"><?= View::e($account['username']) ?>_db</code></small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Contrasenya</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="db_password" id="createDbPass" class="form-control bg-dark text-light border-secondary" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('createDbPass').value=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(36)).join('').slice(0,20)" title="Generar"><i class="bi bi-shuffle"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-secondary">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Crear</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <script>
        function showDbDetail(dbId, db) {
            document.getElementById('dbDetailTitle').innerHTML = '<i class="bi bi-database me-1"></i>' + db.db_name;
            document.getElementById('dbInfoName').textContent = db.db_name;
            document.getElementById('dbInfoUser').textContent = db.db_user;
            var isPg = (db.db_type || 'mysql') === 'pgsql';
            document.getElementById('dbInfoHost').textContent = isPg ? 'localhost' : 'localhost';
            document.getElementById('dbInfoPort').textContent = isPg ? '5432' : '3306';
            document.getElementById('dbInfoType').innerHTML = isPg
                ? '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;">PostgreSQL</span>'
                : '<span class="badge" style="background:rgba(249,115,22,0.15);color:#f97316;">MySQL</span>';
            var form = document.getElementById('dbEditForm');
            if (form) {
                form.action = '/databases/' + dbId + '/edit-credentials';
                form.querySelector('[name=new_db_user]').value = '';
                form.querySelector('[name=new_db_pass]').value = '';
                form.querySelector('[name=admin_password]').value = '';
            }
            new bootstrap.Modal(document.getElementById('dbDetailModal')).show();
        }

        function showCreateDbModal() {
            document.getElementById('createDbPass').value = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(36)).join('').slice(0, 20);
            new bootstrap.Modal(document.getElementById('createDbModal')).show();
        }

        // Update full name + user preview as user types
        document.getElementById('createDbName')?.addEventListener('input', function() {
            var full = '<?= View::e($account['username']) ?>_' + this.value;
            document.getElementById('createDbFullName').textContent = full;
            document.getElementById('createDbFullUser').textContent = full;
        });
        </script>
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

        <!-- Hosting Type -->
        <?php if (!($isSlave ?? false)): ?>
        <?php
        $currentType = $account['hosting_type'] ?? 'php';
        $detectedType = \MuseDockPanel\Services\SystemService::detectHostingType($account['document_root']);
        $typeLabels = ['php' => 'PHP', 'spa' => 'SPA', 'static' => 'Static'];
        $typeIcons = ['php' => 'bi-filetype-php', 'spa' => 'bi-window-stack', 'static' => 'bi-file-earmark-code'];
        $typeColors = ['php' => '#a78bfa', 'spa' => '#10b981', 'static' => '#38bdf8'];
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-2 me-2"></i>Hosting Type</span>
                <?php if ($currentType !== $detectedType): ?>
                <span class="badge bg-warning text-dark" style="font-size:0.65rem;" title="Auto-deteccion sugiere: <?= $typeLabels[$detectedType] ?>"><i class="bi bi-lightbulb me-1"></i>Detectado: <?= $typeLabels[$detectedType] ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body py-2">
                <div class="btn-group w-100" role="group">
                    <?php foreach (['php', 'spa', 'static'] as $t): ?>
                    <button type="button" class="btn btn-sm <?= $currentType === $t ? 'active' : '' ?>"
                        style="<?= $currentType === $t ? "background:{$typeColors[$t]};border-color:{$typeColors[$t]};color:#fff;flex:1;" : "border-color:#334155;color:#94a3b8;flex:1;" ?>"
                        <?= $currentType === $t ? 'disabled' : '' ?>
                        onclick="changeHostingType('<?= $t ?>')">
                        <i class="bi <?= $typeIcons[$t] ?> me-1"></i><?= $typeLabels[$t] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <script>
                function changeHostingType(type) {
                    var labels = {php:'PHP',spa:'SPA','static':'Static'};
                    var descs = {
                        php: 'try_files → index.php + PHP-FPM. Para WordPress, Laravel, etc.',
                        spa: 'try_files → index.html. Para React, Vue, Angular, Vite.',
                        'static': 'Solo file_server. Para HTML estatico puro.'
                    };
                    SwalDark.fire({
                        title: 'Cambiar a ' + labels[type] + '?',
                        html: 'Se reconfigurara la ruta Caddy de <strong><?= View::e($account['domain']) ?></strong>.<br><br><span class="text-muted small">' + descs[type] + '</span>',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Cambiar',
                    }).then(function(result) {
                        if (!result.isConfirmed) return;
                        var form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '/accounts/<?= $account['id'] ?>/hosting-type';
                        form.innerHTML = '<?= View::csrf() ?><input type="hidden" name="hosting_type" value="' + type + '">';
                        document.body.appendChild(form);
                        form.submit();
                    });
                }
                </script>
                <div class="small text-muted mt-2">
                    <?php if ($currentType === 'php'): ?>
                        <i class="bi bi-info-circle me-1"></i>WordPress, Laravel, PHP apps — try_files → index.php
                    <?php elseif ($currentType === 'spa'): ?>
                        <i class="bi bi-info-circle me-1"></i>React, Vue, Angular, Vite — try_files → index.html
                    <?php else: ?>
                        <i class="bi bi-info-circle me-1"></i>HTML estatico — solo file_server, sin fallback
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Federation Clones -->
        <?php if (!empty($federationClones)): ?>
        <div class="card mb-3" style="border-color:rgba(16,185,129,0.3);">
            <div class="card-header" style="color:#10b981;"><i class="bi bi-copy me-2"></i>Clones en otros servidores</div>
            <div class="card-body p-0">
                <?php foreach ($federationClones as $fc): ?>
                <?php if (!empty($fc['metadata']['superseded'])) continue; ?>
                <?php
                // Calculate clone age for staleness warning
                $lastSync = $fc['completed_at'];
                // Check if there's a more recent update_clone
                $lastUpdate = \MuseDockPanel\Database::fetchOne(
                    "SELECT completed_at FROM hosting_migrations WHERE account_id = :aid AND peer_id = :pid AND mode = 'update_clone' AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
                    ['aid' => $fc['account_id'], 'pid' => $fc['peer_id']]
                );
                if ($lastUpdate) $lastSync = $lastUpdate['completed_at'];
                $cloneAgeHours = $lastSync ? (int)((time() - strtotime($lastSync)) / 3600) : 999;
                $cloneStale = $cloneAgeHours > 24;
                ?>
                <div class="p-3 border-bottom border-secondary">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong class="text-light"><?= View::e($fc['peer_name'] ?? 'unknown') ?></strong>
                            <span class="badge bg-success ms-1">clonado</span>
                            <?php if ($cloneStale): ?>
                                <span class="badge bg-warning text-dark ms-1" title="Ultima sync hace <?= $cloneAgeHours ?>h"><i class="bi bi-clock me-1"></i><?= $cloneAgeHours ?>h</span>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted"><?= $lastSync ? date('d/m/Y H:i', strtotime($lastSync)) : '' ?></small>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-outline-info btn-sm" onclick="cloneAction('update', <?= $account['id'] ?>, <?= $fc['peer_id'] ?>, <?= $cloneAgeHours ?>)">
                            <i class="bi bi-arrow-repeat me-1"></i>Actualizar
                        </button>
                        <button class="btn btn-outline-warning btn-sm" onclick="cloneAction('reclone', <?= $account['id'] ?>, <?= $fc['peer_id'] ?>, 0)">
                            <i class="bi bi-arrow-clockwise me-1"></i>Re-clonar
                        </button>
                        <button class="btn btn-outline-success btn-sm" onclick="cloneAction('promote', <?= $account['id'] ?>, <?= $fc['peer_id'] ?>, <?= $cloneAgeHours ?>)">
                            <i class="bi bi-arrow-up-circle me-1"></i>Promover
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Clone Action Modal -->
        <div class="modal fade" id="cloneActionModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="cloneModalTitle"></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="cloneModalDesc" class="mb-3 small"></div>
                        <div class="mb-3" id="cloneUpdateOptions" style="display:none;">
                            <label class="form-label small">Que sincronizar</label>
                            <select id="clone-sync-scope" class="form-select form-select-sm bg-dark text-light border-secondary" onchange="updateScopeWarning()">
                                <option value="all">Archivos + bases de datos</option>
                                <option value="files">Solo archivos</option>
                                <option value="db">Solo bases de datos</option>
                            </select>
                            <div id="scope-warning" class="mt-2 small d-none"></div>
                        </div>
                        <div class="mb-3" id="clonePromoteOptions" style="display:none;">
                            <div class="row">
                                <div class="col-6">
                                    <label class="form-label small">DNS</label>
                                    <select id="clone-dns-mode" class="form-select form-select-sm bg-dark text-light border-secondary">
                                        <option value="auto">Automatico (Cloudflare)</option>
                                        <option value="manual">Manual</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Grace period (min)</label>
                                    <input type="number" id="clone-grace" class="form-control form-control-sm bg-dark text-light border-secondary" value="60" min="5">
                                </div>
                            </div>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="clone-sync-first" checked>
                                <label class="form-check-label small" for="clone-sync-first">Sincronizar antes de promover (recomendado)</label>
                            </div>
                        </div>
                        <div>
                            <label class="form-label small">Contrasenya del administrador</label>
                            <input type="password" id="clone-admin-password" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Introduce tu contrasenya" required>
                        </div>
                        <div id="cloneActionResult" class="mt-2 small"></div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm" id="cloneModalConfirmBtn" onclick="executeCloneAction()">
                            <i class="bi bi-check-lg me-1"></i>Confirmar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        let currentCloneAction = '', currentCloneAccountId = 0, currentClonePeerId = 0;
        const cloneDescriptions = {
            update: {
                title: '<i class="bi bi-arrow-repeat me-1"></i>Actualizar clon',
                desc: '<p>Se sincronizaran los cambios desde el servidor origen al destino.</p>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se actualizaran archivos modificados</div>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se anyadiran nuevos archivos</div>' +
                    '<div class="mb-2"><span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i></span>NO se eliminaran archivos existentes en el destino</div>' +
                    '<div class="mb-2 p-2 rounded" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.15);">' +
                    '<span class="text-warning"><i class="bi bi-database me-1"></i></span>' +
                    '<strong class="text-warning small">Las bases de datos del destino seran sobrescritas</strong> con un dump completo desde el origen. ' +
                    'Cualquier cambio en la BD del destino se perdera.</div>',
                btnClass: 'btn-info',
            },
            reclone: {
                title: '<i class="bi bi-arrow-clockwise me-1"></i>Re-clonar (sobrescribir destino)',
                desc: '<div class="p-2 mb-2 rounded" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);">' +
                    '<i class="bi bi-exclamation-triangle text-danger me-1"></i><strong class="text-danger">Esta accion eliminara COMPLETAMENTE el contenido actual en el servidor destino.</strong></div>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se borraran todos los archivos</div>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se eliminaran todas las bases de datos</div>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se recreara el hosting desde cero</div>' +
                    '<div class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>Todos los cambios realizados en el destino se perderan permanentemente.</div>',
                btnClass: 'btn-warning',
            },
            promote: {
                title: '<i class="bi bi-arrow-up-circle me-1"></i>Promover clon a produccion',
                desc: '<p>Este clon pasara a ser el servidor principal del hosting.</p>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>Se cambiara el DNS hacia el servidor destino</div>' +
                    '<div class="mb-2"><span class="text-success"><i class="bi bi-check-circle me-1"></i></span>El servidor origen dejara de ser el principal</div>' +
                    '<div class="mb-2"><span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i></span>Si el clon no esta actualizado, se pueden perder cambios recientes</div>' +
                    '<div class="text-info small"><i class="bi bi-lightbulb me-1"></i>Recomendacion: actualizar el clon antes de promover.</div>',
                btnClass: 'btn-success',
            },
        };

        function cloneAction(action, accountId, peerId, cloneAgeHours) {
            currentCloneAction = action;
            currentCloneAccountId = accountId;
            currentClonePeerId = peerId;
            const info = cloneDescriptions[action];
            let desc = info.desc;
            // Add staleness warning for promote if clone is old
            if (action === 'promote' && cloneAgeHours > 24) {
                desc = '<div class="p-2 mb-3 rounded" style="background:rgba(249,115,22,0.1);border:1px solid rgba(249,115,22,0.25);">' +
                    '<i class="bi bi-exclamation-triangle text-warning me-1"></i>' +
                    '<strong class="text-warning">Este clon tiene ' + cloneAgeHours + ' horas de antiguedad.</strong> ' +
                    'Los datos pueden estar desactualizados. Se recomienda activar "Sincronizar antes de promover".</div>' + desc;
            }
            document.getElementById('cloneModalTitle').innerHTML = info.title;
            document.getElementById('cloneModalDesc').innerHTML = desc;
            document.getElementById('cloneModalConfirmBtn').className = 'btn btn-sm ' + info.btnClass;
            document.getElementById('cloneUpdateOptions').style.display = action === 'update' ? 'block' : 'none';
            document.getElementById('clonePromoteOptions').style.display = action === 'promote' ? 'block' : 'none';
            // Auto-force sync checkbox if clone is stale (> 24h)
            if (action === 'promote' && cloneAgeHours > 24) {
                const cb = document.getElementById('clone-sync-first');
                cb.checked = true;
                cb.disabled = true; // Force enabled — too risky to promote stale clone without sync
            } else if (action === 'promote') {
                document.getElementById('clone-sync-first').disabled = false;
            }
            // Reset scope warning
            if (action === 'update') {
                document.getElementById('clone-sync-scope').value = 'all';
                document.getElementById('scope-warning').classList.add('d-none');
            }
            document.getElementById('clone-admin-password').value = '';
            document.getElementById('cloneActionResult').innerHTML = '';
            new bootstrap.Modal(document.getElementById('cloneActionModal')).show();
        }

        function updateScopeWarning() {
            const scope = document.getElementById('clone-sync-scope').value;
            const el = document.getElementById('scope-warning');
            if (scope === 'files') {
                el.innerHTML = '<div class="p-2 rounded" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.15);"><i class="bi bi-exclamation-triangle text-warning me-1"></i><span class="text-warning">Codigo nuevo + BD vieja puede causar incompatibilidades (migraciones de BD pendientes, esquemas distintos).</span></div>';
                el.classList.remove('d-none');
            } else if (scope === 'db') {
                el.innerHTML = '<div class="p-2 rounded" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.15);"><i class="bi bi-exclamation-triangle text-warning me-1"></i><span class="text-warning">BD nueva + codigo viejo puede causar errores si el codigo no soporta el esquema actualizado.</span></div>';
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        }

        function executeCloneAction() {
            const pw = document.getElementById('clone-admin-password').value;
            if (!pw) { document.getElementById('cloneActionResult').innerHTML = '<span class="text-danger">Introduce la contrasenya</span>'; return; }

            const btn = document.getElementById('cloneModalConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Ejecutando...';
            document.getElementById('cloneActionResult').innerHTML = '<span class="text-muted">Procesando...</span>';

            const body = new URLSearchParams({
                _csrf_token: '<?= View::csrfToken() ?>',
                peer_id: currentClonePeerId,
                admin_password: pw,
            });

            if (currentCloneAction === 'update') {
                body.append('sync_scope', document.getElementById('clone-sync-scope').value);
            }
            if (currentCloneAction === 'promote') {
                body.append('dns_mode', document.getElementById('clone-dns-mode').value);
                body.append('grace_period', document.getElementById('clone-grace').value);
                body.append('sync_first', document.getElementById('clone-sync-first').checked ? '1' : '');
            }

            fetch(`/accounts/${currentCloneAccountId}/federation-clone/${currentCloneAction}`, {method: 'POST', body})
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar';
                if (data.ok) {
                    let msg = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>';
                    if (currentCloneAction === 'update') msg += 'Clon actualizado correctamente';
                    else if (currentCloneAction === 'reclone') msg += 'Re-clonacion completada';
                    else msg += 'Promocion iniciada';
                    msg += '</span>';
                    if (data.data) {
                        msg += '<br><small class="text-muted">';
                        if (data.data.bytes_transferred) msg += (data.data.bytes_transferred / 1048576).toFixed(1) + ' MB transferidos | ';
                        if (data.data.rsync_duration) msg += data.data.rsync_duration + 's | ';
                        if (data.data.databases_synced !== undefined) msg += data.data.databases_synced + ' BDs sincronizadas';
                        msg += '</small>';
                    }
                    document.getElementById('cloneActionResult').innerHTML = msg;
                    if (currentCloneAction === 'promote' && data.migration_id) {
                        setTimeout(() => { location.href = `/accounts/${currentCloneAccountId}/federation-migrate?migration_id=${data.migration_id}`; }, 1500);
                    } else {
                        setTimeout(() => location.reload(), 2000);
                    }
                } else {
                    document.getElementById('cloneActionResult').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Error') + '</span>';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar';
                document.getElementById('cloneActionResult').innerHTML = '<span class="text-danger">Error de red</span>';
            });
        }
        </script>
        <?php endif; ?>

        <!-- Danger Zone -->
        <?php if ($account['status'] === 'suspended' && !($isSlave ?? false)): ?>
        <?php
            $hasMailForDelete = !empty($mailDomain);
            $dbCount = count($databases ?? []);
            $subCount = count($subdomains ?? []);
            $isMaster = \MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'master';
        ?>
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Zona de peligro</div>
            <div class="card-body">
                <p class="small text-muted">Eliminar esta cuenta permanentemente. Se eliminara el usuario del sistema, FPM pool y ruta Caddy.</p>
                <form id="deleteForm" method="POST" action="/accounts/<?= $account['id'] ?>/delete">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <input type="hidden" name="admin_password" id="delete-admin-pw" value="">
                    <input type="hidden" name="delete_files" id="delete-files-input" value="0">
                    <input type="hidden" name="delete_databases" id="delete-dbs-input" value="0">
                    <input type="hidden" name="delete_mail" id="delete-mail-input" value="0">
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="confirmDeleteAccount()"><i class="bi bi-trash me-1"></i> Eliminar cuenta</button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <?php if (!($isSlave ?? false)): ?>
        <div class="small mb-0 py-2 px-3 rounded" style="background:rgba(100,116,139,0.15);color:#94a3b8;">
            <i class="bi bi-info-circle me-1"></i>El boton de eliminar solo aparece cuando la cuenta esta suspendida — es una medida de seguridad para evitar borrar cuentas activas por accidente.
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmDeleteAlias(accountId, aliasId, domain, type) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    const label = type === 'aliases' ? 'alias' : 'redirección';

    S.fire({
        title: 'Eliminar ' + label,
        html: '<p>Eliminar ' + label + ' <strong>' + domain + '</strong>?</p>' +
              '<p class="small text-muted">Se eliminará la ruta de Caddy y el registro DNS deberá gestionarse manualmente.</p>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="alias-delete-pw" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('alias-delete-pw').value;
            if (!pw) { Swal.showValidationMessage('La contraseña es obligatoria'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + accountId + '/' + type + '/' + aliasId + '/delete';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_csrf_token'; csrfInput.value = csrf;
        form.appendChild(csrfInput);

        var pwInput = document.createElement('input');
        pwInput.type = 'hidden'; pwInput.name = 'admin_password'; pwInput.value = result.value;
        form.appendChild(pwInput);

        document.body.appendChild(form);
        form.submit();
    });
}

function confirmAdoptSubdomain(parentId, childId, childDomain, parentDomain) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;

    S.fire({
        title: 'Adoptar como subdominio',
        html: '<p>Convertir la cuenta independiente <strong>' + childDomain + '</strong> en subdominio de <strong>' + parentDomain + '</strong>?</p>' +
              '<div class="text-start small" style="color:#94a3b8;">' +
              '<p class="mb-1"><strong>Esto hara:</strong></p>' +
              '<ul style="padding-left:1.2rem;">' +
              '<li>Mover archivos a la carpeta del dominio padre</li>' +
              '<li>Eliminar el usuario Linux y FPM pool independiente</li>' +
              '<li>Crear ruta Caddy usando el FPM pool del padre</li>' +
              '<li>Reasignar las bases de datos a la cuenta padre</li>' +
              '</ul>' +
              '<p class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>La carpeta original se conserva por seguridad.</p>' +
              '</div>' +
              '<div class="mb-2"><label class="form-label small">Contrasena de administrador</label>' +
              '<input type="password" id="adopt-pw" class="form-control" placeholder="Confirmar con tu contrasena" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-box-arrow-in-down me-1"></i>Adoptar',
        confirmButtonColor: '#0ea5e9',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('adopt-pw').value;
            if (!pw) { Swal.showValidationMessage('La contrasena es obligatoria'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + parentId + '/subdomains/adopt';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var fields = {
            '_csrf_token': csrf,
            'admin_password': result.value,
            'child_account_id': childId
        };
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = key; input.value = fields[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });
}

function confirmToggleSubdomain(accountId, subId, subdomain, action) {
    var isSuspend = action === 'suspend';
    var S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;

    S.fire({
        title: isSuspend ? 'Suspender subdominio' : 'Activar subdominio',
        html: isSuspend
            ? '<p>Suspender <strong>' + subdomain + '</strong>?</p><p class="small text-muted">Se reemplazará la ruta Caddy con una página de mantenimiento. Los archivos no se eliminan.</p>'
            : '<p>Activar <strong>' + subdomain + '</strong>?</p><p class="small text-muted">Se restaurará la ruta Caddy y el subdominio volverá a estar en línea.</p>',
        icon: isSuspend ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: isSuspend ? '<i class="bi bi-pause-circle me-1"></i>Suspender' : '<i class="bi bi-play-circle me-1"></i>Activar',
        confirmButtonColor: isSuspend ? '#eab308' : '#22c55e',
        cancelButtonText: 'Cancelar',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + accountId + '/subdomains/' + subId + '/toggle-status';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_csrf_token'; csrfInput.value = csrf;
        form.appendChild(csrfInput);

        document.body.appendChild(form);
        form.submit();
    });
}

function confirmDeleteSubdomain(accountId, subId, subdomain) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;

    S.fire({
        title: 'Eliminar subdominio',
        html: '<p>Eliminar subdominio <strong>' + subdomain + '</strong>?</p>' +
              '<p class="small text-muted">Se eliminará la ruta Caddy. Los archivos se pueden conservar o eliminar.</p>' +
              '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="sub-delete-files"><label class="form-check-label small" for="sub-delete-files">Eliminar también los archivos del subdominio</label></div>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="sub-delete-pw" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('sub-delete-pw').value;
            if (!pw) { Swal.showValidationMessage('La contraseña es obligatoria'); return false; }
            return { password: pw, deleteFiles: document.getElementById('sub-delete-files').checked };
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + accountId + '/subdomains/' + subId + '/delete';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_csrf_token'; csrfInput.value = csrf;
        form.appendChild(csrfInput);

        var pwInput = document.createElement('input');
        pwInput.type = 'hidden'; pwInput.name = 'admin_password'; pwInput.value = result.value.password;
        form.appendChild(pwInput);

        if (result.value.deleteFiles) {
            var delInput = document.createElement('input');
            delInput.type = 'hidden'; delInput.name = 'delete_files'; delInput.value = '1';
            form.appendChild(delInput);
        }

        document.body.appendChild(form);
        form.submit();
    });
}

function confirmPromoteSubdomain(accountId, subId, subdomain) {
    var S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;

    S.fire({
        title: 'Promover a cuenta independiente',
        html: '<p>Convertir <strong>' + subdomain + '</strong> en una cuenta de hosting independiente?</p>' +
              '<div class="text-start small" style="color:#94a3b8;">' +
              '<p class="mb-1"><strong>Esto hara:</strong></p>' +
              '<ul style="padding-left:1.2rem;">' +
              '<li>Crear un nuevo usuario Linux para el subdominio</li>' +
              '<li>Crear un nuevo PHP-FPM pool independiente</li>' +
              '<li>Mover archivos a su propia carpeta vhost</li>' +
              '<li>Crear nueva ruta Caddy independiente</li>' +
              '<li>Eliminar el registro de subdominio</li>' +
              '</ul>' +
              '</div>' +
              '<div class="mb-2"><label class="form-label small">Contrasena de administrador</label>' +
              '<input type="password" id="promote-pw" class="form-control" placeholder="Confirmar con tu contrasena" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-box-arrow-up me-1"></i>Promover',
        confirmButtonColor: '#eab308',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('promote-pw').value;
            if (!pw) { Swal.showValidationMessage('La contrasena es obligatoria'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + accountId + '/subdomains/' + subId + '/promote';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var fields = {
            '_csrf_token': csrf,
            'admin_password': result.value
        };
        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = key; input.value = fields[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });
}

function confirmDeleteAccount() {
    var S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    var domain = <?= json_encode($account['domain']) ?>;
    var dbCount = <?= $dbCount ?? 0 ?>;
    var subCount = <?= $subCount ?? 0 ?>;
    var hasMail = <?= $hasMailForDelete ? 'true' : 'false' ?>;
    var mailCount = <?= count($mailAccounts ?? []) ?>;
    var isMaster = <?= ($isMaster ?? false) ? 'true' : 'false' ?>;

    var optionsHtml = '<div style="text-align:left;margin-top:10px;">';

    // Files option
    optionsHtml += '<div style="padding:8px 10px;background:rgba(239,68,68,0.08);border-radius:6px;margin-bottom:8px;">' +
        '<label style="color:#f87171;font-size:0.85rem;cursor:pointer;display:flex;align-items:center;">' +
        '<input type="checkbox" id="swal-delete-files" style="margin-right:8px;"> Eliminar archivos (home directory completo)</label></div>';

    // Databases option
    if (dbCount > 0) {
        optionsHtml += '<div style="padding:8px 10px;background:rgba(239,68,68,0.08);border-radius:6px;margin-bottom:8px;">' +
            '<label style="color:#f87171;font-size:0.85rem;cursor:pointer;display:flex;align-items:center;">' +
            '<input type="checkbox" id="swal-delete-dbs" style="margin-right:8px;"> Eliminar ' + dbCount + ' base(s) de datos y usuarios DB</label></div>';
    }

    // Subdomains info
    if (subCount > 0) {
        optionsHtml += '<div style="padding:8px 10px;background:rgba(251,191,36,0.1);border-radius:6px;margin-bottom:8px;">' +
            '<span style="color:#fbbf24;font-size:0.85rem;"><i class="bi bi-info-circle me-1"></i>' + subCount + ' subdominio(s) seran eliminados automaticamente</span></div>';
    }

    // Mail option
    if (hasMail) {
        optionsHtml += '<div style="padding:8px 10px;background:rgba(239,68,68,0.08);border-radius:6px;margin-bottom:8px;">' +
            '<label style="color:#f87171;font-size:0.85rem;cursor:pointer;display:flex;align-items:center;">' +
            '<input type="checkbox" id="swal-delete-mail" style="margin-right:8px;"> Eliminar correo (' + mailCount + ' cuenta/s, buzones, DKIM)</label></div>';
    }

    // Cluster warning
    if (isMaster) {
        optionsHtml += '<div style="padding:8px 10px;background:rgba(14,165,233,0.1);border-radius:6px;margin-bottom:8px;">' +
            '<span style="color:#38bdf8;font-size:0.85rem;"><i class="bi bi-hdd-network me-1"></i>La eliminacion se propagara a todos los nodos slave del cluster</span></div>';
    }

    optionsHtml += '</div>';

    // Password field
    optionsHtml += '<div class="mb-2 mt-3"><label class="form-label small">Contrasena de administrador</label>' +
        '<input type="password" id="swal-delete-pw" class="form-control" placeholder="Confirmar con tu contrasena" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>';

    S.fire({
        title: 'Eliminar ' + domain + '?',
        html: '<p style="color:#ef4444;font-weight:600;">Esta accion es PERMANENTE y no se puede deshacer.</p>' +
              '<p style="color:#94a3b8;font-size:0.9rem;">Se eliminara: usuario del sistema, PHP-FPM pool, ruta Caddy, aliases y redirecciones.</p>' +
              optionsHtml,
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i>Eliminar permanentemente',
        confirmButtonColor: '#dc2626',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('swal-delete-pw').value;
            if (!pw) { Swal.showValidationMessage('La contrasena es obligatoria'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        document.getElementById('delete-admin-pw').value = result.value;
        document.getElementById('delete-files-input').value = document.getElementById('swal-delete-files')?.checked ? '1' : '0';
        if (document.getElementById('swal-delete-dbs')) {
            document.getElementById('delete-dbs-input').value = document.getElementById('swal-delete-dbs').checked ? '1' : '0';
        }
        if (document.getElementById('swal-delete-mail')) {
            document.getElementById('delete-mail-input').value = document.getElementById('swal-delete-mail').checked ? '1' : '0';
        }

        document.getElementById('deleteForm').submit();
    });
}

// Show loading modal when adding alias, redirect or subdomain
document.querySelectorAll(
    'form[action*="/aliases/add"], form[action*="/redirects/add"], form[action*="/subdomains/add"]'
).forEach(function(form) {
    form.addEventListener('submit', function() {
        var label = 'Procesando...';
        if (form.action.indexOf('aliases') !== -1) label = 'Configurando alias...';
        else if (form.action.indexOf('redirects') !== -1) label = 'Configurando redireccion...';
        else if (form.action.indexOf('subdomains') !== -1) label = 'Creando subdominio...';
        S.fire({
            title: label,
            html: '<div class="py-3"><span class="spinner-border text-info"></span><div class="mt-2" style="color:#94a3b8;font-size:0.9rem;">Configurando Caddy + SSL...</div></div>',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
        });
        // Form submits normally (no preventDefault) — page reloads after server redirect
    });
});
</script>
