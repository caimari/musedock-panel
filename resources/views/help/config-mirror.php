<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Copia de configuración del master</h4>
        <div class="text-muted small">Lo que una copia (slave) necesita, además de los ficheros y las bases de datos, para poder tomar el mando sin que fallen las webs.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/cluster#nodos" class="btn btn-outline-info btn-sm"><i class="bi bi-files me-1"></i> Abrir Cluster → Nodos</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Para qué sirve</div>
    <div class="card-body small text-muted">
        <p>Una copia recibe los ficheros de las webs (lsyncd) y las bases de datos (réplica en vivo o volcados periódicos). Pero las webs también dependen de cosas
            que viven <strong>fuera de /var/www</strong>: programas que trabajan de fondo, tareas programadas, servicios propios, webs fijas del Caddyfile, la
            configuración de PHP de cada hosting… Sin ellas, al tomar el mando algunas webs funcionarían a medias (colas paradas, tareas que no corren, una API caída).</p>
        <p class="mb-0">La copia de configuración las trae del servidor que manda y las deja <strong>instaladas pero apagadas</strong>, listas para encenderse.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-check me-2"></i>Qué copia y cómo queda</div>
    <div class="card-body small text-muted">
        <table class="table table-dark table-sm small mb-2">
            <thead><tr><th>Qué</th><th>Cómo queda en la copia</th></tr></thead>
            <tbody>
                <tr><td>Programas de fondo (supervisor): colas, workers…</td><td>Instalados con <code>autostart=false</code></td></tr>
                <tr><td>Tareas programadas: crontabs de los hostings y <code>/etc/cron.d</code></td><td>Comentadas (desactivadas), con sus variables (<code>MAILTO</code>…)</td></tr>
                <tr><td>Servicios propios (systemd), p. ej. una API en <code>/opt</code></td><td>Instalados, sin activar</td></tr>
                <tr><td>Webs fijas del Caddyfile</td><td>Preparadas aparte, sin cargar en Caddy</td></tr>
                <tr><td>Pools de PHP-FPM de cada hosting</td><td>Instalados (probados con <code>php-fpm -t</code>)</td></tr>
                <tr><td>Lista blanca de fail2ban</td><td>Unida a la de la copia (esta sí se aplica al momento; nunca quita nada)</td></tr>
            </tbody>
        </table>
        <ul class="mb-0">
            <li>Se ejecuta <strong>en la copia</strong>, cada 5 minutos, contra el servidor que manda en ese momento.</li>
            <li>Cada cosa se comprueba antes de aplicarla (ejecutable, carpeta, usuario, sintaxis, <code>caddy validate</code>, <code>php-fpm -t</code>); lo que no pasa se omite y se avisa.</li>
            <li><strong>Nunca borra:</strong> lo que el master ya no tiene se aparta con <code>.removed-by-mirror</code>. Copias previas en <code>/var/backups/musedock-mirror/</code>.</li>
            <li>Se pueden excluir elementos propios de la máquina del master (MCP <code>config_mirror</code> con <code>exclude</code>).</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Al cambiar el mando</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li>Al <strong>tomar el mando</strong>, el panel enciende lo copiado: tareas, programas, servicios y el Caddyfile.</li>
            <li>Al <strong>dejar de mandar</strong>, lo apaga, y ese servidor pasa a copiar del nuevo que manda (si tiene la copia activada).</li>
            <li>La dirección se invierte sola: siempre copia quien es copia, de quien manda en ese momento.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-toggle-on me-2"></i>Cuándo activarla y cómo</div>
    <div class="card-body small text-muted">
        <ul class="mb-2">
            <li><strong>Actívala</strong> en las copias que puedan tomar el mando (el relevo del que manda).</li>
            <li><strong>No hace falta</strong> en un nodo que nunca mandará (p. ej. un nodo de VPN, de vídeo o de copias de seguridad): solo le añadiría cosas apagadas que no usa.
                Para la lista blanca de fail2ban en esos nodos, ponla a mano.</li>
            <li><strong>Desactivarla</strong> no quita nada de lo ya copiado; solo deja de ponerse al día. Si esa copia tomara el mando más tarde, le faltarían los cambios posteriores.</li>
        </ul>
        <p class="mb-0">Se activa o desactiva en <strong>Cluster → Nodos → Copia de configuración del master en cada copia</strong> (pide la contraseña de administrador),
            o por MCP con <code>config_mirror</code> (<code>enable</code>). Sin argumentos, <code>config_mirror</code> muestra el estado y lo que haría, también de otro nodo con <code>node</code>.</p>
    </div>
</div>
