<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- API Status -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Estado API</div>
                    <div class="stat-value" style="font-size:1.2rem;">
                        <?php if ($apiAvailable): ?>
                            <span style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Online</span>
                        <?php else: ?>
                            <span style="color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Offline</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-icon"><i class="bi bi-globe"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Rutas Activas</div>
                    <div class="stat-value"><?= count($routes) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-signpost-2"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Politicas TLS</div>
                    <div class="stat-value"><?= count($tlsPolicies) ?></div>
                </div>
                <div class="stat-icon"><i class="bi bi-shield-lock"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Export / Import -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Exportar / Importar configuracion (JSON)</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-5">
                <form method="POST" action="/settings/caddy/export" onsubmit="return confirmCaddyExportConfig(this)">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <input type="hidden" name="admin_password" class="cfg-admin-password-field" value="">
                    <div class="small text-muted mb-2">Descarga la configuracion completa actual de Caddy desde API.</div>
                    <button type="submit" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-download me-1"></i>Exportar JSON
                    </button>
                </form>
            </div>
            <div class="col-lg-7">
                <form method="POST" action="/settings/caddy/import" enctype="multipart/form-data" onsubmit="return confirmCaddyImportConfig(this)">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <input type="hidden" name="admin_password" class="cfg-admin-password-field" value="">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small text-muted">Archivo JSON</label>
                            <input type="file" name="config_file" class="form-control form-control-sm" accept=".json,application/json" required>
                        </div>
                        <div class="col-md-4 d-grid">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="bi bi-upload me-1"></i>Importar
                            </button>
                        </div>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="replace_existing" id="caddyReplaceExisting" required checked>
                        <label class="form-check-label small text-muted" for="caddyReplaceExisting">
                            Confirmo sobrescritura completa de configuracion Caddy
                        </label>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Routes -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-signpost-2 me-2"></i>Rutas HTTP</span>
        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><?= count($routes) ?> ruta(s)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($routes)): ?>
            <div class="p-3 text-center text-muted">No se pudieron obtener las rutas.</div>
        <?php else: ?>
            <?php foreach ($routes as $r): ?>
            <div class="p-3 border-bottom" style="border-color:#334155 !important;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <?php if ($r['id']): ?>
                                <code style="color:#fbbf24;font-size:0.8rem;"><?= View::e($r['id']) ?></code>
                            <?php else: ?>
                                <code style="color:#64748b;font-size:0.8rem;">sin @id</code>
                            <?php endif; ?>
                            <?php if ($r['terminal']): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.65rem;">terminal</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if (!empty($r['hosts'])): ?>
                                <?php foreach ($r['hosts'] as $h): ?>
                                    <span class="badge me-1" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><?= View::e($h) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(148,163,184,0.15);color:#94a3b8;">catch-all</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($r['doc_root']): ?>
                            <small class="text-muted"><i class="bi bi-folder me-1"></i><?= View::e($r['doc_root']) ?></small>
                        <?php endif; ?>
                        <?php if ($r['upstream']): ?>
                            <br><small class="text-muted"><i class="bi bi-plug me-1"></i><?= View::e($r['upstream']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['id']): ?>
                    <div>
                        <form method="POST" action="/settings/caddy/delete-route" class="d-inline" onsubmit="return caddyDeleteConfirm(event, this, '<?= View::e(addslashes($r['id'])) ?>', '<?= View::e(addslashes(implode(', ', $r['hosts']))) ?>')">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <input type="hidden" name="route_id" value="<?= View::e($r['id']) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar ruta">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- TLS Policies -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-shield-lock me-2"></i>Politicas TLS / Certificados</div>
    <div class="card-body p-0">
        <?php if (empty($tlsPolicies)): ?>
            <div class="p-3 text-center text-muted">No se pudieron obtener las politicas TLS.</div>
        <?php else: ?>
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Sujetos</th>
                        <th>Emisor</th>
                        <th>Challenge</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tlsPolicies as $p): ?>
                    <tr>
                        <td>
                            <?php if (empty($p['subjects'])): ?>
                                <span class="badge" style="background:rgba(148,163,184,0.15);color:#94a3b8;">Catch-all (todos los demas)</span>
                            <?php else: ?>
                                <?php foreach ($p['subjects'] as $s): ?>
                                    <code class="me-1"><?= View::e($s) ?></code>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><small><?= View::e($p['issuer']) ?></small></td>
                        <td>
                            <?php
                            $chBadge = match($p['challenge']) {
                                'dns-01' => 'background:rgba(139,92,246,0.15);color:#a78bfa;',
                                'http-01' => 'background:rgba(34,197,94,0.15);color:#22c55e;',
                                default => 'background:rgba(148,163,184,0.15);color:#94a3b8;',
                            };
                            ?>
                            <span class="badge" style="<?= $chBadge ?>"><?= View::e($p['challenge']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Raw config (collapsible) -->
<div class="card">
    <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#rawConfig" style="cursor:pointer;">
        <i class="bi bi-code-slash me-2"></i>Configuracion JSON Raw
        <i class="bi bi-chevron-down float-end"></i>
    </div>
    <div class="collapse" id="rawConfig">
        <div class="card-body p-0">
            <pre class="p-3 mb-0" style="background:#0f172a;color:#94a3b8;font-size:0.75rem;max-height:500px;overflow:auto;white-space:pre-wrap;word-break:break-all;"><?= View::e($rawConfig) ?></pre>
        </div>
    </div>
</div>

<script>
function setCaddyAdminPassword(form, password) {
    form.querySelectorAll('.cfg-admin-password-field').forEach(function(field) {
        field.value = password;
    });
}

function confirmCaddyConfigAction(form, options) {
    var S = typeof Swal !== 'undefined' ? Swal : (typeof SwalDark !== 'undefined' ? SwalDark : null);
    if (!S) {
        var ok = confirm(options.fallbackText || 'Confirmar accion?');
        if (!ok) return false;
        var pwd = prompt('Contrasena de administrador:');
        if (!pwd) return false;
        setCaddyAdminPassword(form, pwd);
        return true;
    }

    S.fire({
        title: options.title || 'Confirmar accion',
        html: (options.html || '') + '<div class="mt-3 text-start"><label class="form-label fw-semibold mb-1" style="color:#111827;">Contrasena de administrador</label><input id="caddyAdminPassword" type="password" class="form-control" autocomplete="current-password"></div>',
        icon: options.icon || 'warning',
        showCancelButton: true,
        confirmButtonText: options.confirmText || 'Continuar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        background: '#ffffff',
        color: '#111827',
        focusConfirm: false,
        preConfirm: function() {
            var input = document.getElementById('caddyAdminPassword');
            var password = input ? String(input.value || '').trim() : '';
            if (!password) {
                S.showValidationMessage('La contrasena es obligatoria.');
                return false;
            }
            return password;
        },
        didOpen: function(popup) {
            popup.querySelectorAll('.text-muted, small').forEach(function(el) {
                el.style.setProperty('color', '#4b5563', 'important');
                el.style.setProperty('opacity', '1', 'important');
            });
        }
    }).then(function(result) {
        if (!result.isConfirmed || !result.value) return;
        setCaddyAdminPassword(form, result.value);
        form.submit();
    });

    return false;
}

function confirmCaddyExportConfig(form) {
    return confirmCaddyConfigAction(form, {
        title: 'Exportar configuracion de Caddy',
        html: '<div class="text-start"><small class="text-muted">Se descargara un JSON completo de la configuracion activa.</small></div>',
        icon: 'info',
        confirmText: 'Exportar',
        fallbackText: 'Se exportara la configuracion de Caddy.'
    });
}

function confirmCaddyImportConfig(form) {
    var fileInput = form.querySelector('input[name=config_file]');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        return false;
    }
    var replace = form.querySelector('input[name=replace_existing]');
    if (!replace || !replace.checked) {
        return false;
    }
    return confirmCaddyConfigAction(form, {
        title: 'Importar configuracion de Caddy',
        html: '<div class="text-start"><small class="text-muted">Esta accion sobrescribe toda la configuracion activa y aplica reload inmediato.</small></div>',
        icon: 'warning',
        confirmText: 'Importar y sobrescribir',
        fallbackText: 'Se importara la configuracion completa de Caddy.'
    });
}

function caddyDeleteConfirm(e, form, routeId, hosts) {
    e.preventDefault();
    var S = typeof Swal !== 'undefined' ? Swal : (typeof SwalDark !== 'undefined' ? SwalDark : null);
    if (!S) {
        if (confirm('Eliminar ruta ' + routeId + '?')) form.submit();
        return false;
    }
    S.fire({
        title: 'Eliminar ruta de Caddy?',
        html: '<div class="text-start"><small class="text-muted">Route ID:</small> <code>' + routeId + '</code><br><small class="text-muted">Dominios:</small> <code>' + hosts + '</code></div><br><div style="color:#ef4444;font-weight:600;">Los dominios dejaran de funcionar inmediatamente.</div>',
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i> Eliminar',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancelar',
        focusCancel: true,
        background: '#ffffff',
        color: '#111827',
        didOpen: function(popup) {
            popup.querySelectorAll('.text-muted, small').forEach(function(el) {
                el.style.setProperty('color', '#4b5563', 'important');
                el.style.setProperty('opacity', '1', 'important');
            });
        }
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
