<?php use MuseDockPanel\View; ?>

<div class="mb-3">
    <a href="/accounts/<?= $account['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver a <?= View::e($account['domain']) ?></a>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <!-- Ajustes de cuenta -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-gear me-2"></i>Ajustes de la cuenta</div>
            <div class="card-body">
                <form method="POST" action="/accounts/<?= $account['id'] ?>/update">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Dominio</label>
                            <input type="text" class="form-control" value="<?= View::e($account['domain']) ?>" disabled style="opacity: 0.6;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cuota de disco</label>
                            <div class="input-group">
                                <select id="diskQuotaSelect" class="form-select" onchange="applyDiskQuota(this.value)" disabled style="opacity: 0.6;">
                                    <option value="">Personalizado</option>
                                    <option value="512">512 MB</option>
                                    <option value="1024">1 GB</option>
                                    <option value="2048">2 GB</option>
                                    <option value="4096">4 GB</option>
                                    <option value="5120">5 GB</option>
                                    <option value="10240">10 GB</option>
                                    <option value="20480">20 GB</option>
                                    <option value="51200">50 GB</option>
                                    <option value="102400">100 GB</option>
                                    <option value="0">Ilimitado</option>
                                </select>
                                <input type="number" name="disk_quota_mb" id="diskQuotaInput" class="form-control" value="<?= $account['disk_quota_mb'] ?>" min="0" disabled style="opacity: 0.6; max-width: 100px;">
                                <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;">MB</span>
                                <button type="button" class="btn btn-outline-warning btn-sm" id="btnUnlockDiskQuota" onclick="toggleDiskQuotaLock()" title="Desbloquear para editar">
                                    <i class="bi bi-lock" id="diskQuotaLockIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Versión PHP</label>
                            <?php
                            // Detect installed PHP-FPM versions
                            $phpVersions = [];
                            foreach (glob('/etc/php/*/fpm') as $fpmDir) {
                                $ver = basename(dirname($fpmDir));
                                $phpVersions[] = $ver;
                            }
                            sort($phpVersions);
                            if (empty($phpVersions)) $phpVersions = [$account['php_version']];
                            ?>
                            <div class="input-group">
                                <select name="php_version" id="phpVersionSelect" class="form-select" disabled style="opacity: 0.6;">
                                    <?php foreach ($phpVersions as $ver): ?>
                                        <option value="<?= $ver ?>" <?= $account['php_version'] === $ver ? 'selected' : '' ?>>PHP <?= $ver ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-warning btn-sm" id="btnUnlockPhp" onclick="togglePhpLock()" title="Desbloquear para editar">
                                    <i class="bi bi-lock" id="phpLockIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tipo de acceso</label>
                            <select name="shell" class="form-select">
                                <?php
                                $currentShell = $account['shell'] ?? '/usr/sbin/nologin';
                                $shells = [
                                    '/bin/bash' => 'SSH + SFTP (/bin/bash)',
                                    '/usr/sbin/nologin' => 'Solo SFTP (/usr/sbin/nologin)',
                                    '/bin/false' => 'Sin acceso (/bin/false)',
                                ];
                                foreach ($shells as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= $currentShell === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Document Root</label>
                            <?php
                            $homeDir = $account['home_dir'];
                            $docRoot = $account['document_root'];
                            $relativePath = str_replace($homeDir . '/', '', $docRoot);
                            ?>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;font-size:0.85rem;"><?= View::e($homeDir) ?>/</span>
                                <input type="text" name="document_root_relative" id="docRootInput" class="form-control" value="<?= View::e($relativePath) ?>" disabled style="opacity: 0.6;">
                                <button type="button" class="btn btn-outline-warning btn-sm" id="btnUnlockDocRoot" onclick="toggleDocRootLock()" title="Desbloquear para editar">
                                    <i class="bi bi-lock" id="docRootLockIcon"></i>
                                </button>
                            </div>
                            <small class="text-muted">Carpeta web dentro del dominio. Ej: <code>httpdocs</code>, <code>httpdocs/public</code>, <code>public_html</code></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descripción</label>
                            <textarea name="description" class="form-control" rows="3"><?= View::e($account['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="button" class="btn btn-primary" onclick="submitSettings(event)"><i class="bi bi-check-lg me-1"></i> Guardar ajustes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ajustes PHP por cuenta -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-filetype-php me-2"></i>Ajustes PHP</div>
            <div class="card-body">
                <?php if (!empty($poolFileExists)): ?>
                <form method="POST" action="/accounts/<?= $account['id'] ?>/php">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <p class="text-muted small mb-3">Configuración PHP-FPM individual para <code><?= View::e($account['username']) ?></code> (PHP <?= View::e($account['php_version']) ?>)</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">memory_limit</label>
                            <input type="text" name="memory_limit" class="form-control" value="<?= View::e($phpSettings['memory_limit'] ?? '128M') ?>" placeholder="128M">
                            <small class="text-muted">Ej: 128M, 256M, 512M</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">upload_max_filesize</label>
                            <input type="text" name="upload_max_filesize" class="form-control" value="<?= View::e($phpSettings['upload_max_filesize'] ?? '2M') ?>" placeholder="2M">
                            <small class="text-muted">Ej: 2M, 64M, 128M</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">post_max_size</label>
                            <input type="text" name="post_max_size" class="form-control" value="<?= View::e($phpSettings['post_max_size'] ?? '8M') ?>" placeholder="8M">
                            <small class="text-muted">Ej: 8M, 64M, 128M</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">max_execution_time</label>
                            <div class="input-group">
                                <input type="number" name="max_execution_time" class="form-control" value="<?= View::e($phpSettings['max_execution_time'] ?? '30') ?>" min="0" max="3600" placeholder="30">
                                <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#64748b;">seg</span>
                            </div>
                            <small class="text-muted">Ej: 30, 60, 300</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">max_input_vars</label>
                            <input type="number" name="max_input_vars" class="form-control" value="<?= View::e($phpSettings['max_input_vars'] ?? '1000') ?>" min="100" max="100000" placeholder="1000">
                            <small class="text-muted">Ej: 1000, 3000, 5000</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">open_basedir <span class="badge bg-secondary">solo lectura</span></label>
                            <input type="text" class="form-control" value="<?= View::e($phpSettings['open_basedir'] ?? '') ?>" disabled style="opacity: 0.6; font-size: 0.8rem;">
                            <small class="text-muted">Se configura automáticamente</small>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Guardar ajustes PHP</button>
                    </div>
                </form>
                <?php else: ?>
                <div class="mb-0 py-2 px-3 small rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);color:#94a3b8;">
                    <i class="bi bi-exclamation-triangle me-1" style="color:#fbbf24;"></i>
                    No se encontro el archivo de pool FPM para esta cuenta. Los ajustes PHP no estan disponibles.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Renombrar usuario del sistema -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person-gear me-2"></i>Usuario del sistema</div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3">
                        <small class="text-muted d-block">Usuario actual</small>
                        <span class="fs-5 fw-bold"><code><?= View::e($account['username']) ?></code></span>
                    </div>
                    <button type="button" class="btn btn-outline-warning btn-sm" id="btnUnlockRename" onclick="toggleRenameForm()">
                        <i class="bi bi-lock me-1" id="lockIcon"></i> <span id="lockText">Desbloquear para renombrar</span>
                    </button>
                </div>

                <div id="renameSection" style="display: none;">
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Atención:</strong> Renombrar el usuario del sistema cambiará el propietario de todos los archivos,
                        recreará el pool FPM y actualizará la ruta en Caddy. El dominio y directorio no cambian.
                    </div>
                    <form id="renameForm" method="POST" action="/accounts/<?= $account['id'] ?>/rename-user" onsubmit="return confirmRename(event)">
                    <?= \MuseDockPanel\View::csrf() ?>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Nuevo nombre de usuario</label>
                                <input type="text" name="new_username" id="newUsername" class="form-control"
                                       pattern="[a-z][a-z0-9_]{2,30}" required
                                       placeholder="ej: miusuario" title="Empieza por letra, solo a-z, 0-9 y _ (3-31 caracteres)">
                            </div>
                            <div class="col-md-6">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-arrow-repeat me-1"></i> Renombrar usuario
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Cambiar contraseña -->
        <div class="card">
            <div class="card-header"><i class="bi bi-key me-2"></i>Cambiar contraseña</div>
            <div class="card-body">
                <form method="POST" action="/accounts/<?= $account['id'] ?>/change-password">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <p class="text-muted small mb-3">Cambiar la contraseña SFTP/SSH del usuario <code><?= View::e($account['username']) ?></code></p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nueva contraseña</label>
                            <div class="input-group">
                                <input type="text" name="password" id="newPasswordField" class="form-control" required minlength="8" placeholder="Mínimo 8 caracteres">
                                <button type="button" class="btn btn-outline-light" onclick="generateEditPassword()" title="Generar contraseña">
                                    <i class="bi bi-key"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-warning"><i class="bi bi-shield-lock me-1"></i> Cambiar contraseña</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Info -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Información</div>
            <div class="card-body">
                <div class="mb-2">
                    <small class="text-muted">Directorio raíz</small><br>
                    <code><?= View::e($account['home_dir']) ?></code>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Document Root</small><br>
                    <code><?= View::e($account['document_root']) ?></code>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Socket FPM</small><br>
                    <code style="font-size: 0.75rem;"><?= View::e($account['fpm_socket'] ?? 'N/A') ?></code>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Estado</small><br>
                    <span class="badge badge-<?= $account['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $account['status'] ?></span>
                </div>
                <div>
                    <small class="text-muted">Creado</small><br>
                    <?= date('d/m/Y H:i', strtotime($account['created_at'])) ?>
                </div>
            </div>
        </div>

        <!-- Datos de conexión -->
        <div class="card">
            <div class="card-header"><i class="bi bi-plug me-2"></i>Datos de conexión</div>
            <div class="card-body">
                <?php
                $shellLabel = match($account['shell'] ?? '/usr/sbin/nologin') {
                    '/bin/bash' => '<span class="badge bg-success">SSH + SFTP</span>',
                    '/usr/sbin/nologin' => '<span class="badge bg-info">Solo SFTP</span>',
                    '/bin/false' => '<span class="badge bg-danger">Sin acceso</span>',
                    default => '<span class="badge bg-secondary">Desconocido</span>',
                };
                ?>
                <div class="mb-2">
                    <small class="text-muted">Acceso</small><br>
                    <?= $shellLabel ?>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Servidor</small><br>
                    <code><?= View::e($account['domain']) ?></code>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Puerto SSH/SFTP</small><br>
                    <code>22</code>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Usuario</small><br>
                    <code><?= View::e($account['username']) ?></code>
                </div>
                <div>
                    <small class="text-muted">Directorio</small><br>
                    <code><?= View::e($account['document_root']) ?></code>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function generateEditPassword() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    var password = '';
    for (var i = 0; i < 16; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('newPasswordField').value = password;
}

var renameUnlocked = false;

function toggleRenameForm() {
    renameUnlocked = !renameUnlocked;
    var section = document.getElementById('renameSection');
    var icon = document.getElementById('lockIcon');
    var text = document.getElementById('lockText');
    var btn = document.getElementById('btnUnlockRename');

    if (renameUnlocked) {
        section.style.display = 'block';
        icon.className = 'bi bi-unlock me-1';
        text.textContent = 'Bloquear';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-outline-secondary');
    } else {
        section.style.display = 'none';
        icon.className = 'bi bi-lock me-1';
        text.textContent = 'Desbloquear para renombrar';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-warning');
        document.getElementById('newUsername').value = '';
    }
}

var phpVersionOriginal = <?= json_encode($account['php_version']) ?>;

function togglePhpLock() {
    var select = document.getElementById('phpVersionSelect');
    var icon = document.getElementById('phpLockIcon');
    var btn = document.getElementById('btnUnlockPhp');

    if (select.disabled) {
        select.disabled = false;
        select.style.opacity = '1';
        icon.className = 'bi bi-unlock';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-outline-secondary');
    } else {
        select.disabled = true;
        select.style.opacity = '0.6';
        select.value = phpVersionOriginal;
        icon.className = 'bi bi-lock';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-warning');
    }
}

var diskQuotaOriginal = <?= (int) $account['disk_quota_mb'] ?>;

function toggleDiskQuotaLock() {
    var select = document.getElementById('diskQuotaSelect');
    var input = document.getElementById('diskQuotaInput');
    var icon = document.getElementById('diskQuotaLockIcon');
    var btn = document.getElementById('btnUnlockDiskQuota');

    if (select.disabled) {
        select.disabled = false;
        input.disabled = false;
        select.style.opacity = '1';
        input.style.opacity = '1';
        icon.className = 'bi bi-unlock';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-outline-secondary');
        // Pre-select matching option
        syncDiskQuotaSelect(input.value);
    } else {
        select.disabled = true;
        input.disabled = true;
        select.style.opacity = '0.6';
        input.style.opacity = '0.6';
        input.value = diskQuotaOriginal;
        icon.className = 'bi bi-lock';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-warning');
        syncDiskQuotaSelect(diskQuotaOriginal);
    }
}

function applyDiskQuota(val) {
    if (val !== '') {
        document.getElementById('diskQuotaInput').value = val;
    }
}

function syncDiskQuotaSelect(val) {
    var select = document.getElementById('diskQuotaSelect');
    var found = false;
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].value === String(val)) {
            select.selectedIndex = i;
            found = true;
            break;
        }
    }
    if (!found) select.selectedIndex = 0; // Personalizado
}

