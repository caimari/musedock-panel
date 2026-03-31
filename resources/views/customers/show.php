<?php use MuseDockPanel\View; ?>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person me-2"></i>Customer Details</span>
                <a href="/customers/<?= $customer['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a>
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
                            <?php $hasPortal = !empty($customer['password_hash']); ?>
                            <?php if ($hasPortal): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Activo</span>
                                <button type="button" class="btn btn-sm py-0 px-2 ms-2" style="font-size:0.72rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= $customer['id'] ?>, '<?= View::e(addslashes($customer['name'])) ?>', '<?= View::e($customer['email']) ?>', true)">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reset password
                                </button>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(100,116,139,0.15);color:#64748b;"><i class="bi bi-dash-circle me-1"></i>Sin acceso</span>
                                <button type="button" class="btn btn-sm py-0 px-2 ms-2" style="font-size:0.72rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= $customer['id'] ?>, '<?= View::e(addslashes($customer['name'])) ?>', '<?= View::e($customer['email']) ?>', false)">
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
                <a href="/accounts/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Account</a>
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
                                <td><a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
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
            var csrf = document.querySelector('input[name=_csrf_token]');
            if (csrf) { var ci = document.createElement('input'); ci.type = 'hidden'; ci.name = '_csrf_token'; ci.value = csrf.value; form.appendChild(ci); }
            var idI = document.createElement('input'); idI.type = 'hidden'; idI.name = 'customer_id'; idI.value = customerId; form.appendChild(idI);
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>
