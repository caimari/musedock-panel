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
            <div class="text-center py-4" id="portal-activate-section">
                <i class="bi bi-people" style="font-size:3rem;color:#a855f7;opacity:0.5;"></i>
                <h5 class="mt-3" style="color:#e2e8f0;">Portal de Clientes</h5>
                <p class="text-muted" style="max-width:500px;margin:0 auto;">
                    Permite a tus clientes gestionar sus hostings, archivos y bases de datos
                    desde un panel independiente y seguro.
                </p>

                <!-- License key input -->
                <div class="mt-4" style="max-width:480px;margin:0 auto;">
                    <div class="input-group">
                        <span class="input-group-text" style="background:#1e293b;border-color:#334155;color:#a855f7;">
                            <i class="bi bi-key"></i>
                        </span>
                        <input type="text" id="portal-license-key" class="form-control"
                            placeholder="MDCK-XXXX-XXXX-XXXX"
                            style="background:#1e293b;border-color:#334155;color:#e2e8f0;text-transform:uppercase;font-family:monospace;letter-spacing:1px;"
                            maxlength="19" autocomplete="off">
                        <button type="button" id="portal-activate-btn" class="btn" style="background:#a855f7;color:#fff;border-color:#a855f7;"
                            onclick="activatePortal()">
                            <i class="bi bi-download me-1"></i>Activar e instalar
                        </button>
                    </div>
                    <small class="text-muted d-block mt-2">
                        Introduce tu license key para descargar e instalar el Portal automaticamente.
                    </small>
                </div>

                <div class="mt-3">
                    <a href="https://musedock.com/portal" target="_blank" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-cart me-1"></i>Obtener licencia
                    </a>
                </div>
            </div>

            <!-- Install progress (hidden by default) -->
            <div id="portal-install-progress" style="display:none;" class="py-3">
                <div class="text-center mb-3">
                    <div class="spinner-border text-info" role="status" id="portal-spinner"></div>
                    <h6 class="mt-2" style="color:#e2e8f0;" id="portal-install-title">Instalando Portal...</h6>
                </div>
                <div class="mx-auto" style="max-width:600px;">
                    <pre id="portal-install-log" style="background:#020617;border:1px solid #1e293b;border-radius:8px;padding:12px;font-size:0.75rem;color:#94a3b8;max-height:300px;overflow-y:auto;white-space:pre-wrap;"></pre>
                </div>
                <div id="portal-install-done" style="display:none;" class="text-center mt-3">
                    <i class="bi bi-check-circle" style="font-size:2rem;color:#22c55e;"></i>
                    <p class="mt-2" style="color:#22c55e;font-weight:600;">Portal instalado correctamente!</p>
                    <a href="/settings/portal" class="btn btn-sm" style="background:#a855f7;color:#fff;">
                        <i class="bi bi-arrow-clockwise me-1"></i>Recargar pagina
                    </a>
                </div>
                <div id="portal-install-error" style="display:none;" class="text-center mt-3">
                    <i class="bi bi-exclamation-triangle" style="font-size:2rem;color:#ef4444;"></i>
                    <p class="mt-2" style="color:#ef4444;font-weight:600;">Error durante la instalacion</p>
                    <button onclick="activatePortal()" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i>Reintentar
                    </button>
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
                    <?php $licActive = ($licenseStatus['active'] ?? false); ?>
                    <div style="font-size:1.5rem;font-weight:700;color:<?= $licActive ? '#22c55e' : '#fbbf24' ?>;">
                        <i class="bi bi-<?= $licActive ? 'shield-check' : 'shield-exclamation' ?>"></i>
                    </div>
                    <small class="text-muted">Licencia <?= $licActive ? 'activa' : 'sin licencia' ?></small>
                </div>
            </div>
            <?php if (!empty($licenseStatus['license_key'])): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid #1e293b;">
                <div class="d-flex flex-wrap gap-4" style="font-size:0.8rem;">
                    <div>
                        <span class="text-muted">Key:</span>
                        <code><?= View::e($licenseStatus['license_key']) ?></code>
                    </div>
                    <?php if (!empty($licenseStatus['hostname'])): ?>
                    <div>
                        <span class="text-muted">Servidor:</span>
                        <strong style="color:#e2e8f0;"><?= View::e($licenseStatus['hostname']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($licenseStatus['max_accounts'])): ?>
                    <div>
                        <span class="text-muted">Max cuentas:</span>
                        <strong style="color:#e2e8f0;"><?= (int)$licenseStatus['max_accounts'] ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($licenseStatus['expires'])): ?>
                    <div>
                        <span class="text-muted">Expira:</span>
                        <strong style="color:<?= $licenseStatus['expires'] > time() ? '#22c55e' : '#ef4444' ?>;">
                            <?= date('d/m/Y', $licenseStatus['expires']) ?>
                        </strong>
                    </div>
                    <?php endif; ?>
                    <?php if (($licenseStatus['status'] ?? '') === 'grace'): ?>
                    <div>
                        <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;">
                            <i class="bi bi-exclamation-triangle me-1"></i>Periodo de gracia — renueva pronto
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <!-- Clave de licencia: renovar, o activarla en este servidor (p. ej. tras transferirla) sin reinstalar -->
            <form action="/settings/portal/license" method="POST" class="mt-3 pt-3 d-flex flex-wrap gap-2 align-items-center" style="border-top:1px solid #1e293b;">
                <?= View::csrf() ?>
                <span class="small text-muted"><i class="bi bi-key me-1"></i>Clave de licencia:</span>
                <input type="text" name="license_key" class="form-control form-control-sm" style="max-width:220px;font-family:monospace;text-transform:uppercase;"
                       value="<?= View::e($licenseStatus['license_key'] ?? '') ?>" placeholder="MDCK-XXXX-XXXX-XXXX" maxlength="19" autocomplete="off">
                <button type="submit" name="op" value="activate" class="btn btn-sm btn-outline-light"><i class="bi bi-check2-circle me-1"></i>Activar en este servidor</button>
                <?php if (!empty($licenseStatus['license_key'])): ?>
                <button type="submit" name="op" value="renew" class="btn btn-sm btn-outline-info"><i class="bi bi-arrow-repeat me-1"></i>Renovar ahora</button>
                <?php endif; ?>
                <small class="text-muted w-100">La licencia queda ligada a un servidor. Si estaba en otro (p. ej. tras un cambio de rol), primero hay que transferirla en el servidor de licencias y luego pulsar "Activar en este servidor".</small>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($portalInstalled): ?>

