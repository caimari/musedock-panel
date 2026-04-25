<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Backups de Caddy y reconstruccion sin backup</h4>
        <div class="text-muted small">Politica recomendada para no perder rutas web/panel al tocar Caddy.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/bugs-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Bugs
        </a>
        <a href="/docs/bugs/caddy-recovery" class="btn btn-outline-info btn-sm">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Guia de recuperacion
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-header"><i class="bi bi-shield-check me-2 text-success"></i>Politica correcta</div>
    <div class="card-body">
        <p class="small text-muted">Los backups de Caddy no deben depender solo de una accion manual. La estrategia segura combina tres capas:</p>
        <ul class="small text-muted mb-0">
            <li><strong>Pre-cambio:</strong> antes de que el instalador, reparador TLS o panel escriban Caddy, guardar una copia inmediata.</li>
            <li><strong>Diario rotatorio:</strong> un snapshot automatico cada dia, con retencion de 15 dias por defecto.</li>
            <li><strong>Last-known-good:</strong> una copia validada con <code>caddy validate</code> que no se borra por rotacion normal.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>Que se guarda</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td><code>/etc/caddy/Caddyfile</code></td>
                        <td>Fuente principal de dominios, reverse proxies, TLS, headers y rutas del panel.</td>
                    </tr>
                    <tr>
                        <td><code>/var/lib/caddy/.config/caddy/autosave.json</code></td>
                        <td>Config adaptada/autoguardada por Caddy; util cuando se uso API admin o <code>--resume</code>.</td>
                    </tr>
                    <tr>
                        <td><code>last-known-good/Caddyfile</code></td>
                        <td>Ultima config que paso validacion; sirve cuando el Caddyfile actual ya esta roto.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Cron instalado por MuseDock</div>
    <div class="card-body">
        <p class="small text-muted">El panel instala un cron diario para Caddy:</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">/etc/cron.d/musedock-caddy-backup
17 3 * * * root /opt/musedock-panel/bin/backup-caddy-config.sh &gt;&gt; /opt/musedock-panel/storage/logs/caddy-backup.log 2&gt;&amp;1</pre>
        <p class="small text-muted mb-0">La retencion por defecto es de 15 dias. Puede ajustarse exportando <code>CADDY_BACKUP_RETENTION_DAYS</code> si se ejecuta el script manualmente.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2 text-info"></i>Restaurar desde backup</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo ls -ltr /opt/musedock-panel/storage/backups/caddy/daily
sudo ls -la /opt/musedock-panel/storage/backups/caddy/last-known-good

sudo cp /etc/caddy/Caddyfile /root/Caddyfile.before-restore.$(date +%Y%m%d_%H%M%S)
sudo cp /opt/musedock-panel/storage/backups/caddy/last-known-good/Caddyfile /etc/caddy/Caddyfile
sudo chown root:root /etc/caddy/Caddyfile
sudo chmod 0644 /etc/caddy/Caddyfile
sudo chmod 755 /etc/caddy
sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo systemctl restart caddy</pre>
        <p class="small text-muted mb-0">Si necesitas una version concreta, usa uno de los snapshots de <code>daily/</code> en vez de <code>last-known-good/</code>.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.38);">
    <div class="card-header"><i class="bi bi-tools me-2 text-warning"></i>Como se reconstruye sin backup</div>
    <div class="card-body">
        <p class="small text-muted">Sin backup no se pierden automaticamente archivos ni bases de datos. El problema es que se pierde el mapa que une dominio, raiz web, PHP-FPM, proxies, headers y TLS. La reconstruccion se hace por inventario.</p>
        <p class="small text-muted mb-2"><strong>1. Detectar dominios y raices reales</strong></p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo find /var/www/vhosts -maxdepth 3 -type d \( -name public -o -name httpdocs \) | sort
sudo find /var/www/vhosts -maxdepth 2 -type f \( -name .env -o -name composer.json -o -name package.json \) | sort</pre>
        <p class="small text-muted mb-2"><strong>2. Detectar PHP-FPM y sockets disponibles</strong></p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo ls -la /run/php/
sudo ls /etc/php/*/fpm/pool.d/ 2&gt;/dev/null
sudo ss -xlpn | grep php-fpm</pre>
        <p class="small text-muted mb-2"><strong>3. Reconstruir bloques Caddy segun tipo de app</strong></p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;"># Laravel / apps con public/
example.com, www.example.com {
    root * /var/www/vhosts/example.com/httpdocs/public
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}

# PHP clasico / CMS sin public/
example.net, www.example.net {
    root * /var/www/vhosts/example.net/httpdocs
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}

# App por reverse proxy
app.example.org {
    reverse_proxy 127.0.0.1:3000
}</pre>
        <p class="small text-muted mb-0">Despues se valida con <code>caddy validate</code>, se reinicia Caddy y se prueba cada dominio con <code>curl -I https://DOMINIO/</code>.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(248,113,113,.38);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Que se pierde si no hay backup</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Se pueden reconstruir dominios y certificados si DNS apunta al servidor y 80/443 estan abiertos.</li>
            <li>No se recuperan de forma fiable reglas especiales: redirects, headers, logs por dominio, websockets, limits, proxies internos o rutas antiguas.</li>
            <li>Si habia certificados manuales, DNS-01, rutas API de Caddy o snippets personalizados, hay que inferirlos desde scripts, historico shell, repos o backups externos.</li>
            <li>La reconstruccion manual es lenta y tiene mas riesgo que restaurar una copia validada.</li>
        </ul>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-check2-square me-2"></i>Checklist operativo antes de tocar Caddy</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo /opt/musedock-panel/bin/backup-caddy-config.sh
sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo cp /etc/caddy/Caddyfile /root/Caddyfile.manual.$(date +%Y%m%d_%H%M%S)</pre>
        <ul class="small text-muted mb-0">
            <li>Si el servidor ya tiene dominios en produccion, usa <code>Integrar</code>, no <code>Reconfigurar</code>.</li>
            <li>Si Caddy falla despues de un cambio, restaura primero el Caddyfile anterior y solo despues repara el bloque del panel.</li>
            <li>El permiso final debe ser <code>root:root 0644</code> y el directorio <code>/etc/caddy</code> debe permitir lectura/travesia al usuario <code>caddy</code>.</li>
        </ul>
    </div>
</div>
