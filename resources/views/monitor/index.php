<?php use MuseDockPanel\View; use MuseDockPanel\Services\MonitorService; ?>

<!-- Health Score + Alert Badge -->
<div class="row g-3 mb-4">
    <div class="col-md-6 d-flex">
        <div class="stat-card w-100 d-flex align-items-center gap-3">
            <?php
                $hClass = 'text-success';
                $hLabel = 'OK';
                if ($healthScore < 20) { $hClass = 'text-danger'; $hLabel = 'CRITICAL'; }
                elseif ($healthScore < 50) { $hClass = 'text-danger'; $hLabel = 'PROBLEMS'; }
                elseif ($healthScore < 80) { $hClass = 'text-warning'; $hLabel = 'LOADED'; }
            ?>
            <div>
                <div class="stat-value <?= $hClass ?>" style="font-size:2.2rem"><?= $healthScore ?></div>
                <div class="stat-label">Health Score</div>
            </div>
            <div>
                <span class="badge <?= $healthScore >= 80 ? 'bg-success' : ($healthScore >= 50 ? 'bg-warning' : 'bg-danger') ?>" style="font-size:0.9rem">
                    <?= $hLabel ?>
                </span>
            </div>
            <div class="ms-auto">
                <i class="bi bi-heart-pulse stat-icon" style="font-size:2.5rem"></i>
            </div>
        </div>
    </div>
    <div class="col-md-6 d-flex">
        <div class="stat-card w-100 d-flex align-items-center gap-3">
            <div>
                <div class="stat-value"><?= $alertCount ?></div>
                <div class="stat-label">Unacknowledged Alerts</div>
            </div>
            <div class="ms-auto">
                <i class="bi bi-bell<?= $alertCount > 0 ? '-fill text-warning' : '' ?> stat-icon" style="font-size:2.5rem"></i>
            </div>
        </div>
    </div>
</div>

<!-- Stat Cards — Current bandwidth per interface + CPU + RAM -->
<div class="row g-3 mb-4">
    <?php foreach ($interfaces as $iface): ?>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" id="card-<?= View::e($iface) ?>-rx">--</div>
                    <div class="stat-label"><?= View::e($iface) ?> RX (In)</div>
                </div>
                <i class="bi bi-arrow-down-circle stat-icon" style="color:#22c55e"></i>
            </div>
            <div class="mt-auto pt-2">
                <small class="text-muted">TX (Out): <span id="card-<?= View::e($iface) ?>-tx">--</span></small>
            </div>
            <?php if (!empty($ifaceIPs[$iface])): ?>
            <div><small class="text-muted"><i class="bi bi-hdd-network me-1"></i><?= View::e($ifaceIPs[$iface]) ?></small></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" id="card-cpu">--</div>
                    <div class="stat-label">CPU</div>
                </div>
                <i class="bi bi-cpu stat-icon"></i>
            </div>
            <div class="progress mt-auto"><div class="progress-bar bg-info" id="bar-cpu" style="width:0%"></div></div>
        </div>
    </div>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" id="card-ram">--</div>
                    <div class="stat-label">RAM</div>
                </div>
                <i class="bi bi-memory stat-icon"></i>
            </div>
            <div class="progress mt-auto"><div class="progress-bar bg-warning" id="bar-ram" style="width:0%"></div></div>
        </div>
    </div>
</div>

