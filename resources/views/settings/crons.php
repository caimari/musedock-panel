<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Existing crons -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Cron Jobs Activos</span>
        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><?= count($crons) ?> tarea(s)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($crons)): ?>
            <div class="p-3 text-center text-muted">No hay cron jobs configurados.</div>
        <?php else: ?>
            <?php foreach ($crons as $i => $cron): ?>
            <div class="border-bottom" style="border-color:#334155 !important;" id="cron-row-<?= $i ?>">
                <!-- View mode -->
                <div class="p-3 cron-view" id="cron-view-<?= $i ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.75rem;"><?= View::e($cron['user']) ?></span>
                                <code style="font-size:0.8rem;color:#fbbf24;"><?= View::e($cron['schedule']) ?></code>
                                <?php $desc = describeCronSchedule($cron['schedule']); if ($desc): ?>
                                    <small class="text-muted">(<?= $desc ?>)</small>
                                <?php endif; ?>
                            </div>
                            <div style="word-break:break-all;">
                                <code style="font-size:0.8rem;"><?= View::e($cron['command']) ?></code>
                            </div>
                        </div>
                        <div class="d-flex gap-1 ms-2 flex-shrink-0">
                            <button type="button" class="btn btn-outline-light btn-sm py-0 px-1" onclick="cronEdit(<?= $i ?>)" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="/settings/crons/delete" class="d-inline" onsubmit="return cronDeleteConfirm(event, this, '<?= View::e(addslashes($cron['user'])) ?>', '<?= View::e(addslashes($cron['command'])) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                                <input type="hidden" name="user" value="<?= View::e($cron['user']) ?>">
                                <input type="hidden" name="line_index" value="<?= $cron['line_index'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <!-- Edit mode (hidden) -->
                <div class="p-3 cron-edit" id="cron-edit-<?= $i ?>" style="display:none;background:rgba(56,189,248,0.03);">
                    <form method="POST" action="/settings/crons/update">
                    <?= \MuseDockPanel\View::csrf() ?>
                        <input type="hidden" name="user" value="<?= View::e($cron['user']) ?>">
                        <input type="hidden" name="line_index" value="<?= $cron['line_index'] ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-12">
                                <small class="text-muted">Editando cron de <strong style="color:#38bdf8;"><?= View::e($cron['user']) ?></strong></small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" style="font-size:0.8rem;">Programacion</label>
                                <div class="input-group input-group-sm">
                                    <select class="form-select form-select-sm" onchange="if(this.value)this.closest('.col-md-3').querySelector('input[name=schedule]').value=this.value" style="max-width:120px;">
                                        <option value="">Preset</option>
                                        <option value="* * * * *">Cada min</option>
                                        <option value="*/5 * * * *">5 min</option>
                                        <option value="*/15 * * * *">15 min</option>
                                        <option value="*/30 * * * *">30 min</option>
                                        <option value="0 * * * *">Cada hora</option>
                                        <option value="0 */6 * * *">6 horas</option>
                                        <option value="0 0 * * *">Diario</option>
                                        <option value="0 0 * * 1">Semanal</option>
                                        <option value="0 0 1 * *">Mensual</option>
                                    </select>
                                    <input type="text" name="schedule" class="form-control form-control-sm" value="<?= View::e($cron['schedule']) ?>" required style="font-family:monospace;">
                                </div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" style="font-size:0.8rem;">Comando</label>
                                <input type="text" name="command" class="form-control form-control-sm" value="<?= View::e($cron['command']) ?>" required style="font-family:monospace;">
                            </div>
                            <div class="col-md-2">
                                <div class="d-flex gap-1">
                                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-check-lg"></i></button>
                                    <button type="button" class="btn btn-outline-light btn-sm" onclick="cronCancel(<?= $i ?>)"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add cron -->
