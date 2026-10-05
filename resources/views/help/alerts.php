<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Avisos: qué llega y cómo silenciarlo</h4>
        <div class="text-muted small">Quién envía cada correo de aviso, por qué, y cómo dejar de recibir los que ya conoces sin perder los importantes.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/alerts" class="btn btn-outline-info btn-sm"><i class="bi bi-bell-slash me-1"></i> Abrir Avisos</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope me-2"></i>Quién envía</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li>Cada panel envía sus propios avisos con su configuración de <a href="/settings/notifications" class="text-info">Notificaciones</a>
                (el master la copia a sus nodos). El asunto empieza por <code>[nombre del servidor]</code>.</li>
            <li>Un panel sin correo ni Telegram configurado <strong>no avisa de nada</strong> (los avisos solo quedan en su monitor).</li>
            <li>Cada panel tiene un tope diario de correos de aviso (25 por defecto): al llegar, manda uno último y calla hasta el día siguiente.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-check me-2"></i>Los avisos más habituales</div>
    <div class="card-body small">
        <ul class="mb-0" style="line-height:1.9;">
            <li><strong>Nodo de correo con problemas:</strong> el master comprueba cada minuto los servicios de correo de sus nodos (puertos, API del panel, base de datos).
                Avisa solo si el fallo dura varios minutos seguidos (5 por defecto) y otra vez al recuperarse. Si la API del nodo no responde, lo dice así:
                suele ser la red o la VPN entre nodos, no el correo.</li>
            <li><strong>Hardening degradado:</strong> controles de seguridad del servidor fuera de lo recomendado (SSH con contraseña, sysctl de red…).
                Avisa cuando falla un control <strong>nuevo</strong>. Si un control está así a propósito, dalo por bueno en <em>Avisos</em>.</li>
            <li><strong>Disco lleno (DISK_HIGH):</strong> un disco por encima del umbral; se repite como mucho cada 12 h mientras siga.
                Puedes poner un umbral propio a un disco de un servidor, o silenciarlo.</li>
            <li><strong>CPU / RAM / GPU:</strong> solo si duran (5 min seguidos por defecto), no por picos.</li>
            <li><strong>Cambio en el firewall, ficheros críticos, puertos expuestos, acceso raro:</strong> vigilancias de seguridad; avisan una vez por cambio.</li>
            <li><strong>Relevo (failover, cambio de rol, testigos, entrada alternativa):</strong> los del relevo no se pueden silenciar.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(239,68,68,.35);">
    <div class="card-header"><i class="bi bi-eye me-2"></i>Cambios del sistema (posible intruso)</div>
    <div class="card-body small">
        <p class="mb-2">Cada 10 minutos <strong>cada servidor</strong> compara lo que hay ahora con la foto anterior en los sitios donde se instalan
            aplicaciones y donde suele esconderse un intruso. La primera vez solo toma la foto; después avisa <strong>una vez</strong> de cada novedad.</p>
        <table class="table table-sm small mb-2">
            <thead><tr><th>Qué mira</th><th>Avisa de</th></tr></thead>
            <tbody>
            <tr><td><code>/opt</code>, <code>/srv</code>, <code>/var/www</code></td><td>carpetas nuevas de aplicaciones</td></tr>
            <tr><td><code>/var/www/vhosts</code></td><td>carpetas que no son de ningún hosting del panel (las de menos de 30 min esperan a la siguiente vuelta)</td></tr>
            <tr><td><code>/etc</code></td><td>carpetas nuevas que no instala ningún paquete</td></tr>
            <tr><td><code>/etc/systemd/system</code></td><td>servicios y temporizadores nuevos o cambiados (también los <code>.d/*.conf</code> que cambian un servicio)</td></tr>
            <tr><td>cron (<code>/etc/crontab</code>, <code>/etc/cron.*</code>, crontabs de usuarios)</td><td>tareas programadas nuevas o cambiadas</td></tr>
            <tr><td><code>/usr/local/bin</code>, <code>/usr/local/sbin</code></td><td>programas nuevos o cambiados</td></tr>
            <tr><td><code>authorized_keys</code> de root, de <code>/home</code> y de los hostings</td><td>claves SSH añadidas o quitadas (dice cuál, por su comentario)</td></tr>
            <tr><td><code>/tmp</code>, <code>/var/tmp</code>, <code>/dev/shm</code></td><td>ficheros ejecutables (el sitio típico de un programa malicioso descargado)</td></tr>
            </tbody>
        </table>
        <ul class="mb-0">
            <li>No cuenta lo que instala un paquete del sistema (<code>apt</code>) en <code>/opt</code>, <code>/etc</code> o systemd.</li>
            <li>Al actualizar el panel se vuelve a tomar la foto de servicios, tareas y programas sin avisar: los reescribe la propia actualización.</li>
            <li>Si lo has hecho tú, no hay que hacer nada. Si no lo reconoces, el correo trae comandos para empezar a mirar.</li>
            <li>Algo que cambia a menudo y es normal: <em>Ajustes → Avisos → Cambios del sistema: ignorar</em> (patrón por línea, o <code>servidor:patrón</code>),
                o MCP <code>alerts_configure</code> con <code>ignore_system_paths</code>. Mejor ignorar rutas concretas que silenciar el tipo entero.</li>
            <li><strong>Es un cable trampa, no un antivirus:</strong> un intruso que ya es root puede desactivarlo. Avisa pronto de lo más habitual
                (una carpeta, un servicio, una tarea o una clave SSH nuevos), pero no sustituye a mantener el sistema al día y el acceso cerrado.</li>
            <li>Aparte, en el master, el aviso <em>Carpetas sin copia al servidor de relevo</em> dice qué apps de <code>/opt</code>, <code>/srv</code> o <code>/var/www</code>
                no llegarían al otro servidor en un relevo (<a href="/docs/sync-archivos-lsyncd" class="text-info">Sync de archivos</a>).</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-bell-slash me-2"></i>Silenciar</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><a href="/settings/alerts" class="text-info">Ajustes → Avisos</a> (en el master): tipos silenciados, controles de hardening aceptados, reglas por disco
                (servidor + punto de montaje: umbral propio o sin aviso) y minutos de espera del aviso de correo. Se copia a los nodos al guardar.</li>
            <li>MCP: <code>alerts_status</code> (lectura, con <code>node</code>) y <code>alerts_configure</code> (primero el plan).</li>
            <li>Silenciar solo quita el correo o el Telegram: el aviso sigue en el monitor del panel.</li>
            <li>El estado de las vigilancias se guarda en <code>storage/state</code>: una actualización del panel ya no repite avisos que ya se dieron.</li>
        </ul>
    </div>
</div>
