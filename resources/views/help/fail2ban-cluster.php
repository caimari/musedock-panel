<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Fail2ban en el cluster</h4>
        <div class="text-muted small">Qué bloquea cada servidor, cuánto tiempo, qué se copia entre el que manda y sus copias, y cómo consultarlo.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/fail2ban" class="btn btn-outline-info btn-sm"><i class="bi bi-shield-lock me-1"></i> Abrir Fail2Ban</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Qué hace</div>
    <div class="card-body small text-muted">
        <p>fail2ban lee los registros (accesos al panel, al portal, a WordPress, al correo, a SSH) y bloquea las IPs que fallan demasiadas veces.
            Cada servicio vigilado es una <strong>jaula</strong>. Quien llega por Cloudflare se bloquea además en Caddy (el bloqueo de iptables no le afecta,
            porque la conexión viene de Cloudflare).</p>
        <table class="table table-dark table-sm small mb-0">
            <thead><tr><th>Jaula</th><th>Bloquea si…</th><th>Primer bloqueo</th></tr></thead>
            <tbody>
                <tr><td>musedock-panel</td><td>5 fallos en 10 min</td><td>1 h</td></tr>
                <tr><td>musedock-portal</td><td>10 fallos en 10 min</td><td>30 min</td></tr>
                <tr><td>musedock-wordpress</td><td>8 intentos de entrar en 5 min (wp-login, xmlrpc)</td><td>2 h</td></tr>
                <tr><td>musedock-wordpress-slow</td><td>más de 30 en 24 h (ataque lento)</td><td>1 día</td></tr>
                <tr><td>dovecot / postfix-sasl</td><td>5 fallos de contraseña del correo en 10 min</td><td>1 h</td></tr>
                <tr><td>dovecot-slow / postfix-sasl-slow</td><td>más de 15 en 24 h (ataque lento)</td><td>1 día</td></tr>
                <tr><td>sshd</td><td>los valores de fail2ban</td><td>10 min</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-graph-up-arrow me-2"></i>Ataques lentos y bloqueos crecientes</div>
    <div class="card-body small text-muted">
        <p><strong>Ataques lentos:</strong> muchos bots prueban pocas veces por hora (p. ej. 4) para no llegar nunca al límite de las jaulas normales.
            Las jaulas <code>-slow</code> miran las últimas 24 h y los cazan.</p>
        <p class="mb-0"><strong>Bloqueos crecientes:</strong> quien reincide queda bloqueado cada vez el doble (2 h → 4 h → 8 h…) hasta 1 semana, y cuentan
            las reincidencias en todas las jaulas (si ataca SSH y luego WordPress, suma). fail2ban recuerda los bloqueos 30 días para reconocerlos.
            El bloqueo en Caddy dura lo mismo que el de fail2ban.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Qué se copia entre el que manda y sus copias</div>
    <div class="card-body small text-muted">
        <ul class="mb-2">
            <li><strong>Lista blanca (IPs que nunca se bloquean):</strong> la general (<code>[DEFAULT] ignoreip</code> de <code>/etc/fail2ban/jail.local</code>)
                del servidor que manda se <strong>une</strong> a la de cada copia, cada 5 minutos (copia de configuración). <strong>Nunca se quita nada</strong>:
                si quitas una IP en el que manda, en las copias se queda (así no se deja fuera por error una IP propia).
                Al <strong>cambiar el mando</strong> se invierte solo: la copia de configuración la hace siempre quien es copia, contra quien manda en ese momento.
                Requiere que la copia de configuración esté activada en la copia (<a href="/docs/config-mirror" class="text-info">guía</a>); en un nodo sin ella, la lista blanca se pone a mano.</li>
            <li><strong>IPs bloqueadas:</strong> <strong>no se copian</strong>, a propósito. Cada servidor bloquea a quien le ataca a él; una copia no recibe
                visitas mientras no manda, y al tomar el mando empieza a bloquear por su cuenta.</li>
            <li><strong>Jaulas y tiempos:</strong> los pone el panel en cada servidor (<code>config/fail2ban</code>, al actualizar y desde el cluster-worker,
                con la configuración probada antes de recargar). Las del correo, donde el correo está instalado.</li>
        </ul>
        <p class="mb-0">Pon en la lista blanca tus IPs fijas: la red de la VPN, la oficina, los demás servidores y los testigos. Una IP dinámica no sirve.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope-paper me-2"></i>Informe diario y consulta</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>Informe diario de seguridad:</strong> cada noche (21:00 UTC), por correo y Telegram, bloqueos, intentos e IPs que más insisten por servidor y
                jaula. Lo manda el que manda con los datos de sus copias (uno por cluster). Se silencia en Ajustes → Avisos → «Informe diario de seguridad».</li>
            <li><strong>MCP <code>security_attacks</code>:</strong> lo mismo bajo demanda (1–168 h); con <code>all_nodes</code>, en el que manda, también sus copias.</li>
            <li><strong>MCP <code>fail2ban_manage</code>:</strong> sin IP, lista bloqueos y lista blanca (también de otro nodo con <code>node</code>); con IP, desbloquearla
                y, si quieres, añadirla a la lista blanca (solo en el panel al que estás conectado).</li>
            <li><strong>MCP <code>config_mirror</code>:</strong> sin argumentos, muestra el estado de la copia de configuración (también de otro nodo), incluida la lista blanca.</li>
        </ul>
    </div>
</div>
