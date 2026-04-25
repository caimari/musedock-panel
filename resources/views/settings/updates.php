<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!empty($updateError)): ?>
<div class="alert py-2 px-3 small" style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);color:#fbbf24;">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    La pagina de updates se ha recuperado de un error temporal: <code><?= View::e($updateError) ?></code>
</div>
<?php endif; ?>

<?php if (!empty($_GET['updated'])): ?>
<div class="alert py-2 px-3 small d-flex align-items-center" style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;">
    <i class="bi bi-check-circle-fill me-2"></i>
    <span>Panel actualizado correctamente a <strong>v<?= View::e(PANEL_VERSION) ?></strong></span>
</div>
<?php endif; ?>

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
            <form method="POST" action="/settings/updates/check" id="updateCheckForm">
                <?= View::csrf() ?>
                <button type="submit" class="btn btn-outline-light" id="updateCheckBtn">
                    <i class="bi bi-arrow-clockwise me-1"></i>Comprobar ahora
                </button>
            </form>

            <!-- Update button (only if update available) -->
            <?php if ($updateInfo['has_update']): ?>
                <form method="POST" action="/settings/updates/run" id="updateForm" onsubmit="return confirmUpdate(event)">
                    <?= View::csrf() ?>
                    <button type="submit" class="btn btn-success" id="updateRunBtn">
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
const UPDATE_PAGE_START_VERSION = '<?= View::e(PANEL_VERSION) ?>';

function updateCacheBustUrl(url) {
    return url + (url.includes('?') ? '&' : '?') + '_=' + Date.now();
}

function forceReloadUpdatesPage() {
    window.location.replace('/settings/updates?updated=1&_=' + Date.now());
}

function updateStatusFetch() {
    return fetch(updateCacheBustUrl('/settings/updates/api/status'), {
        cache: 'no-store',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    });
}

function panelReadyFetch() {
    return fetch(updateCacheBustUrl('/settings/updates'), {
        cache: 'no-store',
        redirect: 'follow',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
}

function setButtonLoading(button, label, loadingClass) {
    if (!button) return;
    button.dataset.originalHtml = button.dataset.originalHtml || button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
    if (loadingClass) button.classList.add(loadingClass);
}

function resetButtonLoading(button) {
    if (!button || !button.dataset.originalHtml) return;
    button.disabled = false;
    button.innerHTML = button.dataset.originalHtml;
}

function showUpdateProgress(message) {
    var container = document.querySelector('.card-body .d-flex.gap-3');
    if (container) {
        container.closest('.card').innerHTML =
            '<div class="card-header" style="background:rgba(251,191,36,0.1);border-bottom-color:#fbbf24;">' +
            '<i class="bi bi-hourglass-split me-2 text-warning"></i>' +
            '<span class="text-warning">Actualizacion en progreso...</span></div>' +
            '<div class="card-body">' +
            '<div class="progress mb-3"><div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" style="width:100%"></div></div>' +
            '<pre class="bg-dark text-light p-3 rounded mb-0" style="max-height:300px;overflow-y:auto;font-size:0.85rem" id="updateOutput">' + escapeHtml(message || 'Iniciando actualizacion...') + '</pre></div>';
    }
}

function showUpdateStartError(message) {
    var out = document.getElementById('updateOutput');
    if (out) {
        out.textContent = 'No se pudo iniciar la actualizacion.\n\n' + (message || 'Error desconocido');
        out.scrollTop = out.scrollHeight;
    }
    SwalDark.fire({
        icon: 'error',
        title: 'No se pudo iniciar la actualizacion',
        html: '<div class="text-start"><p>El backend devolvio un error antes de lanzar el updater.</p><pre class="bg-dark text-light p-3 rounded small mb-0" style="white-space:pre-wrap;max-height:220px;overflow:auto;">' + escapeHtml(message || 'Error desconocido') + '</pre></div>',
        confirmButtonText: 'Entendido'
    });
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}

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
            var form = document.getElementById('updateForm');
            var btn = document.getElementById('updateRunBtn');
            var fd = new FormData(form);
            setButtonLoading(btn, 'Iniciando update...');
            showUpdateProgress('Solicitando arranque del updater...');

            fetch('/settings/updates/run', {
                method: 'POST',
                body: fd,
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(response) {
                    return response.text().then(function(text) {
                        var data = null;
                        try { data = text ? JSON.parse(text) : null; } catch (e) {}
                        if (!response.ok) {
                            throw new Error((data && data.message) ? data.message : ('HTTP ' + response.status + (text ? '\n' + text : '')));
                        }
                        if (data && data.ok === false) {
                            throw new Error(data.message || 'El updater no pudo arrancar.');
                        }
                        startUpdatePolling();
                    });
                })
                .catch(function(error) {
                    // If the panel restarts exactly while the request is open, fetch can fail.
                    // In that case keep polling. If the backend returned a JSON error, show it.
                    var message = error && error.message ? error.message : '';
                    if (message && !message.match(/Failed to fetch|NetworkError|Load failed/i)) {
                        resetButtonLoading(btn);
                        showUpdateStartError(message);
                        return;
                    }
                    startUpdatePolling('Respuesta interrumpida; comprobando estado del panel...');
                });
        }
    });
    return false;
}

