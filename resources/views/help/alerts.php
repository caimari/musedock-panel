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
