<?php use MuseDockPanel\View; ?>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-eye me-2"></i>Testigos</h4>
        <div class="text-muted small">Servidores ajenos al cluster que miran desde fuera si llegan a tus servidores. Confirman una caída real antes de un relevo y ayudan a elegir la entrada (línea normal o alternativa).</div>
    </div>
    <a href="/docs/witnesses" class="btn btn-outline-info btn-sm"><i class="bi bi-journal-text me-1"></i>Guía de testigos</a>
</div>

<!-- Registrados -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-2"></i>Testigos de este panel</span>
        <button type="button" class="btn btn-outline-light btn-sm" onclick="loadWitnessStatus()"><i class="bi bi-arrow-clockwise me-1"></i>Comprobar ahora</button>
    </div>
    <div class="card-body">
        <?php if (!$witnesses): ?>
            <p class="text-muted small mb-0">Ninguno todavía. Crea uno con el generador de abajo y regístralo. Sin testigos, los relevos se deciden solo con la vista de este servidor.</p>
        <?php else: ?>
            <div id="witness-status" class="small text-muted">Consultando…</div>
            <div class="table-responsive mt-2">
                <table class="table table-sm align-middle small mb-0">
                    <thead><tr><th>Nombre</th><th>URL</th><th>Huella (SHA-256)</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($witnesses as $w): ?>
                        <tr>
                            <td><strong><?= View::e($w['name']) ?></strong></td>
                            <td><code><?= View::e($w['url']) ?></code></td>
                            <td><code class="small"><?= View::e(substr(chunk_split(strtoupper($w['fingerprint']), 2, ':'), 0, 23)) ?>…</code></td>
                            <td class="text-end">
                                <form method="POST" action="/settings/witnesses/remove" class="d-inline" onsubmit="return askPass(this, 'Quitar el testigo ' + <?= View::js($w['name']) ?> + ' de este panel');">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="name" value="<?= View::e($w['name']) ?>">
                                    <input type="hidden" name="admin_password" value="">
                                    <button class="btn btn-outline-danger btn-sm py-0">Quitar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Generador -->
