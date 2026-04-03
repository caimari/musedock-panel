<?php use MuseDockPanel\View; ?>
<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Add Peer Modal -->
<div class="modal fade" id="addPeerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light border-secondary">
            <form method="post" action="/settings/federation/add-peer">
                <?= View::csrf() ?>
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i>Agregar Federation Peer</h5>
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
                        <div class="form-text text-muted">El mismo cluster_local_token del panel remoto</div>
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
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPeerModal">
            <i class="bi bi-plus me-1"></i>Agregar Peer
        </button>
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
</script>
