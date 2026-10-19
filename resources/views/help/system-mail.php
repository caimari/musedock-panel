<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Correo del sistema (cron, root…)</h4>
        <div class="text-muted small">Qué es el correo que genera el propio servidor, por qué puede rebotar y llenar la cola, y qué comprueba y arregla el panel.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/" class="btn btn-outline-info btn-sm"><i class="bi bi-speedometer2 me-1"></i> Ir al dashboard</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Qué es y por qué importa</div>
    <div class="card-body small text-muted">
        <p>Cuando una tarea programada (cron) falla, o el sistema tiene algo que decir al administrador, Linux manda un correo a un usuario local (<code>root</code>, el usuario de una web…).
            Ese correo sale con un remitente y un destinatario <em>sin dominio</em>, y Postfix les añade el <strong>dominio de origen</strong> (<code>myorigin</code>).</p>
        <p>Si Postfix no considera «suyo» ese dominio, no lo entrega en el propio servidor: lo <strong>manda fuera</strong> (por el relé de salida, si lo hay). Si el destino lo rechaza,
            el mensaje se queda en la cola y se <strong>reintenta sin parar</strong>.</p>
        <p class="mb-0"><strong>Caso real (2026-10-18):</strong> una tarea de cron que fallaba cada minuto generó unos 1.100 correos al día; dieron la vuelta de un servidor a otro y se
            acumularon 1.305 mensajes en la cola de un nodo, que el proveedor notó como mucho tráfico saliente por el puerto 25. No había intrusión ni spam: eran avisos internos.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-search me-2"></i>Lo que el panel comprueba (cada 30 minutos, en cada nodo)</div>
    <div class="card-body small text-muted">
        <p>Aparece en el dashboard, en el cuadro amarillo «Este servidor podría tomar el mando, pero no está completamente preparado», y se avisa una vez por cada cosa nueva.</p>
        <table class="table table-dark table-sm small mb-0">
            <thead><tr><th style="width:30%">Aviso</th><th>Qué significa y qué hacer</th></tr></thead>
            <tbody>
                <tr><td>El correo del propio servidor sale como <code>usuario@dominio</code> y Postfix no lo considera local</td>
                    <td>Es la causa del caso real. Mira «Qué hace el panel» más abajo: en un servidor normal se puede arreglar con un comando; en un nodo cuyo origen es un dominio con correo real, no se toca solo.</td></tr>
                <tr><td>La cola de Postfix tiene muchos mensajes (por defecto, más de 100)</td>
                    <td>Mira <code>mailq | head</code>. Si son avisos internos que rebotan, arregla la causa (la tarea que falla). Se ajusta con el ajuste <code>mail_queue_warn</code>.</td></tr>
                <tr><td>Una tarea del panel está a la vez en un crontab de usuario y en <code>/etc/cron.d</code></td>
                    <td>La del usuario suele fallar (sin permiso para escribir su registro) y cada fallo es un correo por minuto. Quita la línea del crontab de usuario: la buena es la de <code>/etc/cron.d</code>.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-wrench-adjustable me-2"></i>Qué hace el panel y cuándo NO lo toca</div>
    <div class="card-body small text-muted">
        <p>Se puede dejar que el correo del sistema se entregue <strong>en el propio servidor</strong> (<code>/var/mail/root</code>…) en vez de salir. El panel lo hace añadiendo el nombre de origen a
            <code>mydestination</code> de Postfix, pero <strong>solo en el caso seguro</strong>. Decide por lo que hay en cada nodo, no por su tipo:</p>
        <table class="table table-dark table-sm small mb-3">
            <thead><tr><th style="width:38%">Situación del nodo</th><th>Qué hace</th></tr></thead>
            <tbody>
                <tr><td>El origen es <strong>el nombre del propio servidor</strong> (por ejemplo <code>mail.ejemplo.com</code>) y no tiene MX ni es un dominio de correo</td><td>Lo añade a <code>mydestination</code>. El correo del sistema se queda en el servidor.</td></tr>
                <tr><td>El origen es <strong>un dominio con correo real</strong> (típico de un nodo relé o de envío)</td><td><strong>No lo toca</strong>: entregarlo en local capturaría todo el correo que ese nodo mande hacia ese dominio y se perdería. Solo avisa.</td></tr>
                <tr><td>El dominio es de buzones virtuales, alias, relé o transporte de Postfix, o tiene MX público, o es un dominio de correo del panel</td><td>No lo toca.</td></tr>
                <tr><td><code>mydestination</code> tiene una configuración propia del administrador</td><td>No lo toca.</td></tr>
                <tr><td>No puede comprobar algo (p. ej. sin permiso para leer las tablas de Postfix)</td><td>No toca nada.</td></tr>
                <tr><td>El servidor no tiene Postfix</td><td>No hace nada.</td></tr>
            </tbody>
        </table>
        <p class="mb-2"><strong>Cuando sí aplica:</strong> hace antes una copia de <code>main.cf</code> en <code>/var/lib/musedock/backup-postfix/</code>, valida con <code>postfix check</code> y, si algo falla,
            restaura la copia y te avisa. Cada cambio queda en el registro de actividad.</p>
        <p class="mb-0">El panel nunca cambia <code>myorigin</code> por su cuenta, porque cambia el remitente de todo el correo local sin dominio (incluido el de las webs alojadas) y puede afectar a SPF.
            Esa decisión es tuya.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Cómo se usa</div>
    <div class="card-body small text-muted">
