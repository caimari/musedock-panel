<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<h5 class="mb-3"><i class="bi bi-server me-2"></i>Servicios del Panel</h5>
<div class="row g-3">
    <?php foreach ($services as $svc): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-0">
                            <i class="<?= $svc['icon'] ?> me-1"></i><?= View::e($svc['name']) ?>
                            <?php if ($svc['id'] === 'caddy'): ?>
                                <a href="/settings/caddy" class="ms-1" style="color:#38bdf8;font-size:0.8rem;" title="Configuracion Caddy"><i class="bi bi-gear"></i></a>
                            <?php endif; ?>
                        </h6>
                        <small class="text-muted"><?= View::e($svc['desc']) ?></small>
                    </div>
                    <?php
                    $badgeClass = match($svc['status']) {
                        'active' => 'background:rgba(34,197,94,0.15);color:#22c55e;',
                        'inactive' => 'background:rgba(239,68,68,0.15);color:#ef4444;',
                        default => 'background:rgba(148,163,184,0.15);color:#94a3b8;',
                    };
                    ?>
                    <span class="badge" style="<?= $badgeClass ?>">
                        <?= $svc['status'] ?>
                        <?php if ($svc['uptime']): ?>
                            <small>(<?= $svc['uptime'] ?>)</small>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="d-flex gap-1 mt-3">
                    <?php if ($svc['status'] === 'active'): ?>
                        <?php if ($svc['id'] === 'caddy'): ?>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'restart', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                                <input type="hidden" name="action" value="restart">
                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i>Reiniciar
                                </button>
                            </form>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'stop', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                                <input type="hidden" name="action" value="stop">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-stop-circle me-1"></i>Detener
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'restart', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                                <input type="hidden" name="action" value="restart">
                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i>Reiniciar
                                </button>
                            </form>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'reload', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                                <input type="hidden" name="action" value="reload">
                                <button type="submit" class="btn btn-outline-info btn-sm">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reload
                                </button>
                            </form>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'stop', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                                <input type="hidden" name="action" value="stop">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-stop-circle me-1"></i>Detener
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'start', '<?= View::e($svc['name']) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <input type="hidden" name="service" value="<?= View::e($svc['id']) ?>">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-play-circle me-1"></i>Iniciar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Supervisor Workers -->
<?php
$supervisorInstalled = file_exists('/usr/bin/supervisord') || file_exists('/etc/supervisor/supervisord.conf');
$workers = [];
if ($supervisorInstalled) {
    $output = shell_exec('supervisorctl status 2>/dev/null') ?? '';
    foreach (explode("\n", trim($output)) as $line) {
        if (empty(trim($line))) continue;
        if (preg_match('/^(\S+)\s+(RUNNING|STOPPED|FATAL|STARTING|BACKOFF|EXITED)\s+(.*)$/', trim($line), $m)) {
            $workers[] = ['name' => $m[1], 'status' => $m[2], 'info' => trim($m[3])];
        }
    }
}
?>
<?php if ($supervisorInstalled && !empty($workers)): ?>
<div class="card mt-3">
    <div class="card-header"><i class="bi bi-cpu me-2"></i>Supervisor Workers</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Proceso</th><th>Estado</th><th>Info</th></tr></thead>
            <tbody>
                <?php foreach ($workers as $w): ?>
                <tr>
                    <td><code><?= View::e($w['name']) ?></code></td>
                    <td>
                        <?php
                        $wBadge = match($w['status']) {
                            'RUNNING' => 'background:rgba(34,197,94,0.15);color:#22c55e;',
                            'FATAL','BACKOFF' => 'background:rgba(239,68,68,0.15);color:#ef4444;',
                            default => 'background:rgba(251,191,36,0.15);color:#fbbf24;',
                        };
                        ?>
                        <span class="badge" style="<?= $wBadge ?>"><?= $w['status'] ?></span>
                    </td>
                    <td><small class="text-muted"><?= View::e($w['info']) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- System Services -->
