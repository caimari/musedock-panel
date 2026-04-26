<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Perfil: MFA con app Authenticator (TOTP)</h4>
        <div class="text-muted small">Guia practica para activar, usar y recuperar MFA del panel.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/profile" class="btn btn-outline-info btn-sm">
            <i class="bi bi-person me-1"></i> Abrir Mi Perfil
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-info-circle me-2 text-info"></i>Que tipo de MFA usa el panel</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            El panel usa <strong>TOTP estandar</strong> (codigo de 6 digitos cada 30s) con URI <code>otpauth://</code>.
            No depende de Google API ni de un servicio externo para validar codigos.
        </p>
        <p class="small text-muted mb-0">
            Flujo de login: primero usuario+contrasena en <code>/login</code>, y si MFA esta activa/obligatoria,
            segundo paso en <code>/login/mfa</code>.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-phone me-2"></i>Apps compatibles (movil y PC)</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Cualquier app que soporte TOTP (RFC 6238) y permita alta manual por secret o URI <code>otpauth://</code>.
        </p>
        <ul class="small text-muted mb-0">
            <li>Movil: Google Authenticator, Microsoft Authenticator, 2FAS, Aegis.</li>
            <li>PC: gestores con TOTP (por ejemplo Bitwarden/1Password) o apps TOTP equivalentes.</li>
            <li>Recomendado: usar una app con backup/sincronizacion cifrada y control de acceso al dispositivo.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-check me-2"></i>Activar MFA paso a paso (desde /profile)</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Entrar en <code>/profile</code> y abrir bloque <code>Autenticacion MFA (TOTP)</code>.</li>
            <li>Pulsar <code>Generar/rotar secret</code>.</li>
            <li>Copiar el <code>Secret</code> o la <code>URI otpauth</code> y anadirlo en tu app Authenticator.</li>
            <li>Introducir tu contrasena actual + codigo MFA de 6 digitos.</li>
            <li>Pulsar <code>Activar MFA</code> y validar que el siguiente login pide <code>/login/mfa</code>.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Operacion y buenas practicas</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Guardar un segundo metodo: otro dispositivo o vault seguro con TOTP.</li>
            <li>No dejar MFA obligatoria global activa si hay admins sin enrolar.</li>
            <li>Tras rotar secret, confirmar inmediatamente un login completo.</li>
            <li>Si hay varios admins, mantener al menos dos con MFA operativa para contingencias.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(245,158,11,.35);">
    <div class="card-header"><i class="bi bi-life-preserver me-2 text-warning"></i>Recuperacion si se pierde el movil o la app</div>
    <div class="card-body">
        <ol class="small text-muted mb-3">
            <li>Si sigues logueado en panel: desactiva MFA en <code>/profile</code> y vuelve a activarla con nuevo dispositivo.</li>
            <li>Si otro admin tiene acceso: que desactive <code>MFA obligatoria</code> en <code>/settings/security</code> si bloquea acceso global.</li>
            <li>Si no hay acceso web admin: usar recuperacion de emergencia por base de datos (root).</li>
        </ol>
        <div class="small text-muted mb-2">
            Recuperacion de emergencia (PostgreSQL, ejemplo):
        </div>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;"># 1) Desactivar MFA del usuario bloqueado
sudo -u postgres psql -d musedock_panel -c \
"UPDATE panel_admins
 SET mfa_enabled = false,
     mfa_secret = NULL,
     updated_at = NOW()
 WHERE username = 'admin';"

# 2) Si MFA global obligatoria impide login, bajarla temporalmente
sudo -u postgres psql -d musedock_panel -c \
"UPDATE panel_settings
 SET value = '0',
     updated_at = NOW()
 WHERE key = 'security_mfa_required';"</pre>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Notas importantes de seguridad</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Tras recuperacion por SQL, reactivar MFA cuanto antes y rotar secret.</li>
            <li>Auditar en Activity Log quien hizo el cambio y desde donde.</li>
            <li>Usar comandos SQL solo como ultimo recurso de emergencia.</li>
        </ul>
    </div>
</div>