<!-- GPU Cards (only if GPUs detected) -->
<?php if (!empty($gpus)): ?>
<div class="row g-3 mb-4">
    <?php foreach ($gpus as $gpu): ?>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" id="card-gpu<?= $gpu['index'] ?>-util">--</div>
                    <div class="stat-label">GPU<?= $gpu['index'] ?> Util</div>
                </div>
                <i class="bi bi-gpu-card stat-icon" style="color:#a855f7"></i>
            </div>
            <div class="mt-1">
                <small class="text-muted"><?= View::e($gpu['name']) ?></small>
            </div>
            <div class="mt-1 d-flex gap-3">
                <small class="text-muted">Mem: <span id="card-gpu<?= $gpu['index'] ?>-mem">--</span></small>
                <small class="text-muted">Temp: <span id="card-gpu<?= $gpu['index'] ?>-temp">--</span></small>
                <small class="text-muted">Power: <span id="card-gpu<?= $gpu['index'] ?>-power">--</span></small>
            </div>
            <div class="progress mt-auto pt-2"><div class="progress-bar" id="bar-gpu<?= $gpu['index'] ?>" style="width:0%;background:#a855f7"></div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Chart Controls + Main Chart -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-graph-up me-1"></i>
            <span>Network Traffic</span>
            <select id="ifaceSelect" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($interfaces as $iface): ?>
                <option value="<?= View::e($iface) ?>"><?= View::e($iface) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="btn-group btn-group-sm" id="rangeButtons">
            <button class="btn btn-outline-light active" data-range="1h">1h</button>
            <button class="btn btn-outline-light" data-range="6h">6h</button>
            <button class="btn btn-outline-light" data-range="24h">24h</button>
            <button class="btn btn-outline-light" data-range="7d">7d</button>
            <button class="btn btn-outline-light" data-range="30d">30d</button>
            <button class="btn btn-outline-light" data-range="1y">1y</button>
        </div>
    </div>
    <div class="card-body" style="height:350px;position:relative">
        <canvas id="netChart"></canvas>
        <div id="chartEmpty" class="text-muted text-center py-5" style="display:none">
            <i class="bi bi-hourglass-split fs-1 d-block mb-2"></i>
            No data yet. Collector needs to run first.
        </div>
    </div>
</div>

