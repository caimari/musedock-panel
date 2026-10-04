<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
$vscodeHttp = json_encode([
    'servers' => [
        'musedock-' . $serverKey => [
            'type' => 'http',
            'url' => $endpoint,
            'headers' => ['Authorization' => 'Bearer ${input:musedock-' . $serverKey . '-token}'],
        ],
    ],
    'inputs' => [[
        'type' => 'promptString',
        'id' => 'musedock-' . $serverKey . '-token',
        'description' => 'Token MCP de MuseDock (' . $sshHost . ')',
        'password' => true,
    ]],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$vscodeSsh = json_encode([
    'servers' => [
        'musedock-' . $serverKey => [
            'type' => 'stdio',
            'command' => 'ssh',
            'args' => ['root@' . $sshHost, 'php', '/opt/musedock-panel/bin/mcp-stdio.php'],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$claudeHttp = 'claude mcp add --transport http musedock-' . $serverKey . ' ' . $endpoint . ' --header "Authorization: Bearer TU_TOKEN"';
$claudeSsh = 'claude mcp add musedock-' . $serverKey . ' -- ssh root@' . $sshHost . ' php /opt/musedock-panel/bin/mcp-stdio.php';
?>

<!-- Estado -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-plug me-2"></i>Servidor MCP (Model Context Protocol)</span>
        <?php if ($enabled): ?>
            <span class="badge bg-success">Activado</span>
        <?php else: ?>
            <span class="badge bg-secondary">Desactivado</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Permite que un asistente de IA (Claude, ChatGPT, VS Code…) consulte este servidor: estado del servidor y del
            cluster, servicios, correo, certificados, failover y el inventario para clonar un slave. Desde el master puede
            consultar también los nodos del cluster (ver el aviso de abajo).
            Con el segundo interruptor puede además <strong>gestionar el correo</strong>: crear dominios, publicar su DNS en
            Cloudflare, y crear buzones y alias (siempre con plan previo y confirmación).
        </p>
        <ul class="small text-muted mb-3">
            <li><strong>Apagado por defecto.</strong> Desactivado, <code>/api/mcp</code> responde 404.</li>
            <li><strong>Token obligatorio</strong> por HTTP. Se guarda solo su hash; se muestra una única vez.</li>
            <li>Los tokens incorrectos quedan en el log que vigila <strong>fail2ban</strong> (5 fallos → ban 1 h).</li>
            <li>Cada llamada queda en el <a href="/logs" class="text-info">log de actividad</a>. Nunca se devuelven contraseñas, tokens ni claves.</li>
        </ul>

        <div class="alert small py-2 px-3 mb-3" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.3);color:#e2e8f0;">
            <i class="bi bi-diagram-3 me-1" style="color:#fbbf24;"></i>
            <strong>Acceso a otros nodos a través de este MCP.</strong> Con el argumento <code>node</code>, un asistente conectado al MCP de
            <em>este</em> panel puede consultar los demás nodos de su cluster <strong>sin el token MCP de cada uno</strong>: la consulta viaja por la API del
            cluster, con la clave entre nodos. Funciona en los dos sentidos: del master a sus copias y de una copia al master, si lo tiene registrado
            (pasa tras un cambio de rol). Límites que aplica siempre el nodo consultado:
            <ul class="mb-1 mt-1">
                <li>solo herramientas de <strong>lectura</strong> (las que modifican nunca se reenvían);</li>
                <li>solo si ese nodo tiene su MCP <strong>activado</strong> y además <strong>"Permitir consultas reenviadas desde otros nodos"</strong>;</li>
                <li>solo desde nodos registrados en su cluster; la respuesta va con los secretos tapados y la consulta queda en su log de actividad (con el nodo que preguntó).</li>
            </ul>
            Para que un nodo solo se pueda consultar con su propio token, desactiva aquí "Permitir consultas reenviadas".
            <a href="/docs/mcp-nodes" class="text-info">Guía</a>.
            <?php if (!empty($clusterNodes)): ?>
                <div class="mt-1">Desde este panel se puede llegar a: <?= implode(', ', array_map(static fn($n) => '<code>' . View::e($n['name']) . '</code>', $clusterNodes)) ?>.</div>
            <?php endif; ?>
        </div>

        <form method="post" action="/settings/mcp/save" class="d-flex align-items-center gap-3 flex-wrap">
            <?= View::csrf() ?>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="mcp_enabled" name="mcp_enabled" value="1"
                       <?= $enabled ? 'checked' : '' ?> <?= !$hasToken ? 'disabled' : '' ?>>
                <label class="form-check-label" for="mcp_enabled">Activar servidor MCP en este servidor</label>
            </div>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="mcp_allow_write" name="mcp_allow_write" value="1"
                       <?= $allowWrite ? 'checked' : '' ?> <?= !$hasToken ? 'disabled' : '' ?>>
                <label class="form-check-label" for="mcp_allow_write">Permitir acciones que modifican
                    <span class="text-muted small">(crear dominios de correo, buzones, alias, bases de datos, publicar DNS en Cloudflare, pedir cambios de contraseña; <strong>nunca borra</strong> buzones ni bases de datos)</span></label>
            </div>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="mcp_allow_dns" name="mcp_allow_dns" value="1"
                       <?= !empty($allowDns) ? 'checked' : '' ?> <?= !$hasToken ? 'disabled' : '' ?>>
                <label class="form-check-label" for="mcp_allow_dns">Permitir editar DNS en Cloudflare
                    <span class="text-muted small">(crear y modificar registros A, AAAA, CNAME, TXT y MX con <code>dns_record_set</code>; <strong>nunca borra</strong>; requiere también la opción anterior)</span></label>
            </div>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="mcp_allow_forwarded" name="mcp_allow_forwarded" value="1"
                       <?= !empty($allowForwarded) ? 'checked' : '' ?> <?= !$hasToken ? 'disabled' : '' ?>>
                <label class="form-check-label" for="mcp_allow_forwarded">Permitir consultas reenviadas desde otros nodos
                    <span class="text-muted small">(el MCP de otro panel del cluster puede consultar este servidor sin su token; solo lectura; ver abajo)</span></label>
            </div>
            <button class="btn btn-primary btn-sm" <?= !$hasToken ? 'disabled' : '' ?>><i class="bi bi-check2 me-1"></i>Guardar</button>
            <?php if (!$hasToken): ?>
                <span class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Genera primero un token.</span>
            <?php endif; ?>
        </form>
        <?php if ($lastUsedAt !== ''): ?>
            <div class="small text-muted mt-2">Último uso por HTTP: <?= View::e($lastUsedAt) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($enabled && $allowWrite): ?>
<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Acciones que modifican permitidas.</strong> La IA puede crear dominios de correo, publicar registros en Cloudflare y crear buzones y alias.
    Cada acción devuelve primero un plan y solo se ejecuta con <code>apply</code>; tu cliente (VS Code / Claude) te pedirá permiso en cada llamada.
    Nunca se reenvían a otros nodos. Desactívalo cuando no lo necesites.
</div>
<?php endif; ?>

<?php if (!empty($changeRequests)): ?>
<div class="card mb-3" style="border-color:rgba(56,189,248,.45);">
    <form method="post" action="/settings/mcp/change-requests" id="mcp-change-form" class="m-0">
        <?= View::csrf() ?>
        <input type="hidden" name="decision" id="mcp-change-decision" value="">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span><i class="bi bi-list-check me-2"></i>Cambios por aprobar (<?= count($changeRequests) ?>)</span>
            <span class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm" data-decision="approve"><i class="bi bi-check2-all me-1"></i>Aprobar seleccionados</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-decision="reject"><i class="bi bi-x-lg me-1"></i>Rechazar seleccionados</button>
            </span>
        </div>
        <div class="card-body p-0">
            <p class="small text-muted px-3 pt-3 mb-2">Cambios que ha preparado la IA por MCP. No se aplica nada hasta que los apruebas aquí. Caducan a los 7 días.</p>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr>
                        <th class="ps-3" style="width:32px"><input class="form-check-input" type="checkbox" id="mcp-change-all" checked title="Todos"></th>
                        <th>Cambio</th><th>Tipo</th><th>Motivo</th><th>Pedido</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($changeRequests as $r): ?>
                        <tr>
                            <td class="ps-3"><input class="form-check-input mcp-change-cb" type="checkbox" name="ids[]" value="<?= View::e($r['id']) ?>" checked></td>
                            <td><?= View::e($r['summary']) ?></td>
                            <td class="small text-muted"><?= View::e($r['type_label']) ?></td>
                            <td class="small"><?= View::e($r['reason'] !== '' ? $r['reason'] : '—') ?></td>
                            <td class="small text-muted"><?= View::e($r['at_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
<script>
(function () {
    var form = document.getElementById('mcp-change-form');
    if (!form) return;
    var cbs = function () { return Array.prototype.slice.call(form.querySelectorAll('.mcp-change-cb')); };
    document.getElementById('mcp-change-all').addEventListener('change', function (e) {
        cbs().forEach(function (c) { c.checked = e.target.checked; });
    });
    form.querySelectorAll('[data-decision]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sel = cbs().filter(function (c) { return c.checked; });
            if (!sel.length) { (window.musedockToast || alert)('Selecciona al menos una solicitud.', 'warning'); return; }
            var approve = btn.dataset.decision === 'approve';
            var list = sel.map(function (c) { return '<li>' + c.closest('tr').children[1].innerHTML + '</li>'; }).join('');
            var S = window.SwalDark || window.Swal;
            var go = function () { document.getElementById('mcp-change-decision').value = btn.dataset.decision; form.submit(); };
            if (!S || typeof S.fire !== 'function') { if (confirm((approve ? 'Aprobar ' : 'Rechazar ') + sel.length + ' cambio(s)?')) go(); return; }
            S.fire({ icon: approve ? 'question' : 'warning',
                     title: (approve ? '¿Aprobar y aplicar ' : '¿Rechazar ') + sel.length + ' cambio(s)?',
                     html: '<ul class="text-start small mb-0">' + list + '</ul>',
                     showCancelButton: true, reverseButtons: true, focusCancel: true,
                     confirmButtonText: approve ? 'Aprobar' : 'Rechazar', cancelButtonText: 'Cancelar' })
             .then(function (r) { if (r.isConfirmed) go(); });
        });
    });
})();
</script>
<?php endif; ?>

<?php if (!empty($passwordRequests)): ?>
<div class="card mb-3" style="border-color:rgba(239,68,68,.45);">
    <div class="card-header"><i class="bi bi-shield-lock-fill me-2"></i>Cambios de contraseña por confirmar (<?= count($passwordRequests) ?>)</div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-2">El MCP solo puede <strong>pedir</strong> un cambio de contraseña. No se cambia nada hasta que lo confirmas aquí con <strong>tu contraseña de administrador</strong>. La nueva se genera en el servidor y aparece en Credenciales pendientes. Nunca root, cuentas del sistema ni la base de datos del panel. Caducan a las 24 h.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th class="ps-3">Cuenta</th><th>Motivo</th><th>Pedido</th><th class="text-end pe-3">Confirmar</th></tr></thead>
                <tbody>
                <?php foreach ($passwordRequests as $r): ?>
                    <tr>
                        <td class="ps-3"><div><?= View::e($r['desc']) ?></div><div class="small text-muted"><?= View::e($r['kind_label']) ?></div></td>
                        <td class="small"><?= View::e($r['reason'] !== '' ? $r['reason'] : '—') ?></td>
                        <td class="small text-muted"><?= View::e($r['at_label']) ?></td>
                        <td class="text-end pe-3">
                            <form method="post" action="/settings/mcp/password-requests" class="d-inline-flex gap-1 m-0">
                                <?= View::csrf() ?>
                                <input type="hidden" name="id" value="<?= View::e($r['id']) ?>">
                                <input type="password" name="admin_password" class="form-control form-control-sm" style="width:190px" placeholder="Tu contraseña de admin" autocomplete="current-password" required>
                                <button class="btn btn-danger btn-sm" name="decision" value="approve"><i class="bi bi-key me-1"></i>Cambiar</button>
                            </form>
                            <form method="post" action="/settings/mcp/password-requests" class="d-inline m-0">
                                <?= View::csrf() ?>
                                <input type="hidden" name="id" value="<?= View::e($r['id']) ?>">
                                <button class="btn btn-outline-secondary btn-sm" name="decision" value="reject">Rechazar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pendingCredentials)): ?>
<div class="card mb-3" style="border-color:rgba(234,179,8,.4);">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-key-fill me-2"></i>Credenciales pendientes (<?= count($pendingCredentials) ?>)</span>
        <form method="post" action="/settings/mcp/credentials/clear" class="js-confirm-token m-0"
              data-confirm-title="¿Borrar las credenciales pendientes?"
              data-confirm-html="<?= View::e('Asegúrate de haberlas guardado: <strong>no se pueden recuperar</strong> (sí cambiar la contraseña desde el panel).') ?>">
            <?= View::csrf() ?>
            <button class="btn btn-outline-warning btn-sm"><i class="bi bi-trash me-1"></i>Ya las he guardado, borrar</button>
        </form>
    </div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-2">Contraseñas generadas por el MCP (buzones, acceso SFTP de hostings, usuarios de bases de datos…). Nunca pasan por el chat con la IA: solo se ven aquí, y caducan a los 7 días.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th class="ps-3">Para</th><th>Contraseña</th><th>Datos de conexión</th><th>Creada</th></tr></thead>
                <tbody>
                <?php foreach ($pendingCredentials as $i => $c): ?>
                    <tr>
                        <td class="ps-3 align-middle"><div><?= View::e($c['label']) ?></div><div class="small text-muted"><?= View::e($c['kind_label']) ?></div></td>
                        <td class="align-middle">
                            <div class="input-group input-group-sm" style="max-width:340px;">
                                <input type="password" class="form-control font-monospace" id="pc<?= $i ?>" value="<?= View::e($c['password']) ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" title="Mostrar/ocultar"
                                        onclick="var f=document.getElementById('pc<?= $i ?>');f.type=f.type==='password'?'text':'password';this.firstElementChild.className=f.type==='password'?'bi bi-eye':'bi bi-eye-slash'"><i class="bi bi-eye"></i></button>
                                <button type="button" class="btn btn-outline-secondary" title="Copiar"
                                        onclick="navigator.clipboard.writeText(document.getElementById('pc<?= $i ?>').value);this.innerHTML='<i class=&quot;bi bi-check2&quot;></i>'"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </td>
                        <td class="align-middle small"><?php foreach ($c['details'] as $dk => $dv): ?><div><span class="text-muted"><?= View::e((string)$dk) ?>:</span> <code><?= View::e((string)$dv) ?></code></div><?php endforeach; ?></td>
                        <td class="align-middle small text-muted"><?= View::e($c['at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Token -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-key me-2"></i>Token de acceso (HTTP)</div>
    <div class="card-body">
        <?php if ($newToken): ?>
            <div class="alert alert-warning">
                <div class="fw-semibold mb-2"><i class="bi bi-exclamation-octagon me-1"></i>Copia este token ahora: no se volverá a mostrar.</div>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="mcpNewToken" value="<?= View::e($newToken) ?>" readonly>
                    <button type="button" class="btn btn-outline-light" onclick="navigator.clipboard.writeText(document.getElementById('mcpNewToken').value);this.innerHTML='<i class=&quot;bi bi-check2&quot;></i> Copiado'">
                        <i class="bi bi-clipboard"></i> Copiar
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasToken): ?>
            <p class="small mb-3">Token activo <code><?= View::e($tokenHint) ?></code> creado el <?= View::e($tokenCreatedAt) ?>.</p>
        <?php else: ?>
            <p class="small text-muted mb-3">No hay token. Sin token el MCP no se puede activar.</p>
        <?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
            <form method="post" action="/settings/mcp/token" class="js-confirm-token"
                  data-confirm-title="<?= $hasToken ? '¿Generar un token nuevo?' : 'Generar token' ?>"
                  data-confirm-html="<?= View::e($hasToken ? 'El token actual <strong>dejará de funcionar</strong> y tendrás que actualizarlo en VS Code / Claude.' : 'Se creará el token de acceso al MCP. Se mostrará una sola vez.') ?>">
                <?= View::csrf() ?>
                <input type="hidden" name="token_action" value="generate">
                <button class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-repeat me-1"></i><?= $hasToken ? 'Regenerar token' : 'Generar token' ?></button>
            </form>
            <?php if ($hasToken): ?>
            <form method="post" action="/settings/mcp/token" class="js-confirm-token"
                  data-confirm-title="¿Revocar el token?"
                  data-confirm-html="<?= View::e('El token dejará de funcionar y el servidor MCP <strong>se desactivará</strong>.') ?>">
                <?= View::csrf() ?>
                <input type="hidden" name="token_action" value="revoke">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1"></i>Revocar</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Conexión -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-code-slash me-2"></i>Cómo conectarse</div>
    <div class="card-body">
        <p class="small text-muted">Dos formas. <strong>Por SSH</strong> no expone nada nuevo: se autentica con tu clave SSH
            (recomendado desde VS Code). <strong>Por HTTP</strong> usa el token (necesario para clientes remotos).
            En ambos casos el MCP debe estar activado aquí.</p>

        <h6 class="mt-3">VS Code (fichero <code>.vscode/mcp.json</code> o <em>MCP: Open User Configuration</em>)</h6>
        <div class="small text-muted mb-1">Por SSH:</div>
        <pre class="small p-2 rounded" style="background:#0f172a;border:1px solid rgba(255,255,255,.08);"><?= View::e($vscodeSsh) ?></pre>
        <div class="small text-muted mb-1">Por HTTP (VS Code te pedirá el token la primera vez y lo guarda cifrado):</div>
        <pre class="small p-2 rounded" style="background:#0f172a;border:1px solid rgba(255,255,255,.08);"><?= View::e($vscodeHttp) ?></pre>

        <h6 class="mt-3">Claude Code (terminal)</h6>
        <pre class="small p-2 rounded" style="background:#0f172a;border:1px solid rgba(255,255,255,.08);"><?= View::e($claudeSsh) ?>

<?= View::e($claudeHttp) ?></pre>

        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Endpoint: <code><?= View::e($endpoint) ?></code>.
            Si tienes <code>ALLOWED_IPS</code> en el <code>.env</code>, la IP del cliente debe estar permitida.</div>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('form.js-confirm-token').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmed === '1') return;
            ev.preventDefault();
            var S = window.SwalDark || window.Swal;
            var title = form.dataset.confirmTitle, html = form.dataset.confirmHtml;
            if (!S || typeof S.fire !== 'function') {
                var tmp = document.createElement('div'); tmp.innerHTML = html;
                if (confirm(title + '\n\n' + tmp.textContent)) { form.dataset.confirmed = '1'; form.submit(); }
                return;
            }
            S.fire({ icon: 'warning', title: title, html: html, showCancelButton: true,
                     confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar', focusCancel: true, reverseButtons: true })
             .then(function (r) { if (r.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); } });
        });
    });
})();
</script>
