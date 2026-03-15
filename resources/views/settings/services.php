<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

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
                            <!-- Caddy: solo restart seguro (ejecuta repair después) — NO ofrecer reload -->
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

<script>
function svcConfirm(e, form, action, name) {
    e.preventDefault();
    var cfg = {
        restart: {
            title: 'Reiniciar ' + name + '?',
            html: 'El servicio se detendrá y volverá a iniciar.<br><small class="text-muted">Puede haber una breve interrupción.</small>',
            icon: 'warning',
            confirmText: '<i class="bi bi-arrow-repeat me-1"></i> Reiniciar',
            confirmColor: '#f59e0b'
        },
        reload: {
            title: 'Recargar ' + name + '?',
            html: 'Se recargará la configuración sin interrumpir el servicio.',
            icon: 'info',
            confirmText: '<i class="bi bi-arrow-clockwise me-1"></i> Reload',
            confirmColor: '#0ea5e9'
        },
        stop: {
            title: 'DETENER ' + name + '?',
            html: '<div style="color:#ef4444;font-weight:600;">Los sitios que dependen de este servicio dejarán de funcionar.</div><br><small class="text-muted">Tendrás que iniciarlo manualmente después.</small>',
            icon: 'error',
            confirmText: '<i class="bi bi-stop-circle me-1"></i> Detener',
            confirmColor: '#ef4444'
        },
        start: {
            title: 'Iniciar ' + name + '?',
            html: 'El servicio se pondrá en marcha.',
            icon: 'question',
            confirmText: '<i class="bi bi-play-circle me-1"></i> Iniciar',
            confirmColor: '#22c55e'
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
        focusCancel: action === 'stop'
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