<!-- Dirección pública del portal y estado en el cluster -->
<?php $ps = $portalState ?? []; ?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-globe2 me-2"></i>Dirección del portal</div>
    <div class="card-body">
        <?php $addrLocked = ($ps['hostname'] ?? '') !== ''; ?>
        <form action="/settings/portal/address" method="POST" class="row g-2 align-items-end" id="portal-address-form">
            <?= View::csrf() ?>
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Nombre público (p. ej. portal.tudominio.com)</label>
                <input type="text" name="portal_hostname" class="form-control form-control-sm portal-addr-field" maxlength="253"
                       value="<?= View::e($ps['hostname'] ?? '') ?>" placeholder="portal.tudominio.com" <?= $addrLocked ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Puerto</label>
                <input type="number" name="portal_port" class="form-control form-control-sm portal-addr-field" min="1" max="65534"
                       value="<?= (int)($addrLocked ? ($ps['port'] ?? 443) : 443) ?>" <?= $addrLocked ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <?php if ($addrLocked): ?>
                <button type="button" class="btn btn-sm btn-outline-light" id="portal-addr-lock" title="Desbloquear para editar"
                        onclick="document.querySelectorAll('.portal-addr-field').forEach(function(f){f.readOnly=false;});document.getElementById('portal-addr-save').disabled=false;this.style.display='none';document.querySelector('.portal-addr-field').focus();">
                    <i class="bi bi-lock me-1"></i>Editar
                </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-sm" id="portal-addr-save" style="background:#a855f7;color:#fff;" <?= $addrLocked ? 'disabled' : '' ?>
                        onclick="return <?= $addrLocked ? "confirm('Cambiar la dirección del portal deja de servir la anterior. ¿Continuar?')" : 'true' ?>;">
                    <i class="bi bi-check-lg me-1"></i>Guardar y aplicar
                </button>
            </div>
        </form>
        <div class="small text-muted mt-2">
            Usa un nombre propio del portal, no el de una máquina. En un relevo el DNS de ese nombre se mueve
            con el resto y el portal sigue en la misma dirección. El nombre debe apuntar (registro A o CNAME)
            al servidor que manda. Recomendado: puerto 443 (dirección sin puerto, ya abierta al público y
            compatible con el proxy de Cloudflare). Con otro puerto hay que abrirlo en el cortafuegos de cada servidor.
        </div>
        <div class="d-flex flex-wrap gap-4 mt-3 pt-3" style="border-top:1px solid #1e293b;font-size:0.8rem;">
            <div><span class="text-muted">Este servidor:</span>
                <strong style="color:#e2e8f0;"><?= View::e(($ps['role'] ?? '') === 'slave' ? 'copia' : (($ps['role'] ?? '') === 'master' ? 'principal' : 'sin cluster')) ?><?= !empty($ps['fenced']) ? ' (apartado)' : '' ?></strong></div>
            <div><span class="text-muted">Debe servir el portal:</span>
                <strong style="color:<?= !empty($ps['should_serve']) ? '#22c55e' : '#94a3b8' ?>;"><?= !empty($ps['should_serve']) ? 'sí' : 'no (lo sirve el principal)' ?></strong></div>
            <div><span class="text-muted">Servicio:</span> <strong style="color:#e2e8f0;"><?= View::e($ps['service'] ?? '?') ?></strong></div>
            <div><span class="text-muted">Ruta en Caddy:</span>
                <strong style="color:<?= !empty($ps['route']) ? '#22c55e' : '#94a3b8' ?>;"><?= !empty($ps['route']) ? 'puesta' : 'no' ?></strong></div>
            <?php if (!empty($ps['last_sync'])): ?>
            <div><span class="text-muted">Clientes copiados del principal:</span>
                <strong style="color:#e2e8f0;"><?= View::e($ps['last_sync']['at'] ?? '') ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sub-tabs for portal sections -->
