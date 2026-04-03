<?php use MuseDockPanel\View; ?>

<div class="mb-3">
    <a href="/accounts/<?= $account['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver a <?= View::e($account['domain']) ?></a>
</div>

<?php if ($activeMigration): ?>
    <!-- Active migration — show progress -->
    <?php
    $mid = $activeMigration['migration_id'];
    $steps = [
        'health_check' => ['icon' => 'bi-heart-pulse', 'label' => 'Health Check'],
        'lock'         => ['icon' => 'bi-lock', 'label' => 'Bloquear cuenta'],
        'prepare'      => ['icon' => 'bi-gear', 'label' => 'Preparar destino'],
        'sync_files'   => ['icon' => 'bi-files', 'label' => 'Sincronizar archivos'],
        'sync_db'      => ['icon' => 'bi-database', 'label' => 'Sincronizar BD'],
        'freeze'       => ['icon' => 'bi-snow', 'label' => 'Congelar (mantenimiento)'],
        'final_sync'   => ['icon' => 'bi-arrow-repeat', 'label' => 'Sync final (deltas)'],
        'finalize'     => ['icon' => 'bi-check2-circle', 'label' => 'Finalizar destino'],
        'verify'       => ['icon' => 'bi-shield-check', 'label' => 'Verificar'],
        'switch_dns'   => ['icon' => 'bi-globe', 'label' => 'Cambiar DNS'],
        'complete'     => ['icon' => 'bi-flag-fill', 'label' => 'Completar'],
    ];
    if ($activeMigration['mode'] === 'clone') {
        unset($steps['switch_dns'], $steps['complete']);
    }
    ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-arrow-left-right me-1"></i>
                Migracion en curso — <code><?= substr($mid, 0, 8) ?></code>
                <?php if ($activeMigration['dry_run']): ?><span class="badge bg-warning text-dark ms-1">DRY-RUN</span><?php endif; ?>
                <span class="badge bg-<?= $activeMigration['mode'] === 'clone' ? 'info' : 'primary' ?> ms-1"><?= View::e($activeMigration['mode']) ?></span>
            </span>
            <span id="migration-status" class="badge bg-primary"><?= View::e($activeMigration['status']) ?></span>
        </div>
        <div class="card-body">
            <!-- Progress bar -->
            <div class="progress mb-3" style="height:6px;">
                <div id="progress-bar" class="progress-bar bg-primary" role="progressbar" style="width:0%"></div>
            </div>

            <!-- Step list -->
            <div class="list-group list-group-flush" id="step-list">
                <?php foreach ($steps as $stepKey => $stepInfo): ?>
                    <div class="list-group-item bg-transparent text-light border-secondary d-flex align-items-center py-2" id="step-<?= $stepKey ?>">
                        <span class="step-icon me-2 text-muted"><i class="bi <?= $stepInfo['icon'] ?>"></i></span>
                        <span class="step-label flex-grow-1"><?= $stepInfo['label'] ?></span>
                        <span class="step-status badge bg-secondary">pendiente</span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Grace period notice -->
            <div id="grace-notice" class="alert mt-3 py-2 px-3 small d-none" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);color:#94a3b8;">
                <i class="bi bi-hourglass-split me-2" style="color:#38bdf8;"></i>
                <span>Grace period activo: ambos servidores sirven el dominio. Origen en modo read-only.</span>
                <strong id="grace-timer" class="ms-1"></strong>
            </div>

            <!-- DNS manual warning -->
            <div id="dns-manual-notice" class="alert mt-3 py-2 px-3 small d-none" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);color:#f97316;">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>DNS manual requerido:</strong> Actualiza el registro A del dominio a: <code id="dns-target-ip"></code>
            </div>

            <!-- Metrics -->
            <div id="metrics-panel" class="mt-3 d-none">
                <div class="d-flex gap-3 flex-wrap small">
                    <div class="px-3 py-2 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);">
                        <span class="text-muted">Tiempo total</span>
                        <div id="metric-time" class="text-light fw-bold">—</div>
                    </div>
                    <div class="px-3 py-2 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);">
                        <span class="text-muted">Transferido</span>
                        <div id="metric-bytes" class="text-light fw-bold">—</div>
                    </div>
                    <div class="px-3 py-2 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);">
                        <span class="text-muted">Velocidad</span>
                        <div id="metric-speed" class="text-light fw-bold">—</div>
                    </div>
                </div>
            </div>

            <!-- Error message -->
            <div id="error-box" class="alert alert-danger mt-3 py-2 d-none"></div>

            <!-- Controls -->
            <div class="mt-3 d-flex gap-2">
                <button id="btn-run-all" class="btn btn-primary btn-sm" onclick="runAll()">
                    <i class="bi bi-play-fill me-1"></i>Ejecutar todo
                </button>
                <button id="btn-next-step" class="btn btn-outline-primary btn-sm" onclick="nextStep()">
                    <i class="bi bi-skip-forward me-1"></i>Siguiente paso
                </button>
                <button id="btn-pause" class="btn btn-outline-warning btn-sm d-none" onclick="pauseMigration()">
                    <i class="bi bi-pause-fill me-1"></i>Pausar
                </button>
                <button id="btn-resume" class="btn btn-outline-success btn-sm d-none" onclick="resumeMigration()">
                    <i class="bi bi-play-fill me-1"></i>Reanudar
                </button>
                <button id="btn-cancel" class="btn btn-outline-danger btn-sm" onclick="cancelMigration()">
                    <i class="bi bi-x-circle me-1"></i>Cancelar
                </button>
            </div>
        </div>
    </div>

    <!-- Logs -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-terminal me-1"></i>Logs</span>
            <button class="btn btn-outline-secondary btn-sm" onclick="refreshLogs()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="card-body p-0">
            <pre id="log-output" class="mb-0 p-3 small" style="max-height:300px;overflow-y:auto;background:#0d1117;color:#c9d1d9;font-size:0.8rem;">Cargando logs...</pre>
        </div>
    </div>

    <script>
    const migrationId = '<?= View::e($mid) ?>';
    const accountId = <?= (int)$account['id'] ?>;
    const csrfToken = '<?= View::csrfToken() ?>';
    let pollTimer = null;

    function postAction(action, extra = {}) {
        const body = new URLSearchParams({_csrf_token: csrfToken, migration_id: migrationId, ...extra});
        return fetch(`/accounts/${accountId}/federation-migrate/${action}`, {method:'POST', body});
    }

    function runAll() {
        document.getElementById('btn-run-all').disabled = true;
        document.getElementById('btn-next-step').disabled = true;
        postAction('execute', {run_all: '1'}).then(r => r.json()).then(data => {
            refreshProgress();
            refreshLogs();
        });
        startPolling();
    }

    function nextStep() {
        document.getElementById('btn-next-step').disabled = true;
        postAction('execute').then(r => r.json()).then(data => {
            refreshProgress();
            refreshLogs();
            document.getElementById('btn-next-step').disabled = false;
        });
    }

    function pauseMigration() {
        postAction('pause').then(() => refreshProgress());
    }

    function resumeMigration() {
        postAction('resume').then(() => {
            refreshProgress();
            runAll();
        });
    }

    function cancelMigration() {
        SwalDark.fire({
            title: 'Cancelar migracion?',
            text: 'Esto ejecutara un rollback completo. El hosting permanecera en el servidor origen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, cancelar',
            cancelButtonText: 'No',
        }).then(result => {
            if (!result.isConfirmed) return;
            postAction('cancel').then(() => refreshProgress());
        });
    }

    function refreshProgress() {
        fetch(`/accounts/${accountId}/federation-migrate/progress?migration_id=${migrationId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            // Update progress bar
            document.getElementById('progress-bar').style.width = data.percent + '%';

            // Update status badge
            const statusEl = document.getElementById('migration-status');
            statusEl.textContent = data.status;
            statusEl.className = 'badge bg-' + ({
                completed: 'success', running: 'primary', paused: 'warning',
                failed: 'danger', rolled_back: 'secondary', cancelled: 'secondary',
                grace_period: 'info'
            }[data.status] || 'secondary');

            // Update steps
            for (const [step, status] of Object.entries(data.step_statuses || {})) {
                const el = document.getElementById('step-' + step);
                if (!el) continue;
                const badge = el.querySelector('.step-status');
                const icon = el.querySelector('.step-icon');
                badge.textContent = {completed:'completado', running:'ejecutando...', failed:'fallido', pending:'pendiente'}[status] || status;
                badge.className = 'step-status badge bg-' + {completed:'success', running:'primary', failed:'danger', pending:'secondary'}[status];
                if (status === 'running') icon.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                else if (status === 'completed') icon.classList.add('text-success');
                else if (status === 'failed') icon.classList.add('text-danger');
            }

            // Metrics
            if (data.wall_clock_seconds > 0 || data.final_metrics) {
                document.getElementById('metrics-panel').classList.remove('d-none');
                const wc = data.wall_clock_seconds;
                const h = Math.floor(wc / 3600); const m = Math.floor((wc % 3600) / 60); const s = wc % 60;
                document.getElementById('metric-time').textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
                // Find sync bytes/speed from step metrics
                const sm = data.step_metrics || {};
                let totalBytes = 0, maxSpeed = 0;
                for (const [step, met] of Object.entries(sm)) {
                    if (met.bytes) totalBytes += met.bytes;
                    if (met.speed_mbps && met.speed_mbps > maxSpeed) maxSpeed = met.speed_mbps;
                }
                if (data.final_metrics) {
                    totalBytes = data.final_metrics.total_bytes_transferred || totalBytes;
                }
                document.getElementById('metric-bytes').textContent = totalBytes > 1048576 ? (totalBytes / 1048576).toFixed(1) + ' MB' : totalBytes > 1024 ? (totalBytes / 1024).toFixed(0) + ' KB' : totalBytes + ' B';
                document.getElementById('metric-speed').textContent = maxSpeed > 0 ? maxSpeed.toFixed(1) + ' MB/s' : '—';
            }

            // DNS manual warning
            if (data.dns_manual_required) {
                document.getElementById('dns-manual-notice').classList.remove('d-none');
                document.getElementById('dns-target-ip').textContent = data.dns_target_ip || '?';
            } else {
                document.getElementById('dns-manual-notice').classList.add('d-none');
            }

            // Grace period
            if (data.grace_remaining !== null && data.grace_remaining > 0) {
                document.getElementById('grace-notice').classList.remove('d-none');
                const mins = Math.floor(data.grace_remaining / 60);
                const secs = data.grace_remaining % 60;
                document.getElementById('grace-timer').textContent = `${mins}m ${secs}s restantes`;
            } else {
                document.getElementById('grace-notice').classList.add('d-none');
            }

            // Error
            if (data.error_message) {
                document.getElementById('error-box').textContent = data.error_message;
                document.getElementById('error-box').classList.remove('d-none');
            } else {
                document.getElementById('error-box').classList.add('d-none');
            }

            // Buttons
            const isPaused = data.status === 'paused';
            const isRunning = data.status === 'running';
            const isFinal = ['completed', 'failed', 'rolled_back', 'cancelled'].includes(data.status);
            document.getElementById('btn-pause').classList.toggle('d-none', !isRunning);
            document.getElementById('btn-resume').classList.toggle('d-none', !isPaused);
            document.getElementById('btn-run-all').disabled = isRunning || isFinal;
            document.getElementById('btn-next-step').disabled = isRunning || isFinal;
            document.getElementById('btn-cancel').disabled = isFinal;

            if (isFinal) stopPolling();
        });
    }

    function refreshLogs() {
        fetch(`/accounts/${accountId}/federation-migrate/logs?migration_id=${migrationId}`)
        .then(r => r.json())
        .then(logs => {
            const el = document.getElementById('log-output');
            el.textContent = logs.map(l => {
                const lvl = l.level === 'error' ? '\x1b[31m' : l.level === 'warn' ? '\x1b[33m' : '';
                return `[${l.created_at}] [${l.step}] ${l.level}: ${l.message}`;
            }).join('\n') || 'Sin logs todavia.';
            el.scrollTop = el.scrollHeight;
        });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            refreshProgress();
            refreshLogs();
        }, 2000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // Initial load
    refreshProgress();
    refreshLogs();
    <?php if (in_array($activeMigration['status'], ['running'])): ?>
    startPolling();
    <?php endif; ?>
    </script>

<?php else: ?>
    <!-- No active migration — show start form -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-arrow-left-right me-1"></i>Migrar hosting a otro servidor
        </div>
        <div class="card-body">
            <div class="mb-3 p-3 rounded" style="background:rgba(56,189,248,0.05);border:1px solid rgba(56,189,248,0.15);">
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-info-circle me-2 text-info"></i>
                    <strong class="text-light">Dominio: <?= View::e($account['domain']) ?></strong>
                </div>
                <small class="text-muted">
                    Usuario: <?= View::e($account['username']) ?> |
                    Disco: <?= $account['disk_used_mb'] ?? '?' ?> MB |
                    PHP: <?= View::e($account['php_version'] ?? '8.3') ?>
                </small>
            </div>

            <?php if (empty($peers)): ?>
                <div class="py-2 px-3 rounded small" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);color:#f97316;">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    No hay peers de federation configurados. <a href="/settings/federation" style="color:#38bdf8;">Configura un peer primero</a>.
                </div>
            <?php else: ?>
                <form id="migration-form">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Servidor destino</label>
                            <select name="peer_id" class="form-select form-select-sm bg-dark text-light border-secondary" required>
                                <option value="">Seleccionar peer...</option>
                                <?php foreach ($peers as $p): ?>
                                    <option value="<?= $p['id'] ?>"
                                        <?= $p['status'] !== 'online' ? 'class="text-muted"' : '' ?>>
                                        <?= View::e($p['name']) ?>
                                        (<?= View::e($p['status']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Modo</label>
                            <select name="mode" class="form-select form-select-sm bg-dark text-light border-secondary">
                                <option value="migrate">Migrar (mover)</option>
                                <option value="clone">Clonar (copiar)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Grace period (min)</label>
                            <input type="number" name="grace_period" class="form-control form-control-sm bg-dark text-light border-secondary" value="60" min="5" max="1440">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Cambio de DNS</label>
                            <select name="dns_mode" class="form-select form-select-sm bg-dark text-light border-secondary">
                                <option value="auto">Automatico (Cloudflare API)</option>
                                <option value="manual">Manual (yo cambio el DNS)</option>
                            </select>
                            <div class="form-text text-muted">Si no usas Cloudflare o prefieres control manual, selecciona "Manual"</div>
                        </div>
                    </div>

                    <?php if (!empty($subdomains) || !empty($aliases)): ?>
                    <div class="mb-3 p-3 rounded" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);">
                        <label class="form-label small text-muted mb-2">Subdominios y aliases a incluir</label>

                        <?php if (!empty($subdomains)): ?>
                        <div class="mb-2">
                            <small class="text-muted d-block mb-1"><i class="bi bi-diagram-2 me-1"></i>Subdominios</small>
                            <?php foreach ($subdomains as $sub): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_subdomains[]" value="<?= (int)$sub['id'] ?>" id="sub-<?= $sub['id'] ?>" checked>
                                <label class="form-check-label small" for="sub-<?= $sub['id'] ?>">
                                    <?= View::e($sub['subdomain']) ?>
                                    <span class="text-muted">(<?= View::e($sub['document_root'] ?? '') ?>)</span>
                                    <?php if ($sub['status'] !== 'active'): ?><span class="badge bg-secondary ms-1"><?= $sub['status'] ?></span><?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($aliases)): ?>
                        <div>
                            <small class="text-muted d-block mb-1"><i class="bi bi-link-45deg me-1"></i>Aliases / Redirects</small>
                            <?php foreach ($aliases as $alias): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_aliases[]" value="<?= (int)$alias['id'] ?>" id="alias-<?= $alias['id'] ?>" checked>
                                <label class="form-check-label small" for="alias-<?= $alias['id'] ?>">
                                    <?= View::e($alias['domain']) ?>
                                    <span class="badge ms-1" style="background:rgba(255,255,255,0.05);color:#94a3b8;font-size:0.7em;"><?= $alias['type'] ?? 'alias' ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.75rem;" onclick="document.querySelectorAll('[name=\'include_subdomains[]\'], [name=\'include_aliases[]\']').forEach(c => c.checked = true)">Todos</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.75rem;" onclick="document.querySelectorAll('[name=\'include_subdomains[]\'], [name=\'include_aliases[]\']').forEach(c => c.checked = false)">Ninguno</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-check mb-3">
                        <input type="checkbox" name="dry_run" class="form-check-input" id="dry-run-check">
                        <label class="form-check-label" for="dry-run-check">
                            Dry-run (validar conflictos sin ejecutar)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btn-start">
                        <i class="bi bi-play-fill me-1"></i>Iniciar migracion
                    </button>
                </form>

                <script>
                document.getElementById('migration-form').addEventListener('submit', function(e) {
                    e.preventDefault();
                    const btn = document.getElementById('btn-start');
                    const form = new FormData(this);

                    SwalDark.fire({
                        title: form.get('mode') === 'clone' ? 'Clonar hosting?' : 'Migrar hosting?',
                        html: form.get('dry_run') ?
                            'Se ejecutara en modo <strong>dry-run</strong> (solo validacion, sin cambios reales).' :
                            'Esto iniciara la migracion del hosting <strong><?= View::e($account['domain']) ?></strong> al servidor seleccionado.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Iniciar',
                    }).then(result => {
                        if (!result.isConfirmed) return;
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Iniciando...';

                        const body = new FormData();
                        body.append('_csrf_token', '<?= View::csrfToken() ?>');
                        body.append('peer_id', form.get('peer_id'));
                        body.append('mode', form.get('mode'));
                        body.append('grace_period', form.get('grace_period'));
                        body.append('dns_mode', form.get('dns_mode'));
                        body.append('dry_run', form.get('dry_run') ? '1' : '');
                        // Pass selected subdomains and aliases
                        for (const val of form.getAll('include_subdomains[]')) body.append('include_subdomains[]', val);
                        for (const val of form.getAll('include_aliases[]')) body.append('include_aliases[]', val);

                        fetch('/accounts/<?= $account['id'] ?>/federation-migrate/start', {method:'POST', body})
                        .then(r => r.json())
                        .then(data => {
                            if (data.ok) {
                                location.href = '/accounts/<?= $account['id'] ?>/federation-migrate?migration_id=' + data.migration_id;
                            } else {
                                btn.disabled = false;
                                btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Iniciar migracion';
                                SwalDark.fire({icon:'error', title:'Error', text: data.error || 'Error desconocido'});
                            }
                        })
                        .catch(() => {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Iniciar migracion';
                            SwalDark.fire({icon:'error', title:'Error de red'});
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
