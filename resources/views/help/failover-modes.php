<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Failover: modos, prioridades e IDs</h4>
        <div class="text-muted small">Cómo se reparte el tráfico cuando cae un servidor, qué hace cada modo y a qué nodo va el failover.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/cluster" class="btn btn-outline-info btn-sm"><i class="bi bi-diagram-3 me-1"></i> Abrir Cluster</a>
    </div>
</div>

<!-- Qué es -->
<div class="card mb-4">
    <div class="card-body">
        <p class="text-muted small mb-0">
            El <strong>failover</strong> es lo que mantiene los sitios online cuando el servidor principal (master) se cae:
            el tráfico se reencamina a un servidor de respaldo (slave). Hay <strong>dos capas</strong> distintas que conviene no mezclar:
            <strong>(1) replicación de datos</strong> — los slaves tienen copia del correo, hostings y BBDD; y
            <strong>(2) failover de tráfico</strong> — cambiar a qué IP apuntan los dominios. Esta guía trata la capa 2.
        </p>
    </div>
</div>

<!-- Los tres modos -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-toggles me-2"></i>Los tres modos</div>
    <div class="card-body">
        <p class="small text-muted">Piensa el failover como un interruptor de <strong>dos momentos</strong>: cuando <em>cae</em> el master, y cuando <em>vuelve</em>.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr>
                    <th>Modo</th>
                    <th>Emails de caída</th>
                    <th>Cae el master → ¿cambia las IPs?</th>
                    <th>Vuelve el master caído</th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-secondary">manual</span></td>
                        <td>✅ Sí</td>
                        <td>❌ No (lo haces tú: Dashboard → Tomar el mando)</td>
                        <td>Se aparta solo y se reincorpora como copia</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-warning text-dark">semiauto</span></td>
                        <td>✅ Sí</td>
                        <td>✅ <strong>Sí, automático</strong> (promociona y, solo si lo consigue, mueve el DNS)</td>
                        <td>Se aparta solo y se reincorpora como copia</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-success">auto</span></td>
                        <td>✅ Sí</td>
                        <td>✅ Sí, automático (igual que semiauto)</td>
                        <td>Se reincorpora como copia y, tras 15 min estable, <strong>recupera el mando solo</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info small mb-0">
            <strong>El titular.</strong> Es el servidor al que diste el mando con un <a href="/docs/role-switch" class="text-info">cambio de rol planificado</a>;
            un relevo por caída no lo cambia. Cuando el titular caído vuelve, se aparta solo (no sirve webs ni acepta escrituras) y se
            reincorpora como copia del sustituto, copiando solo lo cambiado. Después:
            <ul class="mb-1">
                <li><strong>auto</strong>: cuando lleva 15 min seguidos respondiendo bien (<code>failover_return_stable_minutes</code>) y es copia al día,
                    el sustituto le devuelve el mando solo, con el mismo cambio de rol del botón. Si vuelve inestable, el contador empieza de cero: sin idas y venidas.</li>
                <li><strong>semiauto</strong>: te avisa de que está listo y le devuelves el mando tú con el botón.</li>
            </ul>
            <br><strong>Quién actúa:</strong> solo la réplica que debe tomar el mando, nunca el propio master (un master que no
            se alcanza a sí mismo por su IP pública, típico con NAT en casa, no mueve el DNS).
            <br><strong>Testigo:</strong> antes de promoverse, la réplica pregunta a los otros nodos del cluster si ven al master.
            Si alguno lo ve vivo, no se promueve (sería un corte de red, no una caída). Los testigos que no responden no cuentan:
            para que proteja de verdad, conviene un testigo en <em>otro proveedor</em> que el master
            (<a href="/docs/witnesses" class="text-info">testigos externos: cómo crearlos</a>).
        </div>
    </div>
</div>

