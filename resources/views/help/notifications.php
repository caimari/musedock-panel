<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Notificaciones: correo y Telegram</h4>
        <div class="text-muted small">Por dónde salen los avisos del panel y los correos a clientes, el servidor SMTP de reserva y cómo crear el bot de Telegram.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver a Docs</a>
        <a href="/settings/notifications" class="btn btn-outline-info btn-sm"><i class="bi bi-gear me-1"></i> Ir a Notificaciones</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope me-2"></i>Correo</div>
    <div class="card-body small">
        <ul class="mb-2">
            <li><strong>Servidor principal</strong> (SMTP de un proveedor de envío o el tuyo): por aquí salen los avisos y los correos a clientes (invitación y cambio de contraseña del portal, respuestas a tickets).</li>
            <li><strong>Servidor secundario (opcional):</strong> solo se usa si el principal falla o rechaza el envío (caído, sin cupo). Puede tener su propio remitente por si no acepta el del principal.</li>
            <li><strong>Remitente:</strong> mejor una dirección para envíos automáticos (p. ej. <code>notify@tudominio</code>) que la de contacto. El dominio debe tener SPF, DKIM y DMARC del proveedor.</li>
            <li><strong>Marca en los correos a clientes:</strong> el nombre que sale arriba en sus correos (HTML con botón y versión en texto).</li>
            <li>La configuración es <strong>de cada servidor</strong>: se envía desde el que manda. Con "copiar a los nodos" se reparte a los demás.</li>
        </ul>
        <p class="text-muted mb-0">Si un correo llega a spam con SPF, DKIM y DMARC en PASS, no es la configuración: es reputación (marcar "No es spam" enseña al filtro) y, para el servidor que envía directo, su DNS inverso (Correo → anti-abuso).</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-telegram me-2"></i>Telegram: crear el bot</div>
    <div class="card-body small">
        <ol class="mb-3">
            <li>En Telegram busca <strong>@BotFather</strong> (con la marca azul de verificado), pulsa <strong>Iniciar</strong> y escribe <code>/newbot</code>.</li>
            <li>Ponle un nombre (el que se verá, p. ej. "Avisos del servidor") y un usuario que acabe en <code>bot</code>.</li>
            <li>BotFather te da el <strong>token</strong>. Pégalo en <em>Bot Token</em>; no lo compartas: con él se puede escribir en nombre del bot.</li>
            <li>Abre tu bot nuevo y pulsa <strong>Iniciar</strong> (sin esto no puede escribirte).</li>
            <li>Busca <strong>@userinfobot</strong>, pulsa Iniciar: te dice tu <strong>Id</strong>. Ese es el <em>Chat ID</em>.</li>
            <li>Pulsa el botón de prueba (usa lo escrito, aunque no esté guardado) y después <strong>Guardar</strong>.</li>
        </ol>
        <p class="mb-2"><strong>Varios servidores:</strong> el mismo bot y el mismo Chat ID valen para todos (botón copiar junto al token). Cada aviso dice de qué servidor viene.</p>
        <p class="mb-0"><strong>Recomendado:</strong> en @BotFather → <code>/mybots</code> → tu bot → Bot Settings → <em>Allow Groups?</em> → <strong>Turn groups off</strong>, para que nadie lo meta en sus grupos.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-people me-2"></i>Telegram: un grupo para varias personas</div>
    <div class="card-body small">
        <ol class="mb-2">
            <li>Nuevo grupo (p. ej. "Avisos") y añade a las personas.</li>
            <li>Permite grupos al bot un momento (Allow Groups → on), añádelo al grupo y vuelve a desactivarlo.</li>
            <li>Añade @userinfobot al grupo: te da el <strong>ID del grupo</strong> (número negativo, tipo <code>-1001234567890</code>). Después puedes quitarlo.</li>
            <li>Pon ese número como <em>Chat ID</em>. Todos los del grupo verán los avisos.</li>
        </ol>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>¿Es seguro? ¿Qué puede hacer otra persona con el bot?</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>Cualquiera puede encontrarlo</strong> por su nombre y pulsar Iniciar, pero <strong>no ve nada</strong>: el panel solo envía a tu Chat ID (o al de tu grupo). Los avisos no son públicos.</li>
            <li><strong>Escribirle no sirve de nada:</strong> el panel no lee los mensajes que recibe el bot. No es un chat de atención: los clientes no pueden contactarte por ahí ni recibir nada.</li>
            <li><strong>Lo único secreto es el token.</strong> Si se filtra, en @BotFather → tu bot → <em>API Token</em> → <strong>Revoke</strong> y pon el nuevo en el panel.</li>
        </ul>
    </div>
</div>
