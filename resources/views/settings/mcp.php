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
            consultar también los nodos del cluster (el nodo consultado debe tener su MCP activado).
            Con el segundo interruptor puede además <strong>gestionar el correo</strong>: crear dominios, publicar su DNS en
            Cloudflare, y crear buzones y alias (siempre con plan previo y confirmación).
        </p>
        <ul class="small text-muted mb-3">
            <li><strong>Apagado por defecto.</strong> Desactivado, <code>/api/mcp</code> responde 404.</li>
            <li><strong>Token obligatorio</strong> por HTTP. Se guarda solo su hash; se muestra una única vez.</li>
            <li>Los tokens incorrectos quedan en el log que vigila <strong>fail2ban</strong> (5 fallos → ban 1 h).</li>
            <li>Cada llamada queda en el <a href="/logs" class="text-info">log de actividad</a>. Nunca se devuelven contraseñas, tokens ni claves.</li>
        </ul>

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
                    <span class="text-muted small">(crear dominios de correo, publicar DNS en Cloudflare, buzones, alias)</span></label>
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

<?php if (!empty($pendingCredentials)): ?>
<div class="card mb-3" style="border-color:rgba(234,179,8,.4);">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-key-fill me-2"></i>Credenciales pendientes (<?= count($pendingCredentials) ?>)</span>
        <form method="post" action="/settings/mcp/credentials/clear" class="js-confirm-token m-0"
              data-confirm-title="¿Borrar las credenciales pendientes?"
              data-confirm-html="<?= View::e('Asegúrate de haberlas guardado: <strong>no se pueden recuperar</strong> (sí cambiar la contraseña desde el buzón).') ?>">
            <?= View::csrf() ?>
            <button class="btn btn-outline-warning btn-sm"><i class="bi bi-trash me-1"></i>Ya las he guardado, borrar</button>
        </form>
    </div>
    <div class="card-body p-0">
        <p class="small text-muted px-3 pt-3 mb-2">Contraseñas generadas por el MCP al crear buzones. No se muestran en el chat con la IA: solo aquí, y caducan a los 7 días.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th class="ps-3">Buzón</th><th>Contraseña</th><th>Creada</th></tr></thead>
                <tbody>
                <?php foreach ($pendingCredentials as $i => $c): ?>
                    <tr>
                        <td class="ps-3 align-middle"><?= View::e($c['email']) ?></td>
                        <td class="align-middle">
                            <div class="input-group input-group-sm" style="max-width:340px;">
                                <input type="password" class="form-control font-monospace" id="pc<?= $i ?>" value="<?= View::e($c['password']) ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" title="Mostrar/ocultar"
                                        onclick="var f=document.getElementById('pc<?= $i ?>');f.type=f.type==='password'?'text':'password';this.firstElementChild.className=f.type==='password'?'bi bi-eye':'bi bi-eye-slash'"><i class="bi bi-eye"></i></button>
                                <button type="button" class="btn btn-outline-secondary" title="Copiar"
                                        onclick="navigator.clipboard.writeText(document.getElementById('pc<?= $i ?>').value);this.innerHTML='<i class=&quot;bi bi-check2&quot;></i>'"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </td>
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
