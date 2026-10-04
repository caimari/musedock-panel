<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Blindar WordPress</h4>
        <div class="text-muted small">Cómo protege el panel los WordPress de los hostings, cómo se detecta una infección y cómo se limpia sin borrar nada.</div>
    </div>
    <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
</div>

<div class="card mb-4" style="border-color:rgba(239,68,68,.35);">
    <div class="card-header"><i class="bi bi-bug me-2" style="color:#f87171;"></i>Cómo los atacan</div>
    <div class="card-body small">
        <p class="text-muted">Redes de bots con cientos de IPs prueban contraseñas sin parar, sobre todo por <code>xmlrpc.php</code>: con una sola petición prueban cientos.
            Lo normal son miles de intentos al día por web. Cuando aciertan una contraseña de administrador, suben un "plugin" con una puerta trasera
            y desde ahí cambian ficheros del tema, meten ficheros en <code>wp-admin</code> o instalan redes de spam que se reinstalan solas.</p>
        <p class="text-muted mb-0"><strong>Con el proxy de Cloudflare, banear en iptables no sirve:</strong> las conexiones llegan desde IPs de Cloudflare, no del atacante.
            Por eso el panel banea también en Caddy, por la IP real que manda Cloudflare (<code>Cf-Connecting-Ip</code>).</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-shield-lock me-2"></i>Niveles (por hosting)</div>
    <div class="card-body small">
        <ul class="mb-0" style="line-height:1.9;">
            <li><strong>standard</strong> (por defecto en todos): no limita al cliente, que sigue instalando plugins con normalidad.
                <code>xmlrpc.php</code> cerrado (abierto automáticamente si tiene Jetpack, o a mano); no se ejecuta PHP en <code>wp-content/uploads</code>, <code>cache</code> ni <code>upgrade</code>;
                <code>wp-config.php</code>, <code>readme.html</code>, <code>*.sql</code> y copias <code>.bak</code> dan 403; <code>?author=</code> también (sirve para sacar los nombres de usuario).</li>
            <li><strong>strict</strong> (para webs propias): lo anterior, <code>xmlrpc.php</code> siempre cerrado y además <strong>el código pasa a ser de solo lectura para PHP</strong>:
                el usuario del hosting solo puede escribir en <code>uploads</code>, <code>cache</code> y carpetas parecidas. Un mu-plugin del panel impide instalar o editar plugins y temas
                desde el admin. Aunque roben la contraseña de un administrador o un plugin tenga un fallo, <strong>no pueden escribir PHP</strong>.
                Para actualizar: <em>Desbloquear</em> (30 min, 1 h o 2 h); al acabar, el panel lo vuelve a cerrar solo.</li>
            <li><strong>off</strong>: sin reglas.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Dónde se cambia</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li><strong>Panel:</strong> <em>Hostings → (el hosting) → Blindar WordPress</em>. Avisa si ve indicios de infección.</li>
            <li><strong>MCP:</strong> <code>wordpress_status</code> (lectura; con <code>quick</code> añade indicios), <code>wordpress_harden</code> (nivel, xmlrpc, desbloquear).</li>
            <li><strong>Terminal (root):</strong> <code>php bin/wp-harden.php list | set &lt;dominio&gt; &lt;nivel&gt; [--xmlrpc=auto|on|off] | set-all standard | unlock &lt;dominio&gt; [min] | lock &lt;dominio&gt;</code>.</li>
            <li>Se cambia en el master y se copia a los nodos web. Cada 30 min, cada nodo pone al día sus reglas y el master vuelve a cerrar el código de los strict.</li>
        </ul>
    </div>
</div>

<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-search me-2"></i>Detectar y limpiar una infección</div>
    <div class="card-body small">
        <p class="text-muted"><strong>Análisis</strong> (<code>php bin/wp-harden.php scan &lt;dominio&gt;</code> o MCP <code>wordpress_scan</code>). Solo lee y <strong>no ejecuta el PHP del sitio</strong>:</p>
        <ul>
            <li>núcleo contra las sumas oficiales de wordpress.org: ficheros cambiados y <strong>ficheros de más</strong> en <code>wp-admin</code>/<code>wp-includes</code> (un sitio típico de puertas traseras);</li>
            <li>cada plugin contra wordpress.org: igual, modificado, inexistente allí o malware conocido;</li>
            <li>trozos típicos de puertas traseras en <code>wp-content</code> (algunos temas comerciales dan falsos positivos: revisar), PHP en uploads, zips subidos, mu-plugins, carpetas raras;</li>
            <li>base de datos: administradores (para ver si hay alguno que no conoces), opciones con scripts inyectados, entradas recientes.</li>
        </ul>
        <p class="text-muted"><strong>Limpieza</strong> (<code>php bin/wp-harden.php …</code> o MCP <code>wordpress_repair</code>). <strong>Nada se borra:</strong> todo va a
            <code>/var/lib/musedock/wp-quarantine/&lt;dominio&gt;/&lt;fecha&gt;/</code> con su lista (<code>MANIFEST.txt</code>), fuera de la web.</p>
        <ol class="mb-2">
            <li><code>quarantine &lt;dominio&gt; &lt;ruta&gt; …</code>: aparta plugins falsos, mu-plugins, ficheros de más.</li>
            <li><code>reinstall-core &lt;dominio&gt;</code>: núcleo de su misma versión desde wordpress.org.</li>
            <li><code>reinstall-plugin</code> / <code>reinstall-theme &lt;dominio&gt; &lt;slug&gt;</code>: copia oficial de su versión.</li>
            <li><code>rotate-salts &lt;dominio&gt;</code>: claves nuevas en <code>wp-config.php</code>; cierra todas las sesiones, también las del atacante.</li>
            <li>Cambiar las contraseñas de los administradores (en el admin de WordPress) y quitar los que no conozcas.</li>
            <li>Pasar el hosting a <strong>strict</strong> si es una web propia.</li>
        </ol>
        <p class="text-muted mb-0">Un tema comercial que no está en wordpress.org (p. ej. comprado) no se puede reinstalar desde allí: hay que subir una copia limpia del proveedor.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-ban me-2"></i>Baneos de fail2ban</div>
    <div class="card-body small">
        <p class="text-muted mb-0">La jaula <code>musedock-wordpress</code> cuenta los POST a <code>wp-login.php</code> y <code>xmlrpc.php</code>. Cuando una IP se pasa, la banea en iptables
            (para quien conecta directo) y en Caddy (para quien llega por Cloudflare, por su IP real). Las IPs baneadas en Caddy: <code>php bin/wp-harden.php bans</code>.
            Con <code>xmlrpc.php</code> cerrado, la mayoría de los ataques ya no llegan ni a WordPress.</p>
    </div>
</div>