<!-- Ejemplo de los modos -->
<div class="card mb-4" style="border-color:rgba(56,189,248,.24);">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Ejemplo: qué pasa en cada modo</div>
    <div class="card-body small">
        <p class="text-muted">El servidor <strong>A</strong> es el titular (el que manda por decisión tuya) y <strong>B</strong> su relevo, con copia al día de todo.
            A se queda sin luz de 10:00 a 12:00.</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Hora</th><th>manual</th><th>semiauto</th><th>auto</th></tr></thead>
                <tbody>
                    <tr><td>10:00</td><td colspan="3">A cae. B lo comprueba cada minuto y pregunta a los testigos.</td></tr>
                    <tr><td>10:05</td><td>Te llega el aviso. Las webs siguen caídas hasta que pulses «Tomar el mando» en B.</td>
                        <td colspan="2">B toma el mando solo (bases de datos, DNS, correo) y te avisa. Las webs vuelven.</td></tr>
                    <tr><td>12:00</td><td colspan="3">A vuelve: ve que otro manda, se aparta solo y se pone como copia de B (copiando solo lo cambiado).</td></tr>
                    <tr><td>12:15</td><td>Nada: A sigue de copia hasta que tú decidas.</td>
                        <td>Te llega «A está listo para volver a mandar». Tú decides cuándo pulsar, por ejemplo de noche, con menos visitas.</td>
                        <td>A lleva 15 min estable: B le devuelve el mando solo. Las webs se cortan unos segundos mientras cambia el DNS.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            <strong>La diferencia entre semiauto y auto es solo la vuelta.</strong> Tomar el mando cuando cae el que manda es automático en los dos,
            y en los dos sentidos: siempre lo hace el servidor que hace de copia. Lo único que semiauto no hace solo es la <strong>vuelta planificada</strong>:
            devolver el mando al titular cuando todo está bien. Te avisa y lo decides tú. Auto también lo hace solo.
        </div>
        <p class="mb-1"><strong>¿Y si después cae B?</strong> Mientras A sea su copia al día, A toma el mando: en semiauto y auto, solo; en manual, con tu botón.
            El relevo funciona en los dos sentidos: siempre lo hace el servidor que hace de copia.</p>
        <p class="mb-0 text-muted">Recomendación: <strong>semiauto</strong> hasta haber visto una caída y una vuelta reales; luego, si quieres, <strong>auto</strong>.</p>
    </div>
</div>

<!-- Emails de caída -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-envelope-exclamation me-2"></i>Notificaciones de caída (en TODOS los modos)</div>
    <div class="card-body small text-muted">
        <p>Los avisos por email/Telegram de <strong>«Nodo caído»</strong> (el master detecta que un slave no responde) y
        <strong>«Master caído»</strong> (un slave detecta que el master no responde) se envían <strong>siempre</strong>,
        independientemente del modo — hasta en <code>manual</code>. El modo solo controla si el sistema <em>actúa</em>, no si te avisa.</p>
        <p class="mb-0">El aviso de «Master caído» solo se dispara si el master <strong>de verdad</strong> no responde: antes de alertar,
        el slave sondea activamente al master, para no dar falsas alarmas cuando quien estuvo caído fue el propio slave.</p>
    </div>
</div>

<!-- Qué pasa técnicamente: Cloudflare -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Qué cambia por dentro: Cloudflare</div>
    <div class="card-body small text-muted">
        <p>Cuando ocurre un failover, el sistema usa la API de Cloudflare para <strong>repuntar los registros A</strong>:
        busca los dominios que apuntaban a la IP del servidor caído y los cambia a la IP del servidor vivo, con un
        <strong>TTL bajo (60s)</strong> para que propague rápido.</p>
        <ul class="mb-0">
            <li><strong>Dominios en CF Proxy (nube naranja):</strong> el cambio es <strong>casi instantáneo</strong> — Cloudflare ya es el intermediario, solo cambia a qué origen reenvía.</li>
            <li><strong>Dominios DNS-only (nube gris):</strong> hasta ~60s mientras propaga + caché del cliente.</li>
        </ul>
    </div>
</div>