<?php if (!empty($systemServices)): ?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div><i class="bi bi-gear-wide-connected me-2"></i>Servicios del Sistema <span class="badge bg-secondary"><?= count($systemServices) ?></span></div>
        <div class="d-flex gap-2 align-items-center">
            <input type="text" id="svcSearch" class="form-control form-control-sm" placeholder="Buscar servicio..." style="width:200px;background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;" oninput="filterServices()">
            <select id="svcFilter" class="form-select form-select-sm" style="width:140px;background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;" onchange="filterServices()">
                <option value="all">Todos</option>
                <option value="active">Activos</option>
                <option value="inactive">Inactivos</option>
                <option value="enabled">Autostart ON</option>
                <option value="disabled">Autostart OFF</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
        <table class="table table-sm table-hover mb-0" id="sysServicesTable">
            <thead style="position:sticky;top:0;z-index:1;background:#1e293b;">
                <tr>
                    <th style="width:30%;">Servicio</th>
                    <th style="width:25%;">Descripcion</th>
                    <th style="width:10%;text-align:center;">Estado</th>
                    <th style="width:10%;text-align:center;">Autostart</th>
                    <th style="width:25%;text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($systemServices as $ss): ?>
                <tr class="svc-row" data-status="<?= $ss['status'] ?>" data-enabled="<?= $ss['enabled'] ?>" data-name="<?= View::e(strtolower($ss['name'])) ?>">
                    <td>
                        <code style="font-size:0.85rem;"><?= View::e($ss['name']) ?></code>
                        <?php if ($ss['critical']): ?>
                            <i class="bi bi-shield-lock ms-1 text-warning" title="Servicio critico del sistema"></i>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= View::e($ss['description']) ?></small></td>
                    <td class="text-center">
                        <?php
                        $sBadge = match($ss['status']) {
                            'active' => 'background:rgba(34,197,94,0.15);color:#22c55e;',
                            default => 'background:rgba(148,163,184,0.15);color:#94a3b8;',
                        };
                        ?>
                        <span class="badge" style="<?= $sBadge ?>">
                            <?= $ss['status'] ?>
                            <?php if ($ss['uptime']): ?><small>(<?= $ss['uptime'] ?>)</small><?php endif; ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($ss['critical']): ?>
                            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-lock-fill"></i></span>
                        <?php elseif ($ss['enabled'] === 'enabled'): ?>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'disable', '<?= View::e($ss['name']) ?>')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($ss['id']) ?>">
                                <input type="hidden" name="action" value="disable">
                                <button type="submit" class="btn btn-sm p-0 border-0" style="color:#22c55e;" title="Autostart activo — click para desactivar">
                                    <i class="bi bi-toggle-on" style="font-size:1.4rem;"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'enable', '<?= View::e($ss['name']) ?>')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($ss['id']) ?>">
                                <input type="hidden" name="action" value="enable">
                                <button type="submit" class="btn btn-sm p-0 border-0" style="color:#94a3b8;" title="Autostart desactivado — click para activar">
                                    <i class="bi bi-toggle-off" style="font-size:1.4rem;"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($ss['critical']): ?>
                            <small class="text-muted">Protegido</small>
                        <?php elseif ($ss['status'] === 'active'): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'restart', '<?= View::e($ss['name']) ?>')">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="service" value="<?= View::e($ss['id']) ?>">
                                    <input type="hidden" name="action" value="restart">
                                    <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Reiniciar"><i class="bi bi-arrow-repeat"></i></button>
                                </form>
                                <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'stop', '<?= View::e($ss['name']) ?>')">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="service" value="<?= View::e($ss['id']) ?>">
                                    <input type="hidden" name="action" value="stop">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Detener"><i class="bi bi-stop-circle"></i></button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="/settings/services/action" class="d-inline" onsubmit="return svcConfirm(event, this, 'start', '<?= View::e($ss['name']) ?>')">
                                <?= View::csrf() ?>
                                <input type="hidden" name="service" value="<?= View::e($ss['id']) ?>">
                                <input type="hidden" name="action" value="start">
                                <button type="submit" class="btn btn-outline-success btn-sm py-0 px-1" title="Iniciar"><i class="bi bi-play-circle"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="card-footer text-muted" style="font-size:0.8rem;">
        <i class="bi bi-info-circle me-1"></i>Los servicios marcados con <i class="bi bi-shield-lock text-warning"></i> son criticos y no se pueden modificar desde el panel.
        Autostart <i class="bi bi-toggle-on" style="color:#22c55e;"></i> = se inicia automaticamente al arrancar el servidor.
    </div>
