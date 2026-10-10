<?php use MuseDockPanel\View; ?>
<div class="card"><div class="card-body">
<h4>Autorizar ChatGPT</h4>
<p>ChatGPT podrá consultar este servidor y los nodos del cluster que permitan consultas reenviadas. No podrá modificar servidores ni crear contenido.</p>
<p>Permiso solicitado: <code>musedock:read</code>. Los tokens de acceso caducan y puedes revocar la conexión en Ajustes → MCP.</p>
<form method="post" action="/settings/mcp/chatgpt/approve">
<?= View::csrf() ?><input type="hidden" name="request" value="<?= View::e($requestId) ?>">
<button class="btn btn-primary" name="decision" value="approve">Autorizar solo lectura</button>
<button class="btn btn-outline-secondary" name="decision" value="deny">Rechazar</button>
</form></div></div>