<div class="card">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Anadir Cron Job</div>
    <div class="card-body">
        <form method="POST" action="/settings/crons/save">
                    <?= \MuseDockPanel\View::csrf() ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Usuario</label>
                    <select name="user" class="form-select">
                        <option value="root">root</option>
                        <?php
                        $accounts = \MuseDockPanel\Database::fetchAll("SELECT username, domain FROM hosting_accounts ORDER BY domain");
                        foreach ($accounts as $acc):
                        ?>
                            <option value="<?= View::e($acc['username']) ?>"><?= View::e($acc['username']) ?> (<?= View::e($acc['domain']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Programacion</label>
                    <select id="schedulePreset" class="form-select" onchange="applySchedulePreset(this.value)">
                        <option value="">Personalizado</option>
                        <option value="* * * * *">Cada minuto</option>
                        <option value="*/5 * * * *">Cada 5 min</option>
                        <option value="*/15 * * * *">Cada 15 min</option>
                        <option value="*/30 * * * *">Cada 30 min</option>
                        <option value="0 * * * *">Cada hora</option>
                        <option value="0 */6 * * *">Cada 6 horas</option>
                        <option value="0 0 * * *">Diario (00:00)</option>
                        <option value="0 3 * * *">Diario (03:00)</option>
                        <option value="0 0 * * 1">Semanal (lunes)</option>
                        <option value="0 0 1 * *">Mensual (dia 1)</option>
                    </select>
                    <input type="text" name="schedule" id="scheduleInput" class="form-control mt-1" placeholder="* * * * *" required>
                    <small class="text-muted">min hora dia mes dia_semana</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Comando</label>
                    <input type="text" name="command" class="form-control" placeholder="cd /var/www/vhosts/domain.com/httpdocs && php artisan schedule:run >> /dev/null 2>&1" required>
                    <small class="text-muted">Comando completo con ruta absoluta</small>
                </div>
            </div>

            <div class="mt-2 p-2 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                <small class="text-muted">
                    <i class="bi bi-lightbulb me-1" style="color:#fbbf24;"></i>
                    <strong>Tip Laravel:</strong>
                    <code style="font-size:0.75rem;">* * * * * cd /var/www/vhosts/DOMINIO/httpdocs && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1</code>
                </small>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Anadir Cron</button>
            </div>
        </form>
    </div>
</div>

<?php
function describeCronSchedule(string $schedule): string
{
    $map = [
        '* * * * *' => 'Cada minuto',
        '*/5 * * * *' => 'Cada 5 min',
        '*/15 * * * *' => 'Cada 15 min',
        '*/30 * * * *' => 'Cada 30 min',
        '0 * * * *' => 'Cada hora',
        '0 */6 * * *' => 'Cada 6h',
        '0 0 * * *' => 'Diario 00:00',
        '0 3 * * *' => 'Diario 03:00',
        '0 0 * * 1' => 'Semanal lun',
        '0 0 1 * *' => 'Mensual dia 1',
    ];
    return $map[$schedule] ?? '';
}
?>

<script>
function applySchedulePreset(val) {
    if (val) document.getElementById('scheduleInput').value = val;
}

function cronEdit(idx) {
    // Close any other open editors
    document.querySelectorAll('.cron-edit').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.cron-view').forEach(function(el) { el.style.display = 'block'; });
    // Open this one
    document.getElementById('cron-view-' + idx).style.display = 'none';
    document.getElementById('cron-edit-' + idx).style.display = 'block';
    document.getElementById('cron-edit-' + idx).querySelector('input[name=command]').focus();
}

function cronCancel(idx) {
    document.getElementById('cron-view-' + idx).style.display = 'block';
    document.getElementById('cron-edit-' + idx).style.display = 'none';
}

function cronDeleteConfirm(e, form, user, command) {
    e.preventDefault();
    var shortCmd = command.length > 80 ? command.substring(0, 80) + '...' : command;
    SwalDark.fire({
        title: 'Eliminar cron job?',
        html: '<div class="text-start"><small class="text-muted">Usuario:</small> <code>' + user + '</code><br><small class="text-muted">Comando:</small><br><code style="font-size:0.75rem;word-break:break-all;">' + shortCmd + '</code></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i> Eliminar',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancelar',
        focusCancel: true
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
