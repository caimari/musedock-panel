<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <a href="/customers/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Customer</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-people" style="font-size: 2rem;"></i>
                <p class="mt-2">No customers yet.</p>
                <a href="/customers/create" class="btn btn-primary btn-sm">Add first customer</a>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Accounts</th>
                        <th>Disk Used</th>
                        <th>Portal</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/customers/<?= $c['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($c['name']) ?></a>
                        </td>
                        <td><?= View::e($c['email']) ?></td>
                        <td><?= View::e($c['company'] ?? '-') ?></td>
                        <td><?= $c['account_count'] ?></td>
                        <td><?= $c['total_disk_used'] ?> MB</td>
                        <td>
                            <?php $hasPortal = !empty($c['password_hash']); ?>
                            <?php if ($hasPortal): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.65rem;"><i class="bi bi-check-circle"></i></span>
                            <?php else: ?>
                                <button type="button" class="btn py-0 px-1" style="font-size:0.65rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= $c['id'] ?>, '<?= View::e(addslashes($c['name'])) ?>', '<?= View::e($c['email']) ?>', false)">
                                    <i class="bi bi-send"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $c['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $c['status'] ?>
                            </span>
                        </td>
                        <td>
                            <a href="/customers/<?= $c['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function sendPortalInvitation(customerId, name, email, hasAccess) {
    Swal.fire({
        title: hasAccess
            ? '<i class="bi bi-arrow-clockwise me-2" style="color:#a855f7;"></i>Reset password'
            : '<i class="bi bi-send me-2" style="color:#a855f7;"></i>Invitar al Portal',
        html: '<p style="color:#e2e8f0;">' + (hasAccess
            ? 'Se enviara un link para que <strong>' + name + '</strong> cree una nueva contraseña.'
            : 'Se enviara una invitacion a <strong>' + name + '</strong> para acceder al portal.') + '</p>' +
              '<p style="color:#64748b;font-size:0.8rem;"><i class="bi bi-envelope me-1"></i>' + email + '</p>' +
              '<p style="color:#94a3b8;font-size:0.78rem;margin-top:8px;">El link caduca en 48 horas.</p>',
        background: '#0f172a', color: '#e2e8f0',
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