<?php $portalTab = $_GET['tab'] ?? 'access'; ?>
<style>.portal-subtab.active{background:#a855f7;border-color:#a855f7;color:#fff;}</style>
<div class="mb-3 d-flex gap-2">
    <a href="/settings/portal?tab=access" class="btn btn-sm btn-outline-light portal-subtab <?= $portalTab === 'access' ? 'active' : '' ?>" <?= $portalTab === 'access' ? 'aria-current="page"' : '' ?>>
        <i class="bi bi-key me-1"></i>Acceso Clientes
    </a>
    <a href="/settings/portal?tab=appearance" class="btn btn-sm btn-outline-light portal-subtab <?= $portalTab === 'appearance' ? 'active' : '' ?>" <?= $portalTab === 'appearance' ? 'aria-current="page"' : '' ?>>
        <i class="bi bi-palette me-1"></i>Apariencia
    </a>
    <?php if (class_exists(\MuseDockPortal\Services\TicketService::class)): ?>
    <?php $openTickets = 0; try { $openTickets = \MuseDockPortal\Services\TicketService::countOpen(); } catch (\Throwable) {} ?>
    <a href="/portal-admin/tickets" class="btn btn-sm btn-outline-light">
        <i class="bi bi-life-preserver me-1"></i>Tickets de soporte<?php if ($openTickets > 0): ?> <span class="badge bg-warning text-dark ms-1"><?= (int)$openTickets ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if ($portalServiceActive): ?>
    <a href="<?= View::e(($ps['url'] ?? '') !== '' ? $ps['url'] . '/' : 'https://' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . ':' . $portalPort . '/') ?>"
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
                                onclick="sendInvitation(<?= $cust['id'] ?>, <?= View::js($cust['name']) ?>, <?= View::js($cust['email']) ?>, <?= $cust['has_portal_access'] ? 'true' : 'false' ?>)">
                                <i class="bi bi-<?= $cust['has_portal_access'] ? 'arrow-clockwise' : 'send' ?> me-1"></i><?= $cust['has_portal_access'] ? 'Reset password' : 'Invitar' ?>
                            </button>
                            <?php if ($cust['has_portal_access']): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 ms-1" style="font-size:0.75rem;"
                                onclick="revokePortalAccess(<?= $cust['id'] ?>, <?= View::js($cust['name']) ?>)">
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

            <label class="form-label small text-muted mb-2">Acceso de los clientes: "Mantener la sesión iniciada"</label>
            <div class="mb-3">
                <?php $prd = (int)\MuseDockPanel\Settings::get('portal_session_remember_days', '30'); ?>
                <select name="portal_session_remember_days" class="form-select form-select-sm" style="max-width:260px;">
                    <?php foreach ([1 => '1 día', 7 => '7 días', 30 => '30 días', 60 => '2 meses', 90 => '3 meses', 180 => '6 meses', 365 => '1 año', 0 => 'Sin caducidad'] as $d => $lbl): ?>
                        <option value="<?= $d ?>" <?= $prd === $d ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">Si el cliente marca la casilla al entrar, su sesión dura esto aunque cierre el navegador.
                    Sin marcarla, se cierra al cerrar el navegador o tras 30 min sin actividad. Afecta a los inicios de sesión nuevos.</small>
            </div>

            <button type="submit" class="btn btn-sm" style="background:#a855f7;color:#fff;">
                <i class="bi bi-check-lg me-1"></i>Guardar apariencia
            </button>
        </form>
    </div>
</div>

<?php
$favB64 = (string)\MuseDockPanel\Settings::get('portal_favicon', '');
$favType = (string)\MuseDockPanel\Settings::get('portal_favicon_type', '');
$favDefault = '/opt/musedock-portal/public/favicon.svg';
$favPreview = $favB64 !== '' && in_array($favType, ['image/svg+xml', 'image/png', 'image/x-icon'], true)
    ? "data:{$favType};base64,{$favB64}"
    : (is_file($favDefault) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($favDefault)) : '');
