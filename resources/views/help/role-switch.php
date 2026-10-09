<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Cambio de rol y slave completo</h4>
        <div class="text-muted small">Pasar el mando de un servidor a otro sin caídas, qué tipos de nodo hay y cómo preparar un slave que pueda tomar el mando.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/" class="btn btn-outline-info btn-sm"><i class="bi bi-speedometer2 me-1"></i> Abrir Dashboard</a>
    </div>
</div>

<!-- Qué es -->
<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-2">
            El <strong>cambio de rol</strong> es un relevo <strong>planificado</strong>: los dos servidores están bien y decides que el
            otro pase a ser el master (para mantenimiento, para mover la carga o para probar que el relevo funciona).
            No es lo mismo que el <a href="/docs/failover-modes" class="text-info">failover</a>, que actúa cuando el master <em>se cae</em>.
        </p>
        <p class="text-muted small mb-0">
            Al terminar, el antiguo master queda como <strong>copia en vivo</strong> del nuevo. Para volver, se repite el cambio en sentido contrario:
            solo se copia lo que ha cambiado entre medias, no todo de nuevo. <strong>Nada se borra</strong> en ningún paso.
        </p>
    </div>
</div>

<!-- Tipos de nodo -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Tipos de nodo</div>
    <div class="card-body">
        <p class="small text-muted">En el Dashboard del master, la tarjeta <strong>Cluster</strong> dice qué guarda cada nodo y de qué tipo es:</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle small">
                <thead><tr><th>Tipo</th><th>Qué guarda</th><th>¿Puede tomar el mando?</th></tr></thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-success">Réplica completa</span></td>
                        <td>Ficheros de las webs + todas las bases de datos en vivo (cada instancia de PostgreSQL, MariaDB) + Redis, y correo si es nodo de correo.</td>
                        <td>Sí, si además tiene su IP pública en <em>Cluster → Failover</em>.</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-warning">Réplica a medias</span></td>
                        <td>Ficheros y solo algunas bases en vivo.</td>
                        <td>No: las webs de las bases que faltan se quedarían sin datos.</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-primary">Copia de seguridad</span></td>
                        <td>Ficheros de las webs al instante + bases de datos por <strong>volcados periódicos</strong> (cada pocos minutos, no en vivo).</td>
                        <td>No sin perder lo último: tendría los datos de hasta un intervalo antes. Sirve para recuperar, no para relevar.</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-info">Solo copia de ficheros</span></td>
                        <td>Los ficheros de las webs, sin sus bases de datos.</td>
                        <td>No.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="alert alert-warning small mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Una réplica <strong>no es una copia de seguridad</strong>: es un espejo. Si se borra algo en el master, también se borra en la réplica.
            Las copias de seguridad van aparte (<a href="/docs/default-backups" class="text-info">Backups</a>).
        </div>
    </div>
</div>

<!-- Nodo de copia de seguridad -->
<div class="card mb-4" style="border-color:rgba(13,110,253,.3);">
    <div class="card-header"><i class="bi bi-archive me-2"></i>Nodo de copia de seguridad (volcados periódicos)</div>
    <div class="card-body small text-muted">
        <p>Es un nodo que guarda todo, pero <strong>con retraso</strong> en las bases de datos. Útil como copia de seguridad en otro sitio, o en una máquina que
            hace otras cosas (VPN, vídeo, copias) y no debe mandar.</p>
        <ul>
            <li><strong>Ficheros de las webs:</strong> al instante, como cualquier nodo (Cluster → Archivos, lsyncd).</li>
            <li><strong>Bases de datos:</strong> si en Cluster → Archivos está activada la copia de bases de datos por volcados, cada intervalo (p. ej. 15 min)
                el master vuelca las bases de las webs (PostgreSQL y MariaDB/MySQL), las envía al nodo y allí <strong>se restauran encima</strong> de las anteriores.
                A los nodos que ya replican en vivo no se les mandan (se restaurarían encima de la réplica).</li>
            <li><strong>Qué no tiene:</strong> réplica en vivo, así que si el master cae le faltaría lo guardado desde el último volcado. Por eso el panel no lo cuenta
                como nodo que pueda tomar el mando. Tampoco necesita la <a href="/docs/config-mirror" class="text-info">copia de configuración</a>; su lista blanca
                de fail2ban se pone a mano (<a href="/docs/fail2ban-cluster" class="text-info">guía</a>).</li>
            <li><strong>No escribas en sus bases de datos:</strong> cada restauración las pisa.</li>
        </ul>
        <p class="mb-0">Para convertirlo en relevo de verdad, ponle réplica en vivo de cada base (PostgreSQL y MariaDB) y pasará a <span class="badge bg-success">Réplica completa</span>.</p>
    </div>
