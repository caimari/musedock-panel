<?php use MuseDockPanel\View; ?>
<?php
$hasNodes = !empty($nodes);
$hasFederationPeers = !empty($federationPeers ?? []);
$hasRemoteTargets = $hasNodes || $hasFederationPeers;
?>

<!-- Backup Progress Modal (shown when backup in progress on any page) -->
<div class="modal fade" id="backupProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-cloud-arrow-up me-2" id="backupModalIcon"></i>
                    <span id="backupModalTitle">Backup en curso</span>
                </h5>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <small class="text-muted">Cuenta:</small>
                    <span id="backupAccount" class="ms-1"></span>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small id="backupTask" class="text-muted">Cargando...</small>
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
                <button type="button" class="btn btn-outline-light btn-sm" onclick="closeBackupModal()">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted"><?= count($backups) ?> backup(s) en total</span>
    </div>
    <a href="/backups/create" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Nuevo Backup
    </a>
</div>

<!-- Local Backups -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-cloud-arrow-down me-2"></i>Backups Locales
    </div>
    <div class="card-body p-0">
        <?php if (empty($backups)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-cloud-arrow-down" style="font-size: 2rem;"></i>
                <p class="mt-2">No hay backups. Crea uno para comenzar.</p>
                <a href="/backups/create" class="btn btn-outline-light btn-sm"><i class="bi bi-plus-lg me-1"></i> Crear Backup</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Cuenta</th>
                        <th>Fecha</th>
                        <th>Tamano</th>
                        <th>Contenido</th>
                        <th class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                    <?php $isAuto = str_ends_with($backup['dir_name'] ?? '', '_auto'); ?>
                    <tr>
                        <td class="ps-3">
                            <i class="bi bi-server me-1" style="color: #38bdf8;"></i>
                            <strong><?= View::e($backup['username'] ?? '') ?></strong>
                            <?php if ($isAuto): ?>
                                <span class="badge ms-1" style="background:rgba(167,139,250,0.15);color:#a78bfa;font-size:0.7em;">auto</span>
                            <?php endif; ?>
                            <br>
                            <small class="text-muted"><?= View::e($backup['domain'] ?? '') ?></small>
                        </td>
                        <td>
                            <small><?= date('d/m/Y H:i', strtotime($backup['date'] ?? 'now')) ?></small>
                        </td>
                        <td>
                            <?php
                                $size = $backup['file_size'] ?? 0;
                                if ($size >= 1073741824) echo round($size / 1073741824, 2) . ' GB';
                                elseif ($size >= 1048576) echo round($size / 1048576, 2) . ' MB';
                                elseif ($size >= 1024) echo round($size / 1024, 2) . ' KB';
                                else echo $size . ' B';
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($backup['has_files'])): ?>
                                <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e;">
                                    <i class="bi bi-folder me-1"></i><?= ($backup['scope'] ?? 'httpdocs') === 'full' ? 'Completo' : 'httpdocs' ?>
                                </span>
                            <?php endif; ?>
                            <?php if (($backup['db_count'] ?? 0) > 0): ?>
                                <span class="badge" style="background: rgba(56,189,248,0.15); color: #38bdf8;">
                                    <i class="bi bi-database me-1"></i><?= $backup['db_count'] ?> BD
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <?php $dirName = View::e($backup['dir_name'] ?? ''); ?>

                            <?php if (!empty($backup['has_files'])): ?>
                            <a href="/backups/download?backup=<?= urlencode($backup['dir_name']) ?>&path=files.tar.gz"
                               class="btn btn-outline-light btn-sm" title="Descargar archivos">
                                <i class="bi bi-download"></i>
                            </a>
                            <?php endif; ?>

                            <?php if ($hasRemoteTargets): ?>
                            <button type="button" class="btn btn-outline-info btn-sm" title="Transferir a nodo remoto"
                                    onclick="transferBackup('<?= $dirName ?>')">
                                <i class="bi bi-cloud-upload"></i>
                            </button>
                            <?php endif; ?>

                            <a href="/backups/<?= urlencode($backup['dir_name']) ?>/restore"
                               class="btn btn-outline-success btn-sm" title="Restaurar">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                            <form method="POST" action="/backups/<?= urlencode($backup['dir_name']) ?>/delete"
                                  class="d-inline" id="delete-form-<?= $dirName ?>">
                                <?= View::csrf() ?>
                                <input type="hidden" name="admin_password" id="delete-pass-<?= $dirName ?>">
                                <button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar"
                                        onclick="deleteBackup('<?= $dirName ?>', '<?= View::e($backup['username'] ?? '') ?>', '<?= date('d/m/Y H:i', strtotime($backup['date'] ?? 'now')) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasRemoteTargets): ?>
