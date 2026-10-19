<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Correo de las webs (PHP): permitir, capturar o bloquear</h4>
        <div class="text-muted small">Qué pasa con el correo que una web manda con <code>mail()</code> o <code>wp_mail()</code> por el servidor, sin autenticar, y cómo controlarlo hosting a hosting.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/mail/web-capture" class="btn btn-outline-info btn-sm"><i class="bi bi-inbox me-1"></i> Ver lo capturado</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>El problema</div>
    <div class="card-body small text-muted">
        <p>Por defecto, una web PHP (WordPress incluido) manda el correo llamando al <code>sendmail</code> del servidor: <strong>sin usuario ni contraseña</strong> y con un remitente del sistema
            (<code>usuario@nombre-del-servidor</code>). Eso tiene dos consecuencias:</p>
        <ul class="mb-2">
            <li><strong>Los grandes proveedores lo rechazan.</strong> Gmail, por ejemplo, exige SPF o DKIM y contesta <code>550 5.7.26 … the sender is unauthenticated</code>. El aviso de la web (comentarios pendientes, pedidos…) nunca llega.</li>
            <li><strong>Es un riesgo de seguridad.</strong> Si una web se ve comprometida, puede mandar spam desde la IP del servidor sin autenticarse, y esa IP acaba en listas negras que afectan también al correo legítimo.</li>
        </ul>
        <p class="mb-0">Lo correcto es que las webs manden por <strong>SMTP autenticado</strong> (el servidor de correo propio o uno externo; en WordPress, un plugin SMTP) y que el envío sin autenticar quede cerrado.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-toggles me-2"></i>Los tres modos (por hosting)</div>
    <div class="card-body small text-muted">
        <table class="table table-dark table-sm small mb-3">
            <thead><tr><th style="width:16%">Modo</th><th>Qué pasa</th></tr></thead>
            <tbody>
                <tr><td><strong>Permitir</strong></td><td>Como siempre: sale por el Postfix local, sin autenticar. No cambia nada.</td></tr>
                <tr><td><strong>Capturar</strong></td><td>El mensaje <strong>no sale</strong>: se guarda en el servidor con la web, el usuario, la hora y los destinatarios. La web cree que se envió y sigue su curso. Se ve en <a href="/mail/web-capture">Correo de las webs capturado</a>.</td></tr>
                <tr><td><strong>Bloquear</strong></td><td>No sale y <strong>PHP recibe un error</strong> (<code>mail()</code> devuelve <code>false</code>), así que la web sabe que no se envió. Solo se guardan la cabecera y 4 KB, para saber qué se intentó.</td></tr>
            </tbody>
        </table>
        <p class="mb-2"><strong>Qué NO se ve afectado:</strong> una web que envía por SMTP autenticado (plugin de WordPress, configuración de correo de la aplicación) no usa <code>sendmail</code> y sigue funcionando igual. Tampoco el cron ni los avisos del sistema: el control se aplica solo al PHP de cada hosting, no a Postfix.</p>
        <p class="mb-0"><strong>Límites de seguridad:</strong> cada usuario puede capturar como mucho 120 mensajes a la hora y 1.500 al día (una web hackeada no puede llenar el disco); pasado ese tope se descarta y PHP recibe un error. Se guardan 30 días y 3.000 mensajes como máximo.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-gear me-2"></i>Cómo se cambia</div>
    <div class="card-body small text-muted">
        <ul class="mb-2">
            <li><strong>Panel:</strong> Cuentas → editar el hosting → tarjeta «Correo de la web (PHP)».</li>
            <li><strong>MCP:</strong> <code>hosting_php_settings</code> con <code>mail_mode</code> = <code>allow</code>, <code>capture</code> o <code>block</code> (primero sin <code>apply</code> para ver el plan).</li>
            <li><strong>Hostings nuevos:</strong> el modo por defecto lo fija el ajuste <code>hosting_mail_default_mode</code>. Si no está fijado, una instalación <strong>sin hostings</strong> empieza en «capturar» y una instalación <strong>con hostings</strong> sigue en «permitir» (nada cambia sola al actualizar).</li>
        </ul>
        <p class="mb-2"><strong>Cómo funciona por dentro:</strong> el panel pone en el pool de PHP-FPM del hosting un <code>sendmail_path</code> que apunta a un programa propio (<code>/usr/local/sbin/musedock-mail-capture</code>). Antes de recargar se comprueba la configuración (<code>php-fpm -t</code>); si falla, el pool queda como estaba. En los nodos réplica el programa se instala solo, porque el pool se copia con la configuración del master.</p>
        <p class="mb-0">Si el hosting ya tiene un <code>sendmail_path</code> propio (por ejemplo <code>msmtp</code>), el panel <strong>no lo pisa</strong> y lo indica.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-search me-2"></i>Antes de capturar o bloquear: la auditoría</div>
    <div class="card-body small text-muted">
        <p>Para saber qué webs mandan correo por el Postfix local (y cuáles rebotan), sin cambiar nada:</p>