<!-- CPU + RAM Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-cpu me-2"></i>CPU Usage</div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="cpuChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-memory me-2"></i>RAM Usage</div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="ramChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- GPU Charts (only if GPUs detected) -->
<?php if (!empty($gpus)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-gpu-card me-2"></i>GPU Utilization<?php if (count($gpus) === 1): ?> — <?= View::e($gpus[0]['name']) ?><?php endif; ?></div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="gpuUtilChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-thermometer-half me-2"></i>GPU Temperature<?php if (count($gpus) === 1): ?> — <?= View::e($gpus[0]['name']) ?><?php endif; ?></div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="gpuTempChart"></canvas>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-memory me-2"></i>GPU Memory<?php if (count($gpus) === 1): ?> — <?= View::e($gpus[0]['name']) ?><?php endif; ?></div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="gpuMemChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>GPU Power<?php if (count($gpus) === 1): ?> — <?= View::e($gpus[0]['name']) ?><?php endif; ?></div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="gpuPowerChart"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Alerts Panel -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bell me-2"></i>Alerts</span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warning" id="alertBadge" style="display:none">0</span>
            <button class="btn btn-outline-danger btn-sm" onclick="clearAllAlerts()" id="btnClearAlerts" style="display:none"><i class="bi bi-trash me-1"></i>Clear All</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Time</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Value</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="alertsBody">
                    <tr><td colspan="6" class="text-center text-muted py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Alert Settings -->
<div class="card mb-4">
    <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#alertSettingsBody" style="cursor:pointer">
        <i class="bi bi-gear me-2"></i>Alert Settings
        <i class="bi bi-chevron-down float-end"></i>
    </div>
    <div class="collapse" id="alertSettingsBody">
        <div class="card-body">
            <form method="POST" action="/monitor/settings">
                <?= View::csrf() ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="monitorEnabled" name="monitor_enabled" value="1" <?= ($alertSettings['enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="monitorEnabled">Alerts enabled</label>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">CPU threshold (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_cpu" value="<?= View::e($alertSettings['cpu'] ?? '90') ?>" min="0" max="100">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">RAM threshold (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_ram" value="<?= View::e($alertSettings['ram'] ?? '90') ?>" min="0" max="100">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Network threshold (Mbps)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_net_mbps" value="<?= View::e($alertSettings['net_mbps'] ?? '800') ?>" min="0" max="100000">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                </div>
                <?php if (!empty($gpus)): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">GPU temperature (°C)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_gpu_temp" value="<?= View::e($alertSettings['gpu_temp'] ?? '85') ?>" min="0" max="110">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">GPU utilization (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_gpu_util" value="<?= View::e($alertSettings['gpu_util'] ?? '95') ?>" min="0" max="100">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save</button>
            </form>
        </div>
    </div>
</div>

<!-- Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<script>
(function() {
    const HOST = '<?= View::e($host) ?>';
    const CSRF = '<?= $_SESSION['_csrf_token'] ?? '' ?>';

    let currentIface = '<?= View::e($interfaces[0] ?? 'eth0') ?>';
    let currentRange = '1h';
    let netChart, cpuChart, ramChart;
    let gpuUtilChart, gpuTempChart, gpuMemChart, gpuPowerChart;
    let refreshTimer = null;
    const GPU_COUNT = <?= count($gpus ?? []) ?>;
    const GPU_NAMES = <?= json_encode(array_map(fn($g) => $g['name'], $gpus ?? [])) ?>;
    const GPU_COLORS = ['#a855f7', '#ec4899', '#f97316', '#06b6d4'];

    // Panel timezone from Settings > Server (e.g. 'UTC', 'Asia/Tokyo', 'Europe/Madrid')
    const PANEL_TZ = '<?= View::e($panelTz ?? "UTC") ?>';

    // API returns Unix epoch (seconds, always UTC).
    // We store raw epoch ms as chart X values (linear scale, NOT time scale).
    // All formatting is done by us via Intl.DateTimeFormat with PANEL_TZ.
    // This completely bypasses Chart.js date-fns adapter = zero TZ bugs.

    const _tzFmtShort = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, hour: '2-digit', minute: '2-digit' });
    const _tzFmtFull  = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const _tzFmtDay   = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, month: 'short', day: 'numeric' });

    function fmtTzShort(ms) { return _tzFmtShort.format(new Date(ms)); }
    function fmtTzFull(ms)  { return _tzFmtFull.format(new Date(ms)); }
    function fmtTzDay(ms)   { return _tzFmtDay.format(new Date(ms)); }

    function parseUTC(epoch) {
        return Number(epoch) * 1000; // just convert to ms, keep as number
    }

    // ─── Format helpers ──────────────────────────────────────
    function fmtBytes(b) {
        if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB/s';
        if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB/s';
        if (b >= 1024) return (b / 1024).toFixed(2) + ' KB/s';
        return Math.round(b) + ' B/s';
    }

    function fmtTime(ts) {
        const d = new Date(ts);
        return d.toLocaleTimeString('es-ES', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // ─── Chart theme ─────────────────────────────────────────
    const gridColor = 'rgba(51,65,85,0.5)';
    const tickColor = '#64748b';
    const tooltipBg = '#1e293b';
    const tooltipBorder = '#334155';

    function chartDefaults() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#94a3b8', usePointStyle: true, pointStyle: 'line' } },
                tooltip: {
                    backgroundColor: tooltipBg,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                    titleColor: '#f1f5f9',
                    bodyColor: '#e2e8f0',
                    callbacks: {
                        title: (items) => {
                            if (!items.length) return '';
                            return fmtTzFull(items[0].parsed.x) + ' (' + PANEL_TZ + ')';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    grid: { color: gridColor },
                    ticks: {
                        color: tickColor,
                        maxTicksLimit: 12,
                        callback: function(value) {
                            // Pick format based on range
                            if (['30d','1y'].includes(currentRange)) return fmtTzDay(value);
                            return fmtTzShort(value);
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: { color: tickColor }
                }
            }
        };
    }

    // ─── Init charts ─────────────────────────────────────────
    function initCharts() {
        const netOpts = chartDefaults();
        netOpts.scales.y.ticks.callback = (v) => fmtBytes(v);
        netOpts.plugins.tooltip.callbacks = {
            title: (items) => {
                if (!items.length) return '';
                return fmtTzFull(items[0].parsed.x) + ' (' + PANEL_TZ + ')';
            },
            label: (ctx) => ctx.dataset.label + ': ' + fmtBytes(ctx.parsed.y)
        };

        netChart = new Chart(document.getElementById('netChart'), {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'RX (In)',
                        data: [],
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1.5,
                    },
                    {
                        label: 'TX (Out)',
                        data: [],
                        borderColor: '#38bdf8',
                        backgroundColor: 'rgba(56,189,248,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1.5,
                    }
                ]
            },
            options: netOpts
        });

        const cpuOpts = chartDefaults();
        cpuOpts.scales.y.max = 100;
        cpuOpts.scales.y.ticks.callback = (v) => v + '%';

        cpuChart = new Chart(document.getElementById('cpuChart'), {
            type: 'line',
            data: {
                datasets: [{
                    label: 'CPU %',
                    data: [],
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14,165,233,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 1.5,
                }]
            },
            options: cpuOpts
        });

        const ramOpts = chartDefaults();
        ramOpts.scales.y.max = 100;
        ramOpts.scales.y.ticks.callback = (v) => v + '%';

        ramChart = new Chart(document.getElementById('ramChart'), {
            type: 'line',
            data: {
                datasets: [{
                    label: 'RAM %',
                    data: [],
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 1.5,
                }]
            },
            options: ramOpts
        });

        // GPU charts (only if GPUs exist)
        if (GPU_COUNT > 0 && document.getElementById('gpuUtilChart')) {
            function gpuDatasets() {
                const ds = [];
                for (let i = 0; i < GPU_COUNT; i++) {
                    ds.push({
                        label: 'GPU' + i + (GPU_NAMES[i] ? ' — ' + GPU_NAMES[i] : ''),
                        data: [],
                        borderColor: GPU_COLORS[i % GPU_COLORS.length],
                        backgroundColor: GPU_COLORS[i % GPU_COLORS.length] + '14',
                        fill: true, tension: 0.3, pointRadius: 0, borderWidth: 1.5,
                    });
                }
                return ds;
            }

            const gpuPctOpts = chartDefaults();
            gpuPctOpts.scales.y.max = 100;
            gpuPctOpts.scales.y.ticks.callback = (v) => v + '%';

            gpuUtilChart = new Chart(document.getElementById('gpuUtilChart'), {
                type: 'line', data: { datasets: gpuDatasets() }, options: gpuPctOpts
            });
            const gpuMemOpts = chartDefaults();
            gpuMemOpts.scales.y.max = 100;
            gpuMemOpts.scales.y.ticks.callback = (v) => v + '%';
            gpuMemChart = new Chart(document.getElementById('gpuMemChart'), {
                type: 'line', data: { datasets: gpuDatasets() }, options: gpuMemOpts
            });

            const gpuTempOpts = chartDefaults();
            gpuTempOpts.scales.y.ticks.callback = (v) => v + '°C';
            gpuTempChart = new Chart(document.getElementById('gpuTempChart'), {
                type: 'line', data: { datasets: gpuDatasets() }, options: gpuTempOpts
            });

            const gpuPwrOpts = chartDefaults();
            gpuPwrOpts.scales.y.ticks.callback = (v) => v + 'W';
            gpuPowerChart = new Chart(document.getElementById('gpuPowerChart'), {
                type: 'line', data: { datasets: gpuDatasets() }, options: gpuPwrOpts
            });
        }
    }

    // ─── Load chart data ─────────────────────────────────────
    async function loadNetChart() {
        try {
            const [rxResp, txResp] = await Promise.all([
                fetch(`/monitor/api/metrics?host=${HOST}&metric=net_${currentIface}_rx&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=net_${currentIface}_tx&range=${currentRange}`)
            ]);
            const rxJson = await rxResp.json();
            const txJson = await txResp.json();

            const rxData = (rxJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            const txData = (txJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));

            netChart.data.datasets[0].data = rxData;
            netChart.data.datasets[1].data = txData;
            netChart.update();

            document.getElementById('chartEmpty').style.display = (rxData.length === 0 && txData.length === 0) ? 'block' : 'none';
            document.getElementById('netChart').style.display = (rxData.length === 0 && txData.length === 0) ? 'none' : 'block';
        } catch (e) {
            console.error('Error loading net chart:', e);
        }
    }

    async function loadSystemCharts() {
        try {
            const [cpuResp, ramResp] = await Promise.all([
                fetch(`/monitor/api/metrics?host=${HOST}&metric=cpu_percent&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=ram_percent&range=${currentRange}`)
            ]);
            const cpuJson = await cpuResp.json();
            const ramJson = await ramResp.json();

            cpuChart.data.datasets[0].data = (cpuJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            ramChart.data.datasets[0].data = (ramJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            cpuChart.update();
            ramChart.update();
        } catch (e) {
            console.error('Error loading system charts:', e);
        }
    }

    // ─── Load GPU chart data ─────────────────────────────────
    async function loadGpuCharts() {
        if (GPU_COUNT === 0 || !gpuUtilChart) return;
        try {
            const metrics = ['util', 'mem_percent', 'temp', 'power'];
            const charts = [gpuUtilChart, gpuMemChart, gpuTempChart, gpuPowerChart];

            for (let m = 0; m < metrics.length; m++) {
                const fetches = [];
                for (let g = 0; g < GPU_COUNT; g++) {
                    fetches.push(fetch(`/monitor/api/metrics?host=${HOST}&metric=gpu${g}_${metrics[m]}&range=${currentRange}`).then(r => r.json()));
                }
                const results = await Promise.all(fetches);
                for (let g = 0; g < GPU_COUNT; g++) {
                    charts[m].data.datasets[g].data = (results[g].data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
                }
                charts[m].update();
            }
        } catch (e) {
            console.error('Error loading GPU charts:', e);
        }
    }

    // ─── Update stat cards ───────────────────────────────────
    async function updateCards() {
        try {
            const resp = await fetch(`/monitor/api/status?host=${HOST}`);
            const json = await resp.json();
            if (!json.ok) return;

            const s = json.status;

            // Network cards
            <?php foreach ($interfaces as $iface): ?>
            {
                const rxEl = document.getElementById('card-<?= View::e($iface) ?>-rx');
                const txEl = document.getElementById('card-<?= View::e($iface) ?>-tx');
                if (rxEl && s['net_<?= View::e($iface) ?>_rx']) rxEl.textContent = fmtBytes(s['net_<?= View::e($iface) ?>_rx'].value);
                if (txEl && s['net_<?= View::e($iface) ?>_tx']) txEl.textContent = fmtBytes(s['net_<?= View::e($iface) ?>_tx'].value);
            }
            <?php endforeach; ?>

            // CPU & RAM cards
            const cpuEl = document.getElementById('card-cpu');
            const ramEl = document.getElementById('card-ram');
            const cpuBar = document.getElementById('bar-cpu');
            const ramBar = document.getElementById('bar-ram');

            if (s.cpu_percent) {
                const cpuVal = s.cpu_percent.value.toFixed(1);
                cpuEl.textContent = cpuVal + '%';
                cpuBar.style.width = Math.min(100, cpuVal) + '%';
            }
            if (s.ram_percent) {
                const ramVal = s.ram_percent.value.toFixed(1);
                ramEl.textContent = ramVal + '%';
                ramBar.style.width = Math.min(100, ramVal) + '%';
            }

            // GPU cards
            for (let g = 0; g < GPU_COUNT; g++) {
                const utilEl = document.getElementById('card-gpu' + g + '-util');
                const memEl = document.getElementById('card-gpu' + g + '-mem');
                const tempEl = document.getElementById('card-gpu' + g + '-temp');
                const powerEl = document.getElementById('card-gpu' + g + '-power');
                const barEl = document.getElementById('bar-gpu' + g);

                if (s['gpu' + g + '_util'] && utilEl) {
                    const v = s['gpu' + g + '_util'].value.toFixed(1);
                    utilEl.textContent = v + '%';
                    if (barEl) barEl.style.width = Math.min(100, v) + '%';
                }
                if (s['gpu' + g + '_mem_percent'] && memEl) memEl.textContent = s['gpu' + g + '_mem_percent'].value.toFixed(1) + '%';
                if (s['gpu' + g + '_temp'] && tempEl) tempEl.textContent = s['gpu' + g + '_temp'].value.toFixed(0) + '°C';
                if (s['gpu' + g + '_power'] && powerEl) powerEl.textContent = s['gpu' + g + '_power'].value.toFixed(0) + 'W';
            }
        } catch (e) {
            console.error('Error updating cards:', e);
        }
    }

    // ─── Load alerts ─────────────────────────────────────────
    async function loadAlerts() {
        try {
            const resp = await fetch(`/monitor/api/alerts?host=${HOST}`);
            const json = await resp.json();
            if (!json.ok) return;

            const tbody = document.getElementById('alertsBody');
            const badge = document.getElementById('alertBadge');
            const alerts = json.alerts || [];

            const clearBtn = document.getElementById('btnClearAlerts');
            if (alerts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No alerts</td></tr>';
                badge.style.display = 'none';
                if (clearBtn) clearBtn.style.display = 'none';
                return;
            }
            if (clearBtn) clearBtn.style.display = 'inline-block';

            const unack = alerts.filter(a => !a.acknowledged).length;
            badge.textContent = unack;
            badge.style.display = unack > 0 ? 'inline' : 'none';

            tbody.innerHTML = alerts.slice(0, 20).map(a => {
                const ts = fmtTzFull(parseUTC(a.ts));
                const statusBadge = a.acknowledged
                    ? '<span class="badge bg-secondary">ACK</span>'
                    : '<span class="badge bg-warning">Active</span>';
                const ackBtn = a.acknowledged
                    ? ''
                    : `<button class="btn btn-outline-success btn-sm" onclick="ackAlert(${a.id})"><i class="bi bi-check-lg"></i></button>`;

                return `<tr>
                    <td class="ps-3"><small class="text-muted">${esc(ts)}</small></td>
                    <td><span class="badge bg-dark">${esc(a.type)}</span></td>
                    <td><small>${esc(a.message)}</small></td>
                    <td><small class="text-muted">${a.value !== null ? Number(a.value).toFixed(1) : '-'}</small></td>
                    <td>${statusBadge}</td>
                    <td>${ackBtn}</td>
                </tr>`;
            }).join('');
        } catch (e) {
            console.error('Error loading alerts:', e);
        }
    }

    window.ackAlert = async function(id) {
        try {
            const form = new FormData();
            form.append('id', id);
            form.append('_csrf_token', CSRF);
            const resp = await fetch('/monitor/api/alerts/ack', { method: 'POST', body: form });
            const json = await resp.json();
            if (json.ok) loadAlerts();
        } catch (e) {
            console.error('Error acknowledging alert:', e);
        }
    };

    window.clearAllAlerts = async function() {
        const result = await SwalDark.fire({
            title: 'Clear all alerts?',
            text: 'This will permanently delete all alerts for this host.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Clear All',
            cancelButtonText: 'Cancel'
        });
        if (!result.isConfirmed) return;
        try {
            const form = new FormData();
            form.append('host', HOST);
            form.append('_csrf_token', CSRF);
            await fetch('/monitor/api/alerts/clear', { method: 'POST', body: form });
            loadAlerts();
        } catch (e) {
            console.error('Error clearing alerts:', e);
        }
    };

    // ─── Event handlers ──────────────────────────────────────
    document.getElementById('ifaceSelect').addEventListener('change', function() {
        currentIface = this.value;
        loadNetChart();
    });

    document.getElementById('rangeButtons').addEventListener('click', function(e) {
        const btn = e.target.closest('[data-range]');
        if (!btn) return;
        currentRange = btn.dataset.range;
        document.querySelectorAll('#rangeButtons .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        loadNetChart();
        loadSystemCharts();
        loadGpuCharts();
    });

    // ─── Auto-refresh ────────────────────────────────────────
    function startAutoRefresh() {
        if (refreshTimer) clearInterval(refreshTimer);
        const interval = ['1h', '6h'].includes(currentRange) ? 30000 : 60000;
        refreshTimer = setInterval(() => {
            loadNetChart();
            loadSystemCharts();
            loadGpuCharts();
            updateCards();
            loadAlerts();
        }, interval);
    }

    // ─── Init ────────────────────────────────────────────────
    initCharts();
    loadNetChart();
    loadSystemCharts();
    loadGpuCharts();
    updateCards();
    loadAlerts();
    startAutoRefresh();

    // Refresh interval on range change
    document.getElementById('rangeButtons').addEventListener('click', startAutoRefresh);

    // Card refresh every 10s
    setInterval(updateCards, 10000);

})();
</script>