<!-- Remote Backups -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-cloud me-2"></i>Backups Remotos</span>
        <div class="d-flex align-items-center gap-2">
            <select id="remoteNodeSelect" class="form-select form-select-sm" style="width: auto; background: #0f172a; border-color: #334155; color: #e2e8f0;">
                <option value="">Seleccionar destino...</option>
                <?php if ($hasNodes): ?>
                <optgroup label="Cluster Nodes">
                    <?php foreach ($nodes as $node): ?>
                    <option value="node-<?= (int) $node['id'] ?>"><?= View::e($node['name']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($hasFederationPeers): ?>
                <optgroup label="Federation Peers">
                    <?php foreach ($federationPeers as $fp): ?>
                    <?php if ($fp['status'] === 'pending_approval') continue; ?>
                    <option value="peer-<?= (int) $fp['id'] ?>" style="color:#10b981;"><?= View::e($fp['name']) ?> (federation)</option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
            <button type="button" class="btn btn-outline-info btn-sm" onclick="loadRemoteBackups()" id="btnLoadRemote">
                <i class="bi bi-arrow-repeat me-1"></i> Cargar
            </button>
        </div>
    </div>
    <div class="card-body p-0" id="remoteBackupsContainer">
        <div class="p-4 text-center text-muted">
            <i class="bi bi-cloud" style="font-size: 2rem;"></i>
            <p class="mt-2">Selecciona un nodo y pulsa "Cargar" para ver los backups remotos.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    let pollTimer = null;
    let elapsedTimer = null;
    let startedAt = null;
    let bsModal = null;
    const csrfToken = document.querySelector('input[name=_csrf_token]')?.value || '';

    function getModal() {
        if (!bsModal) bsModal = new bootstrap.Modal(document.getElementById('backupProgressModal'));
        return bsModal;
    }

    function formatElapsed(seconds) {
        if (seconds < 60) return seconds + 's';
        return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
    }

    function formatSize(bytes) {
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
        return bytes + ' B';
    }

    function stopTimers() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    }

    function updateProgressUI(data) {
        const step = data.step || 0;
        const total = data.total_steps || 1;
        const percent = Math.round((step / total) * 100);
        const bar = document.getElementById('backupProgressBar');

        document.getElementById('backupTask').textContent = data.current_task || '';
        document.getElementById('backupPercent').textContent = percent + '%';
        document.getElementById('backupStep').textContent = step;
        document.getElementById('backupTotalSteps').textContent = total;
        bar.style.width = percent + '%';
        document.getElementById('backupAccount').textContent = (data.username || '') + (data.domain ? ' — ' + data.domain : '');

        if (data.status === 'completed') {
            bar.classList.remove('progress-bar-animated', 'bg-info');
            bar.classList.add('bg-success');
            bar.style.width = '100%';
            document.getElementById('backupPercent').textContent = '100%';
            document.getElementById('backupModalTitle').textContent = 'Backup Completado';
            document.getElementById('backupModalIcon').className = 'bi bi-check-circle-fill text-success me-2';
            let html = `<div class="mb-0 py-2 px-3 rounded" style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);color:#22c55e;"><i class="bi bi-check-circle me-1"></i> Completado`;
            if (data.file_size_human) html += ` — <b>${data.file_size_human}</b>`;
            html += `</div>`;
            document.getElementById('backupResult').innerHTML = html;
            document.getElementById('backupResult').style.display = '';
            document.getElementById('backupModalFooter').style.display = '';
            stopTimers();
        } else if (data.status === 'error') {
            bar.classList.remove('progress-bar-animated', 'bg-info');
            bar.classList.add('bg-danger');
            document.getElementById('backupModalTitle').textContent = 'Error';
            document.getElementById('backupModalIcon').className = 'bi bi-x-circle-fill text-danger me-2';
            document.getElementById('backupResult').innerHTML = `<div class="mb-0 py-2 px-3 rounded" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#ef4444;"><i class="bi bi-x-circle me-1"></i> ${data.current_task}</div>`;
            document.getElementById('backupResult').style.display = '';
            document.getElementById('backupModalFooter').style.display = '';
            stopTimers();
        }
    }

    window.closeBackupModal = async function() {
        stopTimers();
        getModal().hide();
        try {
            await fetch('/backups/status/clear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf_token=' + encodeURIComponent(csrfToken),
            });
        } catch (e) {}
        location.reload();
    };

    // Check for running backup on page load — show full modal
    (async function() {
        try {
            const resp = await fetch('/backups/status');
            const data = await resp.json();
            if (data.ok && data.status && data.status !== 'idle') {
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
                    pollTimer = setInterval(async () => {
                        try {
                            const r = await fetch('/backups/status');
                            const d = await r.json();
                            if (!d.ok || d.status === 'idle') { stopTimers(); return; }
                            updateProgressUI(d);
                        } catch (e) {}
                    }, 1000);
                }
            }
        } catch (e) {}
    })();

    // ── Remote Backups ────────────────────────────────────────────

    window.loadRemoteBackups = async function() {
        const rawValue = document.getElementById('remoteNodeSelect')?.value;
        if (!rawValue) {
            SwalDark.fire('', 'Selecciona un destino primero.', 'info');
            return;
        }

        // Parse "node-X" or "peer-X"
        const parts = rawValue.split('-');
        const targetType = parts[0]; // 'node' or 'peer'
        const targetId = parts[1];

        const container = document.getElementById('remoteBackupsContainer');
        const btn = document.getElementById('btnLoadRemote');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Cargando...';
        container.innerHTML = '<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Conectando...</div>';

        try {
            const param = targetType === 'peer' ? 'peer_id' : 'node_id';
            const resp = await fetch('/backups/remote?' + param + '=' + targetId);
            const data = await resp.json();

            if (!data.ok) {
                container.innerHTML = `<div class="p-4 text-center"><span class="text-danger"><i class="bi bi-x-circle me-1"></i>${data.error}</span></div>`;
                return;
            }

            if (!data.backups || data.backups.length === 0) {
                container.innerHTML = '<div class="p-4 text-center text-muted"><i class="bi bi-cloud" style="font-size:1.5rem;"></i><p class="mt-2">No hay backups en este nodo.</p></div>';
                return;
            }

            let html = '<table class="table table-hover mb-0"><thead><tr>';
            html += '<th class="ps-3">Cuenta</th><th>Fecha</th><th>Tamano</th><th>Contenido</th><th class="text-end pe-3">Acciones</th>';
            html += '</tr></thead><tbody>';

            for (const bk of data.backups) {
                const isAuto = (bk.dir_name || '').endsWith('_auto');
                const dateStr = bk.date ? new Date(bk.date.replace(' ', 'T')).toLocaleDateString('es-ES', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
                const scope = (bk.scope || 'httpdocs') === 'full' ? 'Completo' : 'httpdocs';
                const sizeStr = formatSize(bk.disk_size || bk.file_size || 0);

                html += '<tr>';
                html += `<td class="ps-3"><i class="bi bi-server me-1" style="color:#a78bfa;"></i><strong>${bk.username || ''}</strong>`;
                if (isAuto) html += ' <span class="badge ms-1" style="background:rgba(167,139,250,0.15);color:#a78bfa;font-size:0.7em;">auto</span>';
                html += `<br><small class="text-muted">${bk.domain || ''}</small></td>`;
                html += `<td><small>${dateStr}</small></td>`;
                html += `<td>${sizeStr}</td>`;
                html += '<td>';
                if (bk.has_files) html += `<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-folder me-1"></i>${scope}</span> `;
                if ((bk.db_count || 0) > 0) html += `<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><i class="bi bi-database me-1"></i>${bk.db_count} BD</span>`;
                html += '</td>';
                html += '<td class="text-end pe-3">';
                html += `<button class="btn btn-outline-success btn-sm" title="Recuperar a local" onclick="fetchRemoteBackup('${targetType}', ${targetId}, '${bk.dir_name}')"><i class="bi bi-cloud-download"></i></button> `;
                html += `<button class="btn btn-outline-danger btn-sm" title="Eliminar" onclick="deleteRemoteBackup('${targetType}', ${targetId}, '${bk.dir_name}', '${bk.username || ''}')"><i class="bi bi-trash"></i></button>`;
                html += '</td></tr>';
            }

            html += '</tbody></table>';
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = `<div class="p-4 text-center"><span class="text-danger"><i class="bi bi-x-circle me-1"></i>Error de conexion</span></div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Cargar';
        }
    };

    let transferPollTimer = null;

    window.transferBackup = function(dirName) {
        const nodeOptions = {};
        <?php if ($hasNodes): ?>
        <?php foreach ($nodes as $node): ?>
        nodeOptions['node-<?= (int) $node['id'] ?>'] = <?= json_encode($node['name'] . ' (cluster)') ?>;
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($hasFederationPeers): ?>
        <?php foreach ($federationPeers as $fp): ?>
        <?php if ($fp['status'] === 'pending_approval') continue; ?>
        nodeOptions['peer-<?= (int) $fp['id'] ?>'] = <?= json_encode($fp['name'] . ' (federation)') ?>;
        <?php endforeach; ?>
        <?php endif; ?>

        // Build HTML with target selector + method selector for peers
        let selectHtml = '<div class="text-start mb-3"><label class="form-label small">Destino</label><select id="swal-target" class="form-select form-select-sm" style="background:#0f172a;border-color:#334155;color:#e2e8f0;">';
        selectHtml += '<option value="">Seleccionar destino...</option>';
        for (const [key, name] of Object.entries(nodeOptions)) {
            selectHtml += `<option value="${key}">${name}</option>`;
        }
        selectHtml += '</select></div>';
        selectHtml += '<div id="swal-method-row" class="text-start mb-2" style="display:none;"><label class="form-label small">Metodo de transferencia</label><select id="swal-method" class="form-select form-select-sm" style="background:#0f172a;border-color:#334155;color:#e2e8f0;">';
        selectHtml += '<option value="ssh">SSH (rsync) — rapido, resumible</option>';
        selectHtml += '<option value="http">HTTP upload — si SSH esta limitado</option>';
        selectHtml += '</select></div>';

        SwalDark.fire({
            title: 'Transferir backup',
            html: 'Backup: <strong>' + dirName + '</strong><br><br>' + selectHtml,
            showCancelButton: true,
            confirmButtonText: 'Transferir',
            cancelButtonText: 'Cancelar',
            didOpen: () => {
                document.getElementById('swal-target').addEventListener('change', function() {
                    const isPeer = this.value.startsWith('peer-');
                    document.getElementById('swal-method-row').style.display = isPeer ? 'block' : 'none';
                });
            },
            preConfirm: function() {
                const val = document.getElementById('swal-target').value;
                if (!val) { Swal.showValidationMessage('Selecciona un destino'); return false; }
                const method = document.getElementById('swal-method').value;
                return { target: val, method: method };
            }
        }).then(async function(result) {
            if (!result.isConfirmed || !result.value) return;

            try {
                const parts = result.value.target.split('-');
                const targetType = parts[0];
                const targetId = parts[1];
                const transferMethod = result.value.method || 'ssh';

                const formData = new FormData();
                formData.append('_csrf_token', csrfToken);
                formData.append(targetType === 'peer' ? 'peer_id' : 'node_id', targetId);
                if (targetType === 'peer') formData.append('transfer_method', transferMethod);

                const resp = await fetch('/backups/' + encodeURIComponent(dirName) + '/transfer', {
                    method: 'POST',
                    body: formData,
                });
                const data = await resp.json();

                if (!data.ok) {
                    SwalDark.fire({ icon: 'error', title: 'Error', text: data.error });
                    return;
                }

                // Show progress modal and start polling
                showTransferProgress();

            } catch (e) {
                SwalDark.fire({ icon: 'error', title: 'Error', text: 'Error de conexion' });
            }
        });
    };

    function showTransferProgress() {
        SwalDark.fire({
            title: '<i class="bi bi-cloud-upload me-2"></i>Transfiriendo...',
            html: getTransferProgressHtml(0, 'Preparando...', '', ''),
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                transferPollTimer = setInterval(pollTransferStatus, 1000);
            }
        });
    }

    function getTransferProgressHtml(percent, message, nodeName, fileSizeHuman) {
        return `
            <div class="mb-3 text-start">
                <div class="d-flex justify-content-between mb-1">
                    <small style="color:#94a3b8;">${message}</small>
                    <small style="color:#38bdf8;">${percent}%</small>
                </div>
                <div class="progress" style="height: 20px; background: #1e293b;">
                    <div class="progress-bar progress-bar-striped ${percent >= 100 ? 'bg-success' : 'progress-bar-animated bg-info'}"
                         role="progressbar" style="width: ${percent}%"></div>
                </div>
            </div>
            ${nodeName ? `<small style="color:#64748b;">Nodo: ${nodeName}</small>` : ''}
            ${fileSizeHuman ? `<small class="ms-2" style="color:#64748b;">Tamano: ${fileSizeHuman}</small>` : ''}
        `;
    }

    async function pollTransferStatus() {
        try {
            const resp = await fetch('/backups/transfer/status');
            const data = await resp.json();

            if (!data.ok || data.status === 'idle') {
                clearInterval(transferPollTimer);
                transferPollTimer = null;
                return;
            }

            if (data.status === 'completed') {
                clearInterval(transferPollTimer);
                transferPollTimer = null;

                await SwalDark.fire({
                    icon: 'success',
                    title: 'Transferido',
                    html: `Backup transferido a <strong>${data.node_name || ''}</strong>` +
                          (data.file_size_human ? ` (${data.file_size_human})` : ''),
                    confirmButtonText: 'OK',
                });

                // Clear status
                fetch('/backups/transfer/clear', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_csrf_token=' + encodeURIComponent(csrfToken),
                }).catch(() => {});
                return;
            }

            if (data.status === 'error') {
                clearInterval(transferPollTimer);
                transferPollTimer = null;

                SwalDark.fire({ icon: 'error', title: 'Error', text: data.error || 'Error en la transferencia' });

                fetch('/backups/transfer/clear', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_csrf_token=' + encodeURIComponent(csrfToken),
                }).catch(() => {});
                return;
            }

            // Update progress
            Swal.update({
                html: getTransferProgressHtml(
                    data.percent || 0,
                    data.message || 'Transfiriendo...',
                    data.node_name || '',
                    data.file_size_human || ''
                ),
            });

        } catch (e) {}
    }

    window.fetchRemoteBackup = async function(targetType, targetId, backupName) {
        const result = await SwalDark.fire({
            title: 'Recuperar backup?',
            html: 'Se descargara <strong>' + backupName + '</strong> del servidor remoto y se guardara localmente.' +
                  (targetType === 'peer' ? '<br><small class="text-muted">Transferencia via SSH (rsync)</small>' : ''),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Si, recuperar',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) return;

        SwalDark.fire({ title: 'Descargando...', html: 'Recuperando backup...<br><small class="text-muted">Esto puede tardar varios minutos.</small>', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const formData = new FormData();
            formData.append('_csrf_token', csrfToken);
            formData.append(targetType === 'peer' ? 'peer_id' : 'node_id', targetId);
            formData.append('backup_name', backupName);

            const resp = await fetch('/backups/remote/fetch', { method: 'POST', body: formData });
            const data = await resp.json();

            if (data.ok) {
                await SwalDark.fire({ icon: 'success', title: 'Recuperado', text: data.message, timer: 3000 });
                location.reload();
            } else {
                SwalDark.fire({ icon: 'error', title: 'Error', text: data.error });
            }
        } catch (e) {
            SwalDark.fire({ icon: 'error', title: 'Error', text: 'Error de conexion' });
        }
    };

    window.deleteRemoteBackup = async function(targetType, targetId, backupName, username) {
        const result = await SwalDark.fire({
            title: 'Eliminar backup remoto?',
            html: 'Se eliminara <strong>' + backupName + '</strong> (' + username + ') del servidor remoto.<br><br><span style="color:#ef4444;">Esta accion es irreversible.</span>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, eliminar',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) return;

        try {
            const formData = new FormData();
            formData.append('_csrf_token', csrfToken);
            formData.append(targetType === 'peer' ? 'peer_id' : 'node_id', targetId);
            formData.append('backup_name', backupName);

            const resp = await fetch('/backups/remote/delete', { method: 'POST', body: formData });
            const data = await resp.json();

            if (data.ok) {
                SwalDark.fire({ icon: 'success', title: 'Eliminado', text: data.message, timer: 2000 });
                loadRemoteBackups(); // Refresh list
            } else {
                SwalDark.fire({ icon: 'error', title: 'Error', text: data.error });
            }
        } catch (e) {
            SwalDark.fire({ icon: 'error', title: 'Error', text: 'Error de conexion' });
        }
    };
})();

function deleteBackup(dirName, username, date) {
    SwalDark.fire({
        title: 'Eliminar backup?',
        html: 'Se eliminara el backup de <strong>' + username + '</strong> del <strong>' + date + '</strong>.<br><br>' +
              '<span style="color:#ef4444;">Esta accion es irreversible.</span><br><br>' +
              'Ingresa tu contrasena de administrador para confirmar:',
        icon: 'warning',
        input: 'password',
        inputPlaceholder: 'Contrasena de administrador',
        inputAttributes: { autocomplete: 'current-password' },
        showCancelButton: true,
        confirmButtonText: 'Si, eliminar',
        cancelButtonText: 'Cancelar',
        preConfirm: function(password) {
            if (!password) { Swal.showValidationMessage('Debes ingresar tu contrasena'); return false; }
            return password;
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            document.getElementById('delete-pass-' + dirName).value = result.value;
            document.getElementById('delete-form-' + dirName).submit();
        }
    });
}
</script>
