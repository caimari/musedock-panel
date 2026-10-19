<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Vigilancia de nodos y relevos</h4>
        <div class="text-muted small">Lo que el panel comprueba y arregla solo en cada servidor antes, durante y después de un relevo, y lo que te avisa si no puede.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/" class="btn btn-outline-info btn-sm"><i class="bi bi-speedometer2 me-1"></i> Ir al dashboard</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Para qué sirve</div>
    <div class="card-body small text-muted">
        <p>Un relevo puede salir bien (el DNS se mueve, las webs responden) y aun así dejar al cluster <strong>sin protección</strong>: el servidor que cayó vuelve, pero no arranca el correo, no
            consigue ser copia de la base de datos, o el que ahora manda no copia ficheros a nadie. Si nadie lo mira, se descubre en el siguiente fallo.</p>
        <p class="mb-0">Cada servidor ejecuta, desde el cron del cluster (cada minuto), una serie de comprobaciones y arreglos <strong>seguros</strong>. Ninguno borra datos. Lo que no es seguro
            hacer solo se avisa, con el motivo, y se decide a mano.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-wrench-adjustable me-2"></i>Lo que se arregla solo</div>
    <div class="card-body small text-muted">
        <table class="table table-dark table-sm small mb-0">
            <thead><tr><th style="width:26%">Problema</th><th>Qué hace el panel</th></tr></thead>
            <tbody>
                <tr><td>Un servicio de correo o Caddy <strong>fallido</strong></td><td>A los 2 minutos hace <code>reset-failed</code> y lo arranca; como mucho 3 intentos, separados 5 minutos. Si sigue caído a los 10 minutos, avisa con las últimas líneas de su registro. Un servicio <strong>parado a propósito</strong> (inactivo) nunca se arranca solo: solo se avisa a los 15 minutos.</td></tr>
                <tr><td>Un slave que vigila <strong>su propia IP</strong> como si fuera el master</td><td>Pasa tras un relevo si la IP guardada del master era la de este mismo servidor. Se sustituye por la del master real (la que manda los latidos) y se limpian las alertas de «master caído».</td></tr>
                <tr><td>Servicios que escuchan en la IP de la VPN y arrancan <strong>antes que ella</strong></td><td>Dovecot y PostgreSQL arrancan ahora después de WireGuard (<code>zz-musedock-after-wg.conf</code>), y Dovecot reintenta solo si falla. Vale desde el siguiente arranque.</td></tr>
                <tr><td>Réplica de PostgreSQL con parámetros <strong>menores</strong> que su primario</td><td>PostgreSQL se niega a arrancar una réplica con <code>max_wal_senders</code>, <code>max_connections</code>, <code>max_worker_processes</code>, <code>max_prepared_transactions</code> o <code>max_locks_per_transaction</code> menores que los del primario. Al reincorporar un nodo (demote), el panel lee los del primario y los iguala <em>antes</em> de arrancar, en un fichero propio de <code>conf.d</code> fuera del directorio de datos (sobrevive a que se reconstruya). Si aun así falla, lee el motivo del registro de PostgreSQL, lo aplica y arranca una vez más.</td></tr>
                <tr><td>El master <strong>no copia ficheros</strong></td><td>Si la copia de ficheros se usa en el cluster pero este master no la está emitiendo (lsyncd sin activar, parado o sin instalar), la pone en marcha hacia cada slave que ya contesta, cada 10 minutos. Si lsyncd no estaba instalado, lo instala. Un slave tiene lsyncd instalado pero <em>parado</em>: solo arranca si el nodo pasa a mandar.</td></tr>
                <tr><td>Caddy <strong>sin el puerto 80</strong> (arrancó con la configuración guardada)</td><td>Crea un servidor :80 con la redirección http → https (308, dejando pasar el desafío HTTP-01 de los certificados), <strong>solo si nadie más escucha en el :80</strong> (ni el http de Caddy ni caddy-l4) y solo creando su propia clave: nunca toca lo existente. Si Caddy está activo y el :80 sigue sin escucharse, avisa en el preflight y el dashboard.</td></tr>
                <tr><td>Solo-lectura de arranque que ya no procede</td><td>Si el nodo ya no está apartado, quita el fichero de solo-lectura que dejó el guardián de arranque.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bell me-2"></i>Lo que solo se avisa (y por qué no se arregla solo)</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>PostgreSQL parado:</strong> no se arranca solo. Tras un relevo puede ser una réplica que no pudo arrancar, y arrancarla mal la dejaría como un segundo primario. El aviso incluye el motivo probable del registro (por ejemplo «<code>max_wal_senders</code> debe ser ≥ 10»).</li>
            <li><strong>Master caducado con escrituras propias:</strong> el panel no lo convierte en réplica solo, porque al reconstruirlo se perdería lo que escribió tras el relevo. Se aparta y te lo dice; decides con <code>php bin/cluster-switch.php demote &lt;ip-del-nuevo-master&gt;</code>.</li>
            <li><strong>Nodo registrado por un nombre que el relevo movió:</strong> si un nodo se contacta por un nombre de DNS (por ejemplo <code>servidor-a.ejemplo.com</code>) y el relevo mueve ese nombre a otro servidor, el panel acaba llamándose a sí mismo y falla con un error de TLS que no dice nada. Ahora el error lo explica y, en la preparación del nodo, se avisa; también si parece un duplicado de otro nodo. No cambia ni borra nada: registra el nodo por su IP de VPN o quita el duplicado desde Cluster.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Preparación para tomar el mando</div>
    <div class="card-body small text-muted">
        <p>Cada 30 minutos cada servidor comprueba <strong>si podría ser el master de verdad</strong>, y si algo falta lo dice <em>antes</em> de que haga falta: en el dashboard («Este servidor podría tomar el mando, pero no está completamente preparado»), por el canal de avisos (tipo <em>Nodo no preparado para tomar el mando</em>, una vez por cada cosa nueva) y en la herramienta MCP <code>failover_preflight</code>.</p>
        <ul class="mb-2">
            <li>La IP del master que vigila un slave existe y no es la suya.</li>
            <li>Si se usa la copia de ficheros: lsyncd está instalado, existe la clave SSH y, en el master, lsyncd corre.</li>
            <li>Los parámetros de réplica de PostgreSQL de este nodo y del otro cuadran (el menor no podría seguir al mayor tras un cambio de mando).</li>
            <li>No hay servicios que lleven caídos más de 5 minutos.</li>
            <li>Ningún nodo está registrado por un nombre que apunta a este mismo servidor.</li>
            <li>Información: si falta el guardián de arranque (ver abajo).</li>
        </ul>
        <p class="mb-0">Los avisos de servicio caído y de nodo no preparado son tipos que puedes silenciar o enrutar por canal en <strong>Ajustes → Avisos</strong>. Mejor no silenciarlos: tras un relevo, un servicio parado deja al nodo sin proteger.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-power me-2"></i>Guardián de arranque (opcional, se instala a mano)</div>
    <div class="card-body small text-muted">
        <p>Un master que vuelve tras un reinicio creyéndose master, mientras otro nodo ya manda, arranca sus bases de datos <strong>escribibles</strong>. Hasta que el cron del cluster lo aísla pasan minutos (en un caso real, 2,5), y las apps escriben en ese tiempo: esos datos impiden reincorporarlo solo.</p>
        <p>El guardián es una unidad de systemd que corre <strong>antes</strong> de las bases de datos de datos, Caddy y el correo. Si el nodo era master y otro se promovió después, lo aparta ya en el arranque (Caddy y correo no arrancan, PostgreSQL de datos arranca en solo lectura); el cluster-worker termina el aislamiento, avisa y propone la reincorporación.</p>
        <ul class="mb-2">
            <li><strong>Falla siempre abierto:</strong> si no hay red, no contestan los otros nodos o hay cualquier error, deja arrancar. Un master legítimo nunca se aparta por no poder preguntar.</li>
            <li><strong>Coste:</strong> las bases de datos de datos esperan a la red y a la VPN antes de arrancar (como mucho unos 2 minutos si la red tarda). En un master es el precio de no escribir en vacío.</li>
            <li>Instalar (como root): <code>/opt/musedock-panel/bin/install-boot-guard.sh</code>. Ver la unidad antes: <code>--dry-run</code>. Probar la decisión sin aislar nada: <code>php /opt/musedock-panel/bin/boot-guard.php --dry-run</code>. Quitarlo: <code>--remove</code>.</li>
        </ul>
        <p class="mb-0">No hace demote ni toca PostgreSQL en marcha: dentro de una unidad que debe terminar antes que PostgreSQL, eso se bloquearía a sí mismo. Eso lo hace el cluster-worker después.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Promover un nodo: qué se comprueba y qué se deshace</div>
    <div class="card-body small text-muted">
        <p>Promover un slave a master (botón «Promover» de Cluster, <code>cluster-switch.php promote</code>) primero <strong>aparta al master actual</strong> para que no haya dos. Esa orden se cumple parando su Caddy, y por ese Caddy llegó la propia petición: la respuesta se perdía, quien promovía creía que el aislamiento había fallado, abortaba… y el otro se quedaba apartado sin que nadie mandara. Pasó el 2026-10-18.</p>
        <ul class="mb-2">
            <li><strong>El nodo contesta antes de apartarse</strong> (el aislamiento y la reactivación se hacen 2 s después, aparte) y quien lo pide <strong>lo comprueba</strong> preguntando por su estado (<code>cluster_fenced</code>) hasta 45 s, en vez de fiarse de la respuesta.</li>
            <li><strong>Si la promoción se aborta</strong> tras haberle pedido apartarse, el panel lo <strong>reactiva</strong> (y lo comprueba) y avisa; nunca lo deja apartado en silencio.</li>
            <li><strong>Un master sano no se aparta «a mano»</strong>: si el master responde (panel y web), «Promover» se rechaza y manda a <em>Pasar el mando a…</em> (cambio planificado, con comprobaciones y vuelta atrás). Si de verdad quieres promover igualmente: <code>php bin/cluster-switch.php promote --allow-alive</code>. Con el master caído se promueve como siempre.</li>
            <li><strong>El nodo a apartar se busca por su dirección exacta</strong>, nunca por una parte de ella (buscar <code>10.10.70.1</code> casaba también con <code>10.10.70.154</code>), y un servidor <strong>no se aísla a sí mismo</strong>. Las pruebas del repositorio llevan <code>MUSEDOCK_DISABLE_REMOTE_FENCE=1</code>, que impide que ninguna orden de aislar o reactivar salga hacia otro nodo: <strong>no ejecutes pruebas que no lo pongan en un servidor real</strong>.</li>
            <li><strong>Reactivar</strong> un nodo apartado ahora devuelve también <strong>MariaDB a escritura</strong> si el nodo manda (antes solo PostgreSQL; una réplica sigue en solo lectura).</li>
        </ul>
        <p class="mb-0">Tras promover, en segundo plano: se corrigen los dueños de los ficheros de las webs, se crean las carpetas de Laravel que falten y, a los 45 s, se prueba cada web (ver abajo).</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-lock me-2"></i>Dueños de los ficheros de cada web</div>
    <div class="card-body small text-muted">
        <p>Si el servidor principal se edita con un usuario de administración, los ficheros quedan con <em>su</em> dueño y modo 660. En el principal la web los lee por una ACL; pero lsyncd copia el dueño por nombre y <strong>no las ACL</strong> (<code>rsync -a</code> no lleva <code>-A -X</code>), así que en la réplica la web (otro usuario) no puede leerlos: error 500 tras un relevo. El panel revisa <strong>en todos los nodos</strong> los ficheros y carpetas de <code>httpdocs</code> cuyo dueño no es <code>root</code> ni el usuario de la web (salta <code>vendor</code>, <code>node_modules</code>, <code>.git</code>, cachés y registros).</p>
        <ul class="mb-2">
            <li><strong>Cada día, solo avisa</strong> (<code>perm_guard_mode=warn</code>, el valor por defecto): aviso del tipo <em>Ficheros de una web con dueño ajeno</em>, con la web y ejemplos. Con <code>perm_guard_mode=fix</code> también corrige.</li>
            <li><strong>Al promocionar un nodo corrige siempre</strong> (en segundo plano; <code>perm_guard_on_promote=0</code> lo apaga).</li>
            <li>Corregir cambia <strong>solo dueño y grupo</strong> (dueño = usuario de la web, grupo = el de la carpeta que lo contiene), nunca los permisos, y no toca lo de <code>root</code> (p. ej. <code>wp-config.php</code>). <strong>Antes guarda la lista de dueños anteriores</strong> en <code>/var/lib/musedock/permisos-&lt;fecha&gt;.txt</code> y lo deja en el registro del panel.</li>
            <li>Informar: <code>php bin/web-permissions.php</code> · una web: <code>--domain=ejemplo.com</code> · corregir: <code>--fix</code> · deshacer: <code>--restore /var/lib/musedock/permisos-….txt</code>.</li>
            <li>Si el usuario de una web no existe en el nodo, se dice (en una réplica la web no podría correr).</li>
        </ul>
        <p class="mb-0"><strong>Carpetas de Laravel:</strong> en las webs con <code>artisan</code> se crean, con el dueño de la web, <code>storage/framework/{views,sessions,cache/data}</code>, <code>storage/logs</code> y <code>bootstrap/cache</code> si faltan (<code>--laravel-dirs</code>). Solo crea; nunca toca lo que existe.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-activity me-2"></i>Prueba de las webs tras un cambio de servidor</div>
    <div class="card-body small text-muted">
        <p>Al promocionar un nodo (45 s después, en segundo plano) y cuando quieras a mano, el panel pide la portada de <strong>cada web</strong> contra <code>127.0.0.1</code> (como si el DNS ya apuntara ahí) y, si redirige a otra web del servidor, esa página. Un <strong>5xx o ninguna respuesta</strong> es un error y el aviso (<em>Webs con error tras un cambio de servidor</em>) trae la causa: la última línea de error de la propia web (<code>storage/logs/laravel.log</code>, <code>logs/php-error.log</code>…). Un 401, 403 o 404 en la portada no cuenta como web rota.</p>
        <p class="mb-0">A mano: <code>php bin/web-smoke.php</code> (todas) o <code>--domain=ejemplo.com</code>; sale con código 2 si alguna falla. Solo lee: no cambia nada. En una <em>réplica</em> es normal que fallen las webs que escriben en la base de datos (está en solo lectura).</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder-symlink me-2"></i>Opciones de la copia en vivo (lsyncd)</div>
    <div class="card-body small text-muted">
        <p>Dos opciones, <strong>apagadas por defecto</strong> (probar primero en un solo nodo; herramienta MCP <code>filesync_options</code>, con plan y luego apply):</p>
        <ul class="mb-2">
            <li><strong>ACL y atributos extendidos</strong> (<code>rsync -A -X</code>): viajan también las ACL, mapeadas por nombre igual que el dueño. Probado: con <code>-a</code> se pierde la ACL, con <code>-aAX</code> se conserva.</li>
            <li><strong>Carpetas vacías de caché, sesiones, vistas y registros:</strong> hoy <code>storage/framework/cache</code>, <code>sessions</code>, <code>views</code>, <code>storage/logs</code>, <code>logs</code> y <code>sessions</code> se excluyen <em>enteras</em>, así que en la réplica no existen y Laravel da 500 («Please provide a valid cache path»). Con la opción se excluye solo su <strong>contenido</strong> (<code>carpeta/*</code>): la carpeta llega vacía. Lo excluido sigue sin tocarse y lsyncd mantiene <code>delete = "running"</code> (no borra nada al arrancar).</li>
        </ul>
        <p class="mb-0">Sea cual sea la opción, las carpetas de Laravel se crean al promocionar (ver arriba): esa red de seguridad no depende de lsyncd.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>Servicios en bucle de reinicios y servicios copiados que no pueden correr</div>
    <div class="card-body small text-muted">
        <p>Un servicio propio de systemd que se reinicia sin parar (más de 20 intentos seguidos) avisa una vez cada 12 h (<em>Servicio caído en un nodo</em>), sea o no de correo. Suele faltar el fichero que lanza. Caso real: <code>python3 /usr/local/sbin/script</code> con python presente y el script ausente en la copia, 4.500 reinicios cada 5 s.</p>
        <p class="mb-0">La copia de configuración del master ahora comprueba también el <strong>script</strong> cuando <code>ExecStart</code> lanza un intérprete (python, php, bash, node, perl, ruby) y <strong>no arranca</strong> un servicio al promocionar si le faltan ficheros: lo dice y pide copiarlos.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-terminal me-2"></i>Si una réplica no arranca tras un relevo</div>
    <div class="card-body small text-muted">
        <ol class="mb-0">
            <li>Mira el aviso «PostgreSQL … parado»: trae el motivo probable.</li>
            <li>Con el panel actualizado, repite el paso a réplica: <code>php bin/cluster-switch.php demote &lt;ip-del-nuevo-master&gt;</code>. Ahora iguala los parámetros antes de arrancar.</li>
            <li>Comprueba con <code>pg_lsclusters</code> que el clúster está <em>online,recovery</em> y, en el nuevo master, que la réplica aparece conectada (herramienta MCP <code>failover_preflight</code>).</li>
        </ol>
    </div>
</div>
