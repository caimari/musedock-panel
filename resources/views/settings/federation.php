<?php use MuseDockPanel\View; ?>
<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Connect with Code Modal (SIMPLE FLOW) -->
<div class="modal fade" id="connectCodeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-link-45deg me-1"></i>Conectar paneles</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Generate or Paste -->
                <div class="mb-4">
                    <h6 class="text-info mb-2"><i class="bi bi-1-circle me-1"></i> Genera un codigo en el panel remoto</h6>
                    <p class="small text-muted mb-2">Ve a <strong>Settings > Federation</strong> en el otro panel y pulsa <strong>"Generar codigo"</strong>.</p>
                    <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);">
                        <span class="small text-muted">O genera uno en ESTE panel:</span>
                        <button type="button" class="btn btn-outline-info btn-sm" id="btn-generate-code" onclick="generateCode()">
                            <i class="bi bi-key me-1"></i>Generar codigo
                        </button>
                    </div>
                    <div id="generated-code-box" class="d-none mb-3">
                        <label class="form-label small text-success"><i class="bi bi-check-circle me-1"></i>Codigo generado (valido 10 min) — copialo al otro panel:</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="generated-code" class="form-control bg-dark text-light border-secondary font-monospace" readonly>
                            <button class="btn btn-outline-light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('generated-code').value);this.innerHTML='<i class=\'bi bi-check\'></i>'"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>
                </div>

                <hr class="border-secondary">

                <div>
                    <h6 class="text-info mb-2"><i class="bi bi-2-circle me-1"></i> Pega el codigo del panel remoto</h6>
                    <div class="input-group input-group-sm">
                        <input type="text" id="pairing-code-input" class="form-control bg-dark text-light border-secondary font-monospace" placeholder="Pega aqui el codigo del otro panel...">
                        <button class="btn btn-primary" type="button" id="btn-connect-code" onclick="connectWithCode()">
                            <i class="bi bi-link-45deg me-1"></i>Conectar
                        </button>
                    </div>
                    <div id="connect-result" class="mt-2 small"></div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Peer Modal (ADVANCED - manual) -->
