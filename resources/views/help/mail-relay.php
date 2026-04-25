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

<div class="card"><div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Errores tipicos</div><div class="card-body"><ul class="small text-muted mb-0"><li>DKIM no publicado o selector incorrecto.</li><li>SPF sin IP de salida real.</li><li>Cliente SMTP apuntando a host/puerto equivocado.</li><li>WireGuard sin ruta hacia el relay.</li></ul></div></div>
