<?php
use MuseDockPanel\View;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\CardDavService;

$status    = CardDavService::status();
$davHost   = $status['host'] ?: 'dav.musedock.com';
$installed = !empty($status['installed']);
$rcPlugin  = !empty($status['roundcube_plugin']);
$lastSync  = Settings::get('carddav_last_sync', '');
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Contactos y calendarios (CardDAV / CalDAV)</h4>
        <div class="text-muted small">Servicio integral: libreta de contactos y calendarios compartidos, con failover y sincronización con el webmail y el móvil, usando la misma contraseña del buzón.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a>
        <a href="/docs/mail/ha" class="btn btn-outline-info btn-sm"><i class="bi bi-shield-lock me-1"></i>Ver Correo HA</a>
    </div>
</div>

<!-- Estado -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Estado en esta instalación</div>
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-4">
                <div class="text-muted">Servidor CardDAV/CalDAV</div>
                <div class="fw-semibold">
                    <?php if ($installed): ?>
                        <span class="badge bg-success">Instalado</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">No instalado</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-muted">Host</div>
                <div class="fw-semibold" style="font-family:monospace;"><?= View::e($davHost) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted">Plugin en el webmail</div>
                <div class="fw-semibold">
                    <?= $rcPlugin ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">—</span>' ?>
                </div>
            </div>
        </div>
        <?php if ($installed && $lastSync): ?>
            <p class="small text-muted mt-3 mb-0">Última réplica al nodo de respaldo: <code><?= View::e($lastSync) ?></code> UTC.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Qué es -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Qué hace y por qué</div>
    <div class="card-body small">
        <p>El servidor de correo ya guardaba mensajes; ahora también guarda <strong>contactos</strong> y <strong>calendarios</strong> en un servidor <strong>CardDAV/CalDAV</strong> (Baïkal). Eso completa el servicio integral:</p>
        <ul class="mb-3">
            <li><strong>Compartidos y con failover:</strong> los contactos viven en la base de datos del cluster y se replican al nodo de respaldo. Si el servidor principal cae, siguen ahí.</li>
            <li><strong>Sincronizados con el móvil:</strong> el iPhone y Android los leen de forma nativa (protocolo estándar CardDAV/CalDAV). Añades un contacto en el móvil y aparece en el webmail, y viceversa.</li>
            <li><strong>Sin segunda contraseña:</strong> se usa la <strong>misma contraseña del buzón</strong>. En el webmail no hay que volver a iniciar sesión; en el móvil se pone email + contraseña del correo.</li>
        </ul>
        <p class="mb-0 text-muted">Cada usuario ve <strong>solo sus propios</strong> contactos y calendarios: la autenticación se valida contra el buzón (por IMAP) y cada uno accede únicamente a su libreta.</p>
    </div>
</div>

<!-- Configurar el móvil -->
<div class="card mb-4" style="border-color:rgba(52,211,153,.24);">
    <div class="card-header"><i class="bi bi-phone me-2"></i>Sincronizar el móvil</div>
    <div class="card-body small">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="fw-semibold mb-2"><i class="bi bi-apple me-1"></i>iPhone / iPad</div>
                <ol class="mb-0">
                    <li>Ajustes → Contactos → Cuentas → Añadir cuenta → <strong>Otra</strong></li>
                    <li>Añadir cuenta CardDAV (y, para agenda, cuenta CalDAV)</li>
                    <li>Servidor: <code><?= View::e($davHost) ?></code></li>
                    <li>Usuario: tu <strong>email completo</strong>. Contraseña: la del correo.</li>
                </ol>
            </div>
            <div class="col-md-6">
                <div class="fw-semibold mb-2"><i class="bi bi-android2 me-1"></i>Android</div>
                <ol class="mb-0">
                    <li>Instala <strong>DAVx⁵</strong> (Play Store / F-Droid)</li>
                    <li>Añadir cuenta → con URL y usuario</li>
                    <li>URL base: <code>https://<?= View::e($davHost) ?>/</code></li>
                    <li>Usuario: tu email completo. Contraseña: la del correo.</li>
                </ol>
            </div>
        </div>
        <p class="text-muted mt-3 mb-0">El descubrimiento automático (<code>.well-known/carddav</code>) hace que baste con dar el servidor; el cliente encuentra la libreta solo.</p>
    </div>
</div>

<!-- Failover -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Cómo funciona el failover</div>
    <div class="card-body small">
        <p>Los contactos los crea el usuario directamente en el servidor (desde el móvil o el webmail), así que el panel no interviene en cada cambio. Por eso la copia al nodo de respaldo se hace por un <strong>proceso periódico</strong> (cada minuto) en vez de por operación:</p>
        <ul class="mb-0">
            <li>El nodo que es <strong>master</strong> en cada momento envía una copia completa de contactos y calendarios al otro nodo.</li>
            <li>Si el respaldo pasa a ser master (failover), <strong>invierte la dirección</strong>: ahora él envía al que vuelve.</li>
            <li>Modelo <em>"la última promoción manda"</em>, el mismo que usa el correo. Como mucho se pierde lo del último minuto antes de una caída súbita.</li>
        </ul>
    </div>
</div>
