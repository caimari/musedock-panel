<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <?php
            $pct = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100) : 0;
            $sumColor = $pct === 100 ? '#22c55e' : ($pct >= 80 ? '#fbbf24' : '#ef4444');
            $sumBg = $pct === 100 ? 'rgba(34,197,94,0.15)' : ($pct >= 80 ? 'rgba(251,191,36,0.15)' : 'rgba(239,68,68,0.15)');
        ?>
        <div class="stat-card text-center">
            <div class="stat-value" style="font-size:2.5rem;color:<?= $sumColor ?>"><?= $passedChecks ?>/<?= $totalChecks ?></div>
            <div class="stat-label">Checks Passed</div>
            <div class="progress mt-2"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $sumColor ?>"></div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-<?= $checks['service']['active'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> fs-4"></i>
                <div>
                    <div class="stat-label">Panel Service</div>
                    <strong><?= $checks['service']['active'] ? 'Running' : ucfirst($checks['service']['status']) ?></strong>
                </div>
            </div>
            <small class="text-muted">Autostart: <?= $checks['service']['enabled'] ? 'Yes' : 'No' ?></small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-<?= $checks['database']['connected'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> fs-4"></i>
                <div>
                    <div class="stat-label">Database</div>
                    <strong><?= $checks['database']['connected'] ? 'Connected' : 'Disconnected' ?></strong>
                </div>
            </div>
            <?php if ($checks['database']['version']): ?>
                <small class="text-muted"><?= View::e(substr($checks['database']['version'], 0, 60)) ?></small>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Database Timezone -->
<?php if ($checks['database']['pg_timezone'] || $checks['database']['mysql_ok']): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock me-2"></i>Database Timezone</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Engine</th>
                    <th>Timezone Actual</th>
                    <th>Recomendado</th>
                    <th>Estado</th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($checks['database']['pg_timezone']): ?>
                <tr>
                    <td class="ps-3"><code>PostgreSQL</code></td>
                    <td><strong><?= View::e($checks['database']['pg_timezone']) ?></strong></td>
                    <td><small class="text-muted">UTC</small></td>
                    <td>
                        <?php if ($checks['database']['pg_tz_ok']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>OK</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>No UTC</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <?php if (!$checks['database']['pg_tz_ok']): ?>
                            <form method="POST" action="/settings/health/fix-timezone" class="d-inline" onsubmit="return confirmTimezone(event, this, 'PostgreSQL')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="engine" value="postgresql">
                                <button type="submit" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-wrench me-1"></i>Set UTC
                                </button>
                            </form>
                        <?php else: ?>
                            <small class="text-muted"><i class="bi bi-check"></i></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($checks['database']['mysql_ok']): ?>
                <tr>
                    <td class="ps-3"><code>MySQL</code></td>
                    <td><strong><?= View::e($checks['database']['mysql_timezone']) ?></strong></td>
                    <td><small class="text-muted">UTC / +00:00</small></td>
                    <td>
                        <?php if ($checks['database']['mysql_tz_ok']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>OK</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>No UTC</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <?php if (!$checks['database']['mysql_tz_ok']): ?>
                            <form method="POST" action="/settings/health/fix-timezone" class="d-inline" onsubmit="return confirmTimezone(event, this, 'MySQL')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="engine" value="mysql">
                                <button type="submit" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-wrench me-1"></i>Set UTC
                                </button>
                            </form>
                        <?php else: ?>
                            <small class="text-muted"><i class="bi bi-check"></i></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Cron Jobs -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Cron Jobs del Panel</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Cron</th>
                    <th>Descripcion</th>
                    <th>Intervalo</th>
                    <th>Estado</th>
                    <th class="text-end pe-3">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks['crons'] as $cron): ?>
                <tr>
                    <td class="ps-3"><code><?= View::e($cron['name']) ?></code></td>
                    <td><small><?= View::e($cron['desc']) ?></small></td>
                    <td><small class="text-muted"><?= View::e($cron['interval']) ?></small></td>
                    <td>
                        <?php if ($cron['valid']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>OK</span>
                        <?php elseif ($cron['exists']): ?>
                            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Empty</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Missing</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <?php if (!$cron['valid']): ?>
                            <form method="POST" action="/settings/health/repair-cron" class="d-inline" onsubmit="return confirmRepair(event, this, '<?= View::e($cron['name']) ?>')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="cron" value="<?= View::e($cron['name']) ?>">
                                <button type="submit" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-wrench me-1"></i>Repair
                                </button>
                            </form>
                        <?php else: ?>
                            <small class="text-muted"><i class="bi bi-check"></i></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Database Repair -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-database-gear me-2"></i>Base de datos — Reparar tablas</span>
        <form method="POST" action="/settings/health/repair-db" class="d-inline" onsubmit="return confirmDbRepair(event, this)">
            <?= \MuseDockPanel\View::csrf() ?>
            <button type="submit" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-wrench me-1"></i>Reparar BD
            </button>
        </form>
    </div>
    <div class="card-body">
        <small class="text-muted">
            Re-ejecuta <code>schema.sql</code> (crea tablas faltantes sin tocar las existentes) y ejecuta migraciones pendientes.
            Usar si alguna seccion del panel da error 500 por tablas inexistentes.
        </small>
    </div>
</div>

<!-- PHP Extensions -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-puzzle me-2"></i>PHP Extensions</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Extension</th>
                    <th>Descripcion</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks['extensions'] as $ext): ?>
                <tr>
                    <td class="ps-3"><code><?= View::e($ext['name']) ?></code></td>
                    <td><small><?= View::e($ext['desc']) ?></small></td>
                    <td>
                        <?php if ($ext['loaded']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Loaded</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Missing</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- System Binaries -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>System Binaries</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Binary</th>
                    <th>Descripcion</th>
                    <th>Path</th>
                    <th>Version</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks['binaries'] as $bin): ?>
                <tr>
                    <td class="ps-3"><code><?= View::e($bin['name']) ?></code></td>
                    <td><small><?= View::e($bin['desc']) ?></small></td>
                    <td><small class="text-muted"><?= $bin['found'] ? View::e($bin['path']) : '-' ?></small></td>
                    <td><small class="text-muted"><?= $bin['version'] ? View::e(substr($bin['version'], 0, 50)) : '-' ?></small></td>
                    <td>
                        <?php if ($bin['found']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Found</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Not found</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Directories & Permissions -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder me-2"></i>Directories & Permissions</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Directory</th>
                    <th>Descripcion</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks['directories'] as $dir): ?>
                <tr>
                    <td class="ps-3"><code><?= View::e($dir['path']) ?></code></td>
                    <td><small><?= View::e($dir['desc']) ?></small></td>
                    <td>
                        <?php if ($dir['writable']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Writable</span>
                        <?php elseif ($dir['exists']): ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Not writable</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Missing</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- GPU Health -->
<?php if ($checks['gpu_driver'] || !empty($checks['gpus'])): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-gpu-card me-2"></i>GPU Health (NVIDIA)</div>
    <div class="card-body p-0">
        <?php if (empty($checks['gpus'])): ?>
            <div class="text-center text-muted py-3">nvidia-smi installed but no GPUs detected</div>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">GPU</th>
                    <th>Model</th>
                    <th>Details</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks['gpus'] as $gpu): ?>
                <tr>
                    <td class="ps-3"><code>GPU <?= $gpu['index'] >= 0 ? $gpu['index'] : '?' ?></code></td>
                    <td><strong><?= View::e($gpu['name']) ?></strong>
                        <?php if ($gpu['uuid']): ?>
                            <br><small class="text-muted"><?= View::e(substr($gpu['uuid'], 0, 20)) ?>...</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($gpu['details']): ?>
                            <small>
                                Driver: <?= View::e($gpu['details']['driver']) ?> &middot;
                                VRAM: <?= View::e($gpu['details']['memory']) ?> &middot;
                                Temp: <?= View::e($gpu['details']['temperature']) ?> &middot;
                                Util: <?= View::e($gpu['details']['utilization']) ?> &middot;
                                Power: <?= View::e($gpu['details']['power']) ?>
                            </small>
                        <?php else: ?>
                            <small class="text-muted">No data available</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($gpu['healthy']): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Healthy</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i><?= View::e($gpu['status']) ?></span>
                            <?php if (!empty($gpu['errors'])): ?>
                                <div class="mt-1">
                                    <?php foreach ($gpu['errors'] as $err): ?>
                                        <small class="d-block text-danger" style="font-size:0.75rem"><i class="bi bi-exclamation-triangle me-1"></i><?= View::e(substr($err, 0, 120)) ?></small>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function confirmRepair(e, form, name) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Repair cron: ' + name + '?',
        html: 'This will recreate the cron file <code>/etc/cron.d/' + name + '</code> with the default configuration.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-wrench me-1"></i> Repair',
        confirmButtonColor: '#22c55e',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}

function confirmTimezone(e, form, engine) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Set ' + engine + ' timezone to UTC?',
        html: 'This will modify the ' + engine + ' configuration file, set the timezone to <strong>UTC</strong>, and <strong>restart the service</strong>.<br><br><small class="text-muted">UTC is recommended for clean timestamp storage. The panel displays times in the timezone configured in Settings &gt; Server.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-wrench me-1"></i> Set UTC & Restart',
        confirmButtonColor: '#22c55e',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
function confirmDbRepair(e, form) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Reparar base de datos?',
        html: 'Se re-ejecutara schema.sql para crear tablas faltantes y se ejecutaran migraciones pendientes.<br><small class="text-muted">Las tablas y datos existentes no se modifican.</small>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-wrench me-1"></i> Reparar',
        confirmButtonColor: '#f59e0b',
        cancelButtonText: 'Cancelar'
    }).then(function(result) {
        if (result.isConfirmed) {
            var btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reparando...';
            form.submit();
        }
    });
    return false;
}
</script>
