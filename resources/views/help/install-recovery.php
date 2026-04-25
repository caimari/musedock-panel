<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Instalacion y recuperacion del panel</h4>
        <div class="text-muted small">Guia base para instalar desde GitHub, actualizar y recuperar credenciales internas si algo se rompe.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm">
            <i class="bi bi-journal-text me-1"></i> Volver a Docs
        </a>
        <a href="/settings/updates" class="btn btn-outline-info btn-sm">
            <i class="bi bi-cloud-arrow-down me-1"></i> Abrir Updates
        </a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,211,238,.35);">
    <div class="card-header"><i class="bi bi-info-circle me-2 text-info"></i>Idea principal</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>La primera instalacion real se hace por shell como <code>root</code>, no desde la web.</li>
            <li>El instalador crea PostgreSQL/BD/usuario/password del panel y escribe <code>/opt/musedock-panel/.env</code>.</li>
            <li>El setup web <code>/setup</code> solo crea el primer usuario admin del panel.</li>
            <li>No tienes que crear manualmente el usuario PostgreSQL <code>musedock_panel</code> en un servidor virgen.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-download me-2"></i>Primera instalacion desde GitHub</div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            En un servidor limpio Ubuntu/Debian, entra por SSH. Si ya estas como <code>root</code>, ejecuta:
        </p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">apt-get update
apt-get install -y git ca-certificates curl
cd /opt
git clone https://github.com/caimari/musedock-panel.git musedock-panel
cd /opt/musedock-panel
bash install.sh</pre>
        <p class="small text-muted mb-2">
            Si entras con un usuario con sudo, no pegues <code>sudo -i</code> junto con todo el bloque. Usa comandos con <code>sudo</code> explicito:
        </p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo apt-get update
sudo apt-get install -y git ca-certificates curl
cd /opt
sudo git clone https://github.com/caimari/musedock-panel.git musedock-panel
cd /opt/musedock-panel
sudo bash install.sh</pre>
        <div class="small text-muted">
            <code>install.sh</code> es interactivo: debe mostrar seleccion de idioma, opciones de instalacion y progreso. Si no ves nada, confirma que estas en
            <code>/opt/musedock-panel</code> y ejecuta <code>sudo bash -x install.sh</code> para ver trazas.
            Si ya tienes el repo descargado, no vuelvas a clonar encima: entra en <code>/opt/musedock-panel</code> y ejecuta el instalador/update segun corresponda.
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-database me-2"></i>Que crea el instalador</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Instala paquetes base, PHP-FPM, PostgreSQL y Caddy si faltan.</li>
            <li>Crea un cluster PostgreSQL interno para el panel, normalmente en puerto <code>5433</code>.</li>
            <li>Crea la base <code>musedock_panel</code>.</li>
            <li>Crea el usuario PostgreSQL <code>musedock_panel</code> con password aleatoria.</li>
            <li>Escribe las credenciales internas en <code>/opt/musedock-panel/.env</code>.</li>
            <li>Aplica <code>database/schema.sql</code> y migraciones necesarias.</li>
            <li>Crea el servicio systemd <code>musedock-panel</code>, que corre como <code>root</code>.</li>
            <li>Configura Caddy para publicar el panel por <code>https://IP:8444/setup</code> o el puerto elegido.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-lock me-2"></i>Usuarios y passwords: que es cada cosa</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Elemento</th>
                        <th>Quien lo crea</th>
                        <th>Donde vive</th>
                        <th>Para que sirve</th>
                    </tr>
                </thead>
                <tbody class="small text-muted">
                    <tr>
                        <td><code>postgres</code> superusuario</td>
                        <td>PostgreSQL/paquete del sistema</td>
                        <td>Sistema operativo + PostgreSQL</td>
                        <td>Administracion y reparacion. Normalmente no necesitas password: usa <code>runuser -u postgres -- psql</code>.</td>
                    </tr>
                    <tr>
                        <td><code>musedock_panel</code> usuario DB</td>
                        <td><code>install.sh</code></td>
                        <td>PostgreSQL puerto <code>5433</code></td>
                        <td>Usuario que usa el panel para conectar a su BD interna.</td>
                    </tr>
                    <tr>
                        <td><code>DB_PASS</code></td>
                        <td><code>install.sh</code></td>
                        <td><code>/opt/musedock-panel/.env</code></td>
                        <td>Password aleatoria del usuario DB del panel.</td>
                    </tr>
                    <tr>
                        <td>Admin del panel</td>
                        <td>Setup web <code>/setup</code></td>
                        <td>Tabla <code>panel_admins</code></td>
                        <td>Usuario humano para entrar al panel. No es usuario Linux ni usuario PostgreSQL.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-ui-checks me-2"></i>Opciones importantes del instalador</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li><strong>Idioma:</strong> elige ES/EN al inicio.</li>
            <li><strong>Puerto del panel:</strong> por defecto <code>8444</code>. Si lo cambias, revisa firewall/Caddy.</li>
            <li><strong>PHP:</strong> elige version base para el runtime del panel.</li>
            <li><strong>PostgreSQL:</strong> en servidor virgen lo instala y crea el cluster interno. En reinstalacion reutiliza credenciales existentes si hay <code>.env</code>.</li>
            <li><strong>MySQL:</strong> si detecta MySQL puede pedir metodo de autenticacion para gestionar BDs de clientes; no es necesario para la BD interna del panel.</li>
            <li><strong>Firewall:</strong> detecta UFW/iptables. Puedes saltarlo, usar el firewall existente, activar UFW existente o instalar UFW si no hay firewall.</li>
            <li><strong>IP/CIDR permitida:</strong> puedes introducir una IP o rango, por ejemplo <code>203.0.113.10</code> o <code>203.0.113.0/24</code>, para abrir el puerto del panel solo a esa fuente.</li>
            <li><strong>Fail2Ban:</strong> pregunta antes de sincronizar jails si ya existe, o antes de instalarlo si falta.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-rocket-takeoff me-2"></i>Primer acceso web</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">https://IP_DEL_SERVIDOR:8444/setup</pre>
        <ul class="small text-muted mb-0">
            <li>En esa pantalla creas el primer admin del panel.</li>
            <li>No deberia pedirte datos PostgreSQL si <code>install.sh</code> termino bien.</li>
            <li>Si muestra error de PostgreSQL, revisa <code>.env</code>, el servicio PostgreSQL y el puerto <code>5433</code>.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-cloud-arrow-down me-2"></i>Actualizar desde shell</div>
    <div class="card-body">
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo bash /opt/musedock-panel/bin/update.sh --auto</pre>
        <div class="small text-muted mb-2">Ver progreso:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo tail -f /opt/musedock-panel/storage/logs/update.log</pre>
        <div class="small text-muted mb-2">Ver version instalada:</div>
        <pre class="small p-3 rounded mb-0" style="background:#0f172a;color:#e2e8f0;">grep "'version'" /opt/musedock-panel/config/panel.php</pre>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-wrench-adjustable me-2 text-warning"></i>Recuperacion PostgreSQL / .env</div>
    <div class="card-body">
        <p class="small text-muted">
            Si el panel no conecta a su BD interna, no necesitas conocer una password root de PostgreSQL en una instalacion normal.
            Entra como superusuario local con peer auth:
        </p>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo runuser -u postgres -- psql -p 5433</pre>
        <div class="small text-muted mb-2">Ver credenciales guardadas del panel:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo grep -E '^(DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASS)=' /opt/musedock-panel/.env</pre>
        <div class="small text-muted mb-2">Resetear password del usuario DB del panel si hace falta:</div>
        <pre class="small p-3 rounded" style="background:#0f172a;color:#e2e8f0;">sudo runuser -u postgres -- psql -p 5433 -c "ALTER USER musedock_panel WITH PASSWORD 'NUEVA_PASSWORD_SEGURA';"