</div>

<!-- Preparar un slave completo -->
<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-hdd-stack me-2"></i>Cómo preparar un slave completo</div>
    <div class="card-body">
        <p class="small text-muted">Para un servidor <strong>nuevo</strong> que quieres convertir en copia exacta del master:</p>
        <ol class="small mb-3" style="line-height:1.9;">
            <li><strong>Instala MuseDock Panel</strong> en el servidor nuevo, en la misma versión que el master, con los mismos motores
                (las mismas instancias de PostgreSQL con el mismo número de versión, MariaDB/MySQL, Redis si el master lo usa).</li>
            <li><strong>Únelo a la VPN</strong> (WireGuard) del cluster. Todo el tráfico entre nodos va por ella.</li>
            <li><strong>Únelo al cluster</strong>: en el master, <em>Ajustes → Cluster → Nodos</em>. También por MCP:
                <code>cluster_pairing_open</code> → <code>cluster_pair_request</code> → <code>cluster_pair_approve</code>, sin pasar secretos por el chat.</li>
            <li><strong>Ficheros</strong>: en el master, <em>Cluster → Archivos</em>, añade el nodo como destino (modo lsyncd = al instante).</li>
            <li><strong>Bases de datos, Redis y todo lo demás</strong>: en el servidor nuevo, como root:
                <pre class="bg-dark text-light p-2 rounded small mb-1">php /opt/musedock-panel/bin/cluster-switch.php demote &lt;IP-VPN-del-master&gt;</pre>
                Lo convierte en copia en vivo del master, con avance en pantalla: cada instancia de PostgreSQL (copia inicial con slot de replicación),
                MariaDB (sembrado + réplica GTID), Redis y la configuración. Los datos que tuviera el servidor nuevo
                <strong>no se borran</strong>: se apartan a carpetas <code>.pre-basebackup</code>. Si falta un permiso en el master
                (p. ej. el <code>GRANT</code> de réplica de MariaDB), el paso se para y dice la orden exacta que hay que ejecutar.
                <div class="text-muted">Recomendado lanzarlo dentro de <code>tmux</code>: la primera copia puede tardar.</div></li>
            <li><strong>Correo</strong> (si va a ser nodo de correo): en el master, <em>Mail → Infra → "Instalar réplica de correo"</em>
                (<a href="/docs/mail/ha" class="text-info">guía</a>). El webmail del nodo se prepara con <code>bin/webmail-node-config.php</code>.</li>
            <li><strong>IP pública</strong>: en el master, <em>Cluster → Failover</em>, añade el servidor con su IP pública. Es a la que se moverá el DNS.</li>
            <li><strong>Comprueba</strong>: el Dashboard del master debe mostrar el nodo como <span class="badge bg-success">Réplica completa: puede tomar el mando</span>.
                Después pulsa <em>Pasar el mando a… → el nodo → Comprobar</em> y cancela: verás todas las comprobaciones sin cambiar nada.</li>
        </ol>
        <p class="small text-muted mb-1"><strong>Para que la vuelta copie solo lo cambiado</strong> (y no la base entera), los dos servidores necesitan:</p>
        <ul class="small mb-0">
            <li>PostgreSQL: <code>wal_log_hints = on</code> en cada instancia (permite "rebobinar" con <code>pg_rewind</code>).</li>
            <li>MariaDB: <code>log_slave_updates = 1</code> y GTID (el antiguo master sigue al nuevo desde donde se quedó).</li>
        </ul>
        <p class="small text-muted mb-0">Las comprobaciones previas avisan si falta alguno.</p>
    </div>
