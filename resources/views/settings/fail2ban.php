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
        <div class="card-header"><i class="bi bi-shield-check me-1"></i> Estado de Fail2Ban</div>
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
                <tr>
                    <td class="text-muted">Total IPs baneadas ahora</td>
                    <td>
                        <?php $totalBanned = array_sum(array_column($jails, 'currently_banned')); ?>
                        <strong class="<?= $totalBanned > 0 ? 'text-warning' : 'text-success' ?>"><?= $totalBanned ?></strong>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <!-- Banear IP manualmente -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-shield-lock me-1"></i> Banear IP manualmente</div>
                <div class="card-body">
                    <form method="POST" action="/settings/fail2ban/ban" onsubmit="return confirmBan(this)">
                        <?= View::csrf() ?>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Jail</label>
                            <select name="jail" class="form-select form-select-sm" required>
                                <?php foreach ($jails as $j): ?>
                                    <option value="<?= View::e($j['name']) ?>"><?= View::e($j['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Direccion IP</label>
                            <input type="text" name="ip" class="form-control form-control-sm" placeholder="Ej: 192.168.1.100" required
                                   pattern="^[\d\.:a-fA-F]+$" title="Introduce una IP valida">
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm w-100">
                            <i class="bi bi-shield-lock me-1"></i>Banear IP
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Whitelist -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-shield-plus me-1"></i> Whitelist (IPs que nunca se banean)</div>
                <div class="card-body">
                    <form method="POST" action="/settings/fail2ban/whitelist" class="mb-3">
                        <?= View::csrf() ?>
                        <input type="hidden" name="action" value="add">
                        <div class="input-group input-group-sm">
                            <input type="text" name="ip" class="form-control" placeholder="IP o CIDR (ej: 83.50.10.0/24)" required>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-plus-lg"></i> Anadir
                            </button>
                        </div>
                    </form>
                    <?php if (!empty($whitelist)): ?>
                        <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                            <table class="table table-sm table-hover mb-0">
                                <?php foreach ($whitelist as $wip): ?>
                                    <tr>
                                        <td><code><?= View::e($wip) ?></code></td>
                                        <td class="text-end" style="width:50px;">
                                            <?php if ($wip !== '127.0.0.1/8' && $wip !== '::1'): ?>
                                                <form method="POST" action="/settings/fail2ban/whitelist" class="d-inline">
                                                    <?= View::csrf() ?>
                                                    <input type="hidden" name="action" value="remove">
                                                    <input type="hidden" name="ip" value="<?= View::e($wip) ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar" onclick="return confirm('Eliminar <?= View::e($wip) ?> de la whitelist?')">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-secondary small">sistema</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">No hay IPs en la whitelist. Se recomienda anadir tu IP de administracion.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Jails -->
    <?php if (empty($jails)): ?>
        <div class="card">
            <div class="card-body text-muted">
                <i class="bi bi-info-circle me-1"></i> No se encontraron jails configurados.
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($jails as $jail): ?>
            <div class="card mb-3">
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
                                        <th class="text-end" style="width:200px;">Acciones</th>
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
                                                <form method="POST" action="/settings/fail2ban/whitelist" class="d-inline ms-1">
                                                    <?= View::csrf() ?>
                                                    <input type="hidden" name="action" value="add">
                                                    <input type="hidden" name="ip" value="<?= View::e($ip) ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Desbanear y anadir a whitelist" onclick="return confirm('Anadir <?= View::e($ip) ?> a la whitelist?')">
                                                        <i class="bi bi-shield-plus me-1"></i>Whitelist
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
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
    function confirmBan(form) {
        var ip = form.querySelector('[name="ip"]').value;
        var jail = form.querySelector('[name="jail"]').value;
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Banear IP',
                html: '¿Seguro que quieres banear <strong>' + ip + '</strong> en el jail <strong>' + jail + '</strong>?<br><small class="text-muted">La IP sera bloqueada inmediatamente.</small>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Si, banear',
                cancelButtonText: 'Cancelar',
                background: '#1e1e2e',
                color: '#cdd6f4',
                confirmButtonColor: '#f38ba8',
                cancelButtonColor: '#585b70',
            }).then(function(result) {
                if (result.isConfirmed) {
                    form.onsubmit = null;
                    form.submit();
                }
            });
            return false;
        }
        return confirm('¿Banear ' + ip + ' en jail ' + jail + '?');
    }

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
