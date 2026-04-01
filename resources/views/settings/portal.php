<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Portal Status Card -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2" style="color:#a855f7;"></i>Portal de Clientes</span>
        <?php if ($portalInstalled): ?>
            <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;">
                <i class="bi bi-check-circle me-1"></i>Instalado v<?= View::e($portalVersion) ?>
            </span>
        <?php else: ?>
            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;">
                <i class="bi bi-exclamation-triangle me-1"></i>No instalado
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$portalInstalled): ?>
            <div class="text-center py-4">
                <i class="bi bi-people" style="font-size:3rem;color:#a855f7;opacity:0.5;"></i>
                <h5 class="mt-3" style="color:#e2e8f0;">Portal de Clientes</h5>
                <p class="text-muted" style="max-width:500px;margin:0 auto;">
                    Permite a tus clientes gestionar sus hostings, archivos y bases de datos
                    desde un panel independiente y seguro.
                </p>
                <div class="mt-3">
                    <a href="https://musedock.com/portal" target="_blank" class="btn btn-sm" style="background:#a855f7;color:#fff;">
                        <i class="bi bi-cart me-1"></i>Obtener licencia
                    </a>
                    <a href="https://musedock.com/portal/docs" target="_blank" class="btn btn-outline-light btn-sm ms-2">
                        <i class="bi bi-book me-1"></i>Documentacion
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-3 text-center">
                    <div style="font-size:1.5rem;font-weight:700;color:<?= $portalServiceActive ? '#22c55e' : '#ef4444' ?>;">
                        <i class="bi bi-<?= $portalServiceActive ? 'check-circle' : 'x-circle' ?>"></i>
                    </div>
                    <small class="text-muted">Servicio <?= $portalServiceActive ? 'activo' : 'detenido' ?></small>
                </div>
                <div class="col-md-3 text-center">
                    <div style="font-size:1.5rem;font-weight:700;color:#38bdf8;"><?= View::e($portalPort) ?></div>
                    <small class="text-muted">Puerto</small>
                </div>
                <div class="col-md-3 text-center">
                    <div style="font-size:1.5rem;font-weight:700;color:#a855f7;">
                        <?= count(array_filter($customers, fn($c) => $c['has_portal_access'])) ?>
                    </div>
                    <small class="text-muted">Clientes con acceso</small>
                </div>
                <div class="col-md-3 text-center">
                    <div style="font-size:1.5rem;font-weight:700;color:<?= ($licenseStatus['active'] ?? false) ? '#22c55e' : '#fbbf24' ?>;">
                        <i class="bi bi-<?= ($licenseStatus['active'] ?? false) ? 'shield-check' : 'shield-exclamation' ?>"></i>
                    </div>
                    <small class="text-muted">Licencia <?= ($licenseStatus['active'] ?? false) ? 'activa' : 'dev mode' ?></small>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($portalInstalled): ?>

<!-- Sub-tabs for portal sections -->
<?php $portalTab = $_GET['tab'] ?? 'access'; ?>
<div class="mb-3 d-flex gap-2">
    <a href="/settings/portal?tab=access" class="btn btn-sm <?= $portalTab === 'access' ? 'btn-light' : 'btn-outline-light' ?>">
        <i class="bi bi-key me-1"></i>Acceso Clientes
    </a>
    <a href="/settings/portal?tab=appearance" class="btn btn-sm <?= $portalTab === 'appearance' ? 'btn-light' : 'btn-outline-light' ?>">
        <i class="bi bi-palette me-1"></i>Apariencia
    </a>
    <?php if ($portalServiceActive): ?>
    <a href="https://<?= preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?>:<?= View::e($portalPort) ?>/"
       target="_blank" class="btn btn-sm btn-outline-light ms-auto" style="border-color:#a855f7;color:#a855f7;">
        <i class="bi bi-box-arrow-up-right me-1"></i>Abrir portal
    </a>
    <?php endif; ?>
</div>

