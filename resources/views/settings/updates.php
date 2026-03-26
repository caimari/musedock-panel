<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<div class="row g-3 mb-4">
    <!-- Current Version -->
    <div class="col-md-4">
        <div class="stat-card text-center">
            <div class="stat-value" style="font-size:2rem"><?= View::e(PANEL_VERSION) ?></div>
            <div class="stat-label">Version Actual</div>
        </div>
    </div>

    <!-- Remote Version -->
    <div class="col-md-4">
        <div class="stat-card text-center">
            <?php if ($updateInfo['has_update']): ?>
                <div class="stat-value text-success" style="font-size:2rem"><?= View::e($updateInfo['remote']) ?></div>
                <div class="stat-label">Nueva Version Disponible</div>
            <?php else: ?>
                <div class="stat-value text-muted" style="font-size:2rem"><?= View::e($updateInfo['remote'] ?: '-') ?></div>
                <div class="stat-label">Version Remota</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status -->
    <div class="col-md-4">
        <div class="stat-card text-center">
            <?php if ($updateInfo['has_update']): ?>
                <div class="stat-value"><span class="badge bg-success" style="font-size:1.2rem"><i class="bi bi-cloud-arrow-down me-1"></i>Disponible</span></div>
            <?php else: ?>
                <div class="stat-value"><span class="badge bg-secondary" style="font-size:1.2rem"><i class="bi bi-check-circle me-1"></i>Al dia</span></div>
            <?php endif; ?>
            <div class="stat-label mt-2">
                <?php if ($updateInfo['checked_at']): ?>
                    Ultimo check: <?= View::e($updateInfo['checked_at']) ?>
                <?php else: ?>
                    Sin verificar
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Actions -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-gear me-2"></i>Acciones</span>
    </div>
    <div class="card-body">
        <div class="d-flex gap-3 flex-wrap">
            <!-- Check for updates -->
            <form method="POST" action="/settings/updates/check">
                <?= View::csrf() ?>
                <button type="submit" class="btn btn-outline-light">
                    <i class="bi bi-arrow-clockwise me-1"></i>Comprobar ahora
                </button>
            </form>

            <!-- Update button (only if update available) -->
            <?php if ($updateInfo['has_update']): ?>
                <form method="POST" action="/settings/updates/run" id="updateForm" onsubmit="return confirmUpdate(event)">
                    <?= View::csrf() ?>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-cloud-arrow-down me-1"></i>Actualizar a v<?= View::e($updateInfo['remote']) ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($updateInfo['has_update']): ?>
            <div class="mt-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    La actualizacion descargara el codigo nuevo, ejecutara migraciones de base de datos, instalara crons necesarios y reiniciara el servicio del panel.
                    La pagina se recargara automaticamente.
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Update in progress -->
<?php if ($updateStatus['in_progress']): ?>
<div class="card mb-4 border-warning">
    <div class="card-header" style="background:rgba(251,191,36,0.1);border-bottom-color:#fbbf24;">
        <i class="bi bi-hourglass-split me-2 text-warning"></i>
        <span class="text-warning">Actualizacion en progreso...</span>
    </div>
    <div class="card-body">
        <div class="progress mb-3">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" style="width:100%"></div>
        </div>
        <pre class="bg-dark text-light p-3 rounded mb-0" style="max-height:300px;overflow-y:auto;font-size:0.85rem" id="updateOutput"><?= View::e($updateStatus['output']) ?></pre>
    </div>
</div>
<?php endif; ?>

<!-- Last update output (if available and not in progress) -->
<?php if (!$updateStatus['in_progress'] && !empty($updateStatus['output'])): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-terminal me-2"></i>Ultima actualizacion
        <?php if ($updateStatus['started_at']): ?>
            <small class="text-muted ms-2"><?= View::e($updateStatus['started_at']) ?></small>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <pre class="bg-dark text-light p-3 mb-0" style="max-height:400px;overflow-y:auto;font-size:0.85rem"><?= View::e($updateStatus['output']) ?></pre>
    </div>
</div>
<?php endif; ?>

<!-- Remote changelog (new versions) -->
<?php if (!empty($changelog)): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-journal-text me-2"></i>Cambios en la nueva version
    </div>
    <div class="card-body">
        <ul class="mb-0">
            <?php foreach ($changelog as $entry): ?>
                <li>
                    <strong>v<?= View::e($entry['version']) ?></strong>
                    <small class="text-muted ms-2"><?= View::e($entry['date']) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="mt-2">
            <a href="/changelog" class="text-info">Ver changelog completo</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Info card -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Informacion</div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" style="width:30%">Repositorio</td><td><code>caimari/musedock-panel</code></td></tr>
            <tr><td class="text-muted">Rama</td><td><code>main</code></td></tr>
            <tr><td class="text-muted">Directorio</td><td><code>/opt/musedock-panel</code></td></tr>
            <tr><td class="text-muted">Intervalo de check</td><td>Cada 6 horas (automatico al visitar esta pagina)</td></tr>
            <tr><td class="text-muted">Proceso de update</td><td>git pull → migraciones → crons → reinicio servicio</td></tr>
        </table>
    </div>
</div>

<script>
function confirmUpdate(e) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Actualizar el panel?',
        html: '<p>Se descargara la version <strong>v<?= View::e($updateInfo['remote'] ?? '') ?></strong> desde GitHub.</p>' +
              '<p>El proceso:</p>' +
              '<ol class="text-start"><li>Descargar codigo nuevo (git pull)</li><li>Ejecutar migraciones de BD</li><li>Instalar crons necesarios</li><li>Reiniciar servicio del panel</li></ol>' +
              '<p class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>La pagina se recargara automaticamente tras el reinicio.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-cloud-arrow-down me-1"></i> Actualizar',
        confirmButtonColor: '#22c55e',
        cancelButtonText: 'Cancelar'
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('updateForm').submit();
        }
    });
    return false;
}

<?php if ($updateStatus['in_progress']): ?>
// Poll for update progress
(function() {
    let polls = 0;
    let catchCount = 0;
    const maxPolls = 60; // 60 * 3s = 3 minutes max
    const timer = setInterval(function() {
        polls++;
        if (polls > maxPolls) { clearInterval(timer); return; }

        fetch('/settings/updates/api/status')
            .then(r => r.json())
            .then(data => {
                catchCount = 0; // Reset on success
                if (data.output) {
                    document.getElementById('updateOutput').textContent = data.output;
                    document.getElementById('updateOutput').scrollTop = 999999;
                }
                if (!data.in_progress) {
                    clearInterval(timer);
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(() => {
                // Panel probably restarting — keep polling until it comes back
                catchCount++;
                if (catchCount >= 10) {
                    // 10 consecutive failures (30s) — force reload, panel should be up
                    clearInterval(timer);
                    location.reload();
                }
                // Otherwise keep polling — panel is still restarting
            });
    }, 3000);
})();
<?php endif; ?>
</script>
