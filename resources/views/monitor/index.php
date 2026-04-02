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
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openNetworkModal('<?= View::e($iface) ?>')" title="Ver detalle de <?= View::e($iface) ?>" style="cursor:pointer">
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
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openProcessModal('cpu')" title="Ver procesos por CPU" style="cursor:pointer">
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
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openProcessModal('ram')" title="Ver procesos por RAM" style="cursor:pointer">
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

<!-- Disk Usage Cards -->
<?php if (!empty($disks)): ?>
<div class="row g-3 mb-4">
    <?php foreach ($disks as $disk):
        $diskColor = $disk['percent'] >= 90 ? '#ef4444' : ($disk['percent'] >= 75 ? '#fbbf24' : '#22c55e');
        $sizeH = $disk['size'] >= 1099511627776 ? round($disk['size']/1099511627776,1).'T' : round($disk['size']/1073741824,1).'G';
        $usedH = $disk['used'] >= 1099511627776 ? round($disk['used']/1099511627776,1).'T' : round($disk['used']/1073741824,1).'G';
        $freeB = $disk['size'] - $disk['used'];
        $freeH = $freeB >= 1099511627776 ? round($freeB/1099511627776,1).'T' : round($freeB/1073741824,1).'G';
    ?>
    <div class="col-md-3 d-flex">
        <div class="stat-card w-100 d-flex flex-column" role="button" onclick="openDiskModal('<?= View::e($disk['mount']) ?>')" title="Ver detalle de <?= View::e($disk['mount']) ?>" style="cursor:pointer">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" style="color:<?= $diskColor ?>"><?= $disk['percent'] ?>%</div>
                    <div class="stat-label"><?= View::e($disk['mount']) ?></div>
                </div>
                <i class="bi bi-hdd stat-icon"></i>
            </div>
            <div class="mt-1">
                <small class="text-muted"><?= View::e($disk['device']) ?> — <?= $usedH ?> / <?= $sizeH ?> (free: <?= $freeH ?>)</small>
            </div>
            <div class="progress mt-auto pt-2"><div class="progress-bar" style="width:<?= min(100, $disk['percent']) ?>%;background:<?= $diskColor ?>"></div></div>
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