<div class="card mb-4" style="border-color:rgba(34,197,94,.3);">
    <div class="card-header"><i class="bi bi-file-earmark-code me-2"></i>1. Crear un testigo nuevo: script de instalación</div>
    <div class="card-body small">
        <p class="text-muted">Genera un script para ejecutar como root en el servidor testigo (cualquier Linux con Python 3, idealmente en <strong>otro proveedor</strong> que tus servidores).
            Instala el agente, crea <strong>allí</strong> su clave y su certificado, lo deja como servicio y abre su puerto solo a las IPs que indiques.
            El script no lleva ningún secreto. Volver a ejecutarlo actualiza el agente y las comprobaciones y conserva la clave y la huella.</p>
        <form method="POST" action="/settings/witnesses/script">
            <?= View::csrf() ?>
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <label class="form-label">IP pública del testigo</label>
                    <input type="text" name="listen" class="form-control form-control-sm" placeholder="203.0.113.10" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Puerto</label>
                    <input type="number" name="port" class="form-control form-control-sm" value="8447" min="1024" max="65535">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cada (s)</label>
                    <input type="number" name="interval" class="form-control form-control-sm" value="15" min="5" max="300">
                </div>
            </div>
            <label class="form-label">Quién puede preguntarle (IPs o redes, una por línea)</label>
            <textarea name="allowed" class="form-control form-control-sm mb-1" rows="3" style="font-family:monospace"><?= View::e(implode("\n", $defaultAllowed)) ?></textarea>
            <div class="form-text mb-2">Propuesta: las IPs públicas de <em>Cluster → Failover → Servidores</em>. Añade los servidores de otros clusters que también vayan a usar este testigo.</div>
            <label class="form-label">Qué mira (JSON)</label>
            <textarea name="targets" class="form-control form-control-sm mb-1" rows="10" style="font-family:monospace"><?= View::e(json_encode($defaultTargets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></textarea>
            <div class="form-text mb-3">
                Propuesta: cada servidor del relevo (<code>tcp</code> a su IP, puerto 443) y, si este panel vigila la entrada de algún servidor,
                su nombre de comprobación por la entrada normal (<code>resolve</code> = IP fija) y por la alternativa (<code>resolve_host</code> = nombre DNS de IP dinámica).
                Tipos: <code>{"id","type":"tcp","host","port"}</code> o <code>{"id","type":"https","url","resolve"|"resolve_host","expect"}</code>.
            </div>
            <button class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Descargar script</button>
        </form>
    </div>
</div>

<!-- Registrar -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>2. Registrar el testigo en este panel</div>
    <div class="card-body small">
        <p class="text-muted">Al acabar, el script muestra la URL y la huella, y cómo ver la clave <strong>en el testigo</strong>. Cópialas aquí directamente: la clave no debe pasar por ningún chat ni correo.
            Antes de guardar, el panel comprueba que el testigo contesta con esa huella y esa clave. Repite el registro en cada panel que vaya a usar el testigo.</p>
        <form method="POST" action="/settings/witnesses/add" onsubmit="return askPass(this, 'Registrar el testigo');" autocomplete="off">
            <?= View::csrf() ?>
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label">Nombre</label><input type="text" name="name" class="form-control form-control-sm" placeholder="testigo1" required pattern="[a-z0-9][a-z0-9-]{0,30}"></div>
                <div class="col-md-4"><label class="form-label">URL</label><input type="text" name="url" class="form-control form-control-sm" placeholder="https://203.0.113.10:8447" required></div>
                <div class="col-md-6"><label class="form-label">Huella SHA-256</label><input type="text" name="fingerprint" class="form-control form-control-sm" placeholder="AB:CD:…" required style="font-family:monospace"></div>
                <div class="col-md-6"><label class="form-label">Clave</label><input type="password" name="key" class="form-control form-control-sm" required autocomplete="new-password"></div>
            </div>
            <input type="hidden" name="admin_password" value="">
            <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-check-lg me-1"></i>Registrar</button>
        </form>
    </div>
</div>

<script>
function askPass(form, title) {
    const S = window.SwalDark || window.Swal;
    if (form.dataset.ok === '1') return true;
    S.fire({ title, input: 'password', inputPlaceholder: 'Tu contraseña de administrador', showCancelButton: true,
        confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar', inputValidator: v => !v && 'Hace falta la contraseña' })
     .then(r => { if (r.isConfirmed) { form.querySelector('[name=admin_password]').value = r.value; form.dataset.ok = '1'; form.submit(); } });
    return false;
}
function loadWitnessStatus() {
    const box = document.getElementById('witness-status');
    if (!box) return;
    box.textContent = 'Consultando…';
    const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    fetch('/settings/witnesses/status', { cache: 'no-store' }).then(r => r.json()).then(d => {
        box.innerHTML = (d.witnesses || []).map(w => '<div class="mb-2"><strong>' + esc(w.name) + '</strong> '
            + (w.ok ? '<span class="badge bg-success">responde</span> <span class="text-muted">v' + esc(w.version) + '</span>' : '<span class="badge bg-danger">no responde</span> ' + esc(w.error))
            + (w.ok ? '<div class="d-flex flex-wrap gap-2 mt-1">' + Object.entries(w.targets || {}).map(([id, t]) =>
                '<span class="badge ' + (t.ok ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger') + '" title="' + esc(t.addr) + '">'
                + esc(id) + ' · ' + (t.ok ? (t.latency_avg_ms ?? '?') + ' ms' : 'falla') + (t.loss_pct ? ' · ' + t.loss_pct + '%' : '') + '</span>').join('') + '</div>' : '')
            + '</div>').join('') || '<span class="text-muted">Sin testigos.</span>';
    }).catch(() => { box.textContent = 'No se pudo consultar.'; });
}
document.addEventListener('DOMContentLoaded', loadWitnessStatus);
</script>
