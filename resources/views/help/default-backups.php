<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Backups por defecto del sistema</h4>
        <div class="text-muted small">Que guarda MuseDock Panel automaticamente, donde lo guarda, retencion y que queda fuera.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver a Docs
        </a>
        <a href="/settings/system-health" class="btn btn-outline-info btn-sm">
            <i class="bi bi-heart-pulse me-1"></i> Health
        </a>
    </div>
</div>

<div class="alert alert-warning small">
    <strong>Importante:</strong> los backups internos del panel son backups operativos de recuperacion rapida. No sustituyen un backup externo completo del servidor, de los sitios de clientes, de sus bases de datos ni del correo.
</div>

<div class="card mb-4" style="border-color:rgba(56,189,248,.35);">
    <div class="card-header"><i class="bi bi-list-check me-2 text-info"></i>Resumen rapido</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Backup</th>
                        <th>Frecuencia</th>
                        <th>Retencion</th>
                        <th>Ruta</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td>BD del panel <code>musedock_panel</code></td>
                        <td>Cada hora, minuto <code>:02</code></td>
                        <td>48 horas</td>
                        <td><code>/opt/musedock-panel/storage/backups/panel-*.sql.gz</code></td>
                    </tr>
                    <tr>
                        <td>Caddyfile diario</td>
                        <td>Diario, <code>03:17</code></td>
                        <td>15 dias</td>
                        <td><code>/opt/musedock-panel/storage/backups/caddy/daily/</code></td>
                    </tr>
                    <tr>
                        <td>Caddy <code>last-known-good</code></td>
                        <td>Al hacer backup si valida</td>
                        <td>No rota automaticamente</td>
                        <td><code>/opt/musedock-panel/storage/backups/caddy/last-known-good/Caddyfile</code></td>
                    </tr>
                    <tr>
                        <td>Snapshot pre-instalacion/reinstalacion</td>
                        <td>Antes de instalar/reinstalar</td>
                        <td>No rota automaticamente</td>
                        <td><code>/opt/musedock-panel/install-backup/YYYYMMDDHHMMSS/</code></td>
                    </tr>
                    <tr>
                        <td>Caddyfile pre-cambio puntual</td>
                        <td>Antes de reparaciones/cambios sensibles</td>
                        <td>No garantizada; revisar manualmente</td>
                        <td><code>/etc/caddy/Caddyfile.bak.*</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-database me-2"></i>1. Backup horario de la base de datos del panel</div>
    <div class="card-body">
        <p class="small text-muted">Protege la configuracion interna del panel: usuarios admin, settings, nodos, estado de cluster, rutas guardadas y datos propios de MuseDock Panel.</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">/etc/cron.d/musedock-backup
2 * * * * root ... pg_dump -p 5433 musedock_panel | gzip &gt; /opt/musedock-panel/storage/backups/panel-YYYYMMDD_HH.sql.gz
7 * * * * root find /opt/musedock-panel/storage/backups/ -name "panel-*.sql.gz" -mmin +2880 -delete</pre>
        <ul class="small text-muted mb-0">
            <li>Retiene unas 48 horas.</li>
            <li>Corre como <code>root</code> para preparar permisos y ejecuta <code>pg_dump</code> como <code>postgres</code>.</li>
            <li>No cubre bases de datos de clientes ni bases externas.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-globe me-2"></i>2. Backup de Caddy</div>
    <div class="card-body">
        <p class="small text-muted">Protege la configuracion del reverse proxy: dominios, TLS, panel <code>8444</code>, proxies, headers y rutas web.</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">/etc/cron.d/musedock-caddy-backup
17 3 * * * root /opt/musedock-panel/bin/backup-caddy-config.sh &gt;&gt; /opt/musedock-panel/storage/logs/caddy-backup.log 2&gt;&amp;1</pre>
        <ul class="small text-muted mb-0">
            <li><code>daily/</code> guarda snapshots rotatorios durante 15 dias.</li>
            <li><code>last-known-good/</code> guarda la ultima copia que paso <code>caddy validate</code>.</li>
            <li>El reparador TLS y el instalador intentan crear snapshot antes de tocar Caddy.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-camera me-2"></i>3. Snapshot pre-instalacion</div>
    <div class="card-body">
        <p class="small text-muted">Antes de instalar o reinstalar, el instalador guarda una fotografia operativa del servidor.</p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">/opt/musedock-panel/install-backup/YYYYMMDDHHMMSS/