sudo sed -i 's/^DB_PASS=.*/DB_PASS=NUEVA_PASSWORD_SEGURA/' /opt/musedock-panel/.env
sudo systemctl restart musedock-panel</pre>
        <ul class="small text-muted mb-0">
            <li>El superusuario <code>postgres</code> normalmente se recupera por usuario de sistema, no por password.</li>
            <li>No cambies la password de <code>postgres</code> salvo que sepas por que lo haces.</li>
            <li>Si existia una password previa de <code>postgres</code>, el instalador no deberia sobrescribirla.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>Archivos y comandos utiles</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Accion</th><th>Comando / Ruta</th></tr></thead>
                <tbody class="small text-muted">
                    <tr><td>Directorio del panel</td><td><code>/opt/musedock-panel</code></td></tr>
                    <tr><td>Config sensible</td><td><code>/opt/musedock-panel/.env</code></td></tr>
                    <tr><td>Logs del panel</td><td><code>/opt/musedock-panel/storage/logs/</code></td></tr>
                    <tr><td>Servicio</td><td><code>sudo systemctl status musedock-panel</code></td></tr>
                    <tr><td>Reiniciar</td><td><code>sudo systemctl restart musedock-panel</code></td></tr>
                    <tr><td>Logs systemd</td><td><code>sudo journalctl -u musedock-panel -f</code></td></tr>
                    <tr><td>Backup BD panel</td><td><code>/opt/musedock-panel/storage/backups/</code></td></tr>
                    <tr><td>Cron backup</td><td><code>/etc/cron.d/musedock-backup</code></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Checklist si algo falla</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>Confirmar que existe <code>/opt/musedock-panel/.env</code>.</li>
            <li>Confirmar que PostgreSQL esta activo: <code>systemctl status postgresql</code>.</li>
            <li>Confirmar que el cluster panel escucha en <code>5433</code>: <code>pg_lsclusters</code>.</li>
            <li>Probar conexion DB con los datos de <code>.env</code>.</li>
            <li>Revisar logs: <code>storage/logs/panel-error.log</code> y <code>journalctl -u musedock-panel</code>.</li>
            <li>Si el problema es update, ejecutar <code>sudo bash /opt/musedock-panel/bin/update.sh --auto</code>.</li>
            <li>Si el navegador muestra <code>ERR_SSL_PROTOCOL_ERROR</code> en <code>https://IP:8444</code>, revisar <a href="/docs/bugs/err-ssl-protocol-error" class="text-info">la guia especifica de TLS/Caddy por IP</a>.</li>
        </ol>
    </div>
</div>
