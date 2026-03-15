<?php use MuseDockPanel\View; ?>

<div class="mb-3 d-flex gap-2">
    <a href="/settings/services" class="btn btn-outline-light btn-sm"><i class="bi bi-hdd-rack me-1"></i>Servicios</a>
    <a href="/settings/crons" class="btn btn-outline-light btn-sm"><i class="bi bi-clock-history me-1"></i>Cron Jobs</a>
    <a href="/settings/caddy" class="btn btn-outline-light btn-sm active"><i class="bi bi-globe me-1"></i>Caddy</a>
</div>

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
function caddyDeleteConfirm(e, form, routeId, hosts) {
    e.preventDefault();
    SwalDark.fire({
        title: 'Eliminar ruta de Caddy?',
        html: '<div class="text-start"><small class="text-muted">Route ID:</small> <code>' + routeId + '</code><br><small class="text-muted">Dominios:</small> <code>' + hosts + '</code></div><br><div style="color:#ef4444;font-weight:600;">Los dominios dejaran de funcionar inmediatamente.</div>',
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-trash me-1"></i> Eliminar',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancelar',
        focusCancel: true
    }).then(function(result) {
        if (result.isConfirmed) form.submit();
    });
    return false;
}
</script>
