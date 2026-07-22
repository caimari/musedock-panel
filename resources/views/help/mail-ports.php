<?php
use MuseDockPanel\View;
use MuseDockPanel\Settings;

// Real values from this installation (fall back to placeholders if not set).
$mailHost = Settings::get('mail_hostname', '')
    ?: Settings::get('mail_local_hostname', '')
    ?: 'mail.tudominio.com';
// Best-effort public IP of this server: prefer resolving the mail hostname (fast,
// local), fall back to the first non-private local IP. No slow external calls.
$publicIp = '';
if (strpos($mailHost, '.') !== false) {
    $resolved = @gethostbyname($mailHost);
    if ($resolved && $resolved !== $mailHost && !preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|127\.)/', $resolved)) {
        $publicIp = $resolved;
    }
}
if ($publicIp === '') {
    $publicIp = trim((string)shell_exec("ip -o -4 addr show scope global 2>/dev/null | awk '{print \$4}' | cut -d/ -f1 | grep -v '^10\\.\\|^172\\.\\|^192\\.168\\.' | head -1")) ?: '(IP pública del servidor)';
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Puertos y clientes de correo</h4>
        <div class="text-muted small">Cómo conectar clientes externos (Outlook, Thunderbird, apps móviles, un CRM u otra plataforma) al servidor de correo del panel.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/mail?tab=deliverability" class="btn btn-outline-info btn-sm"><i class="bi bi-clipboard me-1"></i>Ver Entregabilidad</a>
    </div>
</div>

<!-- Servidor -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Servidor de correo de esta instalación</div>
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-6">
                <div class="text-muted">Hostname</div>
                <div class="fw-semibold" style="font-family:monospace;"><?= View::e($mailHost) ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted">IP pública</div>
                <div class="fw-semibold" style="font-family:monospace;"><?= View::e($publicIp) ?></div>
            </div>
        </div>
        <p class="small text-muted mt-3 mb-0">Un mismo servidor sirve <strong>todos los dominios</strong> dados de alta. Cada buzón se identifica por su <strong>email completo</strong> (<code>usuario@dominio.com</code>), nunca solo por el nombre.</p>
    </div>
</div>

<!-- Recibir -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-inbox me-2"></i>Recibir / leer correo — IMAP</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th class="ps-3">Puerto</th><th>Protocolo</th><th>Cifrado</th><th>Uso</th></tr></thead>
            <tbody class="small">
                <tr class="table-active"><td class="ps-3"><code>993</code></td><td>IMAPS</td><td>SSL/TLS (implícito)</td><td><span class="badge bg-success">Recomendado</span> para clientes (Outlook, Thunderbird, apps)</td></tr>
                <tr><td class="ps-3"><code>143</code></td><td>IMAP</td><td>STARTTLS</td><td>Alternativa con cifrado negociado</td></tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Enviar -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-send me-2"></i>Enviar correo — SMTP</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th class="ps-3">Puerto</th><th>Protocolo</th><th>Cifrado</th><th>Uso</th></tr></thead>
            <tbody class="small">
                <tr class="table-active"><td class="ps-3"><code>587</code></td><td>Submission</td><td>STARTTLS</td><td><span class="badge bg-success">Recomendado</span> para clientes que envían</td></tr>
                <tr><td class="ps-3"><code>465</code></td><td>SMTPS</td><td>SSL/TLS (implícito)</td><td>Alternativa (legacy, soportado)</td></tr>
                <tr class="table-warning"><td class="ps-3"><code>25</code></td><td>SMTP</td><td>STARTTLS</td><td>Solo servidor-a-servidor (entrega entre dominios). <strong>No usar en clientes.</strong></td></tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Opcional + config -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-funnel me-2"></i>Filtros (opcional)</div>
            <div class="card-body small">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td><code>4190</code></td><td>ManageSieve</td><td>Filtros, vacaciones, reenvíos</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-gear me-2"></i>Configuración de un cliente externo</div>
            <div class="card-body">
                <pre class="small mb-0" style="background:#12181f;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px;color:#c7d2dc;white-space:pre-wrap;">── IMAP (recibir) ──
Servidor:      <?= View::e($mailHost) ?>

Puerto:        993
Seguridad:     SSL/TLS
Usuario:       email completo (usuario@dominio.com)
Contraseña:    la del buzón

── SMTP (enviar) ──
Servidor:      <?= View::e($mailHost) ?>

Puerto:        587
Seguridad:     STARTTLS
Autenticación: SÍ (mismo usuario/contraseña)
Usuario:       email completo</pre>
            </div>
        </div>
    </div>
</div>

<!-- Notas -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Datos importantes</div>
    <div class="card-body">
        <ul class="small mb-0" style="line-height:1.9;">
            <li><strong>Certificado</strong>: Let's Encrypt (wildcard del dominio del servidor). Los clientes no verán avisos de certificado si conectan al hostname correcto (<code><?= View::e($mailHost) ?></code>), no a la IP.</li>
            <li><strong>Autenticación</strong>: el usuario es <strong>siempre el email completo</strong>, no solo el nombre. Mismo usuario y contraseña para IMAP y SMTP.</li>
            <li><strong>Multi-dominio</strong>: un único servidor sirve todos los dominios del panel. No hace falta un servidor por dominio.</li>
            <li><strong>SPF / DKIM / DMARC</strong>: si están publicados en el DNS (ver <a href="/mail?tab=deliverability" class="text-info">Entregabilidad</a>), los envíos llegan autenticados y no caen en spam.</li>
        </ul>
    </div>
</div>

<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Puerto 25</strong>: está abierto pero es <strong>solo para recepción</strong> servidor-a-servidor (cuando Gmail u otro servidor te entrega correo). Una plataforma externa <strong>no debe enviar por el 25</strong> — muchos proveedores lo bloquean de salida y no lleva autenticación de usuario. Para enviar, usa siempre el <strong>587</strong>.
</div>
