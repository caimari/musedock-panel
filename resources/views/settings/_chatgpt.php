<?php use MuseDockPanel\View; $g = $chatGpt; $canConnect = $g['installed'] && $g['has_key'] && $g['client_id'] !== '' && !empty($enabled); ?>
<div class="card mb-4"><div class="card-header"><strong>Conexión mediante túnel OpenAI</strong></div><div class="card-body">
<p class="small text-muted">Modalidad alternativa con su propia configuración. Si ya utilizas la conexión directa, no necesitas configurar este túnel.</p><p>Conecta tu cuenta de ChatGPT mediante OAuth y un túnel seguro. El túnel sale hacia OpenAI por HTTPS; no abre el puerto del panel.</p>
<div class="d-flex gap-3 flex-wrap mb-3">
<span class="badge bg-<?= $g['enabled'] ? ($g['ready'] ? 'success' : 'warning text-dark') : 'secondary' ?>"><?= !$g['enabled'] ? 'Desconectado' : ($g['ready'] ? 'Túnel listo' : 'Esperando al túnel') ?></span>
<span>Permisos: <strong>solo lectura</strong> · OAuth <code>musedock:read</code></span>
</div>
<?php if ($g['last_error']): ?><div class="alert alert-warning"><?= View::e($g['last_error']) ?></div><?php endif; ?>
<p class="small text-muted">Última consulta: <?= View::e($g['last_used'] ?: 'Sin consultas') ?> · Última comprobación: <?= View::e($g['last_check'] ?: 'Sin comprobar') ?></p>
<form method="post" action="/settings/mcp/chatgpt/action" class="d-flex gap-2 align-items-center flex-wrap mb-3">
<?= View::csrf() ?>
<div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="chatgpt-switch" <?= $g['enabled'] ? 'checked' : '' ?> <?= !$g['enabled'] && !$canConnect ? 'disabled' : '' ?> onchange="this.form.querySelector('[name=action]').value=this.checked?'connect':'disconnect';this.form.submit()"><label class="form-check-label" for="chatgpt-switch">Conectar con ChatGPT</label></div>
<input type="hidden" name="action" value="<?= $g['enabled'] ? 'disconnect' : 'connect' ?>">
<button class="btn btn-outline-info btn-sm" type="submit" <?= !$g['enabled'] && !$canConnect ? 'disabled' : '' ?>><?= $g['enabled'] ? 'Desconectar y revocar acceso' : 'Conectar' ?></button>
</form>
<?php if (!$g['enabled'] && !$canConnect): ?><p class="small text-muted">Para conectar, completa la configuración OAuth, guarda la clave de OpenAI, instala el servicio del túnel y activa el servidor MCP.</p><?php endif; ?>
<form method="post" action="/settings/mcp/chatgpt/action" class="mb-3"><?= View::csrf() ?><button class="btn btn-outline-secondary btn-sm" name="action" value="test">Comprobar túnel</button></form>
<details><summary>Configurar conexión</summary>
<p class="small mt-3">Necesitas crear un túnel en <a href="https://platform.openai.com/settings/organization/tunnels" target="_blank" rel="noopener noreferrer">OpenAI Platform</a> con la cuenta vinculada a ChatGPT. El ID identifica el túnel; su clave permite ejecutarlo. Son independientes de tu token MCP actual.</p>
<?php if (!$g['installed']): ?><div class="alert alert-info small">Falta instalar el servicio del túnel y publicar las rutas OAuth en un dominio HTTPS. Sigue <code>config/chatgpt/README.md</code>. La conexión permanecerá desactivada hasta completar la instalación.</div><?php endif; ?>
<?php if ($chatGptNewSecret): ?><div class="alert alert-warning">Secreto del cliente OAuth (se muestra una vez): <input id="tunnel-new-secret" type="password" class="form-control font-monospace" readonly value="<?= View::e($chatGptNewSecret) ?>" autocomplete="off"><div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-mcp-toggle="tunnel-new-secret">Mostrar</button><button type="button" class="btn btn-sm btn-outline-info" data-mcp-copy="tunnel-new-secret">Copiar secreto</button></div>Cópialo en ChatGPT y guárdalo de forma segura.</div><?php endif; ?>
<p class="small">En la primera configuración, si ChatGPT aún no muestra el campo Recurso, usa tu dominio OAuth seguido de <code>/api/mcp/chatgpt</code>. Con el túnel en marcha, copia el Recurso detectado en ChatGPT; desconecta, actualízalo y vuelve a conectar antes de autorizar.</p>
<form method="post" action="/settings/mcp/chatgpt/save" autocomplete="off">
<?= View::csrf() ?>
<div class="row g-3 my-2">
<?php foreach (['issuer' => ['Dominio HTTPS de OAuth', 'https://oauth.tu-dominio.com'], 'panel_url' => ['URL privada del panel (para aprobar)', 'https://panel.tu-dominio.com:8444'], 'resource' => ['Recurso (copia el campo Recurso detectado por ChatGPT)', 'URL HTTPS del recurso detectado'], 'redirect_uri' => ['Devolución de llamada mostrada por ChatGPT', 'https://chatgpt.com/connector_platform_oauth_redirect'], 'tunnel_id' => ['ID del túnel OpenAI', 'tunnel_…']] as $key => [$label, $placeholder]): ?>
<div class="col-md-6"><label class="form-label" for="gpt-<?= $key ?>"><?= View::e($label) ?></label><input id="gpt-<?= $key ?>" class="form-control" type="<?= in_array($key, ['issuer','panel_url','resource','redirect_uri'], true) ? 'url' : 'text' ?>" name="<?= $key ?>" value="<?= View::e($g[$key]) ?>" placeholder="<?= View::e($placeholder) ?>" required <?= $g['enabled'] ? 'disabled' : '' ?>></div>
<?php endforeach; ?>
<div class="col-md-6"><label class="form-label" for="gpt-key">Clave de ejecución OpenAI</label><input id="gpt-key" class="form-control" type="password" name="openai_key" autocomplete="new-password" placeholder="<?= $g['has_key'] ? 'Guardada; deja vacío para conservarla' : 'sk-…' ?>" <?= $g['enabled'] ? 'disabled' : '' ?>></div>
</div>
<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="rotate_client" value="1" <?= $g['enabled'] ? 'disabled' : '' ?>> Generar nuevas credenciales OAuth (revoca las anteriores)</label>
<button class="btn btn-primary" <?= $g['enabled'] ? 'disabled' : '' ?>>Guardar configuración</button>
</form>
<?php if ($g['client_id']): ?>
<div class="mt-3 small"><strong>En ChatGPT → Añadir servidor MCP:</strong><ol>
<li>Conexión: Túnel. ID: <code><?= View::e($g['tunnel_id']) ?></code>.</li>
<li>Autenticación: OAuth. Registro: cliente definido por el usuario.</li>
<li>ID de cliente: <code><?= View::e($g['client_id']) ?></code>. Secreto: el mostrado al guardar.</li>
<li>Método del endpoint de tokens: <code>client_secret_basic</code>. Ámbito predeterminado: <code>musedock:read</code>. Ámbitos base: vacío. OIDC: desactivado.</li>
<li>Autoriza el acceso en el panel. Prueba «consulta este servidor y lista los nodos».</li></ol>
<p>La autorización usa el inicio de sesión y MFA del panel, accesible desde tu red autorizada. El dominio OAuth no da acceso a la administración.</p>
</div><?php endif; ?>
</details>
</div></div>
