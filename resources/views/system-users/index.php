<?php use MuseDockPanel\View; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <small class="text-muted">
            <?php
            $totalRegular = count(array_filter($users, fn($u) => $u['type'] === 'regular'));
            $totalSystem = count(array_filter($users, fn($u) => $u['type'] === 'system'));
            ?>
            <span class="me-3"><i class="bi bi-person-fill me-1"></i> <?= $totalRegular ?> usuarios regulares</span>
            <span><i class="bi bi-gear-fill me-1"></i> <?= $totalSystem ?> usuarios de sistema</span>
        </small>
    </div>
    <div>
        <button class="btn btn-outline-light btn-sm" onclick="toggleSystemUsers()">
            <i class="bi bi-eye me-1"></i> <span id="toggle-text">Mostrar sistema</span>
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3" style="width:50px">UID</th>
                    <th>Usuario</th>
                    <th>Grupos</th>
                    <th>Home</th>
                    <th>Shell</th>
                    <th style="width:80px">Tipo</th>
                    <th style="width:70px">Login</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="user-row <?= $u['type'] === 'system' ? 'system-user-row d-none' : '' ?>" data-type="<?= $u['type'] ?>">
                    <td class="ps-3 font-monospace"><?= $u['uid'] ?></td>
                    <td>
                        <span class="fw-semibold <?= $u['is_root'] ? 'text-danger' : ($u['type'] === 'regular' ? 'text-info' : 'text-muted') ?>">
                            <?= View::e($u['username']) ?>
                        </span>
                        <?php if ($u['gecos']): ?>
                            <small class="text-muted ms-1">(<?= View::e($u['gecos']) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach ($u['groups'] as $g): ?>
                            <span class="badge" style="background:rgba(100,116,139,0.3);color:#94a3b8;font-size:0.75em;"><?= View::e($g) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="font-monospace" style="font-size:0.85em;"><?= View::e($u['home']) ?></td>
                    <td>
                        <?php
                        $shellName = basename($u['shell']);
                        $shellColor = match($shellName) {
                            'bash' => 'text-success',
                            'sh' => 'text-warning',
                            'nologin', 'false' => 'text-muted',
                            default => 'text-info',
                        };
                        ?>
                        <span class="font-monospace <?= $shellColor ?>" style="font-size:0.85em;"><?= View::e($shellName) ?></span>
                    </td>
                    <td>
                        <?php if ($u['type'] === 'root'): ?>
                            <span class="badge" style="background:rgba(239,68,68,0.2);color:#ef4444;">root</span>
                        <?php elseif ($u['type'] === 'regular'): ?>
                            <span class="badge" style="background:rgba(34,197,94,0.2);color:#22c55e;">regular</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(100,116,139,0.2);color:#64748b;">sistema</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($u['can_login']): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                        <?php else: ?>
                            <i class="bi bi-x-circle text-muted"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($users): ?>
<div class="mt-2">
    <small class="text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Esta seccion es de solo lectura. Los usuarios se gestionan automaticamente al crear hostings.
        <?php if (count(array_filter($users, fn($u) => $u['is_root'])) > 0): ?>
            El usuario root es visible pero no se puede modificar desde el panel.
        <?php endif; ?>
    </small>
</div>
<?php endif; ?>

<script>
let showSystem = false;
function toggleSystemUsers() {
    showSystem = !showSystem;
    document.querySelectorAll('.system-user-row').forEach(function(row) {
        row.classList.toggle('d-none', !showSystem);
    });
    document.getElementById('toggle-text').textContent = showSystem ? 'Ocultar sistema' : 'Mostrar sistema';
}
</script>