<pre class="p-2 rounded mb-2" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">php /opt/musedock-panel/bin/mail-audit.php</pre>
        <p class="mb-0">Muestra por cada usuario local: mensajes, destinatarios, cuántos salieron, rebotaron o quedaron aplazados, los remitentes y los dominios de destino. Un usuario con muchos rebotados hacia <code>gmail.com</code> es el que
            conviene pasar a SMTP autenticado. Pasa primero esa web a «capturar», comprueba lo que se guarda y, cuando tenga SMTP propio, déjala en «capturar» o «bloquear».</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-lock me-2"></i>SMTP autenticado: que la web mande identificándose</div>
    <div class="card-body small text-muted">
        <p>La solución de fondo es que la aplicación <strong>se autentique</strong> en el servidor de correo del panel. El correo sale firmado con el DKIM del dominio y con su SPF, y los proveedores lo aceptan.</p>
        <ul class="mb-2">
            <li><strong>Cuenta de correo:</strong> <code>web-&lt;usuario&gt;@dominio</code>. Se crea en el dominio de la propia web si ese dominio es un dominio de correo del panel; si no, en el dominio de correo por defecto (<code>web_smtp_default_domain</code>; sin fijar, el primero activo). Se fija con <code>php bin/web-smtp.php --default-domain=ejemplo.com</code>.</li>
            <li><strong>Credenciales:</strong> en <code>.musedock-smtp.php</code> (0600, del usuario del hosting), <strong>fuera de la carpeta pública</strong>. La contraseña no se muestra ni se guarda en el registro.</li>
            <li><strong>WordPress:</strong> el panel instala un <em>mu-plugin</em> (<code>wp-content/mu-plugins/musedock-smtp.php</code>) que lee ese fichero; no hace falta ningún plugin SMTP. Si la dirección de origen de la web no es del dominio que firma, la reescribe a la de la cuenta y deja la original en <code>Reply-To</code> (así el DKIM alinea con el origen).</li>
            <li><strong>Laravel:</strong> pone las claves <code>MAIL_*</code> del <code>.env</code> (con copia previa <code>.env.bak-musedock-FECHA</code>).</li>
            <li><strong>Otra aplicación:</strong> se crea la cuenta y se dan los datos (servidor, puerto 587, STARTTLS, usuario y contraseña del fichero) para configurarla.</li>
        </ul>
        <p class="mb-2"><strong>Después de configurarla</strong>, el envío local sin autenticar de esa web pasa a «capturar» (o «bloquear» con <code>--then=block</code>): si algo intenta salir por el camino viejo, queda registrado.</p>
<pre class="p-2 rounded mb-2" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">php /opt/musedock-panel/bin/web-smtp.php --report                        # informe de todas las webs
php /opt/musedock-panel/bin/web-smtp.php --domain=ejemplo.com            # plan (no cambia nada)
php /opt/musedock-panel/bin/web-smtp.php --domain=ejemplo.com --apply    # lo aplica (root)
php /opt/musedock-panel/bin/web-smtp.php --domain=ejemplo.com --remove   # quita mu-plugin y credenciales</pre>
        <p class="mb-0">También en el panel: <a href="/mail/web-audit">Correo de las webs: auditoría</a> (informe por web con motivos de rechazo, SMTP, modo local, SPF/DMARC y una propuesta; un botón por fila configura su SMTP).</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lightbulb me-2"></i>Consejos</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li>Empieza por <strong>una</strong> web: ponla en «capturar», provoca un correo (un comentario, un formulario) y mira que aparece en la lista.</li>
            <li>«Capturar» no avisa a la web de nada: sirve para <em>ver</em> qué mandaría. Cuando una web ya envía por SMTP autenticado, «bloquear» es más honesto porque la aplicación se entera si algo intenta salir por el camino viejo.</li>
            <li>Revisa de vez en cuando la lista: un aumento brusco de mensajes de una web que no suele mandar es una señal de compromiso.</li>
        </ul>
    </div>
</div>