function startUpdatePolling(initialMessage) {
    showUpdateProgress(initialMessage || 'Updater arrancado. Esperando salida...');

    var polls = 0, catchCount = 0, sawRestart = false;
    var maxPolls = 180;
    var out = document.getElementById('updateOutput');

    function tryReload() {
        panelReadyFetch()
            .then(forceReloadUpdatesPage)
            .catch(function() { setTimeout(tryReload, 2000); });
    }

    var timer = setInterval(function() {
        polls++;
        if (polls > maxPolls) {
            clearInterval(timer);
            tryReload();
            return;
        }

        updateStatusFetch()
            .then(function(data) {
                catchCount = 0;
                if (data.output && out) {
                    out.textContent = data.output;
                    out.scrollTop = out.scrollHeight;
                }
                if (data.current && data.current !== UPDATE_PAGE_START_VERSION && (!data.in_progress || data.completed)) {
                    clearInterval(timer);
                    forceReloadUpdatesPage();
                    return;
                }
                if (!data.in_progress || data.completed || (data.output && data.output.includes('Update complete'))) {
                    clearInterval(timer);
                    setTimeout(tryReload, 1500);
                }
            })
            .catch(function() {
                catchCount++;
                if (!sawRestart && catchCount >= 2) {
                    sawRestart = true;
                    if (out) {
                        out.textContent += '\n\n  Reiniciando panel... espere...';
                        out.scrollTop = out.scrollHeight;
                    }
                }
                if (catchCount >= 8) {
                    clearInterval(timer);
                    tryReload();
                }
            });
    }, 2000);
}

document.addEventListener('DOMContentLoaded', function() {
    var checkForm = document.getElementById('updateCheckForm');
    var checkBtn = document.getElementById('updateCheckBtn');
    if (checkForm && checkBtn) {
        checkForm.addEventListener('submit', function() {
            setButtonLoading(checkBtn, 'Comprobando...');
        });
    }
});

<?php if ($updateStatus['in_progress']): ?>
// Poll for update progress
(function() {
    let polls = 0;
    let catchCount = 0;
    let sawComplete = false;
    let sawRestart = false;
    const maxPolls = 180; // 180 * 2s = 6 minutes max
    const out = document.getElementById('updateOutput');

    function tryReload() {
        panelReadyFetch()
            .then(forceReloadUpdatesPage)
            .catch(() => setTimeout(tryReload, 2000));
    }

    const timer = setInterval(function() {
        polls++;
        if (polls > maxPolls) {
            clearInterval(timer);
            tryReload();
            return;
        }

        updateStatusFetch()
            .then(data => {
                catchCount = 0;
                if (data.output) {
                    out.textContent = data.output;
                    out.scrollTop = out.scrollHeight;

                    // Detect completion or restart in output
                    if (data.output.includes('Update complete') || data.output.includes('Restarting panel')) {
                        sawComplete = true;
                    }
                }
                if (data.current && data.current !== UPDATE_PAGE_START_VERSION && (!data.in_progress || data.completed)) {
                    clearInterval(timer);
                    forceReloadUpdatesPage();
                    return;
                }
                if (!data.in_progress || data.completed) {
                    clearInterval(timer);
                    // Small delay then reload
                    setTimeout(tryReload, 1500);
                }
            })
            .catch(() => {
                catchCount++;
                if (!sawRestart && catchCount >= 2) {
                    sawRestart = true;
                    out.textContent += '\n\n  Reiniciando panel... espere...';
                    out.scrollTop = out.scrollHeight;
                }
                if (catchCount >= 8) {
                    // Panel has been down for ~16s — start trying to reload
                    clearInterval(timer);
                    tryReload();
                }
            });
    }, 2000);
})();
<?php endif; ?>
</script>
