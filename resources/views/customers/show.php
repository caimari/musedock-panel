<?php use MuseDockPanel\View; ?>
<?php $isSlave = \MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'slave'; ?>
<?php if ($isSlave): ?>
<div class="alert d-flex align-items-center mb-3" style="background:rgba(13,202,240,0.1);border:1px solid rgba(13,202,240,0.3);color:#0dcaf0;">
    <i class="bi bi-lock me-2"></i>
    Este servidor es una copia (Slave): los clientes y su acceso al portal se gestionan en el servidor que manda y llegan aquí solos. Aquí solo se consultan.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person me-2"></i>Customer Details</span>
                <?php if (!$isSlave): ?><a href="/customers/<?= $customer['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:30%">Name</td><td><?= View::e($customer['name']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">Email</td><td><a href="mailto:<?= View::e($customer['email']) ?>" class="text-info"><?= View::e($customer['email']) ?></a></td></tr>
                    <tr><td class="ps-3 text-muted">Company</td><td><?= View::e($customer['company'] ?? '-') ?></td></tr>
                    <tr><td class="ps-3 text-muted">Phone</td><td><?= View::e($customer['phone'] ?? '-') ?></td></tr>
                    <tr><td class="ps-3 text-muted">Status</td><td><span class="badge badge-<?= $customer['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $customer['status'] ?></span></td></tr>
                    <?php if ($customer['notes']): ?>
                    <tr><td class="ps-3 text-muted">Notes</td><td><?= View::e($customer['notes']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="ps-3 text-muted">Created</td><td><?= date('d/m/Y H:i', strtotime($customer['created_at'])) ?></td></tr>
                    <tr>
                        <td class="ps-3 text-muted">Portal</td>
                        <td>
                            <?php $hasPortal = !empty($customer['password_hash']); $portalBlocked = str_starts_with((string)$customer['password_hash'], '!'); ?>
                            <?php if ($hasPortal && !$isSlave): ?>
                            <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/portal-toggle" class="d-inline float-end me-2"
                                  onsubmit="return confirm(<?= View::js($portalBlocked ? 'Permitir de nuevo el acceso al portal (con su contraseña de siempre)?' : 'Bloquear el acceso al portal? No podrá entrar y su sesión abierta se cerrará en un minuto. Su contraseña se conserva para poder desbloquearlo.') ?>)">
                                <?= View::csrf() ?><input type="hidden" name="op" value="<?= $portalBlocked ? 'unblock' : 'block' ?>">
                                <button class="btn btn-sm py-0 px-2 <?= $portalBlocked ? 'btn-outline-success' : 'btn-outline-danger' ?>" style="font-size:0.72rem;">
                                    <i class="bi bi-<?= $portalBlocked ? 'unlock' : 'lock' ?> me-1"></i><?= $portalBlocked ? 'Permitir acceso' : 'Bloquear acceso' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($portalBlocked): ?>
                                <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-lock me-1"></i>Bloqueado</span>
                            <?php elseif ($hasPortal): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Activo</span>
                                <button type="button" <?= $isSlave ? 'hidden' : '' ?> class="btn btn-sm py-0 px-2 ms-2" style="font-size:0.72rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= (int)$customer['id'] ?>, <?= View::js($customer['name']) ?>, <?= View::js($customer['email']) ?>, true)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reset password
                                </button>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(100,116,139,0.15);color:#64748b;"><i class="bi bi-dash-circle me-1"></i>Sin acceso</span>
                                <button type="button" <?= $isSlave ? 'hidden' : '' ?> class="btn btn-sm py-0 px-2 ms-2" style="font-size:0.72rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= (int)$customer['id'] ?>, <?= View::js($customer['name']) ?>, <?= View::js($customer['email']) ?>, false)">
                                    <i class="bi bi-send me-1"></i>Invitar al portal
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Customer's Hosting Accounts -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-server me-2"></i>Hosting Accounts</span>
                <?php if (!$isSlave): ?><a href="/accounts/create" class="btn btn-primary btn-sm" title="Crear un hosting nuevo"><i class="bi bi-plus-lg me-1"></i> Nuevo hosting</a><?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($accounts)): ?>
                    <div class="p-3 text-center text-muted">No hosting accounts for this customer.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Domain</th><th>User</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($accounts as $acc): ?>
                            <tr>
                                <td class="ps-3"><a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none"><?= View::e($acc['domain']) ?></a></td>
                                <td><code><?= View::e($acc['username']) ?></code></td>
                                <td><span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $acc['status'] ?></span></td>
                                <td class="text-end pe-3">
                                    <a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a>
                                    <?php if (!$isSlave): ?>
                                    <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/link" class="d-inline"
                                          onsubmit="return confirm(<?= View::js('¿Desvincular ' . $acc['domain'] . ' de este cliente? El hosting no se toca; solo deja de verlo en su portal.') ?>)">
                                        <?= View::csrf() ?><input type="hidden" name="op" value="unlink"><input type="hidden" name="kind" value="hosting"><input type="hidden" name="item_id" value="<?= (int)$acc['id'] ?>">
                                        <button class="btn btn-outline-warning btn-sm" title="Desvincular"><i class="bi bi-link-45deg"></i><i class="bi bi-x"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if (!$isSlave && !empty($freeAccounts)): ?>
                <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/link" class="d-flex gap-2 p-3 border-top" style="border-color:#1e293b!important;">
                    <?= View::csrf() ?><input type="hidden" name="kind" value="hosting">
                    <input type="search" name="item_domain" list="freeAccountsList" class="form-control form-control-sm" required style="max-width:320px;"
                           placeholder="Buscar un hosting ya creado…" autocomplete="off">
                    <datalist id="freeAccountsList"><?php foreach ($freeAccounts as $fa): ?><option value="<?= View::e($fa['domain']) ?>"><?php endforeach; ?></datalist>
                    <button class="btn btn-sm btn-outline-info"><i class="bi bi-link-45deg me-1"></i>Vincular</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dominios de correo del cliente -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-envelope me-2"></i>Dominios de correo</div>
            <div class="card-body p-0">
                <?php if (empty($mailDomains)): ?>
                    <div class="p-3 text-center text-muted">Sin dominios de correo vinculados.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0"><tbody>
                    <?php foreach ($mailDomains as $md): ?>
                        <tr><td class="ps-3"><?= View::e($md['domain']) ?></td>
                            <td class="text-end pe-3"><?php if (!$isSlave): ?>
                                <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/link" class="d-inline"
                                      onsubmit="return confirm(<?= View::js('¿Desvincular ' . $md['domain'] . ' de este cliente? El correo no se toca.') ?>)">
                                    <?= View::csrf() ?><input type="hidden" name="op" value="unlink"><input type="hidden" name="kind" value="mail"><input type="hidden" name="item_id" value="<?= (int)$md['id'] ?>">
                                    <button class="btn btn-outline-warning btn-sm" title="Desvincular"><i class="bi bi-link-45deg"></i><i class="bi bi-x"></i></button>
                                </form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <?php if (!$isSlave && !empty($freeMailDomains)): ?>
                <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/link" class="d-flex gap-2 p-3 border-top" style="border-color:#1e293b!important;">
                    <?= View::csrf() ?><input type="hidden" name="kind" value="mail">
                    <input type="search" name="item_domain" list="freeMailList" class="form-control form-control-sm" required style="max-width:320px;"
                           placeholder="Buscar un dominio de correo ya creado…" autocomplete="off">
                    <datalist id="freeMailList"><?php foreach ($freeMailDomains as $fm): ?><option value="<?= View::e($fm['domain']) ?>"><?php endforeach; ?></datalist>
                    <button class="btn btn-sm btn-outline-info"><i class="bi bi-link-45deg me-1"></i>Vincular</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isSlave): ?>
        <!-- Eliminar cliente: solo sin hostings ni dominios, con la contraseña del administrador -->
        <?php $canDelete = empty($accounts) && empty($mailDomains); ?>
        <div class="card mt-3" style="border-color:rgba(239,68,68,.35);">
            <div class="card-body d-flex flex-wrap align-items-center gap-2 small">
                <i class="bi bi-trash3 text-danger"></i>
                <?php if ($canDelete): ?>
                    <span>Este cliente no tiene hostings ni dominios de correo: se puede eliminar.</span>
                    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deleteCustomerModal">Eliminar cliente</button>
                    <div class="modal fade" id="deleteCustomerModal" tabindex="-1">
                        <div class="modal-dialog"><div class="modal-content" style="background:#1e293b;color:#e2e8f0;">
                            <form method="POST" action="/customers/<?= (int)$customer['id'] ?>/delete">
                                <?= View::csrf() ?>
                                <div class="modal-header"><h6 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>Eliminar cliente</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body small">
                                    <p>Vas a eliminar a <strong><?= View::e($customer['name']) ?></strong> (<?= View::e($customer['email']) ?>). No se puede deshacer.</p>
                                    <label class="form-label">Confirma con tu contraseña de administrador</label>
                                    <input type="password" name="admin_password" required class="form-control form-control-sm" autocomplete="current-password">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                                    <button class="btn btn-sm btn-danger">Eliminar</button>
                                </div>
                            </form>
                        </div></div>
                    </div>
                <?php else: ?>
                    <span class="text-muted">Para eliminar este cliente, primero desvincula (o elimina) sus hostings y dominios de correo.</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Accounts</span>
                    <span class="fw-semibold"><?= count($accounts) ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Total Disk</span>
                    <span class="fw-semibold"><?= array_sum(array_column($accounts, 'disk_used_mb')) ?> MB</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function sendPortalInvitation(customerId, name, email, hasAccess) {
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
              '<p style="color:#94a3b8;font-size:0.78rem;margin-top:8px;">El cliente recibira un email con un link para crear su contraseña. El link caduca en 48 horas.</p>',
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
            // El token va en la propia página: esta vista no siempre tiene otro formulario del que copiarlo.
            var ci = document.createElement('input'); ci.type = 'hidden'; ci.name = '_csrf_token'; ci.value = <?= json_encode(View::csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>; form.appendChild(ci);
            var idI = document.createElement('input'); idI.type = 'hidden'; idI.name = 'customer_id'; idI.value = customerId; form.appendChild(idI);
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>
