<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$installed): ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-shield-exclamation me-1"></i> Fail2Ban no instalado</div>
        <div class="card-body">
            <p class="text-muted mb-3">Fail2Ban no esta instalado en este servidor. Es una herramienta que protege contra ataques de fuerza bruta baneando IPs sospechosas automaticamente.</p>
            <p class="mb-2">Para instalarlo, ejecuta:</p>
            <div class="p-2 rounded" style="background:rgba(255,255,255,0.05);font-family:monospace;font-size:0.9rem;">
                <code>apt update && apt install fail2ban -y</code>
            </div>
            <p class="text-muted small mt-3 mb-0">Despues de instalarlo, recarga esta pagina.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Estado del servicio -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-shield-exclamation me-1"></i> Estado de Fail2Ban</div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tr>
                    <td class="text-muted" style="width:40%">Estado del servicio</td>
                    <td>
                        <?php if ($serviceStatus === 'active'): ?>
                            <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?= View::e(ucfirst($serviceStatus)) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($serviceUptime)): ?>
                <tr>
                    <td class="text-muted">Tiempo activo</td>
                    <td><?= View::e($serviceUptime) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="text-muted">Jails configurados</td>
                    <td><strong><?= count($jails) ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if (empty($jails)): ?>
        <div class="card">
            <div class="card-body text-muted">
                <i class="bi bi-info-circle me-1"></i> No se encontraron jails configurados.
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($jails as $jail): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span>
                                <i class="bi bi-shield-lock me-1"></i>
                                <strong><?= View::e($jail['name']) ?></strong>
                                <span class="badge bg-success ms-2">Activo</span>
                            </span>
                            <span class="text-muted small">
                                <?= $jail['currently_banned'] ?> baneadas ahora
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <div class="p-2 rounded text-center" style="background:rgba(255,255,255,0.05);">
                                        <div class="text-muted small">IPs baneadas ahora</div>
                                        <div class="fs-4 fw-bold <?= $jail['currently_banned'] > 0 ? 'text-warning' : 'text-success' ?>">
                                            <?= $jail['currently_banned'] ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded text-center" style="background:rgba(255,255,255,0.05);">
                                        <div class="text-muted small">Total baneadas (historico)</div>
                                        <div class="fs-4 fw-bold"><?= $jail['total_banned'] ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded text-center" style="background:rgba(255,255,255,0.05);">
                                        <div class="text-muted small">Total intentos fallidos</div>
                                        <div class="fs-4 fw-bold text-danger"><?= $jail['total_failed'] ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($jail['banned_ips'])): ?>
                                <h6 class="mb-2"><i class="bi bi-list-ul me-1"></i>IPs baneadas actualmente</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Direccion IP</th>
                                                <th class="text-end" style="width:120px;">Accion</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($jail['banned_ips'] as $ip): ?>
                                                <tr>
                                                    <td><code><?= View::e($ip) ?></code></td>
                                                    <td class="text-end">
                                                        <form method="POST" action="/settings/fail2ban/unban" class="d-inline" onsubmit="return confirmUnban(this, '<?= View::e($ip) ?>', '<?= View::e($jail['name']) ?>')">
                                                            <?= View::csrf() ?>
                                                            <input type="hidden" name="jail" value="<?= View::e($jail['name']) ?>">
                                                            <input type="hidden" name="ip" value="<?= View::e($ip) ?>">
                                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                                <i class="bi bi-unlock me-1"></i>Desbanear
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted small mb-0"><i class="bi bi-check-circle me-1"></i>No hay IPs baneadas actualmente en este jail.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    function confirmUnban(form, ip, jail) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Desbanear IP',
                html: '¿Seguro que quieres desbanear <strong>' + ip + '</strong> del jail <strong>' + jail + '</strong>?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Si, desbanear',
                cancelButtonText: 'Cancelar',
                background: '#1e1e2e',
                color: '#cdd6f4',
                confirmButtonColor: '#f9e2af',
                cancelButtonColor: '#585b70',
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.onsubmit = null;
                    form.submit();
                }
            });
            return false;
        }
        return confirm('¿Desbanear ' + ip + ' del jail ' + jail + '?');
    }
    </script>
<?php endif; ?>
