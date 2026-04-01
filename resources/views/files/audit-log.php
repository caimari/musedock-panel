<?php
use MuseDockPanel\View;

$accountId = $account['id'];
$pag = $pagination;
$f = $filters;

$actionLabels = [
    'list' => 'Listar', 'read' => 'Leer', 'write' => 'Escribir', 'delete' => 'Eliminar',
    'rename' => 'Renombrar', 'mkdir' => 'Crear carpeta', 'chmod' => 'Permisos',
    'upload' => 'Subir', 'download' => 'Descargar', 'write_mode_activate' => 'Activar edicion',
];
$actionColors = [
    'list' => '#94a3b8', 'read' => '#38bdf8', 'write' => '#fbbf24', 'delete' => '#ef4444',
    'rename' => '#a78bfa', 'mkdir' => '#22c55e', 'chmod' => '#f97316',
    'upload' => '#0891b2', 'download' => '#0891b2', 'write_mode_activate' => '#fbbf24',
];
$basisLabels = [
    'contract_execution' => 'Prestacion servicio',
    'support_request' => 'Soporte tecnico',
    'security_incident' => 'Seguridad',
    'maintenance' => 'Mantenimiento',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="/accounts/<?= $accountId ?>" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-arrow-left me-1"></i>Cuenta</a>
        <a href="/accounts/<?= $accountId ?>/files" class="btn btn-outline-light btn-sm"><i class="bi bi-folder me-1"></i>Archivos</a>
    </div>
    <a href="/accounts/<?= $accountId ?>/audit-log/export" class="btn btn-sm" style="border:1px solid #22c55e;color:#22c55e;font-size:0.75rem;">
        <i class="bi bi-download me-1"></i>Exportar CSV (RGPD Art. 15)
    </a>
</div>

<div class="mb-2" style="font-size:0.7rem;color:#64748b;">
    <i class="bi bi-shield-check me-1"></i>Registro inmutable de accesos a archivos — retencion 2 anos (RGPD Art. 30)
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small text-muted mb-0" style="font-size:0.7rem;">Accion</label>
                <select name="action" class="form-select form-select-sm" style="width:140px;background:#0f172a;border-color:#334155;color:#e2e8f0;font-size:0.8rem;">
                    <option value="">Todas</option>
                    <?php foreach ($actionLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($f['action'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-0" style="font-size:0.7rem;">Admin</label>
                <select name="admin_id" class="form-select form-select-sm" style="width:140px;background:#0f172a;border-color:#334155;color:#e2e8f0;font-size:0.8rem;">
                    <option value="">Todos</option>
                    <?php foreach ($admins as $a): ?>
                    <option value="<?= $a['admin_id'] ?>" <?= ($f['admin_id'] ?? '') == $a['admin_id'] ? 'selected' : '' ?>><?= View::e($a['admin_username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small text-muted mb-0" style="font-size:0.7rem;">Desde</label>
                <input type="date" name="date_from" value="<?= View::e($f['date_from'] ?? '') ?>" class="form-control form-control-sm" style="width:140px;background:#0f172a;border-color:#334155;color:#e2e8f0;font-size:0.8rem;">
            </div>
            <div>
                <label class="form-label small text-muted mb-0" style="font-size:0.7rem;">Hasta</label>
                <input type="date" name="date_to" value="<?= View::e($f['date_to'] ?? '') ?>" class="form-control form-control-sm" style="width:140px;background:#0f172a;border-color:#334155;color:#e2e8f0;font-size:0.8rem;">
            </div>
            <button type="submit" class="btn btn-sm btn-primary" style="font-size:0.8rem;"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            <a href="/accounts/<?= $accountId ?>/audit-log" class="btn btn-sm btn-outline-light" style="font-size:0.8rem;">Reset</a>
        </form>
    </div>
</div>

<!-- Log table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0" style="font-size:0.8rem;">
            <thead>
                <tr>
                    <th class="ps-3">Fecha</th>
                    <th>Admin</th>
                    <th>Accion</th>
                    <th>Path</th>
                    <th>IP</th>
                    <th>Base legal</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Sin registros</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="ps-3 text-muted text-nowrap"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= View::e($log['admin_username']) ?></td>
                    <td>
                        <?php $ac = $log['action']; $color = $actionColors[$ac] ?? '#94a3b8'; ?>
                        <span class="badge" style="background:<?= $color ?>20;color:<?= $color ?>;font-size:0.7rem;"><?= $actionLabels[$ac] ?? $ac ?></span>
                    </td>
                    <td><code style="font-size:0.7rem;word-break:break-all;"><?= View::e($log['path']) ?></code></td>
                    <td class="text-muted" style="font-size:0.75rem;"><?= View::e($log['admin_ip']) ?></td>
                    <td>
                        <span class="text-muted" style="font-size:0.7rem;"><?= $basisLabels[$log['legal_basis']] ?? $log['legal_basis'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pag['pages'] > 1): ?>
<div class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-muted" style="font-size:0.75rem;"><?= $pag['total'] ?> registros — pagina <?= $pag['page'] ?> de <?= $pag['pages'] ?></span>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($pag['page'] > 1): ?>
            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($f, ['page' => $pag['page'] - 1])) ?>" style="background:#1e293b;border-color:#334155;color:#38bdf8;">&laquo;</a></li>
            <?php endif; ?>
            <?php for ($p = max(1, $pag['page'] - 2); $p <= min($pag['pages'], $pag['page'] + 2); $p++): ?>
            <li class="page-item <?= $p === $pag['page'] ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($f, ['page' => $p])) ?>" style="background:<?= $p === $pag['page'] ? '#38bdf8' : '#1e293b' ?>;border-color:#334155;color:<?= $p === $pag['page'] ? '#0f172a' : '#38bdf8' ?>;"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($pag['page'] < $pag['pages']): ?>
            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($f, ['page' => $pag['page'] + 1])) ?>" style="background:#1e293b;border-color:#334155;color:#38bdf8;">&raquo;</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>