?>
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-app-indicator me-2"></i>Favicon del portal</div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <?php if ($favPreview !== ''): ?>
                <img src="<?= View::e($favPreview) ?>" alt="Favicon actual" width="48" height="48" style="border-radius:8px;background:#0f172a;padding:4px;">
            <?php endif; ?>
            <div class="small text-muted">
                <?= $favB64 !== '' ? 'Favicon propio (' . View::e($favType) . ', ' . (int)round(strlen(base64_decode($favB64)) / 1024, 1) . ' KB).' : 'Se usa el favicon por defecto del portal.' ?><br>
                Sale en la pestaña del navegador en todas las páginas del portal, también en el login.
            </div>
        </div>
        <form action="/settings/portal/favicon" method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
            <?= View::csrf() ?>
            <input type="file" name="favicon" accept=".svg,.png,.ico,image/svg+xml,image/png,image/x-icon" class="form-control form-control-sm" style="max-width:340px;" required>
            <button type="submit" class="btn btn-sm" style="background:#a855f7;color:#fff;"><i class="bi bi-upload me-1"></i>Subir</button>
        </form>
        <?php if ($favB64 !== ''): ?>
        <form action="/settings/portal/favicon" method="POST" class="mt-2">
            <?= View::csrf() ?>
            <input type="hidden" name="reset" value="1">
            <button type="submit" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-counterclockwise me-1"></i>Volver al de por defecto</button>
        </form>
        <?php endif; ?>
        <small class="text-muted d-block mt-2">SVG, PNG (de 16 a 1024 px) o ICO, hasta 64 KB. Por seguridad, un SVG con scripts, eventos o enlaces se rechaza.</small>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
