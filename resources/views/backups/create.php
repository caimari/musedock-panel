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
                        <label class="form-label">Alcance de archivos</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="scope" id="scopeFull" value="full" checked>
                            <label class="form-check-label" for="scopeFull">
                                <i class="bi bi-house-door me-1"></i> Directorio completo del dominio
                            </label>
                            <br><small class="text-muted ms-4">Incluye httpdocs/, configs, certificados SSL y todo el contenido del home del usuario</small>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="scope" id="scopeHttpdocs" value="httpdocs">
                            <label class="form-check-label" for="scopeHttpdocs">
                                <i class="bi bi-folder me-1"></i> Solo httpdocs/
                            </label>
                            <br><small class="text-muted ms-4">Solo el directorio web publico</small>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Que incluir en el backup</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="include_files" id="includeFiles" value="1" checked>
                            <label class="form-check-label" for="includeFiles">
                                <i class="bi bi-folder me-1"></i> Archivos
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_databases" id="includeDatabases" value="1" checked>
                            <label class="form-check-label" for="includeDatabases">
                                <i class="bi bi-database me-1"></i> Bases de datos
                            </label>
                            <br><small class="text-muted ms-4">Dump SQL de todas las bases de datos asociadas a esta cuenta</small>
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

        <!-- ═══════════════════════════════════════════════════════ -->
        <!-- Auto-Backup Settings                                    -->
        <!-- ═══════════════════════════════════════════════════════ -->
        <div class="card mt-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-clock-history me-2" style="color:#a78bfa;"></i>Backups Automaticos</span>
                <?php if (!empty($autoBackupEnabled)): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Activo</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Desactivado</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" action="/backups/auto-backup-settings">
                    <?= View::csrf() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Estado</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="auto_backup_enabled" id="autoBackupEnabled" value="1"
                                    <?= !empty($autoBackupEnabled) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="autoBackupEnabled">Activar</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Frecuencia</label>
                            <select name="auto_backup_frequency" class="form-select form-select-sm">
                                <option value="daily" <?= ($autoBackupFrequency ?? 'daily') === 'daily' ? 'selected' : '' ?>>Diario</option>
                                <option value="weekly" <?= ($autoBackupFrequency ?? '') === 'weekly' ? 'selected' : '' ?>>Semanal</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Hora</label>
                            <input type="time" name="auto_backup_time" class="form-control form-control-sm"
                                   value="<?= View::e($autoBackupTime ?? '03:00') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Alcance</label>
                            <select name="auto_backup_scope" class="form-select form-select-sm">
                                <option value="full" <?= ($autoBackupScope ?? 'full') === 'full' ? 'selected' : '' ?>>Directorio completo</option>
                                <option value="httpdocs" <?= ($autoBackupScope ?? '') === 'httpdocs' ? 'selected' : '' ?>>Solo httpdocs/</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Retener diarios</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="auto_backup_retain_daily" class="form-control form-control-sm"
                                       value="<?= (int)($autoBackupRetainDaily ?? 7) ?>" min="1" max="90">
                                <span class="input-group-text">dias</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Retener semanales</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="auto_backup_retain_weekly" class="form-control form-control-sm"
                                       value="<?= (int)($autoBackupRetainWeekly ?? 4) ?>" min="0" max="52">
                                <span class="input-group-text">semanas</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Retencion total</label>
                            <div class="mt-1">
                                <small class="text-muted">
                                    Hasta <strong class="text-light"><?= (int)($autoBackupRetainDaily ?? 7) + (int)($autoBackupRetainWeekly ?? 4) ?></strong> backups por cuenta
                                </small>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($nodes)): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Copia remota</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="auto_backup_remote_enabled" id="autoBackupRemote" value="1"
                                    <?= !empty($autoBackupRemoteEnabled) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="autoBackupRemote">Enviar a nodo</label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nodo destino</label>
                            <select name="auto_backup_remote_node_id" class="form-select form-select-sm">
                                <option value="0">-- Seleccionar nodo --</option>
                                <?php foreach ($nodes as $node): ?>
                                <option value="<?= (int) $node['id'] ?>" <?= ((int)($autoBackupRemoteNodeId ?? 0)) === (int) $node['id'] ? 'selected' : '' ?>>
                                    <?= View::e($node['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Tras cada auto-backup, se transferira al nodo remoto seleccionado</small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Exclusiones <small class="text-muted">(una por linea, aplica a manuales y automaticos)</small></label>
                        <textarea name="backup_exclusions" class="form-control form-control-sm" rows="3"
                                  placeholder="node_modules&#10;.git&#10;*.log" style="font-family:monospace;font-size:0.85em;"><?= View::e($backupExclusions ?? '') ?></textarea>
                        <small class="text-muted">Por defecto ya se excluyen: <code>node_modules</code>, <code>.git</code>, <code>.svn</code>, <code>__pycache__</code>, <code>.cache</code>, <code>.npm</code>, <code>*.log</code>, <code>tmp/</code>, <code>temp/</code></small>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i> Guardar Configuracion
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
                    <li><strong>Directorio completo</strong> incluye httpdocs/, certificados SSL, configs y todo el home del usuario</li>
                    <li>Los backups automaticos se ejecutan via cron y aplican la politica de retencion tras cada ejecucion</li>
                    <li>La retencion guarda los N mas recientes como diarios, y 1 por semana para las semanas anteriores</li>
                    <li>Los backups automaticos se nombran con sufijo <code>_auto</code> para distinguirlos de los manuales</li>
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
                <div class="mb-3">
                    <small class="text-muted">Cuenta:</small>
                    <span id="backupAccount" class="ms-1"></span>
                </div>
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
                <div class="mb-3">
                    <small class="text-muted">Paso <span id="backupStep">0</span> de <span id="backupTotalSteps">0</span></small>
                </div>
                <div class="mb-0">
                    <small class="text-muted"><i class="bi bi-clock me-1"></i>Tiempo: <span id="backupElapsed">0s</span></small>
                </div>
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

    function formatElapsed(seconds) {
        if (seconds < 60) return seconds + 's';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'm ' + s + 's';
    }

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

            let resultHtml = `<div class="mb-0 py-2 px-3 rounded" style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;">
                <i class="bi bi-check-circle me-1"></i> Backup creado exitosamente`;
            if (data.file_size_human) resultHtml += ` — <b>${data.file_size_human}</b>`;
            if (data.db_count > 0) resultHtml += ` (${data.db_count} BD${data.db_count > 1 ? 's' : ''})`;
            resultHtml += `</div>`;

            if (data.errors && data.errors.length > 0) {
                resultHtml += `<div class="mt-2 mb-0 py-2 px-3 rounded small" style="background:rgba(251,191,36,0.15);border:1px solid rgba(251,191,36,0.3);color:#fbbf24;">
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
                <div class="mb-0 py-2 px-3 rounded" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;">
                    <i class="bi bi-x-circle me-1"></i> ${task}
                </div>`;
            document.getElementById('backupResult').style.display = '';
            document.getElementById('backupModalFooter').style.display = '';
            document.getElementById('btnStartBackup').disabled = false;
            stopPolling();
            stopElapsedTimer();
        }
    }

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
            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({ icon: 'warning', title: 'Selecciona una cuenta' });
            return false;
        }

        const includeFiles = document.getElementById('includeFiles').checked;
        const includeDatabases = document.getElementById('includeDatabases').checked;
        if (!includeFiles && !includeDatabases) {
            (typeof SwalDark !== 'undefined' ? SwalDark : Swal).fire({ icon: 'warning', title: 'Selecciona contenido' });
            return false;
        }

        document.getElementById('btnStartBackup').disabled = true;

        // Reset modal
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

        const sel = document.getElementById('accountSelect');
        document.getElementById('backupAccount').textContent = sel.options[sel.selectedIndex]?.text || '';

        getModal().show();
        startElapsedTimer();

        try {
            const form = new FormData();
            form.append('account_id', accountId);
            form.append('include_files', includeFiles ? '1' : '0');
            form.append('include_databases', includeDatabases ? '1' : '0');
            form.append('scope', document.querySelector('input[name="scope"]:checked')?.value || 'full');
            form.append('_csrf_token', csrfToken);

            const resp = await fetch('/backups/store', { method: 'POST', body: form });
            const data = await resp.json();

            if (!data.ok) {
                document.getElementById('backupTask').textContent = data.error || 'Error';
                bar.classList.remove('progress-bar-animated', 'bg-info');
                bar.classList.add('bg-danger');
                document.getElementById('backupModalTitle').textContent = 'Error';
                document.getElementById('backupModalIcon').className = 'bi bi-x-circle-fill text-danger me-2';
                document.getElementById('backupResult').innerHTML = `<div class="mb-0 py-2 px-3 rounded" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;"><i class="bi bi-x-circle me-1"></i> ${data.error}</div>`;
                document.getElementById('backupResult').style.display = '';
                document.getElementById('backupModalFooter').style.display = '';
                document.getElementById('btnStartBackup').disabled = false;
                stopElapsedTimer();
                return false;
            }

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
                document.getElementById('btnStartBackup').disabled = (data.status === 'running');

                const account = (data.username || '') + (data.domain ? ' — ' + data.domain : '');
                document.getElementById('backupAccount').textContent = account;

                if (data.started_at && data.status === 'running') {
                    const started = new Date(data.started_at.replace(' ', 'T'));
                    startedAt = started.getTime();
                    elapsedTimer = setInterval(() => {
                        const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                        document.getElementById('backupElapsed').textContent = formatElapsed(elapsed);
                    }, 1000);
                }

                getModal().show();
                updateProgressUI(data);

                if (data.status === 'running') {
                    startPolling();
                }
            }
        } catch (e) {}
    }

    checkExistingBackup();
})();
</script>