<!-- Prioridades e IDs -->
<div class="card mb-4" style="border-color:rgba(129,140,248,.24);">
    <div class="card-header"><i class="bi bi-list-ol me-2"></i>¿A qué nodo va el failover? Prioridades e IDs</div>
    <div class="card-body">
        <p class="small text-muted">Con varios slaves, el sistema elige <strong>uno solo</strong> (para que no se peleen) con estas reglas, en orden:</p>
        <ol class="small">
            <li><strong>Prioridad configurada (forzada):</strong> en <em>Settings → Cluster → servidores de failover</em>, cada servidor tiene un campo
                <strong>prioridad</strong> (<code>1</code> = máxima, promociona primero). Si la pones, <strong>manda</strong>. El de menor número que esté vivo, gana.</li>
            <li><strong>Si no hay prioridades (o empatan) → el nodo MÁS COMPLETO:</strong> se prefiere el que ofrece más servicios.
                Un nodo <strong>web + mail</strong> gana a uno <strong>solo web</strong> (es el más parecido a lo que daba el master).</li>
            <li><strong>Si aún empatan → el de menor ID</strong> (elección determinista, siempre el mismo, no aleatoria).</li>
        </ol>
        <div class="alert alert-warning small mb-2">
            <strong>Imprescindible: la lista de servidores de failover.</strong> Estas reglas deciden <em>QUIÉN</em> promociona,
            pero el <strong>repunte de DNS</strong> necesita la <strong>IP pública</strong> de cada nodo y el mapeo de zonas Cloudflare,
            y eso <strong>solo</strong> vive en la lista de servidores de failover. Los nodos del cluster solo conocen su IP
            <strong>privada de la VPN</strong> (WireGuard), que como destino DNS público rompería todos los dominios.
            Por eso, si la lista está <strong>vacía</strong>, el failover automático <strong>no actúa</strong> (aunque estés en <code>auto</code>):
            primero hay que rellenarla con las IPs públicas. El desempate por completitud/ID es una red para cuando la lista
            <em>sí</em> está configurada pero <strong>olvidaste los números de prioridad</strong>, no un sustituto de configurarla.
        </div>
        <p class="small text-muted mb-0"><strong>El ID</strong> identifica cada servidor en la lista de failover; se asigna solo al crearlo.
            El <strong>failover_to</strong> de cada servidor indica a qué otro servidor redirige su tráfico.</p>
    </div>
</div>

