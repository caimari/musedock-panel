<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$installed): ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-shield-exclamation me-1"></i> Fail2Ban no instalado</div>
        <div class="card-body">
            <p class="text-muted mb-3">Fail2Ban no esta instalado en este servidor. Protege contra ataques de fuerza bruta baneando IPs sospechosas automaticamente.</p>
            <ul class="text-muted small mb-3">
                <li>Proteccion para panel admin, portal de clientes y WordPress</li>
                <li>Banea IPs tras multiples intentos fallidos de login</li>
                <li>Configurable: umbrales, tiempos de ban, whitelist</li>
            </ul>
            <form method="post" action="/settings/fail2ban/install" id="form-install-f2b">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-primary" id="btn-install-f2b" onclick="confirmInstallF2b()">
                    <i class="bi bi-download me-1"></i>Instalar Fail2Ban
                </button>
            </form>
        </div>
    </div>
    <script>
    function confirmInstallF2b() {
        var S = typeof Swal !== 'undefined' ? Swal : (typeof SwalDark !== 'undefined' ? SwalDark : null);
        if (!S) {
            if (confirm('Se instalara fail2ban y se configuraran los jails de proteccion. Continuar?')) {
                var btnFallback = document.getElementById('btn-install-f2b');
                btnFallback.disabled = true;
                btnFallback.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Instalando Fail2Ban...';
                document.getElementById('form-install-f2b').submit();
            }
            return;
        }
        S.fire({
            title: 'Instalar Fail2Ban',
            html: '<p>Se instalara <code>fail2ban</code> y se configuraran los jails de proteccion.</p><p class="text-muted small">Esto puede tardar 1-2 minutos.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-download me-1"></i>Instalar',
            cancelButtonText: 'Cancelar',
            background: '#ffffff',
            color: '#111827',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
        }).then(function(r) {
            if (!r.isConfirmed) return;
            var btn = document.getElementById('btn-install-f2b');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Instalando Fail2Ban...';
            document.getElementById('form-install-f2b').submit();
        });
    }
    </script>
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
                                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Eliminar" onclick="return confirmWhitelistRemove(this.form, '<?= View::e($wip) ?>')">
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
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <i class="bi bi-shield-exclamation text-warning" style="font-size:1.5rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <p class="mb-1">No se encontraron jails configurados.</p>
                        <p class="text-muted small mb-0">Los jails protegen contra fuerza bruta en: panel admin, portal de clientes y WordPress sites.</p>
                    </div>
                    <form method="POST" action="/settings/fail2ban/setup-jails" id="form-setup-jails">
                        <?= View::csrf() ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btn-setup-jails" onclick="confirmSetupJails()">
                            <i class="bi bi-shield-plus me-1"></i>Configurar Jails
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <script>
        function confirmSetupJails() {
            var S = typeof Swal !== 'undefined' ? Swal : (typeof SwalDark !== 'undefined' ? SwalDark : null);
            if (!S) {
                if (confirm('Se configuraran jails de proteccion para panel, portal y WordPress. Continuar?')) {
                    var btnFallback = document.getElementById('btn-setup-jails');
                    btnFallback.disabled = true;
                    btnFallback.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Configurando jails...';
                    document.getElementById('form-setup-jails').submit();
                }
                return;
            }
            S.fire({
                title: 'Configurar Jails de proteccion?',
                html: '<div class="text-start"><p>Se crearan los siguientes jails:</p>' +
                    '<ul class="small">' +
                    '<li><strong>musedock-panel</strong> — Protege login del panel admin (5 intentos / 10 min → ban 1h)</li>' +
                    '<li><strong>musedock-portal</strong> — Protege login del portal de clientes (10 intentos / 10 min → ban 30 min)</li>' +
                    '<li><strong>musedock-wordpress</strong> — Protege wp-login.php y xmlrpc.php de todos los WordPress (10 intentos / 5 min → ban 1h)</li>' +
                    '</ul>' +
                    '<p class="text-muted small mb-0">Se crearan los archivos de log necesarios y se configurara Caddy para registrar accesos.</p></div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-shield-plus me-1"></i>Configurar',
                cancelButtonText: 'Cancelar',
                background: '#ffffff',
                color: '#111827',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
            }).then(function(r) {
                if (!r.isConfirmed) return;
                var btn = document.getElementById('btn-setup-jails');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Configurando jails...';
                document.getElementById('form-setup-jails').submit();
            });
        }
        </script>
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
                                        <th class="text-end" style="width:260px; white-space:nowrap;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jail['banned_ips'] as $ip): ?>
                                        <tr>
                                            <td><code><?= View::e($ip) ?></code></td>
                                            <td class="text-end align-middle">
                                                <div class="d-inline-flex flex-nowrap align-items-center gap-1">
                                                    <form method="POST" action="/settings/fail2ban/unban" class="d-inline-block mb-0" onsubmit="return confirmUnban(this, '<?= View::e($ip) ?>', '<?= View::e($jail['name']) ?>')">
                                                        <?= View::csrf() ?>
                                                        <input type="hidden" name="jail" value="<?= View::e($jail['name']) ?>">
                                                        <input type="hidden" name="ip" value="<?= View::e($ip) ?>">
                                                        <button type="submit" class="btn btn-outline-warning btn-sm text-nowrap">
                                                            <i class="bi bi-unlock me-1"></i>Desbanear
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="/settings/fail2ban/whitelist" class="d-inline-block mb-0">
                                                        <?= View::csrf() ?>
                                                        <input type="hidden" name="action" value="add">
                                                        <input type="hidden" name="ip" value="<?= View::e($ip) ?>">
                                                        <button type="submit" class="btn btn-outline-success btn-sm text-nowrap" title="Desbanear y anadir a whitelist" onclick="return confirmWhitelistAddFromBan(this.form, '<?= View::e($ip) ?>')">
                                                            <i class="bi bi-shield-plus me-1"></i>Whitelist
                                                        </button>
                                                    </form>
                                                </div>
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
    function getFail2banSwal() {
        if (typeof Swal !== 'undefined') return Swal;
        if (typeof SwalDark !== 'undefined') return SwalDark;
        return null;
    }

    function fireFail2banSwal(options) {
        const S = getFail2banSwal();
        if (!S) {
            if (options && options.showCancelButton) {
                const msg = options.text || 'Confirmar accion?';
                return Promise.resolve({ isConfirmed: confirm(msg) });
            }
            return Promise.resolve({ isConfirmed: true });
        }
        const userDidOpen = options && typeof options.didOpen === 'function' ? options.didOpen : null;
        const defaults = {
            background: '#1e293b',
            color: '#f8fafc',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
            didOpen: function(popup) {
                const title = popup.querySelector('.swal2-title');
                const html = popup.querySelector('.swal2-html-container');
                if (title) {
                    title.style.setProperty('color', '#f8fafc', 'important');
                }
                if (html) {
                    html.style.setProperty('color', '#f8fafc', 'important');
                    html.style.setProperty('opacity', '1', 'important');
                }
                popup.querySelectorAll('.text-muted, small').forEach(function(el) {
                    el.style.setProperty('color', '#e5e7eb', 'important');
                });
                if (userDidOpen) userDidOpen(popup);
            },
        };
        return S.fire(Object.assign({}, defaults, options || {}, { didOpen: defaults.didOpen }));
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s || '');
        return d.innerHTML;
    }

    function confirmBan(form) {
        var ip = form.querySelector('[name="ip"]').value;
        var jail = form.querySelector('[name="jail"]').value;
        fireFail2banSwal({
            title: 'Banear IP',
            html: 'Seguro que quieres banear <strong>' + escHtml(ip) + '</strong> en el jail <strong>' + escHtml(jail) + '</strong>?<br><small style="color:#6b7280;">La IP se bloqueara inmediatamente.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, banear',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }

    function confirmUnban(form, ip, jail) {
        fireFail2banSwal({
            title: 'Desbanear IP',
            html: 'Seguro que quieres desbanear <strong>' + escHtml(ip) + '</strong> del jail <strong>' + escHtml(jail) + '</strong>?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Si, desbanear',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#16a34a',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }

    function confirmWhitelistRemove(form, ip) {
        fireFail2banSwal({
            title: 'Eliminar de whitelist',
            html: 'Seguro que quieres eliminar <strong>' + escHtml(ip) + '</strong> de la whitelist?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626',
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
        return false;
    }

    function confirmWhitelistAddFromBan(form, ip) {
        fireFail2banSwal({
            title: 'Anadir a whitelist',
            html: 'Anadir <strong>' + escHtml(ip) + '</strong> a la whitelist?<br><small style="color:#6b7280;">No se volvera a banear en ningun jail.</small>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Anadir',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#0ea5e9',
        }).then(function(result) {
            if (result.isConfirmed) form.submit();
        });
        return false;
    }

    function showBannedIps(jailName, jailLabel, ips) {
        if (!ips || ips.length === 0) {
            fireFail2banSwal({ title: jailLabel, text: 'No hay IPs baneadas actualmente', icon: 'info' });
            return;
        }

        let html = '<div class="text-start"><table class="table table-sm table-striped table-bordered mb-0">';
        html += '<thead><tr><th>IP</th><th class="text-end">Acciones</th></tr></thead><tbody>';
        ips.forEach(function(ip) {
            html += '<tr><td><code>' + escHtml(ip) + '</code></td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-outline-success btn-sm py-0 px-2 me-1" onclick="quickUnban(' + JSON.stringify(jailName) + ', ' + JSON.stringify(ip) + ')" title="Desbanear"><i class="bi bi-unlock"></i></button>';
            html += '<button class="btn btn-outline-info btn-sm py-0 px-2" onclick="quickWhitelist(' + JSON.stringify(ip) + ')" title="Anadir a whitelist"><i class="bi bi-shield-check"></i></button>';
            html += '</td></tr>';
        });
        html += '</tbody></table></div>';

        fireFail2banSwal({
            title: '<i class="bi bi-shield-exclamation me-2"></i>' + escHtml(jailLabel),
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: 560,
        });
    }

    function quickUnban(jail, ip) {
        fireFail2banSwal({
            title: 'Desbanear IP?',
            html: '<p>Desbanear <code>' + escHtml(ip) + '</code> del jail <strong>' + escHtml(jail) + '</strong>?</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Desbanear',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#16a34a',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/fail2ban/unban';
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            form.innerHTML = '<input type="hidden" name="_csrf_token" value="' + escHtml(csrf) + '">'
                + '<input type="hidden" name="jail" value="' + escHtml(jail) + '">'
                + '<input type="hidden" name="ip" value="' + escHtml(ip) + '">';
            document.body.appendChild(form);
            form.submit();
        });
    }

    function quickWhitelist(ip) {
        fireFail2banSwal({
            title: 'Anadir a whitelist?',
            html: '<p>La IP <code>' + escHtml(ip) + '</code> no se volvera a banear en ningun jail.</p>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Anadir',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#0ea5e9',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/fail2ban/whitelist';
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            form.innerHTML = '<input type="hidden" name="_csrf_token" value="' + escHtml(csrf) + '">'
                + '<input type="hidden" name="action" value="add">'
                + '<input type="hidden" name="ip" value="' + escHtml(ip) + '">';
            document.body.appendChild(form);
            form.submit();
        });
    }

    function confirmToggleJail(jailName, jailLabel) {
        fireFail2banSwal({
            title: 'Desactivar ' + escHtml(jailLabel) + '?',
            html: '<p>La proteccion <strong>' + escHtml(jailLabel) + '</strong> se desactivara.</p>' +
                  '<p style="color:#92400e;font-size:0.85rem;"><i class="bi bi-exclamation-triangle me-1"></i>Los sitios quedaran sin proteccion contra fuerza bruta hasta que se reactive.</p>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-power me-1"></i>Desactivar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626',
        }).then(function(result) {
            if (!result.isConfirmed) return;
            const csrf = document.querySelector('input[name=_csrf_token]').value;
            fetch('/settings/fail2ban/toggle-jail', {
                method: 'POST',
                body: new URLSearchParams({ _csrf_token: csrf, jail: jailName, action: 'disable' }),
            })
            .then(r => r.json())
            .then(data => {
                fireFail2banSwal({
                    title: data.ok ? 'Desactivado' : 'Error',
                    text: data.ok ? jailLabel + ' desactivado temporalmente. Recarga para ver el estado.' : (data.error || 'Error desconocido'),
                    icon: data.ok ? 'success' : 'error',
                    timer: 2000,
                }).then(() => { if (data.ok) location.reload(); });
            });
        });
    }
    </script>
<?php endif; ?>
