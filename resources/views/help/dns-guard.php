<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Protección de DNS</h4>
        <div class="text-muted small">Qué puede cambiar cada servidor en Cloudflare, aunque su token llegue a todas las zonas de la cuenta.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Volver a Docs</a>
        <a href="/settings/cloudflare-dns#dns-guard" class="btn btn-outline-info btn-sm"><i class="bi bi-shield-lock me-1"></i> Abrir la protección</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>Para qué sirve</div>
    <div class="card-body small text-muted">
        <p>Un token de Cloudflare suele llegar a <strong>todas</strong> las zonas de la cuenta. Si tienes varios grupos de servidores (por ejemplo un master con su copia y, aparte, otro
            master con la suya) que comparten cuenta de Cloudflare, cada panel <em>podría</em> cambiar el DNS de los demás: un relevo mal configurado, un error al editar a mano o una
            herramienta externa (MCP) con permisos de más.</p>
        <p class="mb-0">La protección de DNS pone un filtro <strong>dentro del panel</strong>. Todo lo que se <em>escribe</em> en Cloudflare con el token de ese panel (relevos, cambio de rol, MCP,
            publicación de DNS de correo, la pantalla de DNS y las migraciones) se comprueba antes contra tus reglas. <strong>Leer siempre se puede.</strong> Si no hay reglas, o está
            apagada, no cambia nada.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-check2-circle me-2"></i>¿Hace falta activarla? Por defecto ya es limpio</div>
    <div class="card-body small text-muted">
        <p>No. La protección es una <strong>segunda barrera</strong>: sin tocar nada, apagada, el relevo ya es selectivo por diseño. Cada panel, en un relevo o en un cambio de rol,
            solo cambia los registros <strong>A que apuntan exactamente a la IP de su propio servidor principal</strong>.</p>
        <table class="table table-dark table-sm small mb-3">
            <thead><tr><th>Servidor principal</th><th>IP</th><th>Qué mueve su relevo</th></tr></thead>
            <tbody>
                <tr><td><code>servidor-a1</code></td><td><code>203.0.113.10</code></td><td>Los registros A que valen <code>203.0.113.10</code> (y lo que va por CNAME a ellos).</td></tr>
                <tr><td><code>servidor-b1</code></td><td><code>198.51.100.20</code></td><td>Los registros A que valen <code>198.51.100.20</code> (y lo que va por CNAME a ellos).</td></tr>
            </tbody>
        </table>
        <p class="mb-1">Aunque los dos servidores compartan cuenta de Cloudflare y su token llegue a todas las zonas:</p>
        <ul class="mb-3">
            <li>El relevo de <code>servidor-a1</code> no toca un registro que vale <code>198.51.100.20</code>, y el de <code>servidor-b1</code> no toca uno que vale <code>203.0.113.10</code>. Cada uno solo ve «lo suyo».</li>
            <li>Los <strong>nombres de máquina</strong> (<code>servidor-a1</code>, <code>servidor-a2</code>…) no se mueven nunca.</li>
            <li>Al devolver el mando, <strong>solo se restaura lo que ese relevo movió</strong> (hay un diario); no se toca nada más.</li>
            <li>Para comprobarlo, mira el plan de relevo de cada servidor (MCP <code>failover_dns_plan</code>, o el aviso previo al cambio de rol): lista exactamente qué registros cambiaría y cuáles no.</li>
        </ul>
        <p class="mb-1"><strong>Lo único que hay que tener en cuenta</strong></p>
        <ul class="mb-3">
            <li><strong>Lo que apunta a tu IP se mueve contigo.</strong> Si alguien apunta al <code>servidor-a1</code> una web que en realidad es de otro grupo, su relevo la movería. Es lo correcto: significa que la sirve ese servidor.</li>
            <li><strong>Los CNAME acompañan.</strong> Una web que va por CNAME a un nombre que se mueve, se mueve con él. Es lo que se quiere.</li>
            <li><strong>Las ediciones manuales y el MCP no tienen límite.</strong> Con la protección apagada, la pantalla de DNS o <code>dns_record_set</code> pueden escribir en cualquier zona que alcance el token. Esto es lo único que la protección cubre y el relevo no: un error humano o de una herramienta, no del relevo.</li>
        </ul>
        <p class="mb-0"><i class="bi bi-lightbulb me-1"></i><strong>En resumen:</strong> si editas con cuidado y las herramientas externas solo escriben donde les pides, no hace falta activar nada. Aun así, activarla en
            <strong>«Solo avisar»</strong> unos días no cuesta nada, porque no bloquea: si algún día se escribe en una zona que no toca, queda apuntado.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-check me-2"></i>Las tres reglas</div>
    <div class="card-body small text-muted">
        <p>Las tres son opcionales y se combinan. En zonas y nombres, el <code>*</code> vale como comodín.</p>
        <table class="table table-dark table-sm small mb-3">
            <thead><tr><th style="width:18%">Regla</th><th>Qué hace</th><th style="width:30%">Ejemplo</th></tr></thead>
            <tbody>
                <tr>
                    <td><strong>Zonas prohibidas</strong></td>
                    <td>Este servidor <strong>no escribe nunca</strong> en esas zonas. Sirve para dejar fuera lo que pertenece a otro grupo de servidores.</td>
                    <td><code>example.net</code><br><code>otra-empresa.*</code> (todas sus extensiones: .com, .net, .es…)</td>
                </tr>
                <tr>
                    <td><strong>Solo estas zonas</strong></td>
                    <td>Si la lista no está vacía, <strong>solo</strong> se escribe en las zonas que figuran. Es la forma inversa de la anterior: en vez de decir lo que no, dices lo único que sí.</td>
                    <td><code>example.com</code><br><code>example.net</code></td>
                </tr>
                <tr>
                    <td><strong>Registros protegidos</strong></td>
                    <td>Un registro concreto (o varios con comodín) que no se puede crear, cambiar o borrar, aunque su zona sí esté permitida. Se indican nombre, tipos y acciones; si dejas tipos o acciones vacíos, valen todos.</td>
                    <td><code>mail.example.com | A | cambiar,borrar</code><br><code>_dmarc.* | TXT | crear,cambiar,borrar</code></td>
                </tr>
            </tbody>
        </table>
        <p class="mb-1"><strong>Formato de los registros protegidos</strong> (una regla por línea):</p>
        <p class="mb-2"><code>nombre | tipos | acciones | nota</code></p>
        <ul class="mb-0">
            <li><strong>nombre</strong>: completo o con <code>*</code>: <code>mail.example.com</code>, <code>*.example.com</code>, <code>mail.*</code>, <code>*</code> (todos).</li>
            <li><strong>tipos</strong>: separados por comas (<code>A</code>, <code>AAAA</code>, <code>CNAME</code>, <code>TXT</code>, <code>MX</code>, <code>NS</code>, <code>SRV</code>, <code>CAA</code>, <code>PTR</code>). Vacío = todos.</li>
            <li><strong>acciones</strong>: <code>crear</code>, <code>cambiar</code> y <code>borrar</code>, separadas por comas. Vacío = todas.</li>
            <li><strong>nota</strong>: texto libre para acordarte del motivo; aparece en el aviso cuando se bloquea.</li>
            <li>Si <em>renombras</em> un registro protegido se comprueban el nombre de antes <strong>y</strong> el de después, para que no sirva cambiarle el nombre y borrarlo luego.</li>
            <li>Las operaciones masivas de Cloudflare (importar, lotes) no se permiten mientras haya registros protegidos, porque no se ve qué tocan.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Cómo se usa</div>
    <div class="card-body small text-muted">
        <ol>
            <li>Abre <strong>Ajustes → Cloudflare DNS</strong> y despliega la tarjeta <strong>«Protección de DNS»</strong>.</li>
            <li>Rellena las zonas y los registros que quieras proteger. Con el <strong>probador</strong> («¿se bloquearía esto?») escribes una zona, un registro, un tipo y una acción, y te dice si se bloquearía y por qué, sin tocar nada.</li>
            <li>Elige el modo y pulsa <strong>Guardar</strong>. Pide la <strong>contraseña de administrador</strong>.</li>
            <li>Mira la lista <strong>«Últimos intentos bloqueados o avisados»</strong>: cada línea trae la fecha, qué se intentó y el motivo.</li>
        </ol>
        <p class="mb-1"><strong>Los tres modos</strong></p>
        <ul class="mb-2">
            <li><strong>Apagada</strong>: no hace nada.</li>
            <li><strong>Solo avisar</strong>: deja pasar todo, pero apunta lo que <em>habría</em> bloqueado. Es el modo para empezar.</li>
            <li><strong>Activa</strong>: bloquea, y quien lo intentó (el MCP, la pantalla de DNS…) recibe el motivo.</li>
        </ul>
        <p class="mb-0"><i class="bi bi-lightbulb me-1"></i><strong>Recomendación:</strong> déjala unos días en «Solo avisar». Si la lista de intentos solo contiene lo que querías impedir, pásala a «Activa». Si aparece algo legítimo, ajusta la regla antes de activar.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Ejemplo: dos grupos de servidores en la misma cuenta de Cloudflare</div>
    <div class="card-body small text-muted">
        <p>Imagina dos grupos, cada uno con su master y su copia, y un solo token de Cloudflare que llega a todas las zonas:</p>
        <table class="table table-dark table-sm small mb-3">
            <thead><tr><th>Grupo</th><th>Servidores</th><th>Dominios</th></tr></thead>
            <tbody>
                <tr><td>A</td><td><code>servidor-a1</code> (master) y <code>servidor-a2</code> (copia)</td><td><code>example.com</code>, <code>example.net</code></td></tr>
                <tr><td>B</td><td><code>servidor-b1</code> (master) y <code>servidor-b2</code> (copia)</td><td><code>otra-empresa.com</code>, <code>otra-empresa.es</code></td></tr>
            </tbody>
        </table>
        <p class="mb-1">Se configura en el <strong>master de cada grupo</strong>:</p>
        <ul class="mb-3">
            <li>En <code>servidor-a1</code>: zonas prohibidas <code>otra-empresa.*</code>. Así el grupo A no puede tocar el DNS del B.</li>
            <li>En <code>servidor-b1</code>: zonas prohibidas <code>example.*</code>. Y al revés.</li>
            <li>Opcionalmente, un registro protegido en cada grupo para su servidor de correo: <code>mail.example.com | A | cambiar,borrar</code> en el grupo A.</li>
        </ul>
        <p class="mb-0">Cada master guarda sus reglas y las envía a su copia: <code>servidor-a2</code> recibe las del A y <code>servidor-b2</code> las del B. Si una copia toma el mando, ya trabaja con las mismas reglas.
            Quien quiera ser más estricto puede usar «Solo estas zonas» en lugar de «Zonas prohibidas» (<code>example.com</code> y <code>example.net</code> en el A): así cualquier zona nueva que se añada a la cuenta de Cloudflare queda fuera por defecto.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-arrow-left-right me-2"></i>Protección contra errores de configuración (y relevos)</div>
    <div class="card-body small text-muted">
        <p>Una protección mal puesta podría impedir justo lo que más importa: que el relevo mueva el DNS cuando el servidor principal cae. Para evitarlo:</p>
        <ul class="mb-3">
            <li><strong>No se guardan reglas que impidan el propio relevo.</strong> Al activarla en modo «Activa», el panel calcula el plan de relevo de ese servidor (los registros que cambiaría) y rechaza las reglas que bloquearían alguno de ellos, diciendo cuáles.
                <br>Ejemplo: si el relevo de <code>servidor-a1</code> mueve <code>mail.example.com</code> y alguien intenta prohibir la zona <code>example.com</code> en ese mismo servidor, no se guarda y el aviso nombra <code>mail.example.com</code>.</li>
            <li><strong>Si algo queda protegido más tarde</strong> (por ejemplo, un registro nuevo que encaja en una regla), el relevo <strong>salta ese registro con aviso</strong> y mueve todo lo demás. No cuenta como fallo del relevo.</li>
            <li><strong>Un fallo interno de la protección no para nada:</strong> si el propio código de la protección fallara, la escritura sigue adelante y el error se anota. Así la protección nunca puede ser la causa de que un relevo no se haga.</li>
        </ul>
        <p class="mb-1"><strong>Copias y cambio de rol</strong></p>
        <ul class="mb-0">
            <li>Las reglas viajan <strong>del master a sus copias</strong>. Una copia no las edita aquí: las recibe, y en su pantalla se ven en solo lectura.</li>
            <li>Si una copia toma el mando, ya tiene las mismas reglas. El filtro sigue protegiendo igual después del cambio de rol.</li>
            <li>Cada grupo de servidores tiene las suyas; no se mezclan.</li>
        </ul>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-robot me-2"></i>Consultar por MCP</div>
    <div class="card-body small text-muted">
        <p>La herramienta <code>dns_guard</code> es de <strong>solo lectura</strong> y sirve para ver, sin entrar en el panel, qué está excluido:</p>
        <ul class="mb-3">
            <li>el modo, las zonas permitidas y prohibidas y los registros protegidos;</li>
            <li>los últimos intentos bloqueados o avisados;</li>
            <li>con <code>zone</code>, <code>name</code>, <code>type</code> y <code>action</code> (<code>create</code>, <code>update</code> o <code>delete</code>) <strong>simula una operación</strong> y dice si se bloquearía y por qué;</li>
            <li>con <code>check_failover=true</code> comprueba las reglas contra el plan de relevo de ese servidor (consulta Cloudflare, por eso tarda más).</li>
        </ul>
        <p class="mb-0"><strong>Cambiar las reglas solo se puede desde el panel, con la contraseña de administrador:</strong> ni el MCP ni un token pueden quitarlas ni modificarlas. Si el MCP intenta escribir algo que las reglas
            prohíben (por ejemplo con <code>dns_record_set</code> o publicando DNS de correo), recibe el mensaje «Bloqueado por la protección de DNS» con el motivo.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Límites</div>
    <div class="card-body small text-muted">
        <ul class="mb-0">
            <li><strong>Es un filtro del panel, no un permiso de Cloudflare.</strong> Quien tenga el token y escriba <em>fuera</em> del panel (desde la web de Cloudflare o con <code>curl</code>) no pasa por aquí.
                Para cerrar también esa puerta, crea en Cloudflare un token por grupo de servidores, limitado a las zonas de ese grupo, y usa la protección como segunda barrera.</li>
            <li>Solo protege las zonas que están en las cuentas de Cloudflare configuradas en el panel; en las demás no puede escribir de todos modos.</li>
            <li>Protege lo que se <em>escribe</em>. Que un servidor pueda <em>leer</em> todas las zonas no se limita.</li>
            <li>No sustituye al plan de relevo: este solo mueve los registros A que apuntan exactamente a la IP del servidor principal de ese grupo. La protección es la segunda barrera por si algo se configurara mal.</li>
        </ul>
    </div>
</div>