<pre class="p-2 rounded mb-2" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">php /opt/musedock-panel/bin/mail-system-local.php           # simula: dice qué haría y por qué (no cambia nada)
php /opt/musedock-panel/bin/mail-system-local.php --apply   # lo aplica (como root)
php /opt/musedock-panel/bin/mail-system-local.php --mode=auto|warn|off</pre>
        <ul class="mb-2">
            <li><strong>warn</strong> (por defecto): solo avisa en el dashboard y da el comando. No cambia nada solo.</li>
            <li><strong>auto</strong>: el cron del cluster lo mantiene, como mucho una vez por hora. Úsalo cuando ya lo hayas aplicado a mano en un nodo y quieras que se mantenga.</li>
            <li><strong>off</strong>: no hace nada.</li>
        </ul>
        <p class="mb-2"><strong>Probar primero en un solo nodo:</strong> ejecuta la simulación, mira la «Decisión» y, si dice <code>add</code>, aplícalo allí con <code>--apply</code>. Comprueba con
            <code>postconf mydestination</code> y mandando un correo de prueba a <code>root</code> (debe quedar en <code>/var/mail/root</code>).</p>
        <p class="mb-0"><strong>Deshacer:</strong> la salida de <code>--apply</code> da el comando exacto: copiar la copia de <code>main.cf</code> de vuelta y <code>postfix reload</code>.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lightbulb me-2"></i>Consejos para quien administra el cluster</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>Arregla la causa, no solo el síntoma.</strong> Si un cron falla cada minuto, corrígelo; o, si es ruido conocido, pon <code>MAILTO=""</code> en ese crontab.</li>
            <li><strong>Un buzón local puede crecer.</strong> Una tarea que falla cada minuto añade unos 1.100 mensajes al día a <code>/var/mail/&lt;usuario&gt;</code>. Pequeños, pero se acumulan.</li>
            <li><strong>Nodos de relé o de envío:</strong> su origen suele ser el dominio principal del servicio (con correo real en otro servidor). El correo del sistema sale como <code>usuario@dominio</code> y <strong>se entrega al servidor de correo de ese dominio</strong>: llega a un buzón real si ese dominio tiene un alias o un catch-all para ese usuario (por ejemplo, un alias de <code>root</code> o un catch-all), y rebota si no. Por eso el panel lo muestra solo como <em>información</em> (no como «no preparado para tomar el mando») y no lo toca. El panel lo <strong>comprueba con el registro de Postfix</strong>: si el correo del sistema de ese nodo se entrega, no muestra nada; si rebota, avisa en amarillo (hace falta un alias de <code>root</code> o un catch-all en el servidor de correo del dominio); si todavía no hay correo reciente que mirar, lo deja como información. Si la cola de Postfix del nodo crece, avisa aparte.</li>
            <li><strong>Los crontabs de usuario se copian</strong> del master a los nodos con la copia de configuración: una línea duplicada puede viajar de un nodo a otro.</li>
        </ul>
    </div>
</div>
