<?php use MuseDockPanel\View; ?>

<div class="mb-3">
    <a href="/accounts/<?= (int)$account['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver a <?= View::e($account['domain']) ?></a>
</div>

<?php if ($isSlave ?? false): ?>
<div class="alert mb-3 py-2 px-3 small d-flex align-items-center" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);color:#94a3b8;">
    <i class="bi bi-lock me-2" style="color:#38bdf8;"></i>
    <span><strong style="color:#38bdf8;">Servidor Slave</strong> — Modo solo lectura. Los cambios deben realizarse en el Master.</span>
</div>
<?php endif; ?>

<?php
$homeDir = rtrim($account['home_dir'], '/');
$subFolder = $subdomain['subdomain'];
$basePath = "{$homeDir}/{$subFolder}";
$docRoot = $subdomain['document_root'];
$relativePath = $docRoot === $basePath ? '' : str_replace($basePath . '/', '', $docRoot);
?>

<div class="row g-3">
    <div class="col-12">
        <div class="d-flex align-items-center mb-3">
            <i class="bi bi-layers text-primary me-2 fs-4"></i>
            <div>
                <h5 class="mb-0"><?= View::e($subdomain['subdomain']) ?></h5>
                <small class="text-muted">Subdominio de <?= View::e($account['domain']) ?> &middot;
                    <span class="badge badge-<?= $subdomain['status'] === 'active' ? 'active' : 'suspended' ?>"><?= View::e($subdomain['status']) ?></span>
                </small>
            </div>
        </div>

        <!-- Ajustes del subdominio -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-gear me-2"></i>Ajustes del subdominio</div>
            <div class="card-body">
                <form method="POST" action="/accounts/<?= (int)$account['id'] ?>/subdomains/<?= (int)$subdomain['id'] ?>/update">
                    <?= View::csrf() ?>
                    <fieldset <?= ($isSlave ?? false) ? 'disabled' : '' ?>>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Subdominio</label>
                            <input type="text" class="form-control" value="<?= View::e($subdomain['subdomain']) ?>" disabled style="opacity: 0.6;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cuenta principal</label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?= View::e($account['domain']) ?> (<?= View::e($account['username']) ?>)" disabled style="opacity: 0.6;">
                                <a href="/accounts/<?= (int)$account['id'] ?>/edit" class="btn btn-outline-light btn-sm" title="Editar cuenta principal">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Document Root</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;font-size:0.85rem;"><?= View::e($basePath) ?>/</span>
                                <input type="text" name="document_root_relative" id="subDocRootInput" class="form-control" value="<?= View::e($relativePath) ?>" disabled style="opacity: 0.6;" placeholder="(raíz del subdominio)">
                                <button type="button" class="btn btn-outline-warning btn-sm" id="btnUnlockSubDocRoot" onclick="toggleSubDocRootLock()" title="Desbloquear para editar">
                                    <i class="bi bi-lock" id="subDocRootLockIcon"></i>
                                </button>
                            </div>
                            <small class="text-muted">Carpeta web dentro del subdominio. Dejar vacío para usar la raíz. Ej: <code>public</code>, <code>httpdocs</code></small>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" class="btn btn-primary" onclick="submitSubdomainSettings(event)"><i class="bi bi-check-lg me-1"></i> Guardar ajustes</button>
                    </div>
                    </fieldset>
                </form>
            </div>
        </div>

        <!-- Hosting Type -->
        <?php if (!($isSlave ?? false)): ?>
        <?php
        $subHostingType = $subdomain['hosting_type'] ?? 'php';
        $subDetectedType = \MuseDockPanel\Services\SystemService::detectHostingType($subdomain['document_root']);
        $typeLabels = ['php' => 'PHP', 'spa' => 'SPA', 'static' => 'Static'];
        $typeIcons = ['php' => 'bi-filetype-php', 'spa' => 'bi-window-stack', 'static' => 'bi-file-earmark-code'];
        $typeColors = ['php' => '#a78bfa', 'spa' => '#10b981', 'static' => '#38bdf8'];
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-2 me-2"></i>Hosting Type</span>
                <?php if ($subHostingType !== $subDetectedType): ?>
                <span class="badge bg-warning text-dark" style="font-size:0.65rem;"><i class="bi bi-lightbulb me-1"></i>Detectado: <?= $typeLabels[$subDetectedType] ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body py-2">
                <div class="btn-group w-100" role="group">
                    <?php foreach (['php', 'spa', 'static'] as $t): ?>
                    <button type="button" class="btn btn-sm <?= $subHostingType === $t ? 'active' : '' ?>"
                        style="<?= $subHostingType === $t ? "background:{$typeColors[$t]};border-color:{$typeColors[$t]};color:#fff;" : "border-color:#334155;color:#94a3b8;" ?>"
                        <?= $subHostingType === $t ? 'disabled' : '' ?>
                        onclick="changeSubHostingType('<?= $t ?>')">
                        <i class="bi <?= $typeIcons[$t] ?> me-1"></i><?= $typeLabels[$t] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="small text-muted mt-2">
                    <?php if ($subHostingType === 'spa'): ?>
                        <i class="bi bi-info-circle me-1"></i>React, Vue, Angular — try_files → index.html
                    <?php elseif ($subHostingType === 'static'): ?>
                        <i class="bi bi-info-circle me-1"></i>HTML estatico — solo file_server
                    <?php else: ?>
                        <i class="bi bi-info-circle me-1"></i>PHP apps — try_files → index.php
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        function changeSubHostingType(type) {
            var labels = {php:'PHP',spa:'SPA','static':'Static'};
            SwalDark.fire({
                title: 'Cambiar a ' + labels[type] + '?',
                html: 'Se reconfigurara la ruta Caddy de <strong><?= View::e($subdomain['subdomain']) ?></strong> para servir como ' + labels[type] + '.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Cambiar',
            }).then(function(result) {
                if (!result.isConfirmed) return;
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '/accounts/<?= (int)$account['id'] ?>/subdomains/<?= (int)$subdomain['id'] ?>/hosting-type';
                form.innerHTML = '<?= View::csrf() ?><input type="hidden" name="hosting_type" value="' + type + '">';
                document.body.appendChild(form);
                form.submit();
            });
        }
        </script>
        <?php endif; ?>

        <!-- Ajustes PHP del subdominio -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-filetype-php me-2"></i>Ajustes PHP</div>
            <div class="card-body">
                <?php if ($parentPoolExists ?? false): ?>
                <form method="POST" action="/accounts/<?= (int)$account['id'] ?>/subdomains/<?= (int)$subdomain['id'] ?>/php">
                    <?= View::csrf() ?>
                    <fieldset <?= ($isSlave ?? false) ? 'disabled' : '' ?>>
                    <p class="text-muted small mb-2">
                        <i class="bi bi-info-circle me-1"></i>Configuración PHP individual para <code><?= View::e($subdomain['subdomain']) ?></code> (PHP <?= View::e($account['php_version']) ?>).
                        Se aplica mediante <code>.user.ini</code> en el document root.
                    </p>
                    <p class="text-muted small mb-3">
                        <i class="bi bi-arrow-up-circle me-1"></i>Por defecto hereda los valores de la cuenta principal (<code><?= View::e($account['username']) ?></code>).
                        <?php if (!empty($phpOverrides)): ?>
                        <span class="badge bg-info ms-1"><?= count($phpOverrides) ?> override<?= count($phpOverrides) > 1 ? 's' : '' ?> activo<?= count($phpOverrides) > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </p>
                    <div class="row g-3">
                        <?php
                        $fields = [
                            'memory_limit' => ['label' => 'memory_limit', 'hint' => 'Ej: 128M, 256M, 512M', 'type' => 'text'],
                            'upload_max_filesize' => ['label' => 'upload_max_filesize', 'hint' => 'Ej: 2M, 64M, 128M', 'type' => 'text'],
                            'post_max_size' => ['label' => 'post_max_size', 'hint' => 'Ej: 8M, 64M, 128M', 'type' => 'text'],
                            'max_execution_time' => ['label' => 'max_execution_time', 'hint' => 'Ej: 30, 60, 300', 'type' => 'number', 'suffix' => 'seg'],
                            'max_input_vars' => ['label' => 'max_input_vars', 'hint' => 'Ej: 1000, 3000, 5000', 'type' => 'number'],
                        ];
                        foreach ($fields as $key => $field):
                            $isOverridden = isset($phpOverrides[$key]);
                            $value = $phpSettings[$key] ?? '';
                        ?>
                        <div class="col-md-4">
                            <label class="form-label">
                                <?= $field['label'] ?>
                                <?php if ($isOverridden): ?>
                                <span class="badge bg-info" style="font-size:0.65rem;" title="Personalizado para este subdominio">override</span>
                                <?php endif; ?>
                            </label>
                            <?php if (!empty($field['suffix'])): ?>
                            <div class="input-group">
                                <input type="<?= $field['type'] ?>" name="<?= $key ?>" class="form-control <?= $isOverridden ? 'border-info' : '' ?>" value="<?= View::e($value) ?>" placeholder="<?= View::e($value) ?>">
                                <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;"><?= $field['suffix'] ?></span>
                            </div>
                            <?php else: ?>
                            <input type="<?= $field['type'] ?>" name="<?= $key ?>" class="form-control <?= $isOverridden ? 'border-info' : '' ?>" value="<?= View::e($value) ?>" placeholder="<?= View::e($value) ?>">
                            <?php endif; ?>
                            <small class="text-muted"><?= $field['hint'] ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Guardar ajustes PHP</button>
                        <?php if (!empty($phpOverrides)): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetToParentDefaults()" title="Eliminar overrides y volver a los valores de la cuenta principal">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar valores del dominio principal
                        </button>
                        <?php endif; ?>
                    </div>
                    </fieldset>
                </form>
                <?php else: ?>
                <div class="mb-0 py-2 px-3 small rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);color:#94a3b8;">
                    <i class="bi bi-exclamation-triangle me-1" style="color:#fbbf24;"></i>
                    No se encontró el archivo de pool FPM para la cuenta principal. Los ajustes PHP no están disponibles.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Información -->
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Información</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-sm-6 mb-1">
                        <small class="text-muted">Document Root</small><br>
                        <code class="small"><?= View::e($subdomain['document_root']) ?></code>
                    </div>
                    <div class="col-sm-6 mb-1">
                        <small class="text-muted">Base del subdominio</small><br>
                        <code class="small"><?= View::e($basePath) ?></code>
                    </div>
                    <div class="col-sm-4 mb-1">
                        <small class="text-muted">Usuario (compartido)</small><br>
                        <code><?= View::e($account['username']) ?></code>
                    </div>
                    <div class="col-sm-4 mb-1">
                        <small class="text-muted">PHP</small><br>
                        <code>PHP <?= View::e($account['php_version']) ?></code>
                    </div>
                    <div class="col-sm-4 mb-1">
                        <small class="text-muted">Creado</small><br>
                        <span class="small"><?= date('d/m/Y H:i', strtotime($subdomain['created_at'])) ?></span>
                    </div>
                    <?php if (!empty($subdomain['caddy_route_id'])): ?>
                    <div class="col-sm-6 mb-1">
                        <small class="text-muted">Caddy Route ID</small><br>
                        <code class="small"><?= View::e($subdomain['caddy_route_id']) ?></code>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6 mb-1">
                        <small class="text-muted">Socket FPM (compartido)</small><br>
                        <code class="small"><?= View::e($account['fpm_socket'] ?? 'N/A') ?></code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var subDocRootOriginal = <?= json_encode($relativePath) ?>;