<!-- Qué se mueve en el DNS -->
<div class="card mb-4" style="border-color:rgba(251,191,36,.3);">
    <div class="card-header"><i class="bi bi-signpost-split me-2"></i>Qué se mueve en el DNS, y por qué</div>
    <div class="card-body small">
        <p class="text-muted">Nada está escrito a mano en el panel: lo que se mueve se decide en el momento, con estas reglas.</p>
        <ol class="mb-3" style="line-height:1.8;">
            <li><strong>Las IPs</strong> salen de <em>Cluster → Failover → Servidores</em>: la IP pública del servidor que deja de mandar (origen)
                y la del que pasa a mandar (destino).</li>
            <li><strong>Dónde busca</strong>: en todas las zonas (dominios) de todas las cuentas de Cloudflare configuradas en Failover.</li>
            <li><strong>Qué cambia</strong>: los <strong>registros A cuyo contenido es exactamente la IP de origen</strong>. Se busca por IP, no por la
                lista de hostings: si algo apunta a ese servidor, se mueve, aunque no sea un hosting del panel (por ejemplo, webs que añade otra aplicación).
                Se respeta si va por el proxy de Cloudflare o no.</li>
            <li><strong>Los CNAME no se tocan</strong>: siguen a su destino. Si <code>www.ejemplo.com</code> es CNAME de <code>ejemplo.com</code>,
                se mueve cuando se mueve <code>ejemplo.com</code>. Por eso un cambio puede tocar 8 registros A y mover con ellos 180 dominios.</li>
            <li><strong>Los nombres de máquina se quedan quietos</strong>: el hostname de cada servidor, el nombre del panel y los nombres de los nodos
                (se deducen solos; se pueden añadir más en <code>failover_dns_exclude</code>). Cada uno debe seguir apuntando a su propio servidor.</li>
            <li><strong>Excepción: un nombre de máquina al que apuntan webs por CNAME sí se mueve.</strong> Es el caso habitual cuando los dominios
                se configuran con CNAME al nombre del servidor (el <em>destino por defecto</em> de los dominios nuevos). Si no se moviera,
                todas esas webs se quedarían apuntando al servidor que ya no manda.</li>
        </ol>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Consecuencia para entrar al panel:</strong> si el nombre del panel es también el destino de los CNAME de las webs, tras el cambio
            ese nombre lleva al panel del <em>nuevo</em> master. Al servidor que deja de mandar se entra por su IP pública
            (<code>https://IP:puerto</code>). El cambio de rol lo detecta: lo avisa antes de empezar y, al terminar, recarga el panel por IP.
        </div>
        <p class="mb-1"><strong>Qué no se puede mover</strong>: un dominio cuyo DNS no está en las cuentas de Cloudflare del panel
            (en otra cuenta, o en otro proveedor DNS) no cambia. El panel lo detecta y lo lista, tanto los hostings como todo lo que sirve Caddy:</p>
        <ul class="mb-3">
            <li><strong>Antes</strong>: en el cambio de rol, la ventana muestra la lista completa (qué cambia, qué va por CNAME, qué se queda y qué no se puede mover)
                antes de pedir la contraseña. También <code>cluster-switch.php dns-plan</code> o, por MCP, <code>failover_dns_plan</code>.</li>
            <li><strong>Después</strong>: el correo del cambio lista los registros cambiados, los que fallaron y los dominios que no se pudieron mover,
                para cambiarlos a mano en su proveedor.</li>
        </ul>
        <div class="alert alert-warning small mb-0">
            <i class="bi bi-cloud me-1"></i>
            <strong>Hoy el panel está hecho para Cloudflare</strong>: el relevo de DNS solo cambia registros en Cloudflare.
            Los dominios con el DNS en otro proveedor quedan fuera del relevo automático (salen en las listas de "no se puede mover").
            Está previsto estudiar otros proveedores DNS en paralelo a Cloudflare.
        </div>
    </div>
</div>

<!-- Cómo configurarlo -->
<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-gear me-2"></i>Cómo configurarlo, paso a paso</div>
    <div class="card-body small">
        <p class="text-muted">Todo se hace en el <strong>master</strong>, en <a href="/settings/cluster#tab-failover" class="text-info">Ajustes → Cluster → Failover</a>.
            Al guardar, la configuración se copia sola a los demás nodos.</p>

        <h6 class="mt-3">1. Antes de empezar</h6>
        <ul class="mb-2">
            <li>Un <strong>slave que tenga copia de todo</strong> (ficheros, bases de datos, correo si lo hay). Si solo tiene ficheros, al promoverlo
                las webs se quedarían sin datos. El Dashboard del master dice qué guarda cada nodo; cómo prepararlo en
                <a href="/docs/role-switch" class="text-info">Cambio de rol y slave completo</a>.</li>
            <li>Los dominios gestionados en <strong>Cloudflare</strong> (es lo que se repunta).</li>
        </ul>

        <h6 class="mt-3">2. Cuentas de Cloudflare</h6>
        <ul class="mb-2">
            <li>En la tarjeta <strong>Cuentas Cloudflare</strong>, añade un nombre y un <strong>API Token</strong> por cuenta.
                El token necesita permiso de <em>Zona → DNS → Editar</em> y <em>Zona → Leer</em> sobre las zonas que se mueven.</li>
            <li>Marca <strong>"Actualizar token de Caddy"</strong> si Caddy usa ese mismo token para sacar certificados por DNS:
                se copia a Caddy en todos los nodos (y el slave podrá renovar certificados cuando mande él).</li>
        </ul>

        <h6 class="mt-3">3. Servidores</h6>
        <p class="text-muted mb-1">En <strong>Infraestructura → Servidores → Añadir servidor</strong>, uno por fila:</p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead><tr><th>Campo</th><th>Qué poner</th></tr></thead>
                <tbody>
                    <tr><td>Nombre</td><td>El que quieras (aparece en avisos y en el Dashboard).</td></tr>
                    <tr><td>IP</td><td>La <strong>IP pública</strong> del servidor: la que verán los registros DNS. Nunca la de la VPN.</td></tr>
                    <tr><td>Rol</td><td><code>Primary</code> el master; <code>Failover</code> cada slave que puede tomar el mando;
                        <code>Backup</code> solo un último recurso con caddy-l4 (puede tener IP dinámica con DynDNS).</td></tr>
                    <tr><td>Prio</td><td>Solo en los <code>Failover</code>: 1 = el primero en promoverse. Con un solo slave, 1.</td></tr>
                    <tr><td>Failover a</td><td>En cada <code>Primary</code>: a qué <code>Failover</code> va su tráfico si cae.</td></tr>
                </tbody>
            </table>
        </div>

        <h6 class="mt-3">4. Modo y tiempos</h6>
        <ul class="mb-2">
            <li><strong>Modo</strong>: empieza por <code>semiauto</code> (si cae el master el relevo es automático; la vuelta la decides tú).</li>
            <li><strong>TTL</strong>: normal, alerta y failover. Un TTL bajo en failover hace que el cambio llegue antes a quien no usa el proxy de Cloudflare.</li>
            <li><strong>Health checks</strong>: cada cuánto se comprueba, cuántos fallos seguidos marcan un servidor como caído y cuántos OK como recuperado.
                Subir los fallos evita relevos por un corte de unos segundos.</li>
        </ul>

        <h6 class="mt-3">5. Comprobar sin tocar nada</h6>
        <ul class="mb-2">
            <li>En la terminal del master: <code>php bin/cluster-switch.php dns-plan</code>. Lista qué registros DNS se moverían
                y cuáles no se tocan. Por MCP: <code>failover_dns_plan</code> y <code>failover_preflight</code>
                (en el master <em>y</em> en el slave), que dice en llano qué falta.</li>
            <li>Revisa sobre todo la lista de <strong>"no se puede mover"</strong> (dominios con el DNS fuera de las cuentas de Cloudflare del panel)
                y los <strong>nombres de máquina</strong> (ver <em>Qué se mueve en el DNS</em> arriba).</li>
        </ul>

        <h6 class="mt-3">6. Qué hace un relevo</h6>
        <ul class="mb-2">
            <li>Repunta en Cloudflare los registros que apuntaban al servidor caído y apunta un diario de lo movido: la vuelta solo deshace eso.</li>
            <li>Promueve el slave (sus bases aceptan escrituras) y abre los puertos públicos: web y, si tiene correo, los del correo.
                Los puertos que el servidor ya tenía abiertos por su cuenta no se tocan; al volver a slave solo se cierra lo que abrió el panel.</li>
            <li>Si el antiguo master vuelve, se <strong>aparta</strong> solo (no sirve webs ni acepta escrituras) para que no haya dos masters,
                y su panel sigue accesible por IP. Desde ahí se convierte en copia del nuevo master.</li>
            <li>La copia de ficheros no se invierte sola en un relevo por caída: revisa <em>Cluster → Archivos</em> después.
                En un <a href="/docs/role-switch" class="text-info">cambio de rol planificado</a> sí se hace todo.</li>
        </ul>

        <h6 class="mt-3">7. Probar</h6>
        <p class="mb-0 text-muted">Antes de fiarte del modo automático, haz un <a href="/docs/role-switch" class="text-info">cambio de rol planificado</a>
            de ida y vuelta: prueba lo mismo que un relevo (DNS, bases, puertos, correo) con los dos servidores bien y sin prisas.</p>
    </div>
</div>