<?php if ($portalTab === 'access'): ?>
<!-- ============ TAB: Customer Access ============ -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-key me-2"></i>Acceso de Clientes al Portal</span>
        <div>
            <small class="text-muted me-2"><?= count($customers) ?> cliente(s)</small>
            <a href="/customers/create" class="btn btn-outline-light btn-sm py-0 px-2" style="font-size:0.75rem;">
                <i class="bi bi-plus-lg me-1"></i>Nuevo cliente
            </a>
        </div>
    </div>
    <!-- Info box -->
    <div class="card-body pb-0">
        <div class="p-2 rounded" style="background:rgba(168,85,247,0.06);border:1px solid rgba(168,85,247,0.15);">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1" style="color:#a855f7;"></i>
                <strong style="color:#a855f7;">Como funciona:</strong>
                Al hacer clic en <strong>Invitar</strong>, el cliente recibe un email con un link seguro para crear su propia contraseña.
                Tu nunca conoceras su contraseña. El link caduca en 48 horas.
                <strong>Reset password</strong> envia un nuevo link al cliente para que cambie su contraseña actual.
                <strong>Revocar</strong> elimina el acceso al portal (requiere tu contraseña de admin). Los hostings del cliente NO se eliminan.
            </small>
        </div>
    </div>

    <div class="card-body p-0 pt-2">
        <?php if (empty($customers)): ?>
            <div class="p-4 text-center text-muted">
                <p>No hay clientes registrados. Crea uno desde <a href="/customers/create" class="text-info">Customers</a>.</p>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Cliente</th>
                        <th>Email</th>
                        <th>Hostings</th>
                        <th>Acceso Portal</th>
                        <th class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $cust): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/customers/<?= $cust['id'] ?>" class="text-info text-decoration-none">
                                <?= View::e($cust['name']) ?>
                            </a>
                            <?php if ($cust['company']): ?>
                                <small class="text-muted d-block"><?= View::e($cust['company']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= View::e($cust['email']) ?></code></td>
                        <td><span class="badge bg-dark"><?= (int)$cust['account_count'] ?></span></td>
                        <td>
                            <?php if ($cust['has_portal_access']): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;">
                                    <i class="bi bi-check-circle me-1"></i>Activo
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(100,116,139,0.15);color:#64748b;">
                                    <i class="bi bi-dash-circle me-1"></i>Sin acceso
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <button type="button" class="btn btn-sm py-0 px-2 ms-1" style="font-size:0.75rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                onclick="sendInvitation(<?= $cust['id'] ?>, '<?= View::e(addslashes($cust['name'])) ?>', '<?= View::e($cust['email']) ?>', <?= $cust['has_portal_access'] ? 'true' : 'false' ?>)">
                                <i class="bi bi-<?= $cust['has_portal_access'] ? 'arrow-clockwise' : 'send' ?> me-1"></i><?= $cust['has_portal_access'] ? 'Reset password' : 'Invitar' ?>
                            </button>
                            <?php if ($cust['has_portal_access']): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 ms-1" style="font-size:0.75rem;"
                                onclick="revokePortalAccess(<?= $cust['id'] ?>, '<?= View::e(addslashes($cust['name'])) ?>')">
                                <i class="bi bi-x-circle me-1"></i>Revocar
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($portalTab === 'appearance'): ?>
<!-- ============ TAB: Appearance ============ -->
<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-palette me-2"></i>Apariencia del Portal
    </div>
    <div class="card-body">
        <form action="/settings/portal/save" method="POST">
            <?= View::csrf() ?>

            <label class="form-label small text-muted mb-2">Layout</label>
            <div class="row g-3 mb-4">
                <?php foreach ($themes as $themeId => $theme): ?>
                <div class="col-md-4">
                    <div id="theme-card-<?= $themeId ?>" class="p-3 rounded text-center"
                         style="background:<?= $themeId === $portalTheme ? 'rgba(168,85,247,0.12)' : 'rgba(255,255,255,0.02)' ?>;border:2px solid <?= $themeId === $portalTheme ? '#a855f7' : '#1e293b' ?>;cursor:pointer;transition:all 0.15s;"
                         onclick="selectTheme('<?= $themeId ?>')">
                        <input type="radio" name="portal_theme" id="theme-<?= $themeId ?>" value="<?= View::e($themeId) ?>"
                               <?= $themeId === $portalTheme ? 'checked' : '' ?> style="display:none;">
                        <i class="bi <?= View::e($theme['preview']) ?>" style="font-size:2rem;color:<?= $themeId === $portalTheme ? '#a855f7' : '#64748b' ?>;"></i>
                        <div class="mt-2" style="font-weight:600;color:#e2e8f0;"><?= View::e($theme['name']) ?></div>
                        <small class="text-muted"><?= View::e($theme['description']) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <label class="form-label small text-muted mb-2">Color del sidebar</label>
            <div class="d-flex align-items-center gap-3 mb-3">
                <input type="color" name="portal_sidebar_color" id="sidebarColorPicker" value="<?= View::e($sidebarColor) ?>"
                       style="width:50px;height:38px;border:2px solid #334155;border-radius:8px;cursor:pointer;background:transparent;padding:2px;">
                <div class="d-flex gap-2 flex-wrap">
                    <?php
                    $presets = [
                        '#4f46e5' => 'Indigo', '#7c3aed' => 'Violet', '#2563eb' => 'Blue',
                        '#0891b2' => 'Cyan', '#059669' => 'Emerald', '#d97706' => 'Amber',
                        '#dc2626' => 'Red', '#be185d' => 'Pink', '#1e293b' => 'Slate', '#171717' => 'Negro',
                    ];
                    foreach ($presets as $color => $label): ?>
                    <button type="button" title="<?= $label ?>"
                            style="width:28px;height:28px;border-radius:6px;border:2px solid <?= $color === $sidebarColor ? '#fff' : 'transparent' ?>;background:<?= $color ?>;cursor:pointer;transition:border 0.15s;"
                            onclick="document.getElementById('sidebarColorPicker').value='<?= $color ?>';document.querySelectorAll('[onclick*=sidebarColorPicker]').forEach(b=>b.style.borderColor='transparent');this.style.borderColor='#fff';">
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-sm" style="background:#a855f7;color:#fff;">
                <i class="bi bi-check-lg me-1"></i>Guardar apariencia
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function selectTheme(id) {
    document.querySelectorAll('[id^="theme-card-"]').forEach(function(el) {
        el.style.background = 'rgba(255,255,255,0.02)';
        el.style.borderColor = '#1e293b';
        el.querySelector('i').style.color = '#64748b';
    });
    var card = document.getElementById('theme-card-' + id);
    card.style.background = 'rgba(168,85,247,0.12)';
    card.style.borderColor = '#a855f7';
    card.querySelector('i').style.color = '#a855f7';
    document.getElementById('theme-' + id).checked = true;
}

function sendInvitation(customerId, name, email, hasAccess) {
    var title = hasAccess
        ? '<i class="bi bi-arrow-clockwise me-2" style="color:#a855f7;"></i>Reset password'
        : '<i class="bi bi-send me-2" style="color:#a855f7;"></i>Invitar al Portal';
    var msg = hasAccess
        ? 'Se enviara un link para que <strong>' + name + '</strong> cree una nueva contraseña.'
        : 'Se enviara una invitacion a <strong>' + name + '</strong> para acceder al portal.';

    Swal.fire({
        title: title,
        html: '<p style="color:#e2e8f0;">' + msg + '</p>' +
              '<p style="color:#64748b;font-size:0.8rem;"><i class="bi bi-envelope me-1"></i>' + email + '</p>' +
              '<p style="color:#94a3b8;font-size:0.78rem;margin-top:8px;">El cliente recibira un email con un link para crear su contraseña. El link caduca en 48 horas. Tu no conoceras su contraseña.</p>',
        background: '#0f172a',
        color: '#e2e8f0',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-send me-1"></i>Enviar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#a855f7',
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/portal/send-invitation';
            var csrf = document.querySelector('input[name=_csrf_token]');
            if (csrf) { var ci = document.createElement('input'); ci.type = 'hidden'; ci.name = '_csrf_token'; ci.value = csrf.value; form.appendChild(ci); }
            var idI = document.createElement('input'); idI.type = 'hidden'; idI.name = 'customer_id'; idI.value = customerId; form.appendChild(idI);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function revokePortalAccess(customerId, name) {
    Swal.fire({
        title: '<i class="bi bi-exclamation-triangle me-2" style="color:#ef4444;"></i>Revocar acceso',
        html: '<p style="color:#e2e8f0;">Revocar acceso al portal de <strong>' + name + '</strong>.</p>' +
              '<p style="color:#94a3b8;font-size:0.85rem;">El cliente no podra acceder al portal. Sus hostings NO se eliminan.</p>' +
              '<input type="password" id="revokeAdminPw" class="form-control form-control-sm mt-3" placeholder="Tu contraseña de administrador" ' +
              'style="background:#1e293b;border-color:#334155;color:#e2e8f0;">',
        background: '#0f172a',
        color: '#e2e8f0',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-x-circle me-1"></i>Revocar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ef4444',
        focusConfirm: false,
        preConfirm: function() {
            var pw = document.getElementById('revokeAdminPw').value;
            if (!pw) { Swal.showValidationMessage('Contraseña requerida'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/settings/portal/revoke-access';
            var csrf = document.querySelector('input[name=_csrf_token]');
            if (csrf) { var ci = document.createElement('input'); ci.type = 'hidden'; ci.name = '_csrf_token'; ci.value = csrf.value; form.appendChild(ci); }
            var f = function(n,v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i); };
            f('customer_id', customerId);
            f('admin_password', result.value);
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>
