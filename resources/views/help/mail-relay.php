<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h4 class="mb-1">Mail / Relay</h4><div class="text-muted small">Operacion de Relay Privado: dominios, usuarios SMTP y activacion por DNS.</div></div>
    <div class="d-flex gap-2"><a href="/docs/mail-sections" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al mapa Mail</a><a href="/mail?tab=relay" class="btn btn-outline-info btn-sm"><i class="bi bi-diagram-3 me-1"></i>Abrir Relay</a></div>
</div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-signpost-split me-2"></i>Flujo recomendado</div><div class="card-body"><ol class="small text-muted mb-0"><li>Autorizar dominio remitente.</li><li>Publicar SPF, DKIM y DMARC.</li><li>Crear usuario SMTP para app/servidor remoto.</li><li>Probar envio por WireGuard o red privada.</li><li>Refrescar checks hasta estado activo.</li></ol></div></div>

<div class="card mb-4"><div class="card-header"><i class="bi bi-check2-square me-2"></i>Que revisar</div><div class="card-body"><ul class="small text-muted mb-0"><li>Host de salida y IP coinciden con DNS publicado.</li><li>Usuario SMTP con password guardada de forma segura.</li><li>TLS/puerto correctos en cliente SMTP.</li><li>Si todo esta OK, la card de activacion puede quedar plegada automaticamente.</li></ul></div></div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>SaaS autenticado: cuando DKIM/SPF se cumplen</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Si el SaaS envia autenticado por Relay Privado (SMTP AUTH), el servidor aplica politicas del dominio remitente autorizado.</li>
            <li>DKIM se firma si el dominio/selector esta registrado en relay y OpenDKIM esta activo en el nodo de mail.</li>
            <li>SPF pasa cuando la IP/host emisor usado por el relay esta incluido en el SPF del dominio.</li>
            <li>DMARC pasa cuando From esta alineado con SPF y/o DKIM del mismo dominio.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-people me-2"></i>Portal de clientes y apps SaaS</div>
    <div class="card-body">
        <p class="small text-muted mb-0">
            Si el Portal de clientes o una app SaaS usa SMTP autenticado contra el relay privado, el flujo de envio es el mismo:
            credenciales SMTP + dominio autorizado + DNS correcto (SPF/DKIM/DMARC). No se requiere recepcion local para poder enviar bien.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-code-square me-2"></i>Laravel: relay privado local con failover</div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Patron recomendado para una app Laravel/SaaS: usar el relay privado como primer mailer y un proveedor externo como backup.
            Asi el envio normal sale por Postfix interno, pero si el relay cae, Laravel puede saltar al proveedor alternativo.
        </p>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold small mb-2">config/mail.php</div>
                    <pre class="small mb-0" style="color:#cbd5e1;white-space:pre-wrap;">'mailers' =&gt; [
    'local' =&gt; [
        'transport' =&gt; 'smtp',
        'url' =&gt; env('MAIL_LOCAL_URL'),
    ],

    'provider_backup' =&gt; [
        'transport' =&gt; 'smtp',
        'host' =&gt; env('MAIL_BACKUP_HOST'),
        'port' =&gt; env('MAIL_BACKUP_PORT', 587),
        'username' =&gt; env('MAIL_BACKUP_USERNAME'),
        'password' =&gt; env('MAIL_BACKUP_PASSWORD'),
        'encryption' =&gt; 'tls',
    ],

    'failover' =&gt; [
        'transport' =&gt; 'failover',
        'mailers' =&gt; ['local', 'provider_backup'],
    ],
],</pre>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold small mb-2">.env</div>
                    <pre class="small mb-0" style="color:#cbd5e1;white-space:pre-wrap;">MAIL_MAILER=failover
MAIL_LOCAL_URL=smtp://relay-user:RELAY_PASSWORD@10.10.70.2:587?verify_peer=0

MAIL_BACKUP_HOST=smtp-backup.example.net
MAIL_BACKUP_PORT=587
MAIL_BACKUP_USERNAME=backup-user
MAIL_BACKUP_PASSWORD=backup-password</pre>
                </div>
            </div>
        </div>

        <div class="alert alert-info mb-3">
            <div class="small">
                <strong>MAIL_MAILER=failover</strong> no significa proveedor externo. Es un orquestador:
                primero intenta <code>local</code> y solo si falla usa <code>provider_backup</code>.
                Si pones <code>MAIL_MAILER=local</code>, no hay backup.
            </div>
        </div>

        <div class="small text-muted mb-3">
            <strong>verify_peer=0</strong> desactiva la verificacion del certificado TLS del relay interno. Es util cuando el relay usa un
            certificado autofirmado en IP privada/WireGuard. No requiere instalar nada extra: Symfony Mailer entiende esa opcion en el DSN.
            Para un relay publico o expuesto a Internet, lo correcto es usar certificado valido y mantener verificacion TLS.
        </div>

        <div class="small text-muted mb-0">
            Para verificar que realmente sale por el relay local, prueba el mailer aislado:
            <code>Mail::mailer('local')-&gt;raw(...)</code>. Si ese envio funciona, no ha usado el fallback.
        </div>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Relay de reserva en el servidor de relevo</div>
    <div class="card-body small">
        <p class="mb-2">Para que el servidor de relevo también tenga relay, se instala en él una <strong>reserva</strong>: misma configuración (nombre, red, dominio, claves DKIM y usuarios), pero <strong>parada</strong> mientras es copia.
            Cada servidor conserva <strong>su</strong> IP: la reserva escucha en <code>127.0.0.1:587</code> y en la IP propia del nodo en la VPN, no en la del master.
            Por eso las aplicaciones que viven en el mismo servidor deben enviar a <code>127.0.0.1:587</code>: funciona igual en el que manda y en su relevo, sin cambiar nada al relevar.</p>
        <ul class="mb-2">
            <li><strong>Instalar:</strong> en el que manda, como root: <code>php bin/cluster-switch.php relay-standby &lt;nodo&gt;</code> (enseña el plan) y luego con <code>--apply</code>. La instalación sigue 1-2 min en el nodo.</li>
            <li><strong>Datos:</strong> cada 5 min el que manda envía los dominios (con su clave DKIM) y los usuarios SMTP (con su contraseña) por el canal autenticado del cluster, solo si han cambiado. Un usuario quitado en el que manda se quita también en la reserva. Los usuarios sin contraseña recuperable no se pueden copiar (se avisa): regenera su contraseña.</li>
            <li><strong>Arranque y parada:</strong> cada minuto el panel comprueba el papel del nodo: si manda, arranca Postfix y OpenDKIM; si es copia o está apartado, los para. Una reserva instalada con la IP del master se pasa sola a la IP propia del nodo (con copia de <code>main.cf</code> y <code>master.cf</code>).</li>
            <li><strong>DNS:</strong> la IP pública del nodo de relevo tiene que estar en el SPF de cada dominio (una sola línea SPF por dominio) y, para la entregabilidad, su DNS inverso cuadrar con su nombre (Correo → anti-abuso).</li>
        </ul>
        <p class="text-muted mb-0">No sustituye al cambio de rol: el relay sigue al servidor que manda. La cola que quedara en el anterior se entrega cuando vuelva.</p>
    </div>
</div>

<div class="card"><div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Errores tipicos</div><div class="card-body"><ul class="small text-muted mb-0"><li>DKIM no publicado o selector incorrecto.</li><li>SPF sin IP de salida real.</li><li>Cliente SMTP apuntando a host/puerto equivocado.</li><li>WireGuard sin ruta hacia el relay.</li></ul></div></div>