/opt/musedock-panel/install-backup/latest -&gt; ultimo snapshot</pre>
        <p class="small text-muted">Normalmente incluye, si existen:</p>
        <ul class="small text-muted mb-0">
            <li>Servicios activos y puertos escuchando.</li>
            <li>Lista de paquetes.</li>
            <li><code>/etc/caddy/Caddyfile</code>.</li>
            <li>Configuracion Apache relevante si existe.</li>
            <li><code>pg_hba.conf</code> de PostgreSQL.</li>
            <li><code>.env</code> existente del panel.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-exclamation-diamond me-2 text-warning"></i>Que NO cubren estos backups por defecto</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>No respaldan automaticamente <code>/var/www/vhosts</code> completo.</li>
            <li>No respaldan bases de datos de clientes, CMS, WordPress, Laravel u otras apps.</li>
            <li>No respaldan buzones de correo, colas de correo ni historicos de IMAP.</li>
            <li>No sustituyen snapshots del proveedor, backups remotos, S3, Borg/Restic o replicas externas.</li>
            <li>La replica master/slave y el file sync no son backup: si borras o corrompes algo, el cambio puede propagarse.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-search me-2"></i>Comprobar que existen</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo cat /etc/cron.d/musedock-backup
sudo cat /etc/cron.d/musedock-caddy-backup

sudo ls -ltr /opt/musedock-panel/storage/backups/ | tail
sudo ls -ltr /opt/musedock-panel/storage/backups/caddy/daily/ | tail
sudo ls -la /opt/musedock-panel/storage/backups/caddy/last-known-good/
sudo ls -ltr /opt/musedock-panel/install-backup/ | tail</pre>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.35);">
    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2 text-success"></i>Restauraciones rapidas</div>
    <div class="card-body">
        <p class="small text-muted mb-2"><strong>Restaurar Caddy desde last-known-good</strong></p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo cp /etc/caddy/Caddyfile /root/Caddyfile.before-restore.$(date +%Y%m%d_%H%M%S)
sudo cp /opt/musedock-panel/storage/backups/caddy/last-known-good/Caddyfile /etc/caddy/Caddyfile
sudo chown root:root /etc/caddy/Caddyfile
sudo chmod 0644 /etc/caddy/Caddyfile
sudo chmod 755 /etc/caddy
sudo caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
sudo systemctl restart caddy</pre>
        <p class="small text-muted mb-2"><strong>Inspeccionar un backup SQL del panel</strong></p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo ls -ltr /opt/musedock-panel/storage/backups/panel-*.sql.gz | tail
sudo gzip -t /opt/musedock-panel/storage/backups/panel-YYYYMMDD_HH.sql.gz</pre>
        <p class="small text-muted mb-0">La restauracion completa de la BD del panel debe hacerse con el panel parado y sabiendo que reemplaza estado interno. No la ejecutes en caliente sin snapshot del servidor.</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-shield-plus me-2"></i>Politica recomendada para produccion</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Mantener los backups internos por defecto para recuperacion rapida.</li>
            <li>Anadir backup externo diario de <code>/opt/musedock-panel</code>, <code>/etc</code>, <code>/var/www/vhosts</code>, bases de datos de clientes y correo.</li>
            <li>Guardar al menos una copia fuera del servidor: proveedor, S3, Borg, Restic, NAS o nodo backup.</li>
            <li>Probar restauraciones, no solo comprobar que existen ficheros.</li>
            <li>Antes de reinstalar o reconfigurar Caddy en un servidor operativo, hacer snapshot del proveedor ademas de los backups internos.</li>
        </ul>
    </div>
</div>
