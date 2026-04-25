<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Federation: que es y como se configura</h4>
        <div class="text-muted small">Guia base para conectar paneles remotos y habilitar migraciones entre servidores.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/settings-sections" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver al mapa
        </a>
        <a href="/settings/federation" class="btn btn-outline-info btn-sm">
            <i class="bi bi-arrow-left-right me-1"></i> Abrir Federation
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>Que es Federation</div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            <strong>Federation</strong> conecta paneles entre si para migrar o clonar hostings entre servidores independientes
            (relacion peer-to-peer). No sustituye al modelo Master/Slave de Cluster.
        </p>
        <p class="text-muted small mb-0">
            Flujo habitual: conectas paneles en <code>Settings &gt; Federation</code>, validas API/SSH, y luego migras desde
            la cuenta en <code>Accounts &gt; federation-migrate</code>.
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-check2-square me-2"></i>Prerequisitos</div>
    <div class="card-body">
        <ul class="small text-muted mb-0">
            <li>Ambos paneles accesibles por HTTPS en el puerto del panel (normalmente <code>8444</code>).</li>
            <li>Token local disponible en el panel remoto: <code>Settings &gt; Cluster &gt; Token Local</code>.</li>
            <li>Conectividad SSH entre paneles para transferencias de archivos.</li>
            <li>Si usas certificados internos o self-signed, definir <code>TLS pin</code> o <code>CA bundle</code>.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-magic me-2"></i>Configuracion recomendada (Conectar paneles por codigo)</div>
    <div class="card-body">
        <ol class="small text-muted mb-0">
            <li>En un panel, entrar a <code>Settings &gt; Federation</code> y pulsar <strong>Conectar paneles</strong>.</li>
            <li>Generar codigo de emparejamiento (validez aproximada: 10 minutos).</li>
            <li>En el otro panel, abrir el mismo modal, pegar el codigo y pulsar <strong>Conectar</strong>.</li>
            <li>Verificar que el peer aparece en la tabla <strong>Federation Peers</strong>.</li>
            <li>Si un peer aparece <code>pending_approval</code>, aprobarlo desde el panel correspondiente.</li>
            <li>Usar botones <strong>Test conexion</strong> y <strong>Intercambiar SSH keys</strong> para cerrar validacion.</li>
        </ol>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Configuracion manual (si no quieres pairing code)</div>
    <div class="card-body">
        <ol class="small text-muted mb-3">
            <li>Abrir <code>Settings &gt; Federation &gt; Manual</code>.</li>
            <li>Completar <code>Nombre</code>, <code>API URL</code> y <code>Auth Token</code>.</li>
            <li>El <code>Auth Token</code> debe ser el <strong>cluster_local_token</strong> del panel remoto.</li>
            <li>Completar bloque SSH: <code>ssh_host</code>, <code>ssh_port</code>, <code>ssh_user</code>, <code>ssh_key_path</code>.</li>
            <li>Opcional: añadir <code>TLS pin</code> o <code>CA bundle</code> para validar TLS remoto.</li>
            <li>Guardar, luego ejecutar <strong>Test conexion</strong> y <strong>Intercambiar SSH keys</strong>.</li>
        </ol>
        <div class="p-3 rounded" style="background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2);">
            <div class="small text-muted mb-0">
                Campo clave: en Federation la direccion del peer se configura en <strong>API URL</strong>
                (ejemplo: <code>https://10.10.70.156:8444</code> o <code>https://203.0.113.20:8444</code>).
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-signpost-2 me-2"></i>IP privada o publica</div>
    <div class="card-body">
        <ul class="small text-muted mb-3">
            <li><strong>Recomendado:</strong> si existe WireGuard entre paneles, usar IP privada del tunel para <code>API URL</code> y <code>SSH host</code>.</li>
            <li><strong>Sin WireGuard:</strong> usar IP publica y restringir firewall para que solo el peer autorizado acceda al puerto del panel.</li>
            <li><strong>Consistencia:</strong> usar siempre la misma ruta de red por peer (no alternar publica/privada sin motivo).</li>
        </ul>
        <p class="small text-muted mb-0">
            Si necesitas tunel privado, configuralo primero en <a href="/settings/wireguard" class="text-info">Settings &gt; WireGuard</a>.
        </p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Checklist de validacion</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li>Peer en estado <code>online</code>.</li>
                    <li><code>Test conexion</code> con API y SSH correctos.</li>
                    <li>SSH keys intercambiadas sin error.</li>
                    <li>Sin <code>pending_approval</code> pendiente de revisar.</li>
                    <li>Migracion de prueba o dry-run completada correctamente.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tools me-2"></i>Problemas frecuentes</div>
            <div class="card-body">
                <ul class="small text-muted mb-0">
                    <li><strong>Connection refused / timeout:</strong> revisar API URL, puerto, firewall y ruta de red.</li>
                    <li><strong>401 token rechazado:</strong> regenerar/corregir token remoto y volver a conectar.</li>
                    <li><strong>SSH fail:</strong> validar host, usuario, clave y permisos en <code>/root/.ssh</code>.</li>
                    <li><strong>TLS error:</strong> configurar <code>TLS pin</code> o <code>CA bundle</code> en el peer.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
