<?php use MuseDockPanel\View;
$fmtBw = function($b) { if ($b >= 1073741824) return round($b/1073741824, 2) . ' GB'; if ($b >= 1048576) return round($b/1048576, 1) . ' MB'; if ($b > 0) return round($b/1024, 1) . ' KB'; return '0'; };
?>

<div class="mb-3 d-flex justify-content-between align-items-center">
    <a href="/accounts/<?= $account['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver a <?= View::e($account['domain']) ?></a>
    <div class="btn-group btn-group-sm">
        <?php foreach (['1d' => 'Hoy', '7d' => '7 dias', '30d' => '30 dias', '1y' => '1 ano'] as $r => $label): ?>
        <a href="/accounts/<?= $account['id'] ?>/stats?range=<?= $r ?>" class="btn btn-outline-light <?= $currentRange === $r ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card w-100 text-center py-3">
            <div class="stat-value text-info"><?= number_format($stats['unique_visitors']) ?></div>
            <div class="stat-label">Visitantes unicos</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card w-100 text-center py-3">
            <div class="stat-value"><?= number_format(array_sum($stats['status_codes'])) ?></div>
            <div class="stat-label">Total Requests</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card w-100 text-center py-3">
            <div class="stat-value text-info"><i class="bi bi-arrow-up" style="font-size:0.8rem;"></i> <?= $fmtBw($bwMonthly['bytes_out'] ?? 0) ?></div>
            <div class="stat-label">Salida (mes)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card w-100 text-center py-3">
            <div class="stat-value text-success"><i class="bi bi-arrow-down" style="font-size:0.8rem;"></i> <?= $fmtBw($bwMonthly['bytes_in'] ?? 0) ?></div>
            <div class="stat-label">Entrada (mes)</div>
        </div>
    </div>
</div>

<!-- Visitors per day chart -->
<?php if (!empty($stats['days'])): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up me-2"></i>Visitantes unicos por dia</div>
    <div class="card-body" style="height:180px;position:relative;">
        <canvas id="visitorsChart"></canvas>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('visitorsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($d) => date('d/m/Y', strtotime($d['date'])), $stats['days'])) ?>,
        datasets: [{
            label: 'Visitantes',
            data: <?= json_encode(array_column($stats['days'], 'unique_ips')) ?>,
            backgroundColor: 'rgba(56,189,248,0.5)',
            borderColor: '#38bdf8',
            borderWidth: 1,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(51,65,85,0.3)' }, ticks: { color: '#64748b' } },
            y: { beginAtZero: true, grid: { display: false }, ticks: { color: '#64748b' } },
        }
    }
});
</script>
<?php endif; ?>

