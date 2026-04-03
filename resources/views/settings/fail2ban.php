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
    <?php
    $jailLabels = [
        'musedock-panel' => 'Admin Panel',
        'musedock-portal' => 'Customer Portal',
        'musedock-wordpress' => 'WordPress Sites',
    ];
    ?>
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
                        <strong><?= View::e($jailLabels[$jail['name']] ?? $jail['name']) ?></strong>
                        <small class="text-muted ms-1">(<?= View::e($jail['name']) ?>)</small>
                        <span class="badge bg-success ms-2">Activo</span>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small"><?= $jail['currently_banned'] ?> baneadas ahora</span>
                        <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2"
                                onclick="confirmToggleJail('<?= View::e($jail['name']) ?>', '<?= View::e($jailLabels[$jail['name']] ?? $jail['name']) ?>')"
                                title="Desactivar esta proteccion temporalmente">
                            <i class="bi bi-power me-1"></i>Desactivar
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="p-2 rounded text-center" style="background:rgba(255,255,255,0.05);<?= $jail['currently_banned'] > 0 ? 'cursor:pointer;' : '' ?>"
                                 <?php if ($jail['currently_banned'] > 0): ?>
                                 role="button" onclick="showBannedIps('<?= View::e($jail['name']) ?>', '<?= View::e($jailLabels[$jail['name']] ?? $jail['name']) ?>', <?= View::e(json_encode($jail['banned_ips'])) ?>)"
                                 title="Click para ver IPs baneadas"
                                 <?php endif; ?>>
                                <div class="text-muted small">IPs baneadas ahora</div>
                                <div class="fs-4 fw-bold <?= $jail['currently_banned'] > 0 ? 'text-warning' : 'text-success' ?>">
                                    <?= $jail['currently_banned'] ?>
                                    <?php if ($jail['currently_banned'] > 0): ?><i class="bi bi-eye ms-1" style="font-size:0.7rem;"></i><?php endif; ?>
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

                    <div class="mb-3 d-flex gap-3 flex-wrap">
                        <small class="text-muted"><i class="bi bi-shield me-1"></i>Max intentos: <strong><?= $jail['maxretry'] ?? '?' ?></strong></small>
                        <small class="text-muted"><i class="bi bi-clock me-1"></i>Ventana: <strong><?= isset($jail['findtime']) ? round($jail['findtime']/60) . ' min' : '?' ?></strong></small>
                        <small class="text-muted"><i class="bi bi-hourglass me-1"></i>Ban: <strong><?= isset($jail['bantime']) ? ($jail['bantime'] >= 3600 ? round($jail['bantime']/3600) . 'h' : round($jail['bantime']/60) . ' min') : '?' ?></strong></small>
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

    function showBannedIps(jailName, jailLabel, ips) {
        const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
        if (!ips || ips.length === 0) {
            S.fire({ title: jailLabel, text: 'No hay IPs baneadas actualmente', icon: 'info' });
            return;
        }

        const csrf = document.querySelector('input[name=_csrf_token]')?.value || '';
        let html = '<div class="text-start"><table class="table table-sm table-dark mb-0">';
        html += '<thead><tr><th>IP</th><th class="text-end">Acciones</th></tr></thead><tbody>';
        ips.forEach(function(ip) {
            html += '<tr><td><code>' + ip + '</code></td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-outline-success btn-sm py-0 px-2 me-1" onclick="quickUnban(\'' + jailName + '\', \'' + ip + '\')" title="Desbanear"><i class="bi bi-unlock"></i></button>';
            html += '<button class="btn btn-outline-info btn-sm py-0 px-2" onclick="quickWhitelist(\'' + ip + '\')" title="Añadir a whitelist"><i class="bi bi-shield-check"></i></button>';
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';

        S.fire({
            title: '<i class="bi bi-shield-exclamation me-2"></i>' + jailLabel,
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: 500,
        });
    }

    function quickUnban(jail, ip) {
        const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
        S.fire({
            title: 'Desbanear IP?',
            html: '<p>Desbanear <code>' + ip + '</code> del jail <strong>' + jail + '</strong>?</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Desbanear',
            confirmButtonColor: '#22c55e',
            cancelButtonText: 'Cancelar',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/fail2ban/unban';
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            form.innerHTML = '<input type="hidden" name="_csrf_token" value="' + csrf + '">'
                + '<input type="hidden" name="jail" value="' + jail + '">'
                + '<input type="hidden" name="ip" value="' + ip + '">';
            document.body.appendChild(form);
            form.submit();
        });
    }

    function quickWhitelist(ip) {
        const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
        S.fire({
            title: 'Añadir a whitelist?',
            html: '<p>La IP <code>' + ip + '</code> nunca sera baneada en ningun jail.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Añadir',
            confirmButtonColor: '#38bdf8',
            cancelButtonText: 'Cancelar',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/fail2ban/whitelist';
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            form.innerHTML = '<input type="hidden" name="_csrf_token" value="' + csrf + '">'
                + '<input type="hidden" name="action" value="add">'
                + '<input type="hidden" name="ip" value="' + ip + '">';
            document.body.appendChild(form);
            form.submit();
        });
    }

    function confirmToggleJail(jailName, jailLabel) {
        const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
        S.fire({
            title: 'Desactivar ' + jailLabel + '?',
            html: '<p>La proteccion <strong>' + jailLabel + '</strong> se desactivara.</p>' +
                  '<p class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Los sitios quedaran sin proteccion contra fuerza bruta hasta que se reactive.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-power me-1"></i>Desactivar',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancelar',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            fetch('/settings/fail2ban/toggle-jail', {
                method: 'POST',
                body: new URLSearchParams({ _csrf_token: csrf, jail: jailName, action: 'disable' }),
            })
            .then(r => r.json())
            .then(data => {
                S.fire({
                    title: data.ok ? 'Desactivado' : 'Error',
                    text: data.ok ? jailLabel + ' desactivado temporalmente. Recarga para ver el estado.' : data.error,
                    icon: data.ok ? 'success' : 'error',
                    timer: 2000,
                }).then(() => { if (data.ok) location.reload(); });
            });
        });
    }
    </script>
<?php endif; ?>