// Sync select when manual input changes
document.getElementById('diskQuotaInput').addEventListener('input', function() {
    syncDiskQuotaSelect(this.value);
});

var docRootOriginal = <?= json_encode($relativePath) ?>;

function toggleDocRootLock() {
    var input = document.getElementById('docRootInput');
    var icon = document.getElementById('docRootLockIcon');
    var btn = document.getElementById('btnUnlockDocRoot');

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
        input.value = docRootOriginal;
        icon.className = 'bi bi-lock';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-outline-warning');
    }
}

function submitSettings(e) {
    var docRootInput = document.getElementById('docRootInput');
    var newDocRoot = docRootInput.value.trim();
    var domain = <?= json_encode($account['domain']) ?>;
    var homeDir = <?= json_encode($account['home_dir']) ?>;

    // If document root changed and is unlocked, show confirmation
    if (!docRootInput.disabled && newDocRoot !== docRootOriginal) {
        Swal.fire({
            title: 'Cambiar Document Root?',
            html: '<div class="text-start">' +
                  '<p>Caddy actualizara la ruta web inmediatamente:</p>' +
                  '<table style="width:100%;font-size:0.9rem;">' +
                  '<tr><td style="color:#64748b;padding:4px 8px 4px 0;">Actual:</td><td><code>' + homeDir + '/' + docRootOriginal + '</code></td></tr>' +
                  '<tr><td style="color:#64748b;padding:4px 8px 4px 0;">Nuevo:</td><td><code>' + homeDir + '/' + newDocRoot + '</code></td></tr>' +
                  '</table>' +
                  '<p class="mt-2" style="color:#fbbf24;font-size:0.85rem;"><i class="bi bi-exclamation-triangle me-1"></i>Si la carpeta no existe se creara automaticamente. El sitio apuntara al nuevo directorio al instante.</p>' +
                  '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e2a03f',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Cambiar y guardar',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) {
                // Enable all disabled inputs so they submit with the form
                docRootInput.disabled = false;
                document.getElementById('diskQuotaInput').disabled = false;
                document.getElementById('phpVersionSelect').disabled = false;
                document.querySelector('form[action*="/update"]').submit();
            }
        });
        return;
    }

    // Enable disabled inputs so their values submit with the form
    docRootInput.disabled = false;
    document.getElementById('diskQuotaInput').disabled = false;
    document.getElementById('phpVersionSelect').disabled = false;
    document.querySelector('form[action*="/update"]').submit();
}

function confirmRename(e) {
    e.preventDefault();
    var newUser = document.getElementById('newUsername').value.trim();
    if (!newUser) return false;

    var oldUser = <?= json_encode($account['username']) ?>;
    var domain = <?= json_encode($account['domain']) ?>;

    Swal.fire({
        title: '¿Renombrar usuario?',
        html: '<div class="text-start">' +
              '<p>Se realizarán los siguientes cambios:</p>' +
              '<ul>' +
              '<li>Usuario Linux: <code>' + oldUser + '</code> → <code>' + newUser + '</code></li>' +
              '<li>Propietario de archivos en <code>/var/www/vhosts/' + domain + '/</code></li>' +
              '<li>Pool PHP-FPM recreado</li>' +
              '<li>Ruta Caddy actualizada</li>' +
              '</ul>' +
              '<p class="text-warning"><i class="bi bi-exclamation-triangle"></i> Esta operación puede tardar unos segundos.</p>' +
              '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e2a03f',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, renombrar',
        cancelButtonText: 'Cancelar'
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('renameForm').submit();
        }
    });

    return false;
}
</script>
