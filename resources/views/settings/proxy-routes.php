<?php use MuseDockPanel\View; ?>
<?php include __DIR__ . '/_tabs.php'; ?>

<style>
.proxy-table th { font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid rgba(255,255,255,0.06); }
.proxy-table td { font-size: 0.88rem; vertical-align: middle; border-bottom: 1px solid rgba(255,255,255,0.04); }
.proxy-table .badge { font-size: 0.75rem; }
.form-card { background: rgba(30,41,59,0.5); border: 1px solid rgba(255,255,255,0.06); }
.form-card .card-header { background: rgba(30,41,59,0.8); border-bottom: 1px solid rgba(255,255,255,0.06); color: #e2e8f0; font-size: 0.9rem; }
.form-card .form-label { color: #94a3b8; font-size: 0.82rem; }
.form-card .form-control, .form-card .form-select { background: rgba(15,23,42,0.6); border-color: rgba(255,255,255,0.1); color: #e2e8f0; font-size: 0.88rem; }
.form-card .form-control:focus, .form-card .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }
.form-text { color: #64748b !important; }
.info-card { background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.15); border-radius: 0.5rem; }
</style>

<!-- ═══════ Header ═══════ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1" style="color:#e2e8f0;"><i class="bi bi-diagram-2 me-2"></i>Proxy Routes <span class="badge bg-info ms-2" style="font-size:0.7rem;">caddy-l4</span></h5>
        <span class="text-muted small">Enruta dominios a servidores internos (NAT/VPN) a través de SNI passthrough TCP</span>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-info btn-sm" onclick="proxyPreview()">
            <i class="bi bi-eye me-1"></i>Preview Config
        </button>
        <?php if ($canAdd): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="proxyShowForm()">
            <i class="bi bi-plus-circle me-1"></i>Nueva Ruta
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-secondary btn-sm" disabled title="Límite Free alcanzado — necesitas Pro">
            <i class="bi bi-lock me-1"></i>Nueva Ruta (Pro)
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════ Info Card ═══════ -->
<div class="info-card p-3 mb-3">
    <div class="d-flex align-items-start">
        <i class="bi bi-info-circle me-2 mt-1" style="color:#3b82f6;"></i>
        <div class="small" style="color:#94a3b8;">
            <strong style="color:#e2e8f0;">Proxy permanente SNI</strong> — A diferencia del failover (temporal, emergencia), las rutas proxy están siempre activas.
            Permiten que dominios alojados en servidores sin IP pública sean accesibles a través de este servidor usando caddy-l4 SNI passthrough.
            <br>
            <span class="text-muted">Los certificados SSL los gestiona el servidor destino. El tráfico pasa tal cual (TCP passthrough), sin descifrar.</span>
            <?php if ($tier === 'free'): ?>
            <br><span style="color:#eab308;"><i class="bi bi-star me-1"></i>Licencia Free: máximo 1 ruta proxy. <strong>Actualiza a Pro</strong> para rutas ilimitadas.</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════ Requisitos ═══════ -->
<div class="info-card p-3 mb-3" style="background:rgba(234,179,8,0.06); border-color:rgba(234,179,8,0.15);">
    <div class="d-flex align-items-start">
        <i class="bi bi-shield-lock me-2 mt-1" style="color:#eab308;"></i>
        <div class="small" style="color:#94a3b8;">
            <strong style="color:#e2e8f0;">Requisitos de configuración</strong>
            <br>
            <strong style="color:#eab308;">En este servidor (proxy):</strong>
            Los puertos <code>80</code> y <code>443</code> deben estar abiertos a todo internet (es el punto de entrada público).
            caddy-l4 debe estar instalado y activo.
            <br>
            <strong style="color:#eab308;">En el servidor destino:</strong>
            Los puertos <code>80</code> y <code>443</code> deben estar abiertos <strong>solo para la IP de este servidor proxy</strong>.
            No necesita puertos abiertos a todo internet — eso es lo que lo hace seguro.
            El servidor destino queda oculto: el DNS apunta al proxy, los atacantes solo ven la IP del proxy.
            <br>
            <span class="text-muted">
                <i class="bi bi-terminal me-1"></i>Ejemplo firewall en destino:
                <code>ufw allow from <?= View::e(trim((string)shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'")) ?: 'IP_DEL_PROXY') ?> to any port 80,443 proto tcp</code>
            </span>
            <br>
            <span class="text-muted">
                <i class="bi bi-info-circle me-1"></i>El puerto 80 se proxea para que Let's Encrypt (HTTP-01 challenge) funcione en el servidor destino.
                También funciona con servidores con IP pública — el beneficio es que la IP real queda oculta.
            </span>
        </div>
    </div>
</div>

<!-- ═══════ Add/Edit Form (hidden by default) ═══════ -->
<div id="proxy-form-card" class="card form-card mb-3" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span id="proxy-form-title"><i class="bi bi-plus-circle me-1"></i>Nueva Ruta Proxy</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="proxyHideForm()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
        <form method="post" action="/settings/proxy-routes/save" id="proxy-form">
            <?= View::csrf() ?>
            <input type="hidden" name="id" id="proxy-id" value="0">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Nombre <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="name" id="proxy-name" class="form-control form-control-sm" placeholder="Mi servidor interno">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Dominio <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="domain" id="proxy-domain" class="form-control form-control-sm" placeholder="app.ejemplo.com" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">IP destino <span style="color:#ef4444;">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="target_ip" id="proxy-ip" class="form-control" placeholder="10.0.0.5" required>
                        <button type="button" class="btn btn-outline-info" onclick="proxyTestTarget()" title="Test conectividad">
                            <i class="bi bi-wifi" id="proxy-test-icon"></i>
                        </button>
                    </div>
                    <span id="proxy-test-result" class="form-text small"></span>
                </div>
                <div class="col-md-1">
                    <label class="form-label">Puerto</label>
                    <input type="number" name="target_port" id="proxy-port" class="form-control form-control-sm" value="443" min="1" max="65535">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <div class="form-check form-switch">
                        <input type="checkbox" name="enabled" id="proxy-enabled" class="form-check-input" checked>
                        <label class="form-check-label small" for="proxy-enabled" style="color:#94a3b8;">Activa</label>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-8">
                    <label class="form-label">Notas <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="notes" id="proxy-notes" class="form-control form-control-sm" placeholder="Servidor detrás de WireGuard en casa...">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-success btn-sm me-2">
                        <i class="bi bi-check-circle me-1"></i><span id="proxy-btn-text">Crear Ruta</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="proxyHideForm()">Cancelar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ═══════ Routes Table ═══════ -->
<div class="card form-card">
    <div class="card-header">
        <i class="bi bi-list-ul me-1"></i>Rutas Proxy
        <span class="badge bg-secondary ms-2"><?= count($routes) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($routes)): ?>
            <div class="text-center py-4" style="color:#64748b;">
                <i class="bi bi-diagram-2" style="font-size:2rem;"></i>
                <p class="mt-2 mb-0">No hay rutas proxy configuradas.</p>
                <p class="small">Crea una ruta para enrutar dominios a servidores internos a través de este servidor.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover proxy-table mb-0">
                <thead>
                    <tr>
                        <th>Estado</th>
                        <th>Dominio</th>
                        <th>Destino</th>
                        <th>Nombre</th>
                        <th>Notas</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $r): ?>
                    <tr>
                        <td>
                            <?php if ($r['enabled']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Activa</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-pause-circle me-1"></i>Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="color:#60a5fa;"><?= View::e($r['domain']) ?></code>
                        </td>
                        <td>
                            <code style="color:#a78bfa;"><?= View::e($r['target_ip']) ?>:<?= (int)$r['target_port'] ?></code>
                        </td>
                        <td style="color:#94a3b8;"><?= View::e($r['name']) ?></td>
                        <td style="color:#64748b;font-size:0.82rem;"><?= View::e($r['notes']) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-info" onclick="proxyEdit(<?= (int)$r['id'] ?>, <?= View::e(json_encode($r)) ?>)" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="/settings/proxy-routes/toggle" class="d-inline">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-outline-<?= $r['enabled'] ? 'warning' : 'success' ?>" title="<?= $r['enabled'] ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi bi-<?= $r['enabled'] ? 'pause' : 'play' ?>-fill"></i>
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-danger" onclick="proxyDelete(<?= (int)$r['id'] ?>, '<?= View::e($r['domain']) ?>')" title="Eliminar">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════ Config Preview ═══════ -->
<div id="proxy-preview-card" class="card form-card mt-3" style="display:none;">
    <div class="card-header">
        <i class="bi bi-filetype-json me-1"></i>caddy-l4 Proxy Routes Preview
    </div>
    <div class="card-body">
        <pre id="proxy-preview-json" class="mb-0" style="color:#a78bfa;font-size:0.82rem;max-height:300px;overflow:auto;"></pre>
    </div>
</div>

<!-- ═══════ Delete Form (hidden) ═══════ -->
<form method="post" action="/settings/proxy-routes/delete" id="proxy-delete-form" style="display:none;">
    <?= View::csrf() ?>
    <input type="hidden" name="id" id="proxy-delete-id">
</form>

<script>
function proxyShowForm(reset) {
    if (reset !== false) proxyResetForm();
    document.getElementById('proxy-form-card').style.display = '';
    document.getElementById('proxy-domain').focus();
}

function proxyHideForm() {
    document.getElementById('proxy-form-card').style.display = 'none';
    proxyResetForm();
}

function proxyResetForm() {
    document.getElementById('proxy-id').value = '0';
    document.getElementById('proxy-name').value = '';
    document.getElementById('proxy-domain').value = '';
    document.getElementById('proxy-ip').value = '';
    document.getElementById('proxy-port').value = '443';
    document.getElementById('proxy-enabled').checked = true;
    document.getElementById('proxy-notes').value = '';
    document.getElementById('proxy-form-title').innerHTML = '<i class="bi bi-plus-circle me-1"></i>Nueva Ruta Proxy';
    document.getElementById('proxy-btn-text').textContent = 'Crear Ruta';
    document.getElementById('proxy-test-result').textContent = '';
}

function proxyEdit(id, data) {
    document.getElementById('proxy-id').value = id;
    document.getElementById('proxy-name').value = data.name || '';
    document.getElementById('proxy-domain').value = data.domain || '';
    document.getElementById('proxy-ip').value = data.target_ip || '';
    document.getElementById('proxy-port').value = data.target_port || 443;
    document.getElementById('proxy-enabled').checked = !!data.enabled;
    document.getElementById('proxy-notes').value = data.notes || '';
    document.getElementById('proxy-form-title').innerHTML = '<i class="bi bi-pencil me-1"></i>Editar Ruta: ' + (data.domain || '');
    document.getElementById('proxy-btn-text').textContent = 'Guardar Cambios';
    document.getElementById('proxy-test-result').textContent = '';
    proxyShowForm(false);
}

function proxyDelete(id, domain) {
    SwalDark.fire({
        title: 'Eliminar ruta proxy',
        html: '¿Eliminar la ruta para <code>' + domain + '</code>?<br><small class="text-muted">El tráfico dejará de ser proxeado.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
    }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('proxy-delete-id').value = id;
            document.getElementById('proxy-delete-form').submit();
        }
    });
}

function proxyTestTarget() {
    const ip = document.getElementById('proxy-ip').value.trim();
    const port = document.getElementById('proxy-port').value || '443';
    const result = document.getElementById('proxy-test-result');
    const icon = document.getElementById('proxy-test-icon');

    if (!ip) { result.innerHTML = '<span style="color:#ef4444;">Introduce una IP</span>'; return; }

    icon.className = 'bi bi-arrow-repeat spin';
    result.innerHTML = '<span class="text-muted">Probando...</span>';

    const fd = new FormData();
    fd.append('ip', ip);
    fd.append('port', port);
    fd.append('_csrf_token', '<?= View::csrfToken() ?>');

    fetch('/settings/proxy-routes/test', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        icon.className = 'bi bi-wifi';
        if (data.ok) {
            result.innerHTML = '<span style="color:#22c55e;">✓ Conectado (' + data.ms + 'ms)</span>';
        } else {
            result.innerHTML = '<span style="color:#ef4444;">✗ ' + (data.error || 'Error') + '</span>';
        }
    })
    .catch(() => {
        icon.className = 'bi bi-wifi';
        result.innerHTML = '<span style="color:#ef4444;">Error de red</span>';
    });
}

function proxyPreview() {
    const card = document.getElementById('proxy-preview-card');
    const pre = document.getElementById('proxy-preview-json');

    if (card.style.display !== 'none') {
        card.style.display = 'none';
        return;
    }

    pre.textContent = 'Cargando...';
    card.style.display = '';

    fetch('/settings/proxy-routes/preview')
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            pre.textContent = JSON.stringify(data.routes, null, 2);
        } else {
            pre.textContent = 'Error al generar preview';
        }
    })
    .catch(() => pre.textContent = 'Error de red');
}
</script>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.spin { animation: spin 1s linear infinite; display: inline-block; }
</style>