<div class="row g-3">
    <!-- Top Pages -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>Top Paginas</div>
            <div class="card-body p-0" style="max-height:400px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">URL</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($stats['pages'], 0, 25) as $p): ?>
                    <tr>
                        <td class="ps-3"><code class="small" style="word-break:break-all;"><?= View::e($p['name']) ?></code></td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stats['pages'])): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top IPs -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-pc-display me-2"></i>Top IPs</div>
            <div class="card-body p-0" style="max-height:400px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">IP</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($stats['ips'], 0, 25) as $p): ?>
                    <tr>
                        <td class="ps-3"><code><?= View::e($p['name']) ?></code></td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stats['ips'])): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Countries -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-globe me-2"></i>Paises</div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">Pais</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($stats['countries'], 0, 20) as $p): ?>
                    <tr>
                        <td class="ps-3"><?= View::e($p['name']) ?></td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stats['countries'])): ?><tr><td colspan="2" class="text-center text-muted py-2"><small>Solo disponible para dominios con Cloudflare proxy</small></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Referrers -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-link-45deg me-2"></i>Referrers</div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">Origen</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php
                    $refUrls = $stats['referrer_urls'] ?? [];
                    foreach (array_slice($stats['referrers'], 0, 20) as $p):
                        $domain = $p['name'];
                        $storedUrl = $refUrls[$domain] ?? '';
                        // Use stored URL if it has a meaningful path, otherwise just the domain
                        $parsedUrl = $storedUrl ? parse_url($storedUrl) : [];
                        $hasPath = !empty($parsedUrl['path']) && $parsedUrl['path'] !== '/';
                        $linkUrl = $storedUrl ?: "https://{$domain}";
                        if (!str_starts_with($linkUrl, 'http')) $linkUrl = "https://{$linkUrl}";
                    ?>
                    <tr>
                        <td class="ps-3">
                            <a href="<?= View::e($linkUrl) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                <code class="small"><?= View::e($domain) ?></code>
                                <i class="bi bi-box-arrow-up-right text-muted ms-1" style="font-size:0.6rem;"></i>
                            </a>
                            <?php if ($hasPath): ?>
                            <a href="<?= View::e($storedUrl) ?>" target="_blank" rel="noopener" class="text-muted ms-1" style="font-size:0.7rem;" title="<?= View::e($storedUrl) ?>">
                                <?= View::e($parsedUrl['path'] ?? '') ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stats['referrers'])): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Browsers (humans) -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-browser-chrome me-2"></i>Navegadores</div>
            <div class="card-body p-0">
                <?php
                $botNames = ['Googlebot','Bingbot','YandexBot','Baidu','AhrefsBot','SemrushBot','MajesticBot','curl','Python','Go Bot','Other Bot','Facebook','Twitter','LinkedIn','DuckDuckBot','Yahoo','Applebot'];
                $browsers = array_filter($stats['user_agents'], fn($p) => !in_array($p['name'], $botNames));
                $bots = array_filter($stats['user_agents'], fn($p) => in_array($p['name'], $botNames));
                $totalBrowsers = max(1, array_sum(array_column($browsers, 'count')));
                ?>
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">Navegador</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice(array_values($browsers), 0, 10) as $p):
                        $pct = round($p['count'] / $totalBrowsers * 100, 1);
                    ?>
                    <tr>
                        <td class="ps-3">
                            <?= View::e($p['name']) ?>
                            <small class="text-muted ms-1">(<?= $pct ?>%)</small>
                        </td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($browsers)): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Bots -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-robot me-2"></i>Bots / Crawlers</div>
            <div class="card-body p-0">
                <?php $totalBots = max(1, array_sum(array_column($bots, 'count'))); ?>
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th class="ps-3">Bot</th><th class="text-end pe-3">Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice(array_values($bots), 0, 15) as $p):
                        $pct = round($p['count'] / $totalBots * 100, 1);
                    ?>
                    <tr>
                        <td class="ps-3">
                            <?= View::e($p['name']) ?>
                            <small class="text-muted ms-1">(<?= $pct ?>%)</small>
                        </td>
                        <td class="text-end pe-3"><?= number_format($p['count']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bots)): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Status Codes + Methods -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Codigos HTTP / Metodos</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <h6 class="text-muted small">Status Codes</h6>
                        <?php
                        $statusColors = ['2' => 'success', '3' => 'info', '4' => 'warning', '5' => 'danger'];
                        arsort($stats['status_codes']);
                        foreach ($stats['status_codes'] as $code => $count):
                            $color = $statusColors[substr($code, 0, 1)] ?? 'secondary';
                        ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="badge bg-<?= $color ?>"><?= $code ?></span>
                            <span><?= number_format($count) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($stats['status_codes'])): ?><small class="text-muted">Sin datos</small><?php endif; ?>
                    </div>
                    <div class="col-6">
                        <h6 class="text-muted small">Methods</h6>
                        <?php
                        arsort($stats['methods']);
                        foreach ($stats['methods'] as $method => $count):
                        ?>
                        <div class="d-flex justify-content-between mb-1">
                            <code><?= View::e($method) ?></code>
                            <span><?= number_format($count) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($stats['methods'])): ?><small class="text-muted">Sin datos</small><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
