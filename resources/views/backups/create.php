<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-cloud-arrow-up me-2"></i>Crear Nuevo Backup
            </div>
            <div class="card-body">
                <form id="backupForm" onsubmit="return startBackup(event)">
                    <?= View::csrf() ?>

                    <div class="mb-4">
                        <label class="form-label">Cuenta de Hosting</label>
                        <select name="account_id" id="accountSelect" class="form-select" required>
                            <option value="">-- Seleccionar cuenta --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= View::e($acc['username']) ?> — <?= View::e($acc['domain']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Solo se muestran cuentas activas.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Que incluir en el backup</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="include_files" id="includeFiles" value="1" checked>
                            <label class="form-check-label" for="includeFiles">
                                <i class="bi bi-folder me-1"></i> Archivos (httpdocs/)
                            </label>
                            <br><small class="text-muted ms-4">Se creara un archivo files.tar.gz con todo el contenido de httpdocs/</small>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_databases" id="includeDatabases" value="1" checked>
                            <label class="form-check-label" for="includeDatabases">
                                <i class="bi bi-database me-1"></i> Bases de datos
                            </label>
                            <br><small class="text-muted ms-4">Se hara un dump SQL de todas las bases de datos asociadas a esta cuenta</small>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="/backups" class="btn btn-outline-light">
                            <i class="bi bi-arrow-left me-1"></i> Volver
                        </a>
                        <button type="submit" class="btn btn-primary" id="btnStartBackup">
                            <i class="bi bi-cloud-arrow-up me-1"></i> Crear Backup
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-info-circle me-1" style="color: #38bdf8;"></i> Informacion</h6>
                <ul class="mb-0 small text-muted">
                    <li>Los backups se guardan en <code>/opt/musedock-panel/storage/backups/</code></li>
                    <li>El nombre del backup sera: <code>{usuario}_{fecha_hora}/</code></li>
                    <li>Los dumps de MySQL usan el metodo de autenticacion configurado en <code>.env</code></li>
                    <li>Los dumps de PostgreSQL se ejecutan con <code>pg_dump</code> como usuario postgres</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Backup Progress Modal -->
<div class="modal fade" id="backupProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-cloud-arrow-up me-2" id="backupModalIcon"></i>
                    <span id="backupModalTitle">Creando Backup</span>
                </h5>
            </div>
            <div class="modal-body">
                <!-- Account info -->
                <div class="mb-3">
                    <small class="text-muted">Cuenta:</small>
                    <span id="backupAccount" class="ms-1"></span>
                </div>

                <!-- Progress bar -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small id="backupTask" class="text-muted">Iniciando...</small>
                        <small id="backupPercent" class="text-info">0%</small>
                    </div>
                    <div class="progress" style="height: 20px; background: #1e293b;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-info"
                             id="backupProgressBar" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>

                <!-- Steps info -->
                <div class="mb-3">
                    <small class="text-muted">Paso <span id="backupStep">0</span> de <span id="backupTotalSteps">0</span></small>
                </div>

                <!-- Time elapsed -->
                <div class="mb-0">
                    <small class="text-muted"><i class="bi bi-clock me-1"></i>Tiempo: <span id="backupElapsed">0s</span></small>
                </div>

                <!-- Result (hidden until done) -->
                <div id="backupResult" class="mt-3" style="display:none"></div>
            </div>
            <div class="modal-footer border-secondary" id="backupModalFooter" style="display:none">
                <a href="/backups" class="btn btn-primary btn-sm">
                    <i class="bi bi-list me-1"></i> Ver Backups
                </a>
                <button type="button" class="btn btn-outline-light btn-sm" onclick="closeBackupModal()">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .form-check-input { background-color: #0f172a; border-color: #334155; }
    .form-check-input:checked { background-color: #0ea5e9; border-color: #0ea5e9; }
    .form-check-input:focus { box-shadow: 0 0 0 2px rgba(56,189,248,0.2); border-color: #38bdf8; }
</style>

<script>
(function() {
    let pollTimer = null;
    let elapsedTimer = null;
    let startedAt = null;
    let bsModal = null;
    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '';

    function getModal() {
        if (!bsModal) {
            bsModal = new bootstrap.Modal(document.getElementById('backupProgressModal'));
        }
        return bsModal;
    }

    // Format elapsed time
    function formatElapsed(seconds) {
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'm ' + s + 's';
    }

    // Start elapsed timer
    function startElapsedTimer() {
        startedAt = Date.now();
        elapsedTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startedAt) / 1000);
            document.getElementById('backupElapsed').textContent = formatElapsed(elapsed);
        }, 1000);
    }

    function stopElapsedTimer() {
        if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    }

    // Update UI from status data
    function updateProgressUI(data) {
        const step = data.step || 0;
        const total = data.total_steps || 1;
        const percent = Math.round((step / total) * 100);
        const bar = document.getElementById('backupProgressBar');
        const task = data.current_task || '';

        document.getElementById('backupTask').textContent = task;
        document.getElementById('backupPercent').textContent = percent + '%';
        document.getElementById('backupStep').textContent = step;
        document.getElementById('backupTotalSteps').textContent = total;
        bar.style.width = percent + '%';

        const account = (data.username || '') + (data.domain ? ' — ' + data.domain : '');
        document.getElementById('backupAccount').textContent = account;

        if (data.status === 'completed') {
            bar.classList.remove('progress-bar-animated', 'bg-info');
            bar.classList.add('bg-success');
            bar.style.width = '100%';
            document.getElementById('backupPercent').textContent = '100%';
            document.getElementById('backupModalTitle').textContent = 'Backup Completado';
            document.getElementById('backupModalIcon').className = 'bi bi-check-circle-fill text-success me-2';

            let resultHtml = `<div class="alert alert-success mb-0 py-2">
                <i class="bi bi-check-circle me-1"></i> Backup creado exitosamente`;
            if (data.file_size_human) {
                resultHtml += ` — <b>${data.file_size_human}</b>`;
            }
            if (data.db_count > 0) {
                resultHtml += ` (${data.db_count} BD${data.db_count > 1 ? 's' : ''})`;
            }
            resultHtml += `</div>`;

            if (data.errors && data.errors.length > 0) {
                resultHtml += `<div class="alert alert-warning mt-2 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i> Advertencias:<br>
                    ${data.errors.map(e => '• ' + e).join('<br>')}
                </div>`;
            }

            document.getElementById('backupResult').innerHTML = resultHtml;
            document.getElementById('backupResult').style.display = '';
            document.getElementById('backupModalFooter').style.display = '';
            document.getElementById('btnStartBackup').disabled = false;

            stopPolling();
            stopElapsedTimer();

        } else if (data.status === 'error') {
            bar.classList.remove('progress-bar-animated', 'bg-info');
            bar.classList.add('bg-danger');
            document.getElementById('backupModalTitle').textContent = 'Error en Backup';
            document.getElementById('backupModalIcon').className = 'bi bi-x-circle-fill text-danger me-2';

            document.getElementById('backupResult').innerHTML = `
                <div class="alert alert-danger mb-0 py-2">
                    <i class="bi bi-x-circle me-1"></i> ${task}
                </div>`;
            document.getElementById('backupResult').style.display = '';
            document.getElementById('backupModalFooter').style.display = '';
            document.getElementById('btnStartBackup').disabled = false;

            stopPolling();
            stopElapsedTimer();
        }
    }

    // Poll backup status
    async function pollStatus() {
        try {
            const resp = await fetch('/backups/status');
            const data = await resp.json();

            if (!data.ok || data.status === 'idle') {
                stopPolling();
                stopElapsedTimer();
                return;
            }

            updateProgressUI(data);
        } catch (e) {
            console.error('Error polling backup status:', e);
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollStatus, 1000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // Start backup
    window.startBackup = async function(e) {
        e.preventDefault();

        const accountId = document.getElementById('accountSelect').value;
        if (!accountId) {
            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
                icon: 'warning',
                title: 'Selecciona una cuenta',
                text: 'Debes seleccionar una cuenta de hosting.',
            });
            return false;
        }

        const includeFiles = document.getElementById('includeFiles').checked;
        const includeDatabases = document.getElementById('includeDatabases').checked;
        if (!includeFiles && !includeDatabases) {
            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({
                icon: 'warning',
                title: 'Selecciona contenido',
                text: 'Debes seleccionar al menos archivos o bases de datos.',
            });
            return false;
        }

        // Disable button
        document.getElementById('btnStartBackup').disabled = true;

        // Reset modal UI
        const bar = document.getElementById('backupProgressBar');
        bar.style.width = '0%';
        bar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
        document.getElementById('backupTask').textContent = 'Iniciando...';
        document.getElementById('backupPercent').textContent = '0%';
        document.getElementById('backupStep').textContent = '0';
        document.getElementById('backupTotalSteps').textContent = '0';
        document.getElementById('backupResult').style.display = 'none';
        document.getElementById('backupModalFooter').style.display = 'none';
        document.getElementById('backupModalTitle').textContent = 'Creando Backup';
        document.getElementById('backupModalIcon').className = 'bi bi-cloud-arrow-up me-2';
        document.getElementById('backupElapsed').textContent = '0s';

        // Set account name
        const sel = document.getElementById('accountSelect');
        document.getElementById('backupAccount').textContent = sel.options[sel.selectedIndex]?.text || '';

        // Show modal
        getModal().show();
        startElapsedTimer();

        // Launch backup via AJAX
        try {
            const form = new FormData();
            form.append('account_id', accountId);
            form.append('include_files', includeFiles ? '1' : '0');
            form.append('include_databases', includeDatabases ? '1' : '0');
            form.append('_csrf_token', csrfToken);

            const resp = await fetch('/backups/store', { method: 'POST', body: form });
            const data = await resp.json();

            if (!data.ok) {
                document.getElementById('backupTask').textContent = data.error || 'Error al iniciar backup';
                bar.classList.remove('progress-bar-animated', 'bg-info');
                bar.classList.add('bg-danger');
                document.getElementById('backupModalTitle').textContent = 'Error';
                document.getElementById('backupModalIcon').className = 'bi bi-x-circle-fill text-danger me-2';
                document.getElementById('backupResult').innerHTML = `
                    <div class="alert alert-danger mb-0 py-2">
                        <i class="bi bi-x-circle me-1"></i> ${data.error}
                    </div>`;
                document.getElementById('backupResult').style.display = '';
                document.getElementById('backupModalFooter').style.display = '';
                document.getElementById('btnStartBackup').disabled = false;
                stopElapsedTimer();
                return false;
            }

            // Start polling
            startPolling();

        } catch (e) {
            document.getElementById('backupTask').textContent = 'Error de conexion';
            bar.classList.remove('progress-bar-animated', 'bg-info');
            bar.classList.add('bg-danger');
            document.getElementById('btnStartBackup').disabled = false;
            stopElapsedTimer();
        }

        return false;
    };

    // Close modal and clear status
    window.closeBackupModal = async function() {
        stopPolling();
        stopElapsedTimer();
        getModal().hide();
        try {
            await fetch('/backups/status/clear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf_token=' + encodeURIComponent(csrfToken),
            });
        } catch (e) {}
        document.getElementById('btnStartBackup').disabled = false;
    };

    // ── On page load: check if there's an active backup ─────────
    async function checkExistingBackup() {
        try {
            const resp = await fetch('/backups/status');
            const data = await resp.json();

            if (data.ok && data.status && data.status !== 'idle') {
                // There's an active or finished backup — show the modal
                document.getElementById('btnStartBackup').disabled = (data.status === 'running');

                // Set account info
                const account = (data.username || '') + (data.domain ? ' — ' + data.domain : '');
                document.getElementById('backupAccount').textContent = account;

                // Calculate elapsed from started_at
                if (data.started_at && data.status === 'running') {
                    const started = new Date(data.started_at.replace(' ', 'T'));
                    startedAt = started.getTime();
                    elapsedTimer = setInterval(() => {
                        const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                        document.getElementById('backupElapsed').textContent = formatElapsed(elapsed);
                    }, 1000);
                }

                // Show modal
                getModal().show();
                updateProgressUI(data);

                if (data.status === 'running') {
                    startPolling();
                }
            }
        } catch (e) {
            // No backup in progress, do nothing
        }
    }

    // Check on page load
    checkExistingBackup();
})();
</script>
