<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Testigos externos</h4>
        <div class="text-muted small">Servidores "solo ojos" que miran desde fuera si llegan a tus servidores: confirman caídas reales antes de un relevo y ayudan a elegir la entrada.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/witnesses" class="btn btn-outline-info btn-sm"><i class="bi bi-eye me-1"></i> Abrir Testigos</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Para qué sirven</div>
    <div class="card-body small">
        <p class="text-muted">Desde un servidor, <strong>un corte de red se ve igual que una caída</strong>. Si el servidor de relevo deja de ver al que manda,
            puede ser que se haya caído… o que solo se haya cortado el camino entre los dos. Si toma el mando en el segundo caso, habría dos servidores mandando a la vez.</p>
        <p class="text-muted mb-1">Un testigo es un tercer servidor, en <strong>otro sitio</strong> (otro proveedor u otra ciudad), que mira lo mismo. Antes de decidir, el panel le pregunta:</p>
        <ul class="text-muted mb-0">
            <li><strong>Relevo:</strong> si algún testigo llega al que manda, no se toma el mando (es un corte de red, no una caída).</li>
            <li><strong>Entrada alternativa:</strong> si un servidor tiene dos líneas (p. ej. una principal y otra de reserva con IP dinámica), el testigo dice por cuál se llega.
                Si se llega por la de reserva, se cambia la entrada en el DNS y no el servidor (<a href="/docs/failover-modes" class="text-info">guía de Failover</a>).</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Qué es y qué no es un testigo</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>No tiene el panel.</strong> Lleva un único programa pequeño (Python 3, sin dependencias): el agente testigo.</li>
            <li><strong>No tiene datos ni acceso a nada:</strong> ni copias, ni claves de otros servidores, ni SSH hacia ellos. No guarda registros.</li>
            <li><strong>No acepta órdenes:</strong> mira una lista <em>fija</em> de comprobaciones (la de su configuración) y responde cómo las ve: si llega, latencia y pérdidas.</li>
            <li><strong>Responde solo a quien debe:</strong> por HTTPS, con su certificado propio (el panel lo reconoce por su <em>huella</em>), con una clave, y su cortafuegos solo deja preguntar a tus servidores.</li>
            <li><strong>Se consulta por su IP pública</strong>, no por una VPN: si la VPN pasara por uno de tus servidores, dejaría de responder justo cuando ese servidor cae.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-ol me-2"></i>Cómo crear uno</div>
    <div class="card-body small">
        <ol class="mb-2" style="line-height:1.9;">
            <li>Elige un servidor Linux que <strong>no forme parte de tu cluster</strong> y esté en otro sitio. Basta uno muy pequeño. Necesita Python 3 y OpenSSL.</li>
            <li>En <a href="/settings/witnesses" class="text-info">Ajustes → Testigos</a>, rellena <em>Crear un testigo nuevo</em>:
                <ul>
                    <li><strong>IP pública del testigo</strong> y puerto (8447 por defecto).</li>
                    <li><strong>Quién puede preguntarle:</strong> el panel propone las IPs públicas de tus servidores del relevo. Añade las de otros clusters si también lo van a usar.</li>
                    <li><strong>Qué mira:</strong> el panel propone cada servidor del relevo y, si vigilas la entrada de alguno, su comprobación por la línea normal y por la alternativa. Puedes cambiarlo.</li>
                </ul>
                Pulsa <em>Descargar script</em>.</li>
            <li>Copia el script al testigo y ejecútalo como root: <code>bash musedock-witness-install.sh</code>.
                Instala el agente, crea <strong>en el testigo</strong> la clave y el certificado, lo deja como servicio y configura el cortafuegos (si usa ufw; si no, te dice qué abrir).</li>
            <li>Al acabar, el script muestra la <strong>URL</strong>, la <strong>huella</strong> y el comando para ver la <strong>clave</strong> en el testigo.</li>
            <li>En <em>Registrar el testigo</em>, pega URL, huella y clave (directamente; la clave no debe pasar por ningún chat ni correo) y confirma con tu contraseña.
                El panel comprueba que el testigo contesta antes de guardarlo.</li>
            <li>Repite el registro en <strong>cada panel</strong> que vaya a usarlo (los dos servidores de una pareja de relevo).</li>
        </ol>
        <p class="text-muted mb-0">Por terminal también: <code>php bin/witness.php add &lt;nombre&gt; &lt;https://IP:puerto&gt; &lt;huella&gt;</code> (pide la clave sin mostrarla), <code>list</code>, <code>test</code> y <code>remove</code>.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Comprobaciones y personalización</div>
    <div class="card-body small">
        <p class="text-muted">Cada comprobación tiene un <code>id</code> (el nombre que verás) y un tipo:</p>
        <ul>
            <li><code>{"id": "web-a", "type": "tcp", "host": "203.0.113.20", "port": 443}</code>: abre una conexión a esa IP y puerto.</li>
            <li><code>{"id": "a-normal", "type": "https", "url": "https://health-a.ejemplo.com/", "resolve": "203.0.113.20", "expect": "ok-"}</code>:
                conecta a esa IP con ese nombre, verifica el certificado y exige ese texto en la respuesta. Sirve para comprobar <em>una entrada concreta</em> de un servidor.</li>
            <li><code>{"id": "a-alternativa", "type": "https", "url": "https://health-a.ejemplo.com/", "resolve_host": "linea2.ejemplo.com", "expect": "ok-"}</code>:
                igual, pero conectando a la IP que tenga <em>en ese momento</em> ese nombre. Para líneas con IP dinámica (no fijes nunca una IP que cambia).</li>
        </ul>
        <p class="text-muted mb-0">El panel reconoce a qué servidor se refiere cada comprobación por la dirección que mira (la IP), no por el <code>id</code>, así que puedes llamarlas como quieras.
            Para cambiar las comprobaciones, genera de nuevo el script y vuelve a ejecutarlo en el testigo: conserva la clave y la huella, no hay que registrarlo otra vez.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Si un testigo falla</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>Cae uno:</strong> no afecta; decide el otro. Un testigo que no responde no cuenta ni a favor ni en contra.</li>
            <li><strong>Caen todos:</strong> nada se bloquea; los relevos se deciden con la vista del propio panel, como si no hubiera testigos. Se pierde la protección contra cortes de red.</li>
            <li>Si un testigo no responde durante 10 minutos, el panel avisa por los canales de notificación, y otra vez cuando vuelve.</li>
            <li>Recomendación: <strong>dos testigos</strong>, en sitios distintos entre sí y distintos de tus servidores.</li>
        </ul>
    </div>
</div>
