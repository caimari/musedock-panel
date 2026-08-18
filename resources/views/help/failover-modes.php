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
                    <th>Vuelve el master → ¿revierte solo?</th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-secondary">manual</span></td>
                        <td>✅ Sí</td>
                        <td>❌ No (lo haces tú desde el panel)</td>
                        <td>❌ No</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-warning text-dark">semiauto</span></td>
                        <td>✅ Sí</td>
                        <td>✅ <strong>Sí, automático</strong> (repunta DNS + promociona)</td>
                        <td>❌ No — <strong>tú confirmas</strong> «Revertir Failover»</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-success">auto</span></td>
                        <td>✅ Sí</td>
                        <td>✅ Sí, automático</td>
                        <td>✅ Sí, automático</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info small mb-0">
            <strong>Por qué semiauto no revierte solo:</strong> devolver el tráfico al master cuando vuelve es lo más delicado
            (si el master regresa inestable —por ejemplo tras un corte de luz— un cambio automático de vuelta podría causar
            flapping o líos de datos). Por eso en <strong>semiauto</strong> la vuelta es una decisión humana consciente.
            Solo <strong>auto</strong> lo hace todo solo.
        </div>
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
            <strong>WireGuard privada</strong> (<code>10.10.70.x</code>), que como destino DNS público rompería todos los dominios.
            Por eso, si la lista está <strong>vacía</strong>, el failover automático <strong>no actúa</strong> (aunque estés en <code>auto</code>):
            primero hay que rellenarla con las IPs públicas. El desempate por completitud/ID es una red para cuando la lista
            <em>sí</em> está configurada pero <strong>olvidaste los números de prioridad</strong>, no un sustituto de configurarla.
        </div>
        <p class="small text-muted mb-0"><strong>El ID</strong> identifica cada servidor en la lista de failover; se asigna solo al crearlo.
            El <strong>failover_to</strong> de cada servidor indica a qué otro servidor redirige su tráfico.</p>
    </div>
</div>

<!-- Cómo configurarlo -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-gear me-2"></i>Cómo configurarlo (checklist)</div>
    <div class="card-body small">
        <ol class="mb-2">
            <li>En <a href="/settings/cluster" class="text-info">Settings → Cluster</a>, sección <strong>servidores de failover</strong>:</li>
            <li>Añade el <strong>master</strong> como <code>primary</code>.</li>
            <li>Añade cada <strong>slave</strong> como <code>failover</code>, con su <strong>prioridad</strong> (p.ej. el más completo = 1) y su <strong>failover_to</strong>.</li>
            <li>Elige el <strong>modo</strong> (empieza por <code>semiauto</code> — automatiza el forward, tú controlas la vuelta).</li>
            <li><strong>Prueba con calma</strong> (idealmente un fallo provocado controlado) antes de fiarte del automático en producción.</li>
        </ol>
        <p class="text-muted mb-0">Recuerda: el failover de tráfico necesita las credenciales de Cloudflare configuradas (se usan para repuntar los registros A).</p>
    </div>
</div>