<!-- Disk Charts -->
<?php if (!empty($disks)): ?>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hdd me-2"></i>Disk Usage %</span>
                <?php if (count($disks) > 1): ?>
                <select id="diskSelect" class="form-select form-select-sm" style="width:auto">
                    <?php foreach ($disks as $dk): ?>
                    <option value="<?= View::e($dk['metric']) ?>" data-mount="<?= View::e($dk['mount']) ?>" data-io-metric="<?= View::e(str_replace('_percent', '', $dk['metric'])) ?>"><?= View::e($dk['device']) ?> (<?= View::e($dk['mount']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="diskUsageChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-arrow-left-right me-2"></i>Disk I/O</span>
                <?php if (count($disks) > 1): ?>
                <small class="text-muted" id="diskIoLabel"><?= View::e($disks[0]['device']) ?> (<?= View::e($disks[0]['mount']) ?>)</small>
                <?php endif; ?>
            </div>
            <div class="card-body" style="height:200px;position:relative">
                <canvas id="diskIoChart"></canvas>
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
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="monitorEnabled" name="monitor_enabled" value="1" <?= ($alertSettings['enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="monitorEnabled">Alerts enabled</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="notifyEmail" name="notify_email" value="1" <?= ($alertSettings['notify_email'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notifyEmail"><i class="bi bi-envelope me-1"></i>Notificar por Email</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="notifyTelegram" name="notify_telegram" value="1" <?= ($alertSettings['notify_telegram'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notifyTelegram"><i class="bi bi-telegram me-1"></i>Notificar por Telegram</label>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">CPU threshold (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_cpu" value="<?= View::e($alertSettings['cpu'] ?? '90') ?>" min="0" max="100">
                        <small class="text-muted">0 = disabled. Media de todos los cores. Ej: 4 cores al 90% = alerta</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">RAM threshold (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_ram" value="<?= View::e($alertSettings['ram'] ?? '90') ?>" min="0" max="100">
                        <small class="text-muted">0 = disabled</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Network threshold (Mbps)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_net_mbps" value="<?= View::e($alertSettings['net_mbps'] ?? '800') ?>" min="0" max="100000">
                        <small class="text-muted">0 = disabled. Megabits/s (no MegaBytes). Ej: 80 Mbps &asymp; 10 MB/s de descarga</small>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Disk threshold (%)</label>
                        <input type="number" class="form-control form-control-sm" name="alert_disk" value="<?= View::e($alertSettings['disk'] ?? '90') ?>" min="0" max="100">
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
    let diskUsageChart, diskIoChart;
    let refreshTimer = null;
    const GPU_COUNT = <?= count($gpus ?? []) ?>;
    const GPU_NAMES = <?= json_encode(array_map(fn($g) => $g['name'], $gpus ?? [])) ?>;
    const GPU_COLORS = ['#a855f7', '#ec4899', '#f97316', '#06b6d4'];
    const DISKS = <?= json_encode(array_map(fn($d) => ['metric' => $d['metric'], 'ioMetric' => str_replace('_percent', '', $d['metric']), 'device' => $d['device'], 'mount' => $d['mount']], $disks ?? [])) ?>;
    let currentDiskIdx = 0;

    // Panel timezone from Settings > Server (e.g. 'UTC', 'Asia/Tokyo', 'Europe/Madrid')
    const PANEL_TZ = '<?= View::e($panelTz ?? "UTC") ?>';

    // API returns Unix epoch (seconds, always UTC).
    // We store raw epoch ms as chart X values (linear scale, NOT time scale).
    // All formatting is done by us via Intl.DateTimeFormat with PANEL_TZ.
    // This completely bypasses Chart.js date-fns adapter = zero TZ bugs.

    const _tzFmtShort   = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, hour: '2-digit', minute: '2-digit' });
    const _tzFmtFull    = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const _tzFmtDay     = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, month: 'short', day: 'numeric' });
    const _tzFmtDayHour = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    const _tzFmtMonth   = new Intl.DateTimeFormat('es-ES', { timeZone: PANEL_TZ, month: 'short', year: '2-digit' });

    function fmtTzShort(ms)   { return _tzFmtShort.format(new Date(ms)); }
    function fmtTzFull(ms)    { return _tzFmtFull.format(new Date(ms)); }
    function fmtTzDay(ms)     { return _tzFmtDay.format(new Date(ms)); }
    function fmtTzDayHour(ms) { return _tzFmtDayHour.format(new Date(ms)); }
    function fmtTzMonth(ms)   { return _tzFmtMonth.format(new Date(ms)); }

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
    const gridColor = 'rgba(51,65,85,0.25)';
    const tickColor = '#64748b';
    const tooltipBg = '#1e293b';
    const tooltipBorder = '#334155';

    // Plugin: draw vertical day-separator lines for 7d/30d/1y
    // Plugin: draw vertical day/month separator lines
    const dayLinesPlugin = {
        id: 'dayLines',
        afterDraw(chart) {
            if (currentRange !== '7d') return;
            const xScale = chart.scales.x;
            if (!xScale) return;
            const ctx = chart.ctx;
            const yTop = chart.chartArea.top;
            const yBottom = chart.chartArea.bottom;

            const minMs = xScale.min;
            const maxMs = xScale.max;
            if (!minMs || !maxMs) return;

            // Choose step: daily for 7d/30d, monthly for 1y
            const rangeMs = maxMs - minMs;
            const oneDay = 86400000;
            let stepMs, maxLines;

            if (currentRange === '1y') {
                stepMs = oneDay * 30; // ~monthly
                maxLines = 12;
            } else if (currentRange === '30d') {
                stepMs = oneDay;
                maxLines = 31;
            } else {
                stepMs = oneDay;
                maxLines = 8;
            }

            // Find first midnight in panel TZ
            const tzDate = new Intl.DateTimeFormat('en-CA', { timeZone: PANEL_TZ, year: 'numeric', month: '2-digit', day: '2-digit' });
            const parts = tzDate.formatToParts(new Date(minMs));
            const y = +parts.find(p => p.type === 'year').value;
            const m = +parts.find(p => p.type === 'month').value - 1;
            const d = +parts.find(p => p.type === 'day').value;

            let midnightMs = new Date(y, m, d).getTime();
            const fmt = new Intl.DateTimeFormat('en-US', { timeZone: PANEL_TZ, hour: 'numeric', hour12: false });
            const hourAtCursor = +fmt.format(new Date(midnightMs));
            if (hourAtCursor !== 0) midnightMs -= hourAtCursor * 3600000;

            ctx.save();
            ctx.strokeStyle = 'rgba(100,116,139,0.3)';
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 4]);

            let drawn = 0;
            for (let ms = midnightMs; ms <= maxMs && drawn < maxLines; ms += stepMs) {
                if (ms < minMs) continue;
                const x = xScale.getPixelForValue(ms);
                if (x >= chart.chartArea.left && x <= chart.chartArea.right) {
                    ctx.beginPath();
                    ctx.moveTo(x, yTop);
                    ctx.lineTo(x, yBottom);
                    ctx.stroke();
                    drawn++;
                }
            }
            ctx.restore();
        }
    };
    Chart.register(dayLinesPlugin);

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
                            if (currentRange === '1y') return fmtTzMonth(value);
                            if (currentRange === '30d') return fmtTzDay(value);
                            if (currentRange === '7d') return fmtTzDay(value);
                            return fmtTzShort(value); // 1h, 6h, 24h — just hour:min
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { display: false, drawTicks: false, drawBorder: false },
                    border: { display: false },
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
                    },
                    {
                        label: 'RX Avg',
                        data: [],
                        borderColor: 'rgba(34,197,94,0.35)',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1,
                        borderDash: [4, 4],
                        hidden: false,
                    },
                    {
                        label: 'TX Avg',
                        data: [],
                        borderColor: 'rgba(56,189,248,0.35)',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1,
                        borderDash: [4, 4],
                        hidden: false,
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
                datasets: [
                    {
                        label: 'CPU %',
                        data: [],
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14,165,233,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1.5,
                    },
                    {
                        label: 'CPU Avg',
                        data: [],
                        borderColor: 'rgba(14,165,233,0.35)',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1,
                        borderDash: [4, 4],
                    }
                ]
            },
            options: cpuOpts
        });

        const ramOpts = chartDefaults();
        ramOpts.scales.y.max = 100;
        ramOpts.scales.y.ticks.callback = (v) => v + '%';

        ramChart = new Chart(document.getElementById('ramChart'), {
            type: 'line',
            data: {
                datasets: [
                    {
                        label: 'RAM %',
                        data: [],
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245,158,11,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1.5,
                    },
                    {
                        label: 'RAM Avg',
                        data: [],
                        borderColor: 'rgba(245,158,11,0.35)',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        borderWidth: 1,
                        borderDash: [4, 4],
                    }
                ]
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

        // Disk charts
        if (DISKS.length > 0 && document.getElementById('diskUsageChart')) {
            const diskPctOpts = chartDefaults();
            diskPctOpts.scales.y.max = 100;
            diskPctOpts.scales.y.ticks.callback = (v) => v + '%';

            diskUsageChart = new Chart(document.getElementById('diskUsageChart'), {
                type: 'line',
                data: {
                    datasets: [{
                        label: 'Usage %',
                        data: [],
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.08)',
                        fill: true, tension: 0.3, pointRadius: 0, borderWidth: 1.5,
                    }]
                },
                options: diskPctOpts
            });

            const diskIoOpts = chartDefaults();
            diskIoOpts.scales.y.ticks.callback = (v) => fmtBytes(v);
            diskIoOpts.plugins.tooltip.callbacks = {
                title: (items) => {
                    if (!items.length) return '';
                    return fmtTzFull(items[0].parsed.x) + ' (' + PANEL_TZ + ')';
                },
                label: (ctx) => ctx.dataset.label + ': ' + fmtBytes(ctx.parsed.y)
            };

            diskIoChart = new Chart(document.getElementById('diskIoChart'), {
                type: 'line',
                data: {
                    datasets: [
                        {
                            label: 'Read',
                            data: [],
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34,197,94,0.08)',
                            fill: true, tension: 0.3, pointRadius: 0, borderWidth: 1.5,
                        },
                        {
                            label: 'Write',
                            data: [],
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245,158,11,0.08)',
                            fill: true, tension: 0.3, pointRadius: 0, borderWidth: 1.5,
                        }
                    ]
                },
                options: diskIoOpts
            });
        }
    }

    // ─── Load chart data ─────────────────────────────────────
    async function loadNetChart() {
        const isAggregated = ['7d', '30d', '1y'].includes(currentRange);
        const isDaily = ['30d', '1y'].includes(currentRange);

        try {
            const [rxResp, txResp] = await Promise.all([
                fetch(`/monitor/api/metrics?host=${HOST}&metric=net_${currentIface}_rx&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=net_${currentIface}_tx&range=${currentRange}`)
            ]);
            const rxJson = await rxResp.json();
            const txJson = await txResp.json();

            // value = max_val (peak) for aggregated, raw value for realtime
            const rxData = (rxJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            const txData = (txJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));

            if (isDaily) {
                // Daily (30d/1y): thin overlapping bars — discrete peaks per day
                const barBase = {
                    type: 'bar', borderWidth: 1, pointRadius: 0, fill: false,
                    barPercentage: 1, categoryPercentage: 1,
                    maxBarThickness: 20,
                    stack: 'overlap',
                };

                Object.assign(netChart.data.datasets[0], barBase);
                netChart.data.datasets[0].data = txData; // TX behind (drawn first)
                netChart.data.datasets[0].label = 'TX Peak';
                netChart.data.datasets[0].backgroundColor = 'rgba(56,189,248,0.5)';
                netChart.data.datasets[0].borderColor = '#38bdf8';

                Object.assign(netChart.data.datasets[1], barBase);
                netChart.data.datasets[1].data = rxData; // RX in front
                netChart.data.datasets[1].label = 'RX Peak';
                netChart.data.datasets[1].backgroundColor = 'rgba(34,197,94,0.7)';
                netChart.data.datasets[1].borderColor = '#22c55e';

                netChart.data.datasets[2].data = [];
                netChart.data.datasets[3].data = [];

                // Enable stacked mode but NOT summed (overlapping bars)
                netChart.options.scales.x.stacked = true;
                netChart.options.scales.y.stacked = false;
            } else {
                // Line chart for 1h/6h/24h/7d
                netChart.data.datasets[0].data = rxData;
                netChart.data.datasets[0].label = isAggregated ? 'RX Peak' : 'RX (In)';
                netChart.data.datasets[0].type = 'line';
                netChart.data.datasets[0].borderWidth = 1.5;
                netChart.data.datasets[0].borderColor = '#22c55e';
                netChart.data.datasets[0].backgroundColor = 'rgba(34,197,94,0.08)';
                netChart.data.datasets[0].fill = true;
                netChart.data.datasets[0].tension = 0.3;
                netChart.data.datasets[0].pointRadius = 0;

                netChart.data.datasets[1].data = txData;
                netChart.data.datasets[1].label = isAggregated ? 'TX Peak' : 'TX (Out)';
                netChart.data.datasets[1].type = 'line';
                netChart.data.datasets[1].borderWidth = 1.5;
                netChart.data.datasets[1].borderColor = '#38bdf8';
                netChart.data.datasets[1].backgroundColor = 'rgba(56,189,248,0.08)';
                netChart.data.datasets[1].fill = true;
                netChart.data.datasets[1].tension = 0.3;
                netChart.data.datasets[1].pointRadius = 0;

                // Disable stacked mode for line charts
                netChart.options.scales.x.stacked = false;
                netChart.options.scales.y.stacked = false;

                // Avg dashed lines only for 7d (hourly)
                if (isAggregated) {
                    netChart.data.datasets[2].data = (rxJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                    netChart.data.datasets[3].data = (txJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                } else {
                    netChart.data.datasets[2].data = [];
                    netChart.data.datasets[3].data = [];
                }
            }

            netChart.update();

            document.getElementById('chartEmpty').style.display = (rxData.length === 0 && txData.length === 0) ? 'block' : 'none';
            document.getElementById('netChart').style.display = (rxData.length === 0 && txData.length === 0) ? 'none' : 'block';
        } catch (e) {
            console.error('Error loading net chart:', e);
        }
    }

    async function loadSystemCharts() {
        const isAggregated = ['7d', '30d', '1y'].includes(currentRange);
        const isDaily = ['30d', '1y'].includes(currentRange);
        try {
            const [cpuResp, ramResp] = await Promise.all([
                fetch(`/monitor/api/metrics?host=${HOST}&metric=cpu_percent&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=ram_percent&range=${currentRange}`)
            ]);
            const cpuJson = await cpuResp.json();
            const ramJson = await ramResp.json();

            const cpuData = (cpuJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            const ramData = (ramJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));

            if (isDaily) {
                // Daily (30d/1y): thin bars for peaks + dashed line for avg
                const barProps = (bg, border) => ({
                    type: 'bar', borderWidth: 1, backgroundColor: bg, borderColor: border,
                    barPercentage: 1, categoryPercentage: 1, maxBarThickness: 20,
                    pointRadius: 0, fill: false,
                });

                Object.assign(cpuChart.data.datasets[0], barProps('rgba(14,165,233,0.7)', '#0ea5e9'));
                cpuChart.data.datasets[0].data = cpuData;
                cpuChart.data.datasets[0].label = 'CPU Peak';
                cpuChart.data.datasets[1].data = (cpuJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                cpuChart.data.datasets[1].label = 'CPU Avg';
                cpuChart.data.datasets[1].type = 'line';

                Object.assign(ramChart.data.datasets[0], barProps('rgba(245,158,11,0.7)', '#f59e0b'));
                ramChart.data.datasets[0].data = ramData;
                ramChart.data.datasets[0].label = 'RAM Peak';
                ramChart.data.datasets[1].data = (ramJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                ramChart.data.datasets[1].label = 'RAM Avg';
                ramChart.data.datasets[1].type = 'line';
            } else {
                // Line chart for 1h/6h/24h/7d
                const lineProps = (color, bg) => ({
                    type: 'line', borderWidth: 1.5, borderColor: color,
                    backgroundColor: bg, fill: true, tension: 0.3, pointRadius: 0,
                });

                Object.assign(cpuChart.data.datasets[0], lineProps('#0ea5e9', 'rgba(14,165,233,0.08)'));
                cpuChart.data.datasets[0].data = cpuData;
                cpuChart.data.datasets[0].label = isAggregated ? 'CPU Peak' : 'CPU %';

                Object.assign(ramChart.data.datasets[0], lineProps('#f59e0b', 'rgba(245,158,11,0.08)'));
                ramChart.data.datasets[0].data = ramData;
                ramChart.data.datasets[0].label = isAggregated ? 'RAM Peak' : 'RAM %';

                // Avg dashed line only for 7d (hourly)
                if (isAggregated) {
                    cpuChart.data.datasets[1].data = (cpuJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                    cpuChart.data.datasets[1].label = 'CPU Avg';
                    ramChart.data.datasets[1].data = (ramJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                    ramChart.data.datasets[1].label = 'RAM Avg';
                } else {
                    cpuChart.data.datasets[1].data = [];
                    ramChart.data.datasets[1].data = [];
                }
            }

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

    // Helper: check if current range uses aggregated data
    function isAggregatedRange() {
        return ['7d', '30d', '1y'].includes(currentRange);
    }

    // ─── Load Disk chart data ───────────────────────────────
    async function loadDiskCharts() {
        if (DISKS.length === 0 || !diskUsageChart) return;
        const disk = DISKS[currentDiskIdx];
        const isAgg = isAggregatedRange();
        const isDaily = ['30d', '1y'].includes(currentRange);
        try {
            const [usageResp, readResp, writeResp] = await Promise.all([
                fetch(`/monitor/api/metrics?host=${HOST}&metric=${disk.metric}&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=${disk.ioMetric}_read&range=${currentRange}`),
                fetch(`/monitor/api/metrics?host=${HOST}&metric=${disk.ioMetric}_write&range=${currentRange}`)
            ]);
            const usageJson = await usageResp.json();
            const readJson = await readResp.json();
            const writeJson = await writeResp.json();

            // Disk usage %: always line (it's a slowly changing value)
            diskUsageChart.data.datasets[0].data = (usageJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            diskUsageChart.data.datasets[0].type = 'line';
            diskUsageChart.data.datasets[0].tension = isDaily ? 0 : 0.3;
            diskUsageChart.data.datasets[0].pointRadius = isDaily ? 3 : 0;
            diskUsageChart.update();

            const readData = (readJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));
            const writeData = (writeJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +d.value }));

            if (isDaily) {
                // Daily: thin overlapping bars for I/O peaks
                const barBase = {
                    type: 'bar', borderWidth: 1, pointRadius: 0, fill: false,
                    barPercentage: 1, categoryPercentage: 1,
                    maxBarThickness: 20, // max 20px wide regardless of data density
                    stack: 'overlap', tension: 0,
                };

                Object.assign(diskIoChart.data.datasets[0], barBase);
                diskIoChart.data.datasets[0].data = readData;
                diskIoChart.data.datasets[0].label = 'Read Peak';
                diskIoChart.data.datasets[0].backgroundColor = 'rgba(34,197,94,0.7)';
                diskIoChart.data.datasets[0].borderColor = '#22c55e';

                Object.assign(diskIoChart.data.datasets[1], barBase);
                diskIoChart.data.datasets[1].data = writeData;
                diskIoChart.data.datasets[1].label = 'Write Peak';
                diskIoChart.data.datasets[1].backgroundColor = 'rgba(245,158,11,0.5)';
                diskIoChart.data.datasets[1].borderColor = '#f59e0b';

                diskIoChart.options.scales.x.stacked = true;
                diskIoChart.options.scales.y.stacked = false;

                // Clear avg lines
                if (diskIoChart.data.datasets[2]) {
                    diskIoChart.data.datasets[2].data = [];
                    diskIoChart.data.datasets[3].data = [];
                }
            } else {
                // Line chart for 1h/6h/24h/7d
                const lineProps = (color, bg) => ({
                    type: 'line', borderWidth: 1.5, borderColor: color,
                    backgroundColor: bg, fill: true, tension: 0.3, pointRadius: 0,
                });

                Object.assign(diskIoChart.data.datasets[0], lineProps('#22c55e', 'rgba(34,197,94,0.08)'));
                diskIoChart.data.datasets[0].data = readData;
                diskIoChart.data.datasets[0].label = isAgg ? 'Read Peak' : 'Read';

                Object.assign(diskIoChart.data.datasets[1], lineProps('#f59e0b', 'rgba(245,158,11,0.08)'));
                diskIoChart.data.datasets[1].data = writeData;
                diskIoChart.data.datasets[1].label = isAgg ? 'Write Peak' : 'Write';

                diskIoChart.options.scales.x.stacked = false;
                diskIoChart.options.scales.y.stacked = false;

                // Avg lines for 7d (hourly)
                if (isAgg && !isDaily) {
                    if (!diskIoChart.data.datasets[2]) {
                        diskIoChart.data.datasets.push({
                            label: 'Read Avg', data: [], borderColor: 'rgba(34,197,94,0.35)',
                            backgroundColor: 'transparent', fill: false, tension: 0.3,
                            pointRadius: 0, borderWidth: 1, borderDash: [4, 4],
                        }, {
                            label: 'Write Avg', data: [], borderColor: 'rgba(245,158,11,0.35)',
                            backgroundColor: 'transparent', fill: false, tension: 0.3,
                            pointRadius: 0, borderWidth: 1, borderDash: [4, 4],
                        });
                    }
                    diskIoChart.data.datasets[2].data = (readJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                    diskIoChart.data.datasets[3].data = (writeJson.data || []).map(d => ({ x: parseUTC(d.ts), y: +(d.avg_val || d.value) }));
                } else if (diskIoChart.data.datasets[2]) {
                    diskIoChart.data.datasets[2].data = [];
                    diskIoChart.data.datasets[3].data = [];
                }
            }
            diskIoChart.update();
        } catch (e) {
            console.error('Error loading disk charts:', e);
        }
    }

    // ─── Update stat cards ───────────────────────────────────
    async function updateCards() {
        try {
            const resp = await fetch(`/monitor/api/status?host=${HOST}`);
            const json = await resp.json();
            if (!json.ok) return;

            const s = json.status;

            // CPU, RAM & Network cards — real-time from /monitor/api/realtime
            try {
                const rtResp = await fetch('/monitor/api/realtime');
                const rt = await rtResp.json();
                if (rt.ok) {
                    // CPU
                    const cpuEl = document.getElementById('card-cpu');
                    const cpuBar = document.getElementById('bar-cpu');
                    cpuEl.textContent = rt.cpu_percent + '%';
                    cpuBar.style.width = Math.min(100, rt.cpu_percent) + '%';

                    // RAM
                    const ramEl = document.getElementById('card-ram');
                    const ramBar = document.getElementById('bar-ram');
                    ramEl.textContent = rt.mem_percent + '%';
                    ramBar.style.width = Math.min(100, rt.mem_percent) + '%';

                    // Network
                    if (rt.net) {
                        for (const [iface, data] of Object.entries(rt.net)) {
                            const rxEl = document.getElementById('card-' + iface + '-rx');
                            const txEl = document.getElementById('card-' + iface + '-tx');
                            if (rxEl) rxEl.textContent = fmtBytes(data.rx);
                            if (txEl) txEl.textContent = 'TX (Out): ' + fmtBytes(data.tx);
                        }
                    }
                }
            } catch(e) {
                // Fallback to collector data
                <?php foreach ($interfaces as $iface): ?>
                {
                    const rxEl = document.getElementById('card-<?= View::e($iface) ?>-rx');
                    const txEl = document.getElementById('card-<?= View::e($iface) ?>-tx');
                    if (rxEl && s['net_<?= View::e($iface) ?>_rx']) rxEl.textContent = fmtBytes(s['net_<?= View::e($iface) ?>_rx'].value);
                    if (txEl && s['net_<?= View::e($iface) ?>_tx']) txEl.textContent = 'TX (Out): ' + fmtBytes(s['net_<?= View::e($iface) ?>_tx'].value);
                }
                <?php endforeach; ?>
                const cpuEl = document.getElementById('card-cpu');
                const ramEl = document.getElementById('card-ram');
                const cpuBar = document.getElementById('bar-cpu');
                const ramBar = document.getElementById('bar-ram');
                if (s.cpu_percent) { cpuEl.textContent = s.cpu_percent.value.toFixed(1) + '%'; cpuBar.style.width = Math.min(100, s.cpu_percent.value) + '%'; }
                if (s.ram_percent) { ramEl.textContent = s.ram_percent.value.toFixed(1) + '%'; ramBar.style.width = Math.min(100, s.ram_percent.value) + '%'; }
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

            // Store alerts globally for modal access
            window._monitorAlerts = {};
            alerts.forEach(a => { window._monitorAlerts[a.id] = a; });

            tbody.innerHTML = alerts.slice(0, 20).map(a => {
                const ts = fmtTzFull(parseUTC(a.ts));
                const statusBadge = a.acknowledged
                    ? '<span class="badge bg-secondary">ACK</span>'
                    : '<span class="badge bg-warning">Active</span>';
                const ackBtn = a.acknowledged
                    ? ''
                    : `<button class="btn btn-outline-success btn-sm" onclick="event.stopPropagation();ackAlert(${a.id})"><i class="bi bi-check-lg"></i></button>`;
                const detailsIcon = a.details ? ' <i class="bi bi-search text-muted" style="font-size:0.7rem" title="View process details"></i>' : '';

                return `<tr style="cursor:pointer" onclick="showAlertDetails(${a.id})">
                    <td class="ps-3"><small class="text-muted">${esc(ts)}</small></td>
                    <td><span class="badge bg-dark">${esc(a.type)}</span>${detailsIcon}</td>
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

    window.showAlertDetails = function(id) {
        const a = window._monitorAlerts[id];
        if (!a) return;
        const ts = fmtTzFull(parseUTC(a.ts));
        const detailsHtml = a.details
            ? `<pre style="text-align:left;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:0.8rem;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto;border:1px solid #334155;">${esc(a.details)}</pre>`
            : '<p class="text-muted">No process details available for this alert.</p>';

        SwalDark.fire({
            title: `${a.type}`,
            html: `<div style="text-align:left;">
                <p style="margin-bottom:0.5rem;"><strong>Time:</strong> ${esc(ts)}</p>
                <p style="margin-bottom:0.5rem;"><strong>Message:</strong> ${esc(a.message)}</p>
                <p style="margin-bottom:0.75rem;"><strong>Value:</strong> ${a.value !== null ? Number(a.value).toFixed(2) : '-'}</p>
                <hr style="border-color:#334155;margin:0.75rem 0;">
                <p style="margin-bottom:0.5rem;color:#94a3b8;font-size:0.85rem;"><i class="bi bi-cpu me-1"></i>Processes at time of alert:</p>
                ${detailsHtml}
            </div>`,
            width: 700,
            showConfirmButton: true,
            confirmButtonText: 'Close',
            showCancelButton: false,
        });
    };

    // ─── Event handlers ──────────────────────────────────────
    document.getElementById('ifaceSelect').addEventListener('change', function() {
        currentIface = this.value;
        loadNetChart();
    });

    const diskSelectEl = document.getElementById('diskSelect');
    if (diskSelectEl) {
        diskSelectEl.addEventListener('change', function() {
            currentDiskIdx = this.selectedIndex;
            const opt = this.options[this.selectedIndex];
            const ioLabel = document.getElementById('diskIoLabel');
            if (ioLabel) ioLabel.textContent = opt.textContent;
            loadDiskCharts();
        });
    }

    document.getElementById('rangeButtons').addEventListener('click', function(e) {
        const btn = e.target.closest('[data-range]');
        if (!btn) return;
        currentRange = btn.dataset.range;
        document.querySelectorAll('#rangeButtons .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        loadNetChart();
        loadSystemCharts();
        loadGpuCharts();
        loadDiskCharts();
    });

    // ─── Auto-refresh ────────────────────────────────────────
    function startAutoRefresh() {
        if (refreshTimer) clearInterval(refreshTimer);
        const interval = ['1h', '6h'].includes(currentRange) ? 30000 : 60000;
        refreshTimer = setInterval(() => {
            loadNetChart();
            loadSystemCharts();
            loadGpuCharts();
            loadDiskCharts();
            updateCards();
            loadAlerts();
        }, interval);
    }

    // ─── Init ────────────────────────────────────────────────
    initCharts();
    loadNetChart();
    loadSystemCharts();
    loadGpuCharts();
    loadDiskCharts();
    updateCards();
    loadAlerts();
    startAutoRefresh();

    // Refresh interval on range change
    document.getElementById('rangeButtons').addEventListener('click', startAutoRefresh);

    // Card refresh every 10s
    setInterval(updateCards, 3000);

})();
</script>

<!-- Process Modal (shared with dashboard) -->
<div class="modal fade" id="processModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary d-flex align-items-center">
                <h5 class="modal-title me-auto" id="processModalTitle">
                    <i class="bi bi-cpu me-2"></i>Procesos
                </h5>
                <span id="processSummary" class="text-muted small me-3"></span>
                <div class="form-check form-switch mb-0 me-3">
                    <input class="form-check-input" type="checkbox" id="processAutoRefresh" checked>
                    <label class="form-check-label small text-muted" for="processAutoRefresh">
                        Auto <span id="processCountdown">3s</span>
                    </label>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="d-flex border-bottom border-secondary">
                    <button class="btn btn-sm rounded-0 flex-fill process-tab active" data-sort="cpu" onclick="switchProcessTab('cpu')">
                        <i class="bi bi-cpu me-1"></i> Por CPU %
                    </button>
                    <button class="btn btn-sm rounded-0 flex-fill process-tab" data-sort="ram" onclick="switchProcessTab('ram')">
                        <i class="bi bi-memory me-1"></i> Por RAM %
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:50px">PID</th>
                                <th style="width:90px">Usuario</th>
                                <th style="width:70px" class="text-end">CPU %</th>
                                <th style="width:70px" class="text-end">RAM %</th>
                                <th style="width:80px" class="text-end">RSS</th>
                                <th style="width:70px">Estado</th>
                                <th style="width:70px">Tiempo</th>
                                <th>Comando</th>
                            </tr>
                        </thead>
                        <tbody id="processTableBody">
                            <tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-secondary py-1">
                <small class="text-muted" id="processTimestamp"></small>
            </div>
        </div>
    </div>
</div>

<!-- Process Detail Modal -->
<div class="modal fade" id="processDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-terminal me-2"></i>Proceso <span id="detailPid" class="text-info"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="processDetailBody">
                <div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</div>
            </div>
            <div class="modal-footer border-secondary">
                <div class="d-flex gap-2 w-100">
                    <button class="btn btn-warning btn-sm" onclick="killProcess(currentDetailPid, 'TERM')">
                        <i class="bi bi-exclamation-triangle me-1"></i>SIGTERM
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="killProcess(currentDetailPid, 'KILL')">
                        <i class="bi bi-x-octagon me-1"></i>SIGKILL
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="killProcess(currentDetailPid, 'HUP')">
                        <i class="bi bi-arrow-repeat me-1"></i>SIGHUP
                    </button>
                    <button class="btn btn-outline-secondary btn-sm ms-auto" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    let currentSort = 'cpu';
    let refreshTimer = null;
    let countdownTimer = null;
    let countdown = 3;
    const REFRESH_INTERVAL = 3;
    const modal = document.getElementById('processModal');
    let bsModal = null;

    function formatKB(kb) {
        if (kb >= 1048576) return (kb / 1048576).toFixed(1) + ' GB';
        if (kb >= 1024) return (kb / 1024).toFixed(0) + ' MB';
        return kb + ' KB';
    }
    function cpuColor(val) {
        if (val >= 50) return '#dc3545';
        if (val >= 20) return '#ffc107';
        if (val > 1) return '#0dcaf0';
        return '#6c757d';
    }
    function memColor(val) {
        if (val >= 30) return '#dc3545';
        if (val >= 10) return '#ffc107';
        if (val > 1) return '#ffc107';
        return '#6c757d';
    }
    function esc(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
    function truncCmd(cmd, max) { return (!cmd || cmd.length <= max) ? (cmd||'') : cmd.substring(0, max) + '...'; }

    async function fetchProcesses() {
        try {
            const resp = await fetch(`/dashboard/processes?sort=${currentSort}&limit=25`);
            const data = await resp.json();
            if (!data.ok) return;

            const tbody = document.getElementById('processTableBody');
            const s = data.summary;

            document.getElementById('processSummary').innerHTML =
                `CPU: <b>${s.cpu_percent}%</b> de ${s.cores} cores | Load: ${s.cpu_load} | RAM: <b>${s.mem_percent}%</b> (${s.mem_used_gb}/${s.mem_total_gb} GB)`;

            document.getElementById('processTimestamp').textContent =
                'Actualizado: ' + new Date().toLocaleTimeString('es-ES');

            if (!data.processes || data.processes.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Sin procesos</td></tr>';
                return;
            }

            tbody.innerHTML = data.processes.map(p => {
                const cpuW = Math.min(60, p.cpu * 2);
                const memW = Math.min(60, p.mem * 3);
                return `<tr onclick="openProcessDetail(${p.pid})" style="cursor:pointer" title="Ver detalle PID ${p.pid}">
                    <td class="ps-3 text-muted">${p.pid}</td>
                    <td><small>${esc(p.user)}</small></td>
                    <td class="text-end"><span style="color:${cpuColor(p.cpu)}">${p.cpu.toFixed(1)}</span> <span class="cpu-bar" style="width:${Math.min(60, p.cpu*2)}px"></span></td>
                    <td class="text-end"><span style="color:${memColor(p.mem)}">${p.mem.toFixed(1)}</span> <span class="mem-bar" style="width:${Math.min(60, p.mem*3)}px"></span></td>
                    <td class="text-end"><small class="text-muted">${formatKB(p.rss)}</small></td>
                    <td><small class="text-muted">${esc(p.stat)}</small></td>
                    <td><small class="text-muted">${esc(p.time)}</small></td>
                    <td><small>${esc(truncCmd(p.command, 80))}</small></td>
                </tr>`;
            }).join('');
        } catch (e) { console.error('Error fetching processes:', e); }
    }

    function startRefresh() {
        stopRefresh();
        countdown = REFRESH_INTERVAL;
        updateCountdownDisplay();
        countdownTimer = setInterval(() => {
            countdown--;
            if (countdown <= 0) { fetchProcesses(); countdown = REFRESH_INTERVAL; }
            updateCountdownDisplay();
        }, 1000);
    }
    function stopRefresh() { if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; } }
    function updateCountdownDisplay() { const el = document.getElementById('processCountdown'); if (el) el.textContent = countdown + 's'; }

    window.switchProcessTab = function(sort) {
        currentSort = sort;
        document.querySelectorAll('.process-tab').forEach(t => t.classList.toggle('active', t.dataset.sort === sort));
        const icon = sort === 'cpu' ? 'bi-cpu' : 'bi-memory';
        const label = sort === 'cpu' ? 'Procesos por CPU' : 'Procesos por RAM';
        document.getElementById('processModalTitle').innerHTML = `<i class="bi ${icon} me-2"></i>${label}`;
        fetchProcesses();
    };

    window.openProcessModal = function(sort) {
        currentSort = sort || 'cpu';
        document.querySelectorAll('.process-tab').forEach(t => t.classList.toggle('active', t.dataset.sort === currentSort));
        const icon = currentSort === 'cpu' ? 'bi-cpu' : 'bi-memory';
        const label = currentSort === 'cpu' ? 'Procesos por CPU' : 'Procesos por RAM';
        document.getElementById('processModalTitle').innerHTML = `<i class="bi ${icon} me-2"></i>${label}`;
        if (!bsModal) bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        fetchProcesses();
        if (document.getElementById('processAutoRefresh').checked) startRefresh();
    };

    document.getElementById('processAutoRefresh')?.addEventListener('change', function() {
        if (this.checked) { startRefresh(); } else { stopRefresh(); document.getElementById('processCountdown').textContent = 'off'; }
    });
    modal?.addEventListener('hidden.bs.modal', function() { stopRefresh(); });

    // Process detail
    let currentDetailPid = 0;
    let detailModal = null;
    window.currentDetailPid = 0;

    window.openProcessDetail = async function(pid) {
        currentDetailPid = pid;
        window.currentDetailPid = pid;
        document.getElementById('detailPid').textContent = '#' + pid;
        document.getElementById('processDetailBody').innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Cargando...</div>';
        if (!detailModal) detailModal = new bootstrap.Modal(document.getElementById('processDetailModal'));
        detailModal.show();

        try {
            const resp = await fetch(`/dashboard/process-detail?pid=${pid}`);
            const data = await resp.json();
            if (!data.ok) {
                document.getElementById('processDetailBody').innerHTML = `<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>${esc(data.error)}</div>`;
                return;
            }
            const d = data;
            document.getElementById('processDetailBody').innerHTML = `
                <div class="table-responsive"><table class="table table-dark table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:140px">PID</td><td><code>${d.pid}</code></td></tr>
                    <tr><td class="ps-3 text-muted">PPID</td><td><code>${d.ppid}</code></td></tr>
                    <tr><td class="ps-3 text-muted">Usuario</td><td>${esc(d.user)}</td></tr>
                    <tr><td class="ps-3 text-muted">CPU %</td><td><span style="color:${cpuColor(d.cpu)}">${d.cpu.toFixed(1)}%</span></td></tr>
                    <tr><td class="ps-3 text-muted">RAM %</td><td><span style="color:${memColor(d.mem)}">${d.mem.toFixed(1)}%</span></td></tr>
                    <tr><td class="ps-3 text-muted">RSS</td><td>${formatKB(d.rss)}</td></tr>
                    <tr><td class="ps-3 text-muted">VSZ</td><td>${formatKB(d.vsz)}</td></tr>
                    <tr><td class="ps-3 text-muted">Estado</td><td><code>${esc(d.stat)}</code></td></tr>
                    <tr><td class="ps-3 text-muted">Iniciado</td><td>${esc(d.started)}</td></tr>
                    <tr><td class="ps-3 text-muted">Tiempo CPU</td><td>${esc(d.time)}</td></tr>
                    <tr><td class="ps-3 text-muted">Threads</td><td>${d.threads}</td></tr>
                    <tr><td class="ps-3 text-muted">File Descriptors</td><td>${d.fd_count}</td></tr>
                    ${d.exe ? `<tr><td class="ps-3 text-muted">Ejecutable</td><td><code class="text-info small">${esc(d.exe)}</code></td></tr>` : ''}
                    ${d.cwd ? `<tr><td class="ps-3 text-muted">Directorio</td><td><code class="small">${esc(d.cwd)}</code></td></tr>` : ''}
                </table></div>
                <div class="mt-3"><label class="text-muted small mb-1 d-block">Comando completo:</label>
                <pre class="bg-black text-light p-3 rounded small mb-0" style="white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto">${esc(d.cmdline || d.command)}</pre></div>`;
        } catch (e) {
            document.getElementById('processDetailBody').innerHTML = `<div class="alert alert-danger mb-0">Error: ${esc(e.message)}</div>`;
        }
    };

    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '';
    window.killProcess = async function(pid, signal) {
        if (!pid || pid < 2) return;
        const signalLabels = { TERM: 'SIGTERM (graceful)', KILL: 'SIGKILL (forzar)', HUP: 'SIGHUP (reload)' };
        const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
        const confirmed = await S.fire({
            title: 'Confirmar Kill',
            html: `<p>Enviar <b>${signalLabels[signal]||signal}</b> al proceso <code>${pid}</code>?</p>
                   ${signal === 'KILL' ? '<p class="text-danger"><small>SIGKILL termina el proceso inmediatamente.</small></p>' : ''}`,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: `Enviar ${signal}`,
            confirmButtonColor: signal === 'KILL' ? '#dc3545' : '#ffc107',
            cancelButtonText: 'Cancelar',
        });
        if (!confirmed.isConfirmed) return;
        try {
            const form = new FormData();
            form.append('pid', pid); form.append('signal', signal); form.append('_csrf_token', csrfToken);
            const resp = await fetch('/dashboard/process-kill', { method: 'POST', body: form });
            const data = await resp.json();
            S.fire({ title: data.killed ? 'Terminado' : 'Signal Enviada', text: data.message || (data.ok ? 'OK' : data.error), icon: data.killed ? 'success' : (data.ok ? 'info' : 'error'), timer: 3000 });
            if (data.killed && detailModal) detailModal.hide();
            fetchProcesses();
        } catch (e) { S.fire({ title: 'Error', text: e.message, icon: 'error' }); }
    };
})();

// ─── Network Detail Modal ──────────────────────────────────
window.openNetworkModal = async function(iface) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    S.fire({ title: iface, html: '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Cargando...</div>', showConfirmButton: false, showCloseButton: true, width: 550 });

    try {
        const resp = await fetch('/monitor/api/network-detail?iface=' + encodeURIComponent(iface));
        const d = await resp.json();
        if (!d.ok) { S.fire({ icon: 'error', title: 'Error', text: d.error }); return; }

        const fmtB = (b) => {
            if (b >= 1099511627776) return (b/1099511627776).toFixed(2) + ' TB';
            if (b >= 1073741824) return (b/1073741824).toFixed(2) + ' GB';
            if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
            if (b >= 1024) return (b/1024).toFixed(1) + ' KB';
            return b + ' B';
        };
        const fmtRate = (b) => fmtB(b) + '/s';

        const stateColor = d.state === 'up' ? '#22c55e' : '#ef4444';
        const errTotal = d.rx_errors + d.tx_errors + d.rx_dropped + d.tx_dropped;
        const errBadge = errTotal > 0 ? `<span class="badge bg-danger ms-1">${errTotal} errors</span>` : '<span class="badge bg-success ms-1">clean</span>';

        S.fire({
            title: `<i class="bi bi-hdd-network me-2"></i>${iface}`,
            html: `<div class="text-start">
                <div class="d-flex gap-3 mb-3 justify-content-center">
                    <div class="text-center"><div style="font-size:1.4rem;color:#22c55e;"><i class="bi bi-arrow-down"></i> ${fmtRate(d.rx_rate)}</div><small class="text-muted">Download</small></div>
                    <div class="text-center"><div style="font-size:1.4rem;color:#38bdf8;"><i class="bi bi-arrow-up"></i> ${fmtRate(d.tx_rate)}</div><small class="text-muted">Upload</small></div>
                </div>
                <table class="table table-sm table-dark mb-0">
                    <tr><td class="text-muted ps-2">Estado</td><td><span style="color:${stateColor}"><i class="bi bi-circle-fill me-1" style="font-size:0.5rem;"></i>${d.state}</span></td></tr>
                    <tr><td class="text-muted ps-2">IPs</td><td>${d.ips.map(ip => '<code>'+ip+'</code>').join('<br>') || 'N/A'}</td></tr>
                    <tr><td class="text-muted ps-2">Speed / MTU</td><td>${d.speed} / ${d.mtu}</td></tr>
                    <tr><td class="text-muted ps-2">Duplex</td><td>${d.duplex}</td></tr>
                    <tr><td class="text-muted ps-2 border-top border-secondary pt-2" colspan="2"><strong>Totales desde boot</strong></td></tr>
                    <tr><td class="text-muted ps-2">RX</td><td>${fmtB(d.rx_bytes)} (${d.rx_packets.toLocaleString()} pkts)</td></tr>
                    <tr><td class="text-muted ps-2">TX</td><td>${fmtB(d.tx_bytes)} (${d.tx_packets.toLocaleString()} pkts)</td></tr>
                    <tr><td class="text-muted ps-2">Errores / Drops</td><td>RX: ${d.rx_errors}/${d.rx_dropped} — TX: ${d.tx_errors}/${d.tx_dropped} ${errBadge}</td></tr>
                </table>
            </div>`,
            showConfirmButton: false,
            showCloseButton: true,
            width: 550,
        });
    } catch (e) { S.fire({ icon: 'error', title: 'Error', text: e.message }); }
};

// ─── Disk Detail Modal ─────────────────────────────────────
window.openDiskModal = async function(mount) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    S.fire({ title: mount, html: '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Analizando disco...</div>', showConfirmButton: false, showCloseButton: true, width: 600 });

    try {
        const resp = await fetch('/monitor/api/disk-detail?mount=' + encodeURIComponent(mount));
        const d = await resp.json();
        if (!d.ok) { S.fire({ icon: 'error', title: 'Error', text: d.error }); return; }

        const fmtB = (b) => {
            if (b >= 1099511627776) return (b/1099511627776).toFixed(1) + ' TB';
            if (b >= 1073741824) return (b/1073741824).toFixed(1) + ' GB';
            if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
            return (b/1024).toFixed(0) + ' KB';
        };

        const pctColor = d.percent >= 90 ? '#ef4444' : (d.percent >= 75 ? '#fbbf24' : '#22c55e');

        let topHtml = '';
        if (d.top_dirs && d.top_dirs.length > 0) {
            const maxMb = d.top_dirs[0].mb || 1;
            topHtml = d.top_dirs.map(t => {
                const w = Math.max(2, Math.round(t.mb / maxMb * 100));
                const label = t.mb >= 1024 ? (t.mb/1024).toFixed(1)+' GB' : t.mb+' MB';
                const shortPath = t.path.replace(mount === '/' ? '' : mount, '').replace(/^\//, '') || '/';
                return `<div class="mb-1 d-flex align-items-center gap-2">
                    <code class="small" style="min-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${t.path}">${shortPath}</code>
                    <div class="flex-grow-1"><div style="height:6px;border-radius:3px;background:#1e293b;"><div style="width:${w}%;height:100%;border-radius:3px;background:#38bdf8;"></div></div></div>
                    <small class="text-muted" style="min-width:55px;text-align:right;">${label}</small>
                </div>`;
            }).join('');
        }

        const inodeHtml = d.inodes && d.inodes.total ? `<tr><td class="text-muted ps-2">Inodes</td><td>${d.inodes.used.toLocaleString()} / ${d.inodes.total.toLocaleString()} (${d.inodes.percent})</td></tr>` : '';

        S.fire({
            title: `<i class="bi bi-hdd me-2"></i>${mount}`,
            html: `<div class="text-start">
                <div class="text-center mb-3">
                    <div style="font-size:2rem;color:${pctColor};">${d.percent}%</div>
                    <div class="progress mx-auto" style="width:80%;height:8px;"><div class="progress-bar" style="width:${d.percent}%;background:${pctColor};"></div></div>
                    <small class="text-muted">${fmtB(d.used)} / ${fmtB(d.size)} (${fmtB(d.free)} libre)</small>
                </div>
                <table class="table table-sm table-dark mb-3">
                    <tr><td class="text-muted ps-2">Dispositivo</td><td><code>${d.device}</code></td></tr>
                    <tr><td class="text-muted ps-2">Filesystem</td><td>${d.fstype}</td></tr>
                    ${inodeHtml}
                </table>
                ${topHtml ? '<div class="mb-1"><strong class="small text-muted">Top directorios:</strong></div>' + topHtml : ''}
            </div>`,
            showConfirmButton: false,
            showCloseButton: true,
            width: 600,
        });
    } catch (e) { S.fire({ icon: 'error', title: 'Error', text: e.message }); }
};
</script>
