<?php use MuseDockPanel\View; ?>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-bell-slash me-2"></i>Avisos</h4>
        <div class="text-muted small">Qué avisos te llegan por correo o Telegram. Silenciar un aviso solo quita el correo: sigue apareciendo en el monitor del panel.
            Se guarda en el master y se copia a sus nodos. Los avisos del relevo (failover, cambio de rol) no se pueden silenciar.</div>
    </div>
    <a href="/settings/notifications" class="btn btn-outline-light btn-sm"><i class="bi bi-envelope me-1"></i>Canales (correo, Telegram)</a>
</div>

<?php if ($isSlave): ?>
    <div class="alert small py-2 px-3 mb-3" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.25);color:#e2e8f0;">
        <i class="bi bi-info-circle me-1" style="color:#38bdf8;"></i>
        <strong>Este servidor es copia:</strong> aquí solo se ven las reglas. Se cambian en el master<?= !empty($masterUrl) ? ' (<a href="' . View::e($masterUrl) . '/settings/alerts" class="text-info" target="_blank">abrir Avisos del master</a>)' : '' ?>
        y se copian solas a este servidor al guardar. También por MCP: <code>alerts_configure</code> en el master.
    </div>
<?php endif; ?>
<form method="POST" action="/settings/alerts/save">
    <?= View::csrf() ?>
    <fieldset <?= $isSlave ? 'disabled' : '' ?>>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-toggles me-2"></i>Tipos de aviso</div>
        <div class="card-body small">
            <div class="row g-2">
                <?php foreach ($types as $key => [$label, $desc]): ?>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="mute-<?= View::e($key) ?>" name="muted[]" value="<?= View::e($key) ?>"
                                <?= in_array($key, $policy['muted'], true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mute-<?= View::e($key) ?>"><strong>Silenciar: <?= View::e($label) ?></strong>
                                <span class="text-muted d-block"><?= View::e($desc) ?></span></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-envelope-exclamation me-2"></i>Nodo de correo con problemas</div>
        <div class="card-body small d-flex flex-wrap align-items-center gap-2">
            <span>Avisar solo si el fallo dura</span>
            <input type="number" name="mail_node_after_minutes" min="1" max="120" value="<?= (int)$policy['mail_node_after_minutes'] ?>" class="form-control form-control-sm" style="width:90px">
            <span>minutos seguidos. Avisa también cuando se recupera. Solo lo vigila el master.</span>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-shield-check me-2"></i>Hardening: controles que das por buenos</div>
        <div class="card-body small">
            <p class="text-muted">Si un control está así a propósito (p. ej. SSH con contraseña porque lo necesitas), márcalo: deja de avisar de él.
                Solo vuelve a avisar si falla un control <strong>nuevo</strong>.</p>
            <?php if ($failedHardening): ?>
                <div class="mb-2"><strong>Fallan ahora en este servidor (<?= View::e($thisHost) ?>):</strong></div>
                <?php foreach ($failedHardening as $i => $f): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="acc-<?= $i ?>" name="accepted[]" value="<?= View::e($f['title']) ?>"
                            <?= in_array($f['title'], $policy['hardening_accepted'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="acc-<?= $i ?>"><strong><?= View::e($f['title']) ?></strong>
                            <span class="text-muted">: ahora <?= View::e($f['current'] ?: 'n/a') ?>; recomendado <?= View::e($f['recommended']) ?></span></label>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-success mb-2">En este servidor no falla ningún control.</p>
            <?php endif; ?>
            <?php $others = array_values(array_diff($policy['hardening_accepted'], array_column($failedHardening, 'title'))); ?>
            <label class="form-label mt-2 mb-0">Otros controles aceptados (uno por línea; p. ej. los que solo fallan en otro nodo)</label>
            <textarea name="accepted_extra" rows="3" class="form-control form-control-sm" style="font-family:monospace"><?= View::e(implode("\n", $others)) ?></textarea>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-hdd me-2"></i>Discos: umbral propio o sin aviso</div>
        <div class="card-body small">
            <p class="text-muted">Umbral general: <?= View::e((string)$diskDefault) ?> %. Aquí puedes poner otro para un disco concreto de un servidor, o silenciarlo.
                Servidor = nombre corto (p. ej. <code>nitro</code>); <code>*</code> = todos.
                Discos de este servidor: <?php foreach ($localDisks as $m => $pct): ?><code><?= View::e($m) ?></code> <?= $pct ?> % <?php endforeach; ?></p>
            <table class="table table-sm align-middle small mb-2" id="disk-rules">
                <thead><tr><th>Servidor</th><th>Punto de montaje</th><th>Aviso</th><th>Umbral %</th><th></th></tr></thead>
                <tbody>
                <?php $rows = [];
                foreach ($policy['disk_overrides'] as $h => $ms) { foreach ($ms as $m => $t) { $rows[] = [$h, $m, $t]; } }
                $rows[] = ['', '', ''];
                foreach ($rows as [$h, $m, $t]): ?>
                    <tr>
                        <td><input name="disk_host[]" list="host-hints" value="<?= View::e($h) ?>" class="form-control form-control-sm" placeholder="nitro"></td>
                        <td><input name="disk_mount[]" value="<?= View::e($m) ?>" class="form-control form-control-sm" placeholder="/workspace"></td>
                        <td><select name="disk_mode[]" class="form-select form-select-sm">
                                <option value="threshold" <?= ($t === '' || (float)$t > 0) ? 'selected' : '' ?>>con umbral</option>
                                <option value="mute" <?= ($t !== '' && (float)$t == 0) ? 'selected' : '' ?>>sin aviso</option>
                            </select></td>
                        <td><input type="number" name="disk_threshold[]" min="1" max="100" value="<?= $t !== '' && (float)$t > 0 ? View::e((string)$t) : '' ?>" class="form-control form-control-sm" style="width:90px"></td>
                        <td><button type="button" class="btn btn-outline-danger btn-sm py-0" onclick="this.closest('tr').remove()">Quitar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <datalist id="host-hints"><?php foreach ($hostHints as $h): ?><option value="<?= View::e($h) ?>"><?php endforeach; ?></datalist>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="addDiskRow()"><i class="bi bi-plus me-1"></i>Añadir regla</button>
        </div>
    </div>

    <?php if (!$isSlave): ?>
        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar y copiar a los nodos</button>
    <?php endif; ?>
    </fieldset>
</form>

<script>
function addDiskRow() {
    const tb = document.querySelector('#disk-rules tbody');
    const tr = tb.querySelector('tr:last-child').cloneNode(true);
    tr.querySelectorAll('input').forEach(i => i.value = '');
    tr.querySelector('select').value = 'threshold';
    tb.appendChild(tr);
}
</script>