</div>

<!-- Cómo se hace el cambio -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Hacer el cambio</div>
    <div class="card-body">
        <p class="small text-muted">En el Dashboard, tarjeta <strong>Cluster</strong>:</p>
        <ul class="small" style="line-height:1.9;">
            <li>En el master: <strong>Pasar el mando a…</strong> y eliges el nodo.</li>
            <li>En un slave: <strong>Tomar el mando</strong> (se le pide al master que te lo pase a ti).</li>
        </ul>
        <p class="small text-muted mb-1">Pasos que hace el panel:</p>
        <ol class="small mb-3" style="line-height:1.9;">
            <li><strong>Comprobaciones</strong>: réplicas al día (lo que le queda por aplicar a cada una), <code>wal_log_hints</code>,
                <code>log_slave_updates</code>, Redis, IPs públicas y ruta entre los nodos. Si una que bloquea falla, no empieza.</li>
            <li><strong>Plan DNS</strong>: lista qué registros cambian, qué dominios van con ellos por CNAME, qué nombres de máquina se quedan
                y qué dominios <strong>no se pueden mover</strong> porque su DNS no está en las cuentas de Cloudflare del panel. Si el nombre del panel
                se va al nuevo master, lo avisa. (Reglas en <a href="/docs/failover-modes" class="text-info">Failover → Qué se mueve en el DNS</a>.)</li>
            <li>Pide tu <strong>contraseña de administrador</strong>.</li>
            <li>El master se <strong>aparta</strong>: deja de servir webs y sus bases pasan a solo lectura. Su panel sigue accesible
                por IP en el puerto del panel (panel de rescate), con un aviso rojo "Servidor APARTADO".</li>
            <li>El elegido se <strong>promueve</strong> (comprobando que cada base acepta escrituras), abre los puertos públicos
                (web y, si tiene correo, los del correo), mueve el DNS a su IP pública e invierte los papeles del relevo.</li>
            <li>El antiguo master se convierte en <strong>copia en vivo</strong> del nuevo, solo con lo cambiado.</li>
        </ol>
        <p class="small text-muted mb-0">
            El avance se ve paso a paso. Al terminar, la ventana ofrece recargar este panel (por IP si su nombre se ha ido al nuevo master)
            o abrir el del otro nodo, y llega un correo con el resultado: registros cambiados, los que fallaron y los dominios que no se
            pudieron mover, para cambiarlos a mano. Durante el cambio de DNS las webs se cortan unos segundos.
            Si el elegido no llega a promoverse, el master se reactiva solo y todo queda como estaba.
            Si se pierde la conexión con el panel es normal (su nombre pasa al nuevo master): sigue el resultado en el panel del otro.
        </p>
    </div>
</div>

<!-- Terminal -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Desde la terminal</div>
    <div class="card-body">
        <p class="small text-muted">Como root, <code>php /opt/musedock-panel/bin/cluster-switch.php</code> con:</p>
        <div class="table-responsive">
            <table class="table table-sm small mb-0">
                <tbody>
                    <tr><td><code>status</code></td><td>Estado del nodo: rol, réplicas, apartado o no.</td></tr>
                    <tr><td><code>switch-check &lt;id&gt;</code></td><td>Solo las comprobaciones previas hacia ese nodo (no cambia nada).</td></tr>
                    <tr><td><code>switch-to &lt;id&gt;</code></td><td>El cambio de rol completo, igual que el botón.</td></tr>
                    <tr><td><code>demote &lt;ip-vpn&gt;</code></td><td>Convertir este servidor en copia en vivo de ese master.</td></tr>
                    <tr><td><code>fence</code> / <code>unfence</code></td><td>Apartar / reactivar este servidor a mano.</td></tr>
                    <tr><td><code>promote</code></td><td>Promover este servidor a master a mano.</td></tr>
                    <tr><td><code>dns-plan</code></td><td>Ver qué registros DNS se moverían, sin tocarlos.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