function toggleSubDocRootLock() {
    var input = document.getElementById('subDocRootInput');
    var icon = document.getElementById('subDocRootLockIcon');
    var btn = document.getElementById('btnUnlockSubDocRoot');

    if (input.disabled) {
        input.disabled = false;
        input.style.opacity = '1';
        icon.className = 'bi bi-unlock';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-outline-secondary');
        input.focus();
    } else {
        input.disabled = true;
        input.style.opacity = '0.6';
        input.value = subDocRootOriginal;
        icon.className = 'bi bi-lock';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-warning');
    }
}

function submitSubdomainSettings(e) {
    var docRootInput = document.getElementById('subDocRootInput');
    var newDocRoot = docRootInput.value.trim();
    var basePath = <?= json_encode($basePath) ?>;

    if (!docRootInput.disabled && newDocRoot !== subDocRootOriginal) {
        var currentFull = basePath + (subDocRootOriginal ? '/' + subDocRootOriginal : '');
        var newFull = basePath + (newDocRoot ? '/' + newDocRoot : '');

        Swal.fire({
            title: 'Cambiar Document Root?',
            html: '<div class="text-start">' +
                  '<p>Caddy actualizará la ruta web inmediatamente:</p>' +
                  '<table style="width:100%;font-size:0.9rem;">' +
                  '<tr><td style="color:#64748b;padding:4px 8px 4px 0;">Actual:</td><td><code>' + currentFull + '</code></td></tr>' +
                  '<tr><td style="color:#64748b;padding:4px 8px 4px 0;">Nuevo:</td><td><code>' + newFull + '</code></td></tr>' +
                  '</table>' +
                  '<p class="mt-2" style="color:#fbbf24;font-size:0.85rem;"><i class="bi bi-exclamation-triangle me-1"></i>Si la carpeta no existe se creará automáticamente.</p>' +
                  '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e2a03f',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Cambiar y guardar',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) {
                docRootInput.disabled = false;
                document.querySelector('form[action*="/update"]').submit();
            }
        });
        return;
    }

    docRootInput.disabled = false;
    document.querySelector('form[action*="/update"]').submit();
}

function resetToParentDefaults() {
    Swal.fire({
        title: 'Restaurar valores del dominio principal?',
        text: 'Se eliminarán los overrides PHP de este subdominio y se usarán los valores de la cuenta principal.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#e2a03f',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Restaurar',
        cancelButtonText: 'Cancelar'
    }).then(function(result) {
        if (result.isConfirmed) {
            // Clear all PHP input values and submit
            var form = document.querySelector('form[action*="/php"]');
            form.querySelectorAll('input[type="text"], input[type="number"]').forEach(function(input) {
                input.value = '';
            });
            form.submit();
        }
    });
}
</script>
