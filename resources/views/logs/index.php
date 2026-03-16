<?php use MuseDockPanel\View; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/logs" class="d-flex gap-2 align-items-center flex-wrap">
            <div class="input-group" style="max-width:250px;">
                <span class="input-group-text bg-dark border-secondary text-light"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Buscar..."
                       value="<?= View::e($filterSearch) ?>">
            </div>
            <select name="action" class="form-select" style="max-width:180px;">
                <option value="">Todas las acciones</option>
                <?php foreach ($actionTypes as $at): ?>
                    <option value="<?= View::e($at['action']) ?>" <?= $filterAction === $at['action'] ? 'selected' : '' ?>>
                        <?= View::e($at['action']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="admin" class="form-select" style="max-width:150px;">
                <option value="">Todos</option>
                <?php foreach ($admins as $adm): ?>
                    <option value="<?= View::e($adm['username']) ?>" <?= $filterAdmin === $adm['username'] ? 'selected' : '' ?>>
                        <?= View::e($adm['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-filter me-1"></i>Filtrar</button>
            <?php if ($filterAction !== '' || $filterAdmin !== '' || $filterSearch !== ''): ?>
                <a href="/logs" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x me-1"></i>Limpiar</a>
            <?php endif; ?>
            <span class="text-muted small ms-auto"><?= $total ?> registros</span>
        </form>
    </div>
</div>

<!-- Activity Log Table -->
<div class="card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-journal-text me-2"></i>Activity Log</span>
        <div class="d-flex gap-2">
            <!-- Clear old logs -->
            <form method="POST" action="/logs/clear" class="d-inline" onsubmit="return confirmClearOld(this)">
                <?= View::csrf() ?>
                <select name="days" class="d-none" id="clearDaysSelect">
                    <option value="7">7 dias</option>
                    <option value="30" selected>30 dias</option>
                    <option value="90">90 dias</option>
                </select>
                <button type="submit" class="btn btn-outline-warning btn-sm" title="Limpiar logs antiguos">
                    <i class="bi bi-calendar-x me-1"></i>Limpiar antiguos
                </button>
            </form>
            <!-- Clear all -->
            <form method="POST" action="/logs/clear-all" class="d-inline" id="clearAllForm">
                <?= View::csrf() ?>
                <input type="hidden" name="password" id="clearAllPassword" value="">
                <button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar todos" onclick="confirmClearAll()">
                    <i class="bi bi-trash me-1"></i>Vaciar todo
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-journal" style="font-size:2rem;"></i>
                <p class="mt-2">No hay registros de actividad<?= ($filterAction || $filterAdmin || $filterSearch) ? ' con estos filtros' : '' ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Fecha</th>
                            <th>Admin</th>
                            <th>Accion</th>
                            <th>Destino</th>
                            <th>Detalles</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="ps-3 text-nowrap">
                                <small><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small>
                            </td>
                            <td>
                                <?php
                                    $adminName = $log['admin_name'] ?? 'system';
                                    $adminBadge = $adminName === 'system' ? 'bg-secondary' : 'bg-dark';
                                ?>
                                <span class="badge <?= $adminBadge ?>"><?= View::e($adminName) ?></span>
                            </td>
                            <td>
                                <?php
                                    $action = $log['action'];
                                    $actionColor = 'bg-dark';
                                    if (str_contains($action, 'create') || str_contains($action, 'add') || str_contains($action, 'store')) $actionColor = 'bg-success';
                                    elseif (str_contains($action, 'delete') || str_contains($action, 'clear') || str_contains($action, 'kill')) $actionColor = 'bg-danger';
                                    elseif (str_contains($action, 'update') || str_contains($action, 'edit') || str_contains($action, 'save')) $actionColor = 'bg-info';
                                    elseif (str_contains($action, 'login') || str_contains($action, 'auth')) $actionColor = 'bg-warning text-dark';
                                    elseif (str_contains($action, 'error')) $actionColor = 'bg-danger';
                                ?>
                                <span class="badge <?= $actionColor ?>"><?= View::e($action) ?></span>
                            </td>
                            <td><small><?= View::e($log['target'] ?? '-') ?></small></td>
                            <td><small class="text-muted"><?= View::e(mb_strimwidth($log['details'] ?? '', 0, 80, '...')) ?></small></td>
                            <td><small class="text-muted"><?= View::e($log['ip_address'] ?? '') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm justify-content-center">
            <?php
                $queryBase = [];
                if ($filterAction !== '') $queryBase['action'] = $filterAction;
                if ($filterAdmin !== '') $queryBase['admin'] = $filterAdmin;
                if ($filterSearch !== '') $queryBase['q'] = $filterSearch;
            ?>
            <!-- Previous -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="/logs?<?= http_build_query(array_merge($queryBase, ['page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>

            <?php
                $start = max(1, $page - 3);
                $end = min($totalPages, $page + 3);
            ?>
            <?php if ($start > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="/logs?<?= http_build_query(array_merge($queryBase, ['page' => 1])) ?>">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="/logs?<?= http_build_query(array_merge($queryBase, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="/logs?<?= http_build_query(array_merge($queryBase, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                </li>
            <?php endif; ?>

            <!-- Next -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="/logs?<?= http_build_query(array_merge($queryBase, ['page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<script>
function confirmClearOld(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Limpiar logs antiguos',
            html: 'Selecciona cuantos dias de antiguedad quieres eliminar:<br><br>' +
                  '<select id="swal-days" class="swal2-select" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;padding:8px;border-radius:4px;width:200px;">' +
                  '<option value="7">Mas de 7 dias</option>' +
                  '<option value="30" selected>Mas de 30 dias</option>' +
                  '<option value="90">Mas de 90 dias</option>' +
                  '<option value="180">Mas de 180 dias</option>' +
                  '</select>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Limpiar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#585b70',
            preConfirm: function() {
                return document.getElementById('swal-days').value;
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                form.querySelector('#clearDaysSelect').value = result.value;
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Limpiar logs de mas de 30 dias?');
}

function confirmClearAll() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Eliminar todos los logs',
            html: '<p style="color:#ef4444;">Esta accion eliminara <strong>todos</strong> los registros de actividad.</p>' +
                  '<p>Escribe tu contrasena de admin para confirmar:</p>' +
                  '<input type="password" id="swal-password" class="swal2-input" style="background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Contrasena">',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar todo',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#585b70',
            preConfirm: function() {
                var pwd = document.getElementById('swal-password').value;
                if (!pwd) {
                    Swal.showValidationMessage('Debes ingresar tu contrasena');
                    return false;
                }
                return pwd;
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                document.getElementById('clearAllPassword').value = result.value;
                document.getElementById('clearAllForm').submit();
            }
        });
    }
}
</script>