<div class="modal fade" id="addPeerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light border-secondary">
            <form method="post" action="/settings/federation/add-peer">
                <?= View::csrf() ?>
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i>Agregar Peer (manual)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small">Nombre</label>
                        <input type="text" name="name" class="form-control form-control-sm bg-dark text-light border-secondary" required placeholder="asterisk">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">API URL</label>
                        <input type="url" name="api_url" class="form-control form-control-sm bg-dark text-light border-secondary" required placeholder="https://10.10.70.156:8444">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Auth Token</label>
                        <input type="text" name="auth_token" class="form-control form-control-sm bg-dark text-light border-secondary" required placeholder="Token del peer remoto">
                        <div class="form-text text-muted">El mismo cluster_local_token del panel remoto (Settings > Cluster)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">TLS pin (opcional)</label>
                        <input type="text" name="tls_pin" class="form-control form-control-sm bg-dark text-light border-secondary font-monospace"
                               placeholder="sha256//BASE64_SPki_HASH">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">CA bundle local (opcional)</label>
                        <input type="text" name="tls_ca_file" class="form-control form-control-sm bg-dark text-light border-secondary font-monospace"
                               placeholder="/etc/ssl/certs/ca-certificates.crt">
                    </div>
                    <hr class="border-secondary">
                    <h6 class="text-muted mb-2">SSH (para transferencia de archivos)</h6>
                    <div class="row">
                        <div class="col-8 mb-3">
                            <label class="form-label small">SSH Host</label>
                            <input type="text" name="ssh_host" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="10.10.70.156">
                        </div>
                        <div class="col-4 mb-3">
                            <label class="form-label small">Puerto</label>
                            <input type="number" name="ssh_port" class="form-control form-control-sm bg-dark text-light border-secondary" value="22">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-4 mb-3">
                            <label class="form-label small">Usuario</label>
                            <input type="text" name="ssh_user" class="form-control form-control-sm bg-dark text-light border-secondary" value="root">
                        </div>
                        <div class="col-8 mb-3">
                            <label class="form-label small">SSH Key Path</label>
                            <input type="text" name="ssh_key_path" class="form-control form-control-sm bg-dark text-light border-secondary" value="/root/.ssh/id_ed25519">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus me-1"></i>Agregar Peer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Federation Peers -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-1"></i>Federation Peers</span>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#connectCodeModal">
                <i class="bi bi-link-45deg me-1"></i>Conectar paneles
            </button>
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addPeerModal">
                <i class="bi bi-gear me-1"></i>Manual
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($peers)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-diagram-3 d-block mb-2" style="font-size:2rem;"></i>
                No hay peers configurados. Agrega un panel remoto para habilitar migraciones entre servidores.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>API URL</th>
                            <th>SSH</th>
                            <th>Estado</th>
                            <th>Ultima conexion</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($peers as $peer): ?>
                        <tr id="peer-row-<?= $peer['id'] ?>">
                            <td><strong><?= View::e($peer['name']) ?></strong></td>
                            <td><code class="small"><?= View::e($peer['api_url']) ?></code></td>
                            <td>
                                <?php if (!empty($peer['ssh_host'])): ?>
                                    <code class="small"><?= View::e($peer['ssh_user'] ?? 'root') ?>@<?= View::e($peer['ssh_host']) ?>:<?= $peer['ssh_port'] ?? 22 ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">No configurado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = match($peer['status']) {
                                    'online' => 'text-success',
                                    'offline' => 'text-danger',
                                    'pending_approval' => 'text-warning',
                                    default => 'text-warning',
                                };
                                $statusIcon = match($peer['status']) {
                                    'online' => 'bi-check-circle-fill',
                                    'offline' => 'bi-x-circle-fill',
                                    'pending_approval' => 'bi-exclamation-triangle-fill',
                                    default => 'bi-question-circle-fill',
                                };
                                ?>
                                <span class="<?= $statusClass ?>"><i class="bi <?= $statusIcon ?> me-1"></i><?= View::e($peer['status']) ?></span>
                            </td>
                            <td class="small text-muted"><?= $peer['last_seen_at'] ? date('d/m H:i', strtotime($peer['last_seen_at'])) : '—' ?></td>
                            <td class="text-end">
                                <?php if ($peer['status'] === 'pending_approval'): ?>
                                    <form method="post" action="/settings/federation/approve-peer/<?= $peer['id'] ?>" class="d-inline">
                                        <?= View::csrf() ?>
                                        <button class="btn btn-outline-success btn-sm" title="Aprobar peer"><i class="bi bi-check-lg me-1"></i>Aprobar</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-outline-info btn-sm" onclick="testPeer(<?= $peer['id'] ?>)" title="Test conexion">
                                        <i class="bi bi-lightning"></i>
                                    </button>
                                    <button class="btn btn-outline-warning btn-sm" onclick="exchangeKeys(<?= $peer['id'] ?>)" title="Intercambiar SSH keys">
                                        <i class="bi bi-key"></i>
                                    </button>
                                <?php endif; ?>
                                <form method="post" action="/settings/federation/remove-peer/<?= $peer['id'] ?>" class="d-inline"
                                      onsubmit="return confirm('Eliminar peer <?= View::e($peer['name']) ?>?')">
                                    <?= View::csrf() ?>
                                    <button class="btn btn-outline-danger btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Migration History -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-clock-history me-1"></i>Historial de Migraciones
    </div>
    <div class="card-body p-0">
        <?php if (empty($migrations)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-inbox d-block mb-2" style="font-size:2rem;"></i>
                No hay migraciones registradas.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Dominio</th>
                            <th>Destino</th>
                            <th>Modo</th>
                            <th>Estado</th>
                            <th>Paso actual</th>
                            <th>Duracion</th>
                            <th>Transferido</th>
                            <th>Fecha</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($migrations as $m): ?>
                        <?php
                        $statusBadge = match($m['status']) {
                            'completed'   => 'bg-success',
                            'running'     => 'bg-primary',
                            'paused'      => 'bg-warning text-dark',
                            'failed'      => 'bg-danger',
                            'rolled_back' => 'bg-secondary',
                            'cancelled'   => 'bg-secondary',
                            default       => 'bg-info',
                        };
                        $modeBadge = $m['mode'] === 'clone' ? 'bg-info' : 'bg-primary';
                        ?>
                        <tr>
                            <td><code class="small"><?= substr($m['migration_id'], 0, 8) ?></code></td>
                            <td><?= View::e($m['domain'] ?? '—') ?></td>
                            <td><?= View::e($m['peer_name'] ?? '—') ?></td>
                            <td>
                                <span class="badge <?= $modeBadge ?>"><?= View::e($m['mode']) ?></span>
                                <?php if ($m['dry_run']): ?><span class="badge bg-warning text-dark">dry-run</span><?php endif; ?>
                            </td>
                            <td><span class="badge <?= $statusBadge ?>"><?= View::e($m['status']) ?></span></td>
                            <td class="small"><?= View::e($m['current_step']) ?></td>
                            <?php
                            $mMeta = $m['metadata'] ?? [];
                            $mMetrics = $mMeta['metrics'] ?? [];
                            $wallClock = $mMetrics['wall_clock_human'] ?? '';
                            $totalMb = $mMetrics['total_mb_transferred'] ?? '';
                            ?>
                            <td class="small text-muted"><?= $wallClock ?: '—' ?></td>
                            <td class="small text-muted"><?= $totalMb ? $totalMb . ' MB' : '—' ?></td>
                            <td class="small text-muted"><?= date('d/m H:i', strtotime($m['created_at'])) ?></td>
                            <td class="text-end">
                                <?php if ($m['status'] === 'running' || $m['status'] === 'paused'): ?>
                                    <a href="/accounts/<?= $m['account_id'] ?>/federation-migrate?migration_id=<?= urlencode($m['migration_id']) ?>" class="btn btn-outline-info btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function testPeer(id) {
    const row = document.getElementById('peer-row-' + id);
    const btn = row.querySelector('.btn-outline-info');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled = true;

    fetch('/settings/federation/test-peer', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=<?= View::csrfToken() ?>&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        if (data.ok) {
            SwalDark.fire({icon:'success', title:'Conexion OK', text:'API: OK | SSH: OK', timer:3000});
            location.reload();
        } else {
            let detail = '';
            if (data.api_detail) detail += 'API: ' + (data.api_detail.ok ? 'OK' : data.api_detail.error || 'FAIL') + '\n';
            if (data.ssh_detail) detail += 'SSH: ' + (data.ssh_detail.ok ? 'OK' : data.ssh_detail.error || 'FAIL');
            SwalDark.fire({icon:'error', title:'Conexion fallida', text: detail || 'Error desconocido'});
        }
    })
    .catch(() => {
        btn.innerHTML = origHtml;
        btn.disabled = false;
        SwalDark.fire({icon:'error', title:'Error', text:'No se pudo contactar el panel'});
    });
}

