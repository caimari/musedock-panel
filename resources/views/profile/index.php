<?php use MuseDockPanel\View; ?>

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
</script>
