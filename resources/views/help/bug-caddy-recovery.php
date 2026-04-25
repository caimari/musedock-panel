<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Restaurar Caddy y la web tras reinstalacion accidental</h4>
        <div class="text-muted small">Caso real: el panel vuelve, pero la web principal desaparece o Caddy queda con permisos rotos.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/bugs-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Bugs
        </a>
        <a href="/settings/caddy" class="btn btn-outline-info btn-sm">
            <i class="bi bi-globe me-1"></i> Caddy
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(248,113,113,.38);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Sintomas</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li><code>https://DOMINIO/</code> deja de cargar, devuelve 404/502 o no responde.</li>
            <li><code>https://IP:8444/</code> puede funcionar y redirigir a <code>/login</code> o <code>/setup</code>.</li>
            <li><code>caddy validate</code> como root dice <code>Valid configuration</code>, pero <code>systemctl restart caddy</code> falla.</li>
            <li>En <code>journalctl -u caddy</code> aparece <code>open /etc/caddy/Caddyfile: permission denied</code>.</li>
            <li>El Caddyfile activo solo contiene el bloque del panel <code>:8444</code> y ya no contiene los dominios web.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-search me-2 text-info"></i>Que paso en el caso real</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Se ejecuto una reinstalacion en un servidor que ya tenia panel, Caddy y una web real funcionando.</li>
            <li>En el paso de Caddy se eligio <code>Reconfigurar</code> en vez de <code>Integrar</code>.</li>
            <li>Eso genero un Caddyfile centrado en el panel y se perdieron del archivo activo los bloques de la web principal.</li>
            <li>El panel PHP seguia vivo en <code>127.0.0.1:8445</code>, pero Caddy ya no servia bien los dominios.</li>
            <li>Ademas, al escribir/restaurar el Caddyfile, el archivo quedo con permisos no legibles por el usuario <code>caddy</code>.</li>
            <li>Como root, <code>caddy validate</code> funcionaba; como servicio systemd, Caddy fallaba por permisos.</li>
            <li>La recuperacion consistio en restaurar un backup de Caddyfile que aun tenia los dominios y despues normalizar permisos.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Arquitectura que debe quedar</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Puerto</th>
                        <th>Servicio</th>
                        <th>Uso</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td><code>80/443</code></td>
                        <td><code>caddy</code></td>
                        <td>Webs y dominios publicos, por ejemplo <code>example.com</code>.</td>
                    </tr>
                    <tr>
                        <td><code>8444</code></td>
                        <td><code>caddy</code></td>
                        <td>Entrada HTTPS del panel admin.</td>
                    </tr>
                    <tr>
                        <td><code>127.0.0.1:8445</code></td>
                        <td><code>musedock-panel</code> PHP</td>
                        <td>Backend interno del panel; nunca debe exponerse publico.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-activity me-2"></i>Diagnostico rapido</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">ss -tlnp | grep -E ':80|:443|:8444|:8445|:2019'