// Portal activation flow
function activatePortal() {
    var keyInput = document.getElementById('portal-license-key');
    var key = (keyInput ? keyInput.value.trim().toUpperCase() : '');

    if (!/^MDCK-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/.test(key)) {
        Swal.fire({
            title: 'Clave invalida',
            html: 'El formato debe ser: <code>MDCK-XXXX-XXXX-XXXX</code>',
            icon: 'warning',
            background: '#0f172a',
            color: '#e2e8f0',
            confirmButtonColor: '#a855f7',
        });
        return;
    }

    // Show progress, hide input
    document.getElementById('portal-activate-section').style.display = 'none';
    document.getElementById('portal-install-progress').style.display = '';
    document.getElementById('portal-install-done').style.display = 'none';
    document.getElementById('portal-install-error').style.display = 'none';
    document.getElementById('portal-spinner').style.display = '';
    document.getElementById('portal-install-title').textContent = 'Activando licencia e instalando Portal...';
    document.getElementById('portal-install-log').textContent = 'Iniciando...\n';

    var csrf = document.querySelector('input[name=_csrf_token]');
    var csrfVal = csrf ? csrf.value : '';

    // POST to activate
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/settings/portal/activate');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var data = JSON.parse(xhr.responseText);
        } catch(e) {
            showInstallError('Respuesta invalida del servidor');
            return;
        }
        if (!data.ok) {
            showInstallError(data.error || 'Error desconocido');
            return;
        }
        // Start polling for progress
        pollInstallStatus();
    };
    xhr.onerror = function() { showInstallError('Error de conexion'); };
    xhr.send('_csrf_token=' + encodeURIComponent(csrfVal) + '&license_key=' + encodeURIComponent(key));
}

function pollInstallStatus() {
    var logEl = document.getElementById('portal-install-log');
    var interval = setInterval(function() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/settings/portal/install-status');
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
            } catch(e) { return; }

            if (data.log) {
                logEl.textContent = data.log;
                logEl.scrollTop = logEl.scrollHeight;
            }

            if (data.status === 'done') {
                clearInterval(interval);
                document.getElementById('portal-spinner').style.display = 'none';
                document.getElementById('portal-install-title').textContent = 'Instalacion completada!';
                document.getElementById('portal-install-done').style.display = '';
            } else if (data.status === 'error' || data.status === 'timeout') {
                clearInterval(interval);
                showInstallError(data.status === 'timeout' ? 'Timeout — la instalacion tardo demasiado' : 'Error durante la instalacion');
            }
        };
        xhr.send();
    }, 2000);
}

function showInstallError(msg) {
    document.getElementById('portal-spinner').style.display = 'none';
    document.getElementById('portal-install-title').textContent = msg;
    document.getElementById('portal-install-error').style.display = '';
    // Also show the input again for retry
    document.getElementById('portal-activate-section').style.display = '';
    document.getElementById('portal-install-progress').style.display = 'none';
}

// Auto-format license key input
var keyInput = document.getElementById('portal-license-key');
if (keyInput) {
    keyInput.addEventListener('input', function() {
        var v = this.value.toUpperCase().replace(/[^A-Z2-9]/g, '');
        // Insert dashes: MDCK-XXXX-XXXX-XXXX
        if (v.length > 4) v = v.substring(0, 4) + '-' + v.substring(4);
        if (v.length > 9) v = v.substring(0, 9) + '-' + v.substring(9);
        if (v.length > 14) v = v.substring(0, 14) + '-' + v.substring(14);
        if (v.length > 19) v = v.substring(0, 19);
        this.value = v;
    });
}

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
