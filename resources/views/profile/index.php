<?php use MuseDockPanel\View; ?>
<?php
    $mfaEnabledRaw = $admin['mfa_enabled'] ?? false;
    $mfaEnabled = $mfaEnabledRaw === true || $mfaEnabledRaw === 1 || $mfaEnabledRaw === '1' || $mfaEnabledRaw === 't';
    $mfaSetupSecret = (string)($mfaSetupSecret ?? '');
    $mfaOtpAuthUri = (string)($mfaOtpAuthUri ?? '');
    $mfaRequiredGlobal = !empty($mfaRequiredGlobal);
    $mfaActiveAdmins = (int)($mfaActiveAdmins ?? 0);
    $mfaEnrolledAdmins = (int)($mfaEnrolledAdmins ?? 0);
?>

<div class="row g-4">
    <!-- Profile Info Card -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body text-center py-4">
                <div style="width:80px;height:80px;border-radius:50%;background:rgba(56,189,248,0.15);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                    <i class="bi bi-person-fill" style="font-size:2.5rem;color:#38bdf8;"></i>
                </div>
                <h5 class="mb-1"><?= View::e($admin['username']) ?></h5>
                <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;"><?= View::e($admin['role']) ?></span>
                <?php if ($admin['email']): ?>
                    <p class="text-muted mt-2 mb-0" style="font-size:0.85rem;"><?= View::e($admin['email']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-body border-top" style="border-color:#334155 !important;">
                <div class="d-flex justify-content-between mb-2" style="font-size:0.85rem;">
                    <span class="text-muted">Ultimo login</span>
                    <span><?= $admin['last_login_at'] ? date('d/m/Y H:i', strtotime($admin['last_login_at'])) : '—' ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2" style="font-size:0.85rem;">
                    <span class="text-muted">IP</span>
                    <span><code><?= View::e($admin['last_login_ip'] ?: '—') ?></code></span>
                </div>
                <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                    <span class="text-muted">Creado</span>
                    <span><?= date('d/m/Y', strtotime($admin['created_at'])) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Forms -->
    <div class="col-lg-8">
        <!-- Username -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person me-2"></i>Nombre de usuario</div>
            <div class="card-body">
                <form method="POST" action="/profile/username">
                    <?= View::csrf() ?>
                    <div class="row align-items-end">
                        <div class="col">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= View::e($admin['username']) ?>" required minlength="3" maxlength="50" pattern="[a-zA-Z][a-zA-Z0-9_.\-]{2,49}">
                            <div class="form-text text-muted">Letras, numeros, guiones y puntos. Minimo 3 caracteres.</div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Email -->
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-envelope me-2"></i>Email</div>
            <div class="card-body">
                <form method="POST" action="/profile/email">
                    <?= View::csrf() ?>
                    <div class="row align-items-end">
                        <div class="col">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= View::e($admin['email'] ?? '') ?>" placeholder="admin@ejemplo.com">
                            <div class="form-text text-muted">Opcional. Para notificaciones del panel.</div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Password -->
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-lock me-2"></i>Cambiar contrasena</div>
            <div class="card-body">
                <form method="POST" action="/profile/password" id="passwordForm">
                    <?= View::csrf() ?>
                    <div class="mb-3">
                        <label class="form-label">Contrasena actual</label>
                        <div class="input-group">
                            <input type="password" name="current_password" id="currentPwd" class="form-control" required>
                            <button type="button" class="btn btn-outline-light" onclick="togglePwd('currentPwd', this)"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nueva contrasena</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="newPwd" class="form-control" required minlength="8">
                            <button type="button" class="btn btn-outline-light" onclick="togglePwd('newPwd', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text text-muted">Minimo 8 caracteres.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmar nueva contrasena</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirmPwd" class="form-control" required minlength="8">
                            <button type="button" class="btn btn-outline-light" onclick="togglePwd('confirmPwd', this)"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Cambiar contrasena</button>
                </form>
            </div>
        </div>

        <!-- MFA -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-phone me-2"></i>Autenticacion MFA (TOTP)</span>
                <div class="d-flex align-items-center gap-2">
                    <a href="/docs/profile-mfa" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-journal-text me-1"></i>Guia
                    </a>
                    <?php if ($mfaEnabled): ?>
                        <span class="badge bg-success">Activa</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactiva</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-3">
                    Estado global: <?= $mfaRequiredGlobal ? '<span class="badge bg-warning text-dark">MFA obligatorio</span>' : '<span class="badge bg-secondary">opcional</span>' ?>
                    <span class="ms-2">Admins enrolados: <strong><?= $mfaEnrolledAdmins ?></strong>/<?= $mfaActiveAdmins ?></span>
                </div>

                <form method="POST" action="/profile/mfa/start" class="mb-3">
                    <?= View::csrf() ?>
                    <button type="submit" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-key me-1"></i><?= $mfaSetupSecret !== '' ? 'Generar/rotar secret' : 'Generar secret MFA' ?>
                    </button>
                </form>

                <?php if ($mfaSetupSecret !== ''): ?>
                    <div class="rounded p-3 mb-3" style="border:1px solid rgba(56,189,248,0.35);background:rgba(56,189,248,0.08);">
                        <div class="fw-semibold mb-2">1) Anade esta cuenta en tu app Authenticator</div>
                        <div class="small text-muted mb-2">Puedes copiar el secret o la URI <code>otpauth://</code> y anadirlo manualmente.</div>

                        <label class="form-label small mb-1">Secret</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control" id="mfaSecretValue" value="<?= View::e($mfaSetupSecret) ?>" readonly>
                            <button type="button" class="btn btn-outline-light" onclick="copyNow('mfaSecretValue')"><i class="bi bi-clipboard"></i></button>
                        </div>

                        <label class="form-label small mb-1">URI otpauth</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" id="mfaUriValue" value="<?= View::e($mfaOtpAuthUri) ?>" readonly>
                            <button type="button" class="btn btn-outline-light" onclick="copyNow('mfaUriValue')"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>

                    <form method="POST" action="/profile/mfa/enable" class="mb-3">
                        <?= View::csrf() ?>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Contrasena actual</label>
                                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Codigo MFA (6 digitos)</label>
                                <input type="text" name="mfa_code" class="form-control" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-check2-circle me-1"></i>Activar MFA</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($mfaEnabled): ?>
                    <div class="rounded p-3" style="border:1px solid rgba(239,68,68,0.35);background:rgba(239,68,68,0.08);">
                        <div class="fw-semibold mb-2 text-danger">Desactivar MFA</div>
                        <form method="POST" action="/profile/mfa/disable">
                            <?= View::csrf() ?>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label">Contrasena actual</label>
                                    <input type="password" name="current_password_disable" class="form-control" required autocomplete="current-password">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Codigo MFA actual</label>
                                    <input type="text" name="mfa_code_disable" class="form-control" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-outline-danger w-100" <?= $mfaRequiredGlobal ? 'disabled title="MFA obligatorio global activo"' : '' ?>>
                                        <i class="bi bi-x-circle me-1"></i>Desactivar
                                    </button>
                                </div>
                            </div>
                            <?php if ($mfaRequiredGlobal): ?>
                                <div class="small text-warning mt-2">No se puede desactivar mientras MFA obligatorio global este activo.</div>
                            <?php endif; ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const np = document.getElementById('newPwd').value;
    const cp = document.getElementById('confirmPwd').value;
    if (np !== cp) {
        e.preventDefault();
        if (typeof SwalDark !== 'undefined') {
            SwalDark.fire('Error', 'Las contrasenas no coinciden.', 'error');
        } else {
            alert('Las contrasenas no coinciden.');
        }
    }
});

function copyNow(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(el.value || '');
}
</script>
