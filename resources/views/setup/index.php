<?php use MuseDockPanel\View; use MuseDockPanel\Flash; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MuseDock Panel — Setup</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: 'Inter', -apple-system, sans-serif;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .setup-container { max-width: 760px; width: 100%; padding: 1rem; }
        .setup-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 2.5rem; }
        .setup-header { text-align: center; margin-bottom: 2rem; }
        .setup-header h1 { color: #38bdf8; font-size: 1.5rem; font-weight: 700; }
        .setup-header p { color: #cbd5e1; font-size: 0.9rem; }
        .form-control { background: #0f172a; border: 1px solid #334155; color: #e2e8f0; }
        .form-control:focus { background: #0f172a; border-color: #38bdf8; color: #e2e8f0; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
        .form-control::placeholder { color: #475569; }
        .form-label { color: #94a3b8; font-size: 0.85rem; font-weight: 500; }
        .btn-primary { background: #0ea5e9; border-color: #0ea5e9; font-weight: 600; }
        .btn-primary:hover { background: #0284c7; border-color: #0284c7; }
        .check-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0; font-size: 0.85rem; }
        .check-ok { color: #22c55e; }
        .check-fail { color: #ef4444; }
        .check-name { color: #e2e8f0; flex: 1; }
        .check-detail { color: #cbd5e1; font-size: 0.8rem; }
        .divider { border: 0; border-top: 1px solid #334155; margin: 1.5rem 0; }
        .alert-danger { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #ef4444; }
        .alert-success { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #22c55e; }
        .input-group .btn { border-color: #334155; color: #94a3b8; }
        .input-group .btn:hover { color: #38bdf8; }
        .step-badge { display: inline-block; width: 24px; height: 24px; border-radius: 50%; background: rgba(56,189,248,0.15);
                      color: #38bdf8; text-align: center; line-height: 24px; font-size: 0.75rem; font-weight: 700; margin-right: 0.5rem; }
        .setup-option { border: 1px solid #334155; background: rgba(15,23,42,0.35); border-radius: 12px; padding: 1rem; }
        .setup-option .form-check-input { margin-top: 0.25rem; }
        .setup-option strong { color: #f8fafc; }
        .setup-option .setup-help { color: #e2e8f0; }
        .setup-note { background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.25); color: #fbbf24; border-radius: 12px; padding: 0.9rem; }
        .setup-help { color: #e2e8f0; }
        .setup-help code,
        .setup-option code { color: #7dd3fc; }
        .setup-muted { color: #cbd5e1; }
    </style>
</head>
<body>
<div class="setup-container">
    <div class="setup-card">
        <div class="setup-header">
            <div style="font-size:2.5rem;margin-bottom:0.5rem;"><i class="bi bi-hdd-rack" style="color:#38bdf8;"></i></div>
            <h1>MuseDock Panel</h1>
            <p>Primera configuracion — crea tu cuenta de administrador</p>
        </div>

        <?php foreach (Flash::all() as $type => $msg): ?>
            <div class="alert alert-<?= $type === 'error' ? 'danger' : $type ?> mb-3"><?= View::e($msg) ?></div>
        <?php endforeach; ?>

        <!-- System Checks -->
        <div class="mb-4">
            <h6 style="color:#94a3b8;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
                <span class="step-badge">1</span> Requisitos del sistema
            </h6>
            <?php
            $allOk = true;
            foreach ($checks as $check):
                if (!$check['ok']) $allOk = false;
            ?>
                <div class="check-item">
                    <i class="bi <?= $check['ok'] ? 'bi-check-circle-fill check-ok' : 'bi-x-circle-fill check-fail' ?>"></i>
                    <span class="check-name"><?= View::e($check['name']) ?></span>
                    <span class="check-detail"><?= View::e($check['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <hr class="divider">

        <div class="mb-4">
            <h6 style="color:#94a3b8;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.75rem;">
                <span class="step-badge">1b</span> TLS del panel admin (<?= View::e((string)\MuseDockPanel\Env::get('PANEL_PORT', '8444')) ?>)
            </h6>
            <div class="alert alert-success mb-0" style="color:#22c55e;">
                <div class="mb-1"><strong>Modo por defecto:</strong> certificado interno/autofirmado en el puerto del panel (recomendado para administracion privada).</div>
                <div class="mb-1">Tras completar el setup puedes cambiarlo en <strong>Settings → Servidor</strong>:</div>
                <div class="small">1) Self-signed (privado)</div>
                <div class="small">2) Let's Encrypt HTTP-01/TLS-ALPN-01 (requiere 80/443 abiertos desde Internet, con fallback interno)</div>
                <div class="small">3) Let's Encrypt DNS-01 (requiere proveedor DNS con API, con fallback interno)</div>
                <div class="small mt-2">Mantén el puerto admin restringido a IPs de confianza.</div>
            </div>
        </div>

        <hr class="divider">

        <!-- Admin Setup Form -->
        <form method="POST" action="/setup/install" <?= !$allOk ? 'style="opacity:0.5;pointer-events:none;"' : '' ?>>
            <?= View::csrf() ?>
            <h6 style="color:#94a3b8;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:1rem;">
                <span class="step-badge">2</span> Cuenta de administrador
            </h6>

            <?php if (!$allOk): ?>
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Corrige los requisitos del sistema antes de continuar.
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Nombre de usuario</label>
                <input type="text" name="username" class="form-control" value="admin" required
                       minlength="3" maxlength="50" pattern="[a-zA-Z][a-zA-Z0-9_.\-]{2,49}"
                       placeholder="admin">
            </div>

            <div class="mb-3">
                <label class="form-label">Contrasena</label>
                <div class="input-group">
                    <input type="password" name="password" id="pwd1" class="form-control" required minlength="8"
                           placeholder="Minimo 8 caracteres">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd1', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Confirmar contrasena</label>
                <div class="input-group">
                    <input type="password" name="password_confirm" id="pwd2" class="form-control" required minlength="8"
                           placeholder="Repite la contrasena">
                    <button type="button" class="btn btn-outline-secondary" onclick="togglePwd('pwd2', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Email (opcional)</label>
                <input type="email" name="email" class="form-control" placeholder="admin@ejemplo.com">
            </div>

            <hr class="divider">

            <h6 style="color:#94a3b8;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:1rem;">
                <span class="step-badge">3</span> Acceso seguro por firewall
            </h6>

            <?php
                $fw = $firewall ?? [];
                $fwActive = (bool)($fw['active'] ?? false);
                $fwType = (string)($fw['type'] ?? 'none');
                $fwAdminIp = (string)($fw['admin_ip'] ?? '');
                $fwPanelPort = (int)($fw['panel_port'] ?? 8444);
                $fwCanManage = (bool)($fw['can_manage'] ?? true);
            ?>

            <div class="setup-note mb-3">
                <div class="d-flex gap-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div class="small">
                        Si activas un firewall sin permitir primero SSH y el puerto del panel, puedes perder la conexion.
                        Lo seguro es permitir <strong>22/tcp</strong> y <strong><?= View::e((string)$fwPanelPort) ?>/tcp</strong>
                        solo desde tu IP o un rango de confianza.
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">IP o rango de confianza</label>
                <input type="text" name="firewall_trusted_source" class="form-control"
                       value="<?= View::e($fwAdminIp) ?>" placeholder="203.0.113.10 o 203.0.113.0/24"
                       autocomplete="off" spellcheck="false">
                <small class="setup-help">
                    Detectada desde tu sesion: <?= View::e($fwAdminIp ?: 'no disponible') ?>.
                    Puedes cambiarla por un CIDR IPv4 si administras desde una oficina/VPN.
                </small>
            </div>

            <div class="d-grid gap-2 mb-4">
                <?php if (!$fwCanManage): ?>
                    <div class="alert alert-danger mb-2">
                        El proceso web no corre como root, asi que el setup no puede modificar firewall. Hazlo manualmente desde SSH.
                    </div>
                <?php endif; ?>

                <?php if ($fwActive): ?>
                    <label class="setup-option">
                        <input class="form-check-input me-2" type="radio" name="firewall_mode" value="allow_existing" <?= $fwCanManage ? 'checked' : 'disabled' ?>>
                        <strong>Firewall activo detectado (<?= View::e(strtoupper($fwType === 'iptables' ? 'iptables' : 'UFW')) ?>)</strong>
                        <span class="d-block small setup-help mt-1">
                            Anadir reglas para permitir SSH y panel solo desde la IP/rango de confianza. Tambien guarda ese rango en <code>ALLOWED_IPS</code>.
                        </span>
                    </label>
                    <label class="setup-option">
                        <input class="form-check-input me-2" type="radio" name="firewall_mode" value="skip">
                        <strong>No tocar firewall ahora</strong>
                        <span class="d-block small setup-help mt-1">Podras ajustarlo despues en Settings &rarr; Firewall, pero revisa manualmente que <?= View::e((string)$fwPanelPort) ?>/tcp no quede abierto a todo internet.</span>
                    </label>
                <?php else: ?>
                    <label class="setup-option">
                        <input class="form-check-input me-2" type="radio" name="firewall_mode" value="install_ufw" <?= $fwCanManage ? 'checked' : 'disabled' ?>>
                        <strong>No hay firewall activo: preparar UFW recomendado</strong>
                        <span class="d-block small setup-help mt-1">
                            Instala UFW si hace falta, aplica <code>deny incoming</code>, <code>allow outgoing</code>, permite SSH y panel solo desde la IP/rango de confianza, y activa UFW.
                        </span>
                    </label>
                    <label class="setup-option">
                        <input class="form-check-input me-2" type="radio" name="firewall_mode" value="skip">
                        <strong>Dejar firewall para despues</strong>
                        <span class="d-block small setup-help mt-1">
                            El panel quedara instalado, pero tendras que restringir manualmente SSH y <?= View::e((string)$fwPanelPort) ?>/tcp antes de exponerlo.
                        </span>
                    </label>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2" <?= !$allOk ? 'disabled' : '' ?>>
                <i class="bi bi-rocket-takeoff me-2"></i>Instalar MuseDock Panel
            </button>
        </form>

        <hr class="divider">

        <div style="text-align:center;">
            <small style="color:#475569;">MuseDock Panel v<?= View::e(PANEL_VERSION) ?></small>
        </div>
    </div>
</div>

<script>
function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

document.querySelector('form')?.addEventListener('submit', function(e) {
    const p1 = document.getElementById('pwd1').value;
    const p2 = document.getElementById('pwd2').value;
    if (p1 !== p2) {
        e.preventDefault();
        alert('Las contrasenas no coinciden.');
    }
});
</script>
</body>
</html>