function exchangeKeys(peerId) {
    SwalDark.fire({
        title: 'Intercambiar SSH Keys',
        text: 'Esto generara/enviara las SSH keys entre ambos paneles para permitir rsync.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Intercambiar',
    }).then(result => {
        if (!result.isConfirmed) return;
        SwalDark.fire({title:'Intercambiando keys...', allowOutsideClick:false, didOpen:()=>SwalDark.showLoading()});

        fetch('/settings/federation/exchange-keys', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '_csrf_token=<?= View::csrfToken() ?>&peer_id=' + peerId
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                SwalDark.fire({icon:'success', title:'Keys intercambiadas', timer:3000});
            } else {
                SwalDark.fire({icon:'error', title:'Error', text: data.error || 'Fallo el intercambio'});
            }
        })
        .catch(() => SwalDark.fire({icon:'error', title:'Error de red'}));
    });
}

// ── Pairing code system ──────────────────────────────
function generateCode() {
    const btn = document.getElementById('btn-generate-code');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generando...';

    fetch('/settings/federation/generate-pairing-code', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=<?= View::csrfToken() ?>'
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-key me-1"></i>Generar codigo';
        if (data.ok) {
            document.getElementById('generated-code').value = data.code;
            document.getElementById('generated-code-box').classList.remove('d-none');
        } else {
            SwalDark.fire({icon:'error', title:'Error', text: data.error || 'No se pudo generar el codigo'});
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-key me-1"></i>Generar codigo';
        SwalDark.fire({icon:'error', title:'Error de red'});
    });
}

function connectWithCode() {
    const code = document.getElementById('pairing-code-input').value.trim();
    if (!code) {
        document.getElementById('connect-result').innerHTML = '<span class="text-danger">Introduce un codigo</span>';
        return;
    }

    const btn = document.getElementById('btn-connect-code');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Conectando...';
    document.getElementById('connect-result').innerHTML = '<span class="text-muted">Conectando... (verificando API, SSH, intercambiando keys)</span>';

    fetch('/settings/federation/connect-with-code', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: '_csrf_token=<?= View::csrfToken() ?>&pairing_code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Conectar';
        if (data.ok) {
            let status = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Conectado a <strong>' + (data.peer_name || 'panel remoto') + '</strong></span><br>';
            status += '<small class="text-muted">';
            status += 'API: ' + (data.api_ok ? '<span class="text-success">OK</span>' : '<span class="text-danger">FAIL</span>') + ' | ';
            status += 'SSH: ' + (data.ssh_ok ? '<span class="text-success">OK</span>' : '<span class="text-warning">Pendiente</span>') + ' | ';
            status += 'Handshake: ' + (data.handshake_ok ? '<span class="text-success">Bidireccional</span>' : '<span class="text-warning">Unidireccional</span>');
            status += '</small>';
            document.getElementById('connect-result').innerHTML = status;
            setTimeout(() => location.reload(), 2000);
        } else {
            document.getElementById('connect-result').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (data.error || 'Error desconocido') + '</span>';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Conectar';
        document.getElementById('connect-result').innerHTML = '<span class="text-danger">Error de red</span>';
    });
}
</script>
