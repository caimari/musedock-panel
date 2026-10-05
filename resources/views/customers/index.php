<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <div class="d-flex gap-2">
        <?php if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'master'): ?>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="mergePeersOpen()" title="Clientes creados en otro servidor del cluster (p. ej. el que mandaba antes)">
            <i class="bi bi-people me-1"></i> Traer clientes de otros servidores
        </button>
        <?php endif; ?>
        <?php if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') !== 'slave'): ?>
        <a href="/customers/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Customer</a>
        <?php endif; ?>
    </div>
</div>
<?php if (\MuseDockPanel\Settings::get('cluster_role', 'standalone') === 'slave'): ?>
<div class="alert d-flex align-items-center mb-3" style="background:rgba(13,202,240,0.1);border:1px solid rgba(13,202,240,0.3);color:#0dcaf0;">
    <i class="bi bi-lock me-2"></i>
    Este servidor es una copia (Slave): los clientes se gestionan en el servidor que manda y llegan aquí solos. Los que se crearon aquí cuando mandaba
    este servidor los recupera el que manda (cada 30 min, o con "Traer clientes de otros servidores" en su panel).
</div>
<?php endif; ?>

<div class="modal fade" id="mergePeersModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content" style="background:#1e293b;color:#e2e8f0;">
        <div class="modal-header"><h6 class="modal-title"><i class="bi bi-people me-2"></i>Traer clientes de otros servidores</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body small" id="mergePeersBody">Consultando los otros servidores…</div>
        <div class="modal-footer">
            <form method="POST" action="/customers/merge-peers"><?= View::csrf() ?>
                <button type="button" class="btn btn-sm btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary" id="mergePeersGo" disabled>Traerlos</button>
            </form>
        </div>
    </div></div>
</div>
<script>
function mergePeersOpen() {
    var body = document.getElementById('mergePeersBody'), go = document.getElementById('mergePeersGo');
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
    body.textContent = 'Consultando los otros servidores…'; go.disabled = true;
    new bootstrap.Modal(document.getElementById('mergePeersModal')).show();
    fetch('/customers/merge-peers', {headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { body.innerHTML = '<span class="text-danger">' + esc(d.error || 'Error') + '</span>'; return; }
        var h = '<p class="text-muted">Clientes que se crearon en otro servidor (por ejemplo, el que mandaba antes de un cambio de rol) y aquí no existen. Solo se <strong>añaden</strong>: no se cambia ni se borra nada.</p>';
        h += '<div class="mb-2"><strong>Servidores:</strong> ' + Object.keys(d.nodes).map(function (k) { return esc(k + ': ' + d.nodes[k]); }).join(' · ') + '</div>';
        h += '<div><strong>Clientes nuevos:</strong> ' + (d.customers.length ? '<ul class="mb-1">' + d.customers.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul>' : 'ninguno') + '</div>';
        h += '<div><strong>Hostings que se les asignan:</strong> ' + (d.links.length ? '<ul class="mb-0">' + d.links.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul>' : 'ninguno') + '</div>';
        body.innerHTML = h; go.disabled = !(d.customers.length || d.links.length);
    }).catch(function () { body.innerHTML = '<span class="text-danger">No se pudo consultar.</span>'; });
}
</script>

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
                            <?php if (str_starts_with((string)$c['password_hash'], '!')): ?>
                                <span class="badge" title="Acceso al portal bloqueado" style="background:rgba(239,68,68,0.15);color:#ef4444;font-size:0.65rem;"><i class="bi bi-lock"></i></span>
                            <?php elseif ($hasPortal): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.65rem;"><i class="bi bi-check-circle"></i></span>
                            <?php else: ?>
                                <button type="button" class="btn py-0 px-1" style="font-size:0.65rem;background:rgba(168,85,247,0.15);color:#a855f7;border:1px solid rgba(168,85,247,0.3);"
                                    onclick="sendPortalInvitation(<?= (int)$c['id'] ?>, <?= View::js($c['name']) ?>, <?= View::js($c['email']) ?>, false)">
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
