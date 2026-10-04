<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">MCP entre nodos (acceso reenviado)</h4>
        <div class="text-muted small">Cómo el MCP de un panel consulta los demás nodos de su cluster sin el token de cada uno, qué límites tiene y cómo cerrarlo.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/mcp" class="btn btn-outline-info btn-sm"><i class="bi bi-plug me-1"></i> Abrir MCP</a>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2" style="color:#fbbf24;"></i>Es una segunda puerta: conviene saber que existe</div>
    <div class="card-body small">
        <p class="text-muted">Un asistente (Claude, ChatGPT…) conectado al MCP de un panel con su token puede añadir el argumento <code>node</code> a las herramientas de lectura.
            El panel reenvía la consulta a ese nodo por la <strong>API del cluster</strong>, con la clave que comparten los nodos. <strong>No hace falta el token MCP del nodo consultado</strong>
            ni tenerlo conectado en el asistente.</p>
        <p class="text-muted mb-0">Así, quien tenga el token MCP de <em>un</em> panel puede leer el estado de <em>todos</em> los nodos registrados en su cluster que lo permitan.
            Trata el token MCP de cada panel como una llave de todo su cluster, en modo lectura.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>En qué sentidos funciona</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>Del master a sus copias:</strong> el caso normal; el master tiene registrados todos los nodos.</li>
            <li><strong>De una copia al master:</strong> también, si la copia tiene al master en su lista de nodos del cluster. Suele pasar después de un cambio de rol
                (el antiguo master queda registrado en el nuevo, y al revés).</li>
            <li><strong>Entre clusters distintos:</strong> no. Solo se llega a los nodos registrados en <em>Cluster → Nodos</em> del panel que recibe la pregunta.</li>
        </ul>
        <p class="text-muted mt-2 mb-0">La lista de nodos a los que se puede llegar desde un panel aparece en su página <a href="/settings/mcp" class="text-info">Ajustes → MCP</a>, y el asistente la ve con <code>list_nodes</code>.</p>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Límites que pone siempre el nodo consultado</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>Solo lectura:</strong> las herramientas que modifican algo (crear correo, DNS, failover, registrar réplicas…) nunca se reenvían; solo se ejecutan con el token del propio panel.</li>
            <li><strong>Tiene que tener su MCP activado</strong> en <em>Ajustes → MCP</em>. Con el MCP desactivado, no responde ni a su token ni a consultas reenviadas.</li>
            <li><strong>Tiene que permitir consultas reenviadas</strong> (interruptor "Permitir consultas reenviadas desde otros nodos", activado por defecto).
                Si lo desactivas, ese nodo solo se puede consultar conectándose a su propio MCP con su token.</li>
            <li><strong>Solo desde nodos de su cluster:</strong> la consulta llega con la clave del cluster; sin ella, se rechaza.</li>
            <li><strong>Secretos tapados:</strong> claves, tokens y contraseñas salen ocultos en la respuesta.</li>
            <li><strong>Queda registrado en el nodo consultado</strong>: en su registro de actividad, como <code>mcp.call</code>, con la herramienta y el nodo que preguntó (sin los argumentos).</li>
            <li><code>page_check</code> solo abre páginas de consulta: se niega a cargar rutas con pinta de acción (logout, delete, restart, apply, toggle…).</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-lock me-2"></i>Cómo cerrarlo</div>
    <div class="card-body small">
        <ol class="mb-0" style="line-height:1.9;">
            <li>En el nodo que quieras proteger: <em>Ajustes → MCP</em>, desactiva "Permitir consultas reenviadas desde otros nodos" y guarda. Desde ese momento solo responde a su propio token.</li>
            <li>Para cortar todo el MCP de un nodo, desactiva el MCP. Se corta también el acceso reenviado.</li>
            <li>Si sospechas que un token MCP se ha filtrado, regenéralo en el panel al que pertenece: el token viejo deja de servir para ese panel y para llegar a los demás.</li>
        </ol>
    </div>
</div>