</div>
<?php endif; ?>

<script>
function filterServices() {
    var search = document.getElementById('svcSearch').value.toLowerCase();
    var filter = document.getElementById('svcFilter').value;
    var rows = document.querySelectorAll('.svc-row');
    var visible = 0;
    rows.forEach(function(row) {
        var name = row.getAttribute('data-name');
        var status = row.getAttribute('data-status');
        var enabled = row.getAttribute('data-enabled');
        var show = true;
        if (search && name.indexOf(search) === -1) show = false;
        if (filter === 'active' && status !== 'active') show = false;
        if (filter === 'inactive' && status !== 'inactive') show = false;
        if (filter === 'enabled' && enabled !== 'enabled') show = false;
        if (filter === 'disabled' && enabled !== 'disabled') show = false;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
}

function svcConfirm(e, form, action, name) {
    e.preventDefault();
    var cfg = {
        restart: {
            title: 'Reiniciar ' + name + '?',
            html: 'El servicio se detendr\u00e1 y volver\u00e1 a iniciar.<br><small class="text-muted">Puede haber una breve interrupci\u00f3n.</small>',
            icon: 'warning',
            confirmText: '<i class="bi bi-arrow-repeat me-1"></i> Reiniciar',
            confirmColor: '#f59e0b'
        },
        reload: {
            title: 'Recargar ' + name + '?',
            html: 'Se recargar\u00e1 la configuraci\u00f3n sin interrumpir el servicio.',
            icon: 'info',
            confirmText: '<i class="bi bi-arrow-clockwise me-1"></i> Reload',
            confirmColor: '#0ea5e9'
        },
        stop: {
            title: 'DETENER ' + name + '?',
            html: '<div style="color:#ef4444;font-weight:600;">El servicio dejar\u00e1 de funcionar.</div><br><small class="text-muted">Tendr\u00e1s que iniciarlo manualmente despu\u00e9s.</small>',
            icon: 'error',
            confirmText: '<i class="bi bi-stop-circle me-1"></i> Detener',
            confirmColor: '#ef4444'
        },
        start: {
            title: 'Iniciar ' + name + '?',
            html: 'El servicio se pondr\u00e1 en marcha.',
            icon: 'question',
            confirmText: '<i class="bi bi-play-circle me-1"></i> Iniciar',
            confirmColor: '#22c55e'
        },
        enable: {
            title: 'Habilitar autostart de ' + name + '?',
            html: 'El servicio se iniciar\u00e1 autom\u00e1ticamente al arrancar el servidor.',
            icon: 'question',
            confirmText: '<i class="bi bi-toggle-on me-1"></i> Habilitar',
            confirmColor: '#22c55e'
        },
        disable: {
            title: 'Deshabilitar autostart de ' + name + '?',
            html: 'El servicio <strong>NO</strong> se iniciar\u00e1 autom\u00e1ticamente al arrancar el servidor.<br><small class="text-muted">El servicio seguir\u00e1 activo ahora, pero no arrancar\u00e1 tras un reinicio.</small>',
            icon: 'warning',
            confirmText: '<i class="bi bi-toggle-off me-1"></i> Deshabilitar',
            confirmColor: '#f59e0b'
        }
    };
    var c = cfg[action];
    SwalDark.fire({
        title: c.title,
        html: c.html,
        icon: c.icon,
        showCancelButton: true,
        confirmButtonText: c.confirmText,
        confirmButtonColor: c.confirmColor,
        cancelButtonText: 'Cancelar',
        focusCancel: (action === 'stop' || action === 'disable')
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
