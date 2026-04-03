<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<div class="row g-3">
    <!-- IP Restrictions -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-check me-1"></i> Restriccion de IPs</div>
            <div class="card-body">
                <form method="POST" action="/settings/security/save">
                    <?= View::csrf() ?>

                    <div class="mb-3">
                        <label class="form-label">IPs permitidas <small class="text-muted">(separadas por coma)</small></label>
                        <textarea name="allowed_ips" class="form-control" rows="4" placeholder="1.2.3.4,5.6.7.8/32"><?= View::e($allowedIps) ?></textarea>
                        <small class="text-muted">Vacio = sin restriccion (cualquier IP puede acceder al panel). Soporta IPs y rangos CIDR.</small>
                    </div>

                    <div class="alert py-2 px-3" style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);color:#fbbf24;font-size:0.85rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Cuidado: si pones una IP incorrecta puedes perder acceso al panel. Asegurate de incluir tu IP actual.
                    </div>

                    <?php
                    $currentIp = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
                    ?>
                    <div class="mb-3">
                        <small class="text-muted">Tu IP actual: <strong><?= View::e($currentIp) ?></strong></small>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Session Info -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-people me-1"></i> Sesiones</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted" style="width:50%">Sesiones activas</td><td><strong><?= (int)$sessionCount ?></strong></td></tr>
                    <tr><td class="text-muted">Duracion de sesion</td><td><?= \MuseDockPanel\Env::get('SESSION_LIFETIME', '7200') ?>s (<?= round(\MuseDockPanel\Env::get('SESSION_LIFETIME', '7200') / 3600, 1) ?>h)</td></tr>
                    <tr><td class="text-muted">Cookie secure</td><td><?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '<span class="text-success">Si</span>' : '<span class="text-warning">No (HTTP)</span>' ?></td></tr>
                    <tr><td class="text-muted">Cookie HttpOnly</td><td><span class="text-success">Si</span></td></tr>
                    <tr><td class="text-muted">SameSite</td><td>Strict</td></tr>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-lock me-1"></i> Cuenta de Admin</div>
            <div class="card-body">
                <p class="text-muted small mb-2">Para cambiar usuario, email o contraseña, ve a tu perfil:</p>
                <a href="/profile" class="btn btn-outline-light btn-sm"><i class="bi bi-person me-1"></i>Mi Perfil</a>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <!-- PostgreSQL SSL -->
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-lock me-1"></i> PostgreSQL SSL</div>
            <div class="card-body">
                <?php
                $pgSslEnabled = false;
                $pgConfFile = '';
                $pgVersion = '';
                // Detect PG config location
                foreach (['14', '15', '16', '17'] as $v) {
                    $f = "/etc/postgresql/{$v}/main/postgresql.conf";
                    if (file_exists($f)) { $pgConfFile = $f; $pgVersion = $v; break; }
                }
                if ($pgConfFile) {
                    $pgConf = @file_get_contents($pgConfFile);
                    $pgSslEnabled = $pgConf && preg_match('/^\s*ssl\s*=\s*on/m', $pgConf);
                }
                $pgCertExists = file_exists('/etc/postgresql/ssl/server.crt');
                ?>

                <div class="d-flex align-items-center mb-3">
                    <span class="me-2">Estado:</span>
                    <?php if ($pgSslEnabled): ?>
                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>SSL activo</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>SSL desactivado</span>
                    <?php endif; ?>
                    <?php if ($pgVersion): ?>
                        <span class="badge bg-info ms-2">PostgreSQL <?= $pgVersion ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$pgConfFile): ?>
                    <p class="text-muted small">No se detecto PostgreSQL instalado.</p>
                <?php elseif ($pgSslEnabled): ?>
                    <p class="text-muted small mb-2">SSL esta activo. Las conexiones encriptadas estan disponibles.</p>
                    <form method="POST" action="/settings/security/pg-ssl-disable" onsubmit="return confirm('Desactivar SSL en PostgreSQL?')">
                        <?= View::csrf() ?>
                        <button class="btn btn-outline-warning btn-sm"><i class="bi bi-shield-x me-1"></i>Desactivar SSL</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small mb-2">Activa SSL para encriptar conexiones a PostgreSQL. Recomendado si aceptas conexiones remotas.</p>
                    <form method="POST" action="/settings/security/pg-ssl-enable" onsubmit="this.querySelector('button[type=submit]').disabled=true;this.querySelector('button[type=submit]').innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Configurando...';">
                        <?= View::csrf() ?>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" name="update_envs" id="updateEnvs" value="1">
                            <label class="form-check-label small" for="updateEnvs">Actualizar DB_SSLMODE=prefer en todos los .env de hostings</label>
                        </div>
                        <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-shield-check me-1"></i>Activar SSL</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