curl --connect-timeout 2 --max-time 8 -v http://127.0.0.1:8445/ -o /dev/null
sudo systemctl status caddy --no-pager
sudo journalctl -u caddy -n 120 --no-pager
sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo grep -n "DOMINIO" /etc/caddy/Caddyfile</pre>
        <ul class="small text-muted mb-0">
            <li>Si <code>8445</code> responde <code>302 Location: /login</code> o <code>/setup</code>, el panel interno esta vivo.</li>
            <li>Si <code>caddy validate</code> valida pero systemd falla, revisa permisos del Caddyfile.</li>
            <li>Si el dominio no aparece en <code>/etc/caddy/Caddyfile</code>, hay que restaurar backup o reconstruir el bloque.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2 text-success"></i>Restaurar con backup de Caddyfile</div>
    <div class="card-body">
        <p class="small text-muted">Primero localiza backups que contengan el dominio real:</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo grep -Rsl "DOMINIO_REAL" \
  /etc/caddy/Caddyfile.bak.* \
  /opt/musedock-panel/install-backup/*/Caddyfile* 2&gt;/dev/null | sort -V | tail -20</pre>
        <p class="small text-muted">Despues restaura el backup elegido:</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo cp /etc/caddy/Caddyfile /root/Caddyfile.panel-only.$(date +%Y%m%d_%H%M%S)

sudo cp /RUTA/AL/BACKUP/Caddyfile.bak /etc/caddy/Caddyfile
sudo chown root:root /etc/caddy/Caddyfile
sudo chmod 0644 /etc/caddy/Caddyfile

sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo systemctl restart caddy
curl -I https://DOMINIO_REAL/</pre>
        <p class="small text-muted mb-0">
            Si la web responde <code>200</code>, <code>301</code> o <code>302</code>, la web queda recuperada. Luego se anade el bloque del panel sin borrar los bloques web.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Anadir bloque del panel sin tocar la web</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo tee -a /etc/caddy/Caddyfile &gt;/dev/null &lt;&lt;'EOF'

https://IP_PUBLICA:8444, https://IP_PRIVADA_WG:8444, https://127.0.0.1:8444, https://localhost:8444, https://DOMINIO_PANEL:8444 {
    tls internal
    reverse_proxy 127.0.0.1:8445 {
        header_up X-Forwarded-Proto https
        header_up X-Real-Ip {remote_host}
    }
}
EOF

sudo chown root:root /etc/caddy/Caddyfile
sudo chmod 0644 /etc/caddy/Caddyfile
sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo systemctl restart caddy</pre>
        <p class="small text-muted mb-0">
            Si ya existe un bloque <code>:8444</code>, no dupliques. Sustituye el bloque viejo por el nuevo o usa el reparador del panel actualizado.
        </p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-exclamation-diamond me-2 text-warning"></i>Si no hubiese backup</div>
    <div class="card-body">
        <p class="small text-muted">Sin backup, no se pierden necesariamente archivos ni BD, pero hay que reconstruir rutas Caddy manualmente desde lo que exista en <code>/var/www/vhosts</code>.</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">ls -la /var/www/vhosts
find /var/www/vhosts -maxdepth 3 -type d -name public -o -name httpdocs
sudo ss -tlnp | grep php-fpm
ls /etc/php/*/fpm/pool.d/</pre>
        <p class="small text-muted">Ejemplo minimo para una app Laravel:</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">DOMINIO_REAL {
    root * /var/www/vhosts/DOMINIO_REAL/httpdocs/public
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}</pre>
        <p class="small text-muted mb-0">
            Ese bloque puede no ser exacto si la cuenta usa pool PHP propio, usuario dedicado, rutas especiales, logs por dominio o proxy hacia otra app. Por eso el backup de Caddyfile es critico.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lock me-2"></i>Permisos correctos de Caddyfile</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo chown root:root /etc/caddy/Caddyfile
sudo chmod 0644 /etc/caddy/Caddyfile
sudo chmod 755 /etc/caddy
sudo systemctl restart caddy</pre>
        <p class="small text-muted mb-0">
            <code>caddy validate</code> ejecutado como root no detecta este problema. La prueba real es <code>systemctl restart caddy</code>, porque el servicio corre como usuario <code>caddy</code>.
        </p>
    </div>
</div>

<div class="card" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-shield-check me-2 text-info"></i>Prevencion en instaladores</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Si existe <code>.env</code>, Caddy activo, dominios en Caddyfile y el panel responde, el instalador no debe permitir reinstalar sin confirmacion literal.</li>
            <li>La opcion segura para servidores existentes es <code>Actualizar</code> o <code>Reparar</code>, no <code>Reinstalar</code>.</li>
            <li>Si se detectan dominios/rutas existentes, <code>Reconfigurar Caddy</code> debe exigir confirmacion literal y avisar que puede tumbar webs.</li>
            <li>Cualquier escritura de Caddyfile debe hacer backup, validar, aplicar permisos <code>root:root 0644</code>, reiniciar y restaurar si falla.</li>
            <li>El reparador TLS no debe ejecutarse como sustituto de restaurar una web perdida: primero se recuperan los bloques de dominio, despues se anade el bloque del panel.</li>
        </ul>
    </div>
</div>
