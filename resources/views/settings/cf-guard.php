<?php use MuseDockPanel\View; use MuseDockPanel\Services\CfGuardService as G; ?>
<?php require __DIR__ . '/_tabs.php'; ?>
<?php
$modeLabels = ['off' => 'Apagado', 'simulate' => 'Simulación', 'auto' => 'Automático'];
$stateBadge = ['blocked' => ['danger', 'Bloqueada'], 'clear' => ['success', 'Responde'], 'glitch' => ['warning text-dark', 'Fallo puntual'],
               'recovering' => ['info text-dark', 'Volviendo (espera ' . $restoreMinutes . ' min estable)'], 'unknown' => ['secondary', 'Sin datos']];
$statusBadge = ['active' => ['danger', 'Sin proxy ahora'], 'restored' => ['success', 'Restaurado'], 'kept_manual' => ['warning text-dark', 'Cambiado a mano: respetado'],
                'failed' => ['dark', 'Falló'], 'sim_closed' => ['secondary', 'Simulación cerrada'], 'cancelled_user' => ['secondary', 'Anulado a mano: queda sin proxy']];
$causeLabel = ['bloqueo' => 'Bloqueo de operadoras (desde fuera respondía)', 'cloudflare' => 'Cloudflare no respondía', 'manual' => 'Cambio manual a «con proxy» durante un bloqueo'];
$realActive = array_values(array_filter($active, fn ($c) => !(int) $c['simulated']));
$simActive = array_values(array_filter($active, fn ($c) => (int) $c['simulated']));
$blockedIps = array_keys(array_filter($states, fn ($s) => $s['state'] === 'blocked'));
$proxiedCount = count(array_filter($records, fn ($r) => (int) $r['proxied']));
$dWith = count(array_filter($domains, fn ($d) => $d['original']));
$dOff = count(array_filter($domains, fn ($d) => $d['original'] && !$d['now']));
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-shield-exclamation me-2"></i>Bloqueos Cloudflare
            <span class="badge <?= $mode === 'auto' ? 'bg-success' : ($mode === 'simulate' ? 'bg-info text-dark' : 'bg-secondary') ?> ms-2"><?= $modeLabels[$mode] ?></span></h4>
        <div class="text-muted small" style="max-width:900px;">Unas sondas en conexiones reales comprueban cada minuto si llegan a las IPs de Cloudflare de los dominios de hosting de este panel.
            Si una IP deja de responder desde alguna operadora (bloqueos de LaLiga en España, caídas de Cloudflare…) y el servidor responde directo con un certificado válido,
            se quita la nube naranja de los dominios afectados y, cuando la IP vuelve a responder de forma estable, se pone otra vez. No es un escudo: ir sin proxy expone
            la IP del servidor y pierde la protección de Cloudflare, por eso se hace solo cuando hace falta y el menor tiempo posible. Solo se toca lo que tenía el proxy, y se respetan los cambios manuales.</div>
    </div>
</div>

<?php if (!$isActive): ?>
<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i> Este panel no manda (slave o aislado): muestra el estado pero no cambia nada en Cloudflare.</div>
<?php endif; ?>

<?php if ($realActive): ?>
<div class="alert alert-danger"><i class="bi bi-shield-fill-exclamation me-1"></i>
    <strong>Ahora mismo hay <?= count($realActive) ?> dominio(s) sin la nube naranja</strong> porque sus IPs de Cloudflare están bloqueadas (<?= View::e(implode(', ', $blockedIps) ?: 'comprobando si ya responden') ?>). Van directos al servidor y se ven desde España.
    <?php if ($episodes): ?><div class="small mt-1"><i class="bi bi-clock me-1"></i>Bloqueo detectado desde <?= View::e(G::fmt(min($episodes))) ?>. Hora de la página: <?= View::e(G::fmt(G::now())) ?>.</div><?php endif; ?>
    <div class="small mt-1">Se volverá a poner el proxy cuando esas IPs lleven <?= (int) $restoreMinutes ?> minutos seguidos respondiendo desde todas las sondas.</div>
</div>
<?php elseif ($simActive): ?>
<div class="alert alert-info"><i class="bi bi-eye me-1"></i><strong>Simulación:</strong> en automático ahora se habría quitado el proxy de <?= count($simActive) ?> dominio(s). Cloudflare no se ha tocado.</div>
<?php elseif ($blockedIps): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Hay IPs bloqueadas (<?= View::e(implode(', ', $blockedIps)) ?>) pero no se ha cambiado nada<?= $mode === 'off' ? ': el modo está apagado' : ' todavía' ?>.</div>
<?php elseif ($livePaths): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Todo normal: ninguna IP de Cloudflare de tus dominios está bloqueada desde las sondas.</div>
<?php else: ?>
<div class="alert alert-secondary"><i class="bi bi-broadcast me-1"></i>Ninguna sonda ha informado en los últimos minutos: sin datos no se cambia nada.</div>
<?php endif; ?>

<?php if ($newToken): ?>
<div class="alert alert-warning">
    <h6><i class="bi bi-key me-1"></i>Token de la sonda «<?= View::e($newToken['name']) ?>»</h6>
    <p class="small mb-2">Cópialo ahora: <strong>no se volverá a mostrar</strong> (solo se guarda su huella).</p>
    <code class="d-block p-2 rounded user-select-all" style="background:rgba(0,0,0,.3);"><?= View::e($newToken['token']) ?></code>
    <p class="small mt-2 mb-1"><strong>En la máquina de la sonda</strong> (como root). Si ya tiene la sonda del CMS, solo hace falta una segunda configuración:</p>
<pre class="small p-2 rounded mb-0" style="background:rgba(0,0,0,.3);white-space:pre-wrap;">curl -fsSk <?= View::e($apiBase) ?>/settings/cf-guard/probe.py  # o el botón «Descargar sonda»
install -m 600 /dev/null /etc/musedock-edge-probe-panel.token   # pega el token dentro
cat &gt; /etc/musedock-edge-probe-panel.conf &lt;&lt;'EOF'
URL=<?= View::e($apiBase) ?>

API_PREFIX=/api/cf-guard
TOKEN_FILE=/etc/musedock-edge-probe-panel.token
# PATHS=Operadora1:eth0,Operadora2:eth1
# RESOLVE=10.0.0.1
EOF
echo '* * * * * root EDGE_PROBE_CONF=/etc/musedock-edge-probe-panel.conf flock -n /run/musedock-edge-probe-panel.lock python3 -I /usr/local/bin/musedock-edge-probe.py' &gt; /etc/cron.d/musedock-edge-probe-panel</pre>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-sliders me-2"></i>Ajustes</div>
    <div class="card-body small">
        <form method="POST" action="/settings/cf-guard">
            <?= View::csrf() ?>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Modo</label>
                    <?php foreach (['off' => 'No hace nada (solo deshace lo que ya cambió)', 'simulate' => 'Detecta y avisa de lo que haría, sin tocar Cloudflare', 'auto' => 'Quita y vuelve a poner el proxy solo'] as $m => $desc): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="cfg-mode-<?= $m ?>" value="<?= $m ?>" <?= $mode === $m ? 'checked' : '' ?> <?= $isSlave ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="cfg-mode-<?= $m ?>"><strong><?= $modeLabels[$m] ?></strong> <span class="text-muted">— <?= $desc ?></span></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Volver a poner el proxy tras</label>
                    <div class="input-group input-group-sm"><input type="number" min="10" max="240" class="form-control" name="restore_minutes" value="<?= (int) $restoreMinutes ?>"><span class="input-group-text">min</span></div>
                    <div class="form-text">Seguidos respondiendo bien desde todas las sondas.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nunca tocar estos dominios</label>
                    <textarea class="form-control form-control-sm" name="excluded" rows="3" placeholder="uno por línea (incluye sus subdominios)"><?= View::e($excluded) ?></textarea>
                    <?php if ($cmsManaged): ?><div class="form-text">Excluidos solos (los gestiona su MuseDock CMS): <?= View::e(implode(', ', $cmsManaged)) ?></div><?php endif; ?>
                </div>
            </div>
            <?php if (!$isSlave): ?><button class="btn btn-primary btn-sm mt-3"><i class="bi bi-check-lg me-1"></i>Guardar</button><?php endif; ?>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-broadcast me-2"></i>Sondas</span>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/settings/cf-guard/probe.py" class="btn btn-outline-light btn-sm"><i class="bi bi-download me-1"></i>Descargar sonda</a>
            <?php if (!$isSlave): ?>
            <form method="POST" action="/settings/cf-guard/probe" class="d-flex gap-2"><?= View::csrf() ?>
                <input type="text" name="name" class="form-control form-control-sm" placeholder="nombre, p. ej. b250" style="width:170px;" required>
                <button class="btn btn-outline-info btn-sm text-nowrap"><i class="bi bi-plus-circle me-1"></i>Crear sonda</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body small">
        <?php if (!$probes): ?><p class="text-muted mb-0">No hay sondas. Crea una para la máquina que vigilará desde España (puede ser la misma que usa MuseDock CMS).</p><?php else: ?>
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Sonda</th><th>Salidas (último informe)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($probes as $p): ?>
                <tr>
                    <td><strong><?= View::e($p['name']) ?></strong><div class="text-muted">creada <?= View::e(G::fmt($p['created_at'])) ?></div></td>
                    <td>
                        <?php $mine = array_filter($probePaths, fn ($pp) => $pp['probe'] === $p['name']); ?>
                        <?php if (!$mine): ?><span class="text-muted">Aún no ha informado.</span><?php endif; ?>
                        <?php foreach ($mine as $pp): $live = isset($livePaths[$pp['probe'] . '/' . $pp['path']]); ?>
                            <div><span class="badge <?= $live ? 'bg-success' : 'bg-secondary' ?>"><?= $live ? 'viva' : 'muda' ?></span> <strong><?= View::e($pp['path']) ?></strong> <span class="text-muted"><?= View::e($pp['public_ip']) ?> · <?= View::e(G::fmt($pp['last'])) ?></span></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-end">
                        <?php if (!$isSlave): ?>
                        <form method="POST" action="/settings/cf-guard/probe/revoke" onsubmit="return confirm('¿Revocar la sonda <?= View::e($p['name']) ?>?');"><?= View::csrf() ?>
                            <input type="hidden" name="name" value="<?= View::e($p['name']) ?>"><button class="btn btn-outline-danger btn-sm py-0">Revocar</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-hdd-network me-2"></i>IPs de Cloudflare de tus dominios
            <span class="badge bg-dark border fw-normal ms-2" title="Las sondas informan cada minuto; la página se actualiza sola"><span class="spinner-grow spinner-grow-sm text-success" style="width:.55rem;height:.55rem;"></span> en directo · actualiza en <span id="cfg-countdown">60</span> s</span></span>
        <?php if (!$isSlave): ?>
        <form method="POST" action="/settings/cf-guard/inventory"><?= View::csrf() ?><button class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Leer Cloudflare</button></form>
        <?php endif; ?>
    </div>
    <div class="card-body small p-0">
        <div class="px-3 pt-2 text-muted"><?= count($records) ?> registros de dominios del panel, <?= $proxiedCount ?> con proxy · última lectura: <?= $inventoryAt ? View::e(G::fmt(gmdate('Y-m-d H:i:s', $inventoryAt))) : 'nunca' ?>
            <?php if ($inventoryErrors): ?><div class="text-danger"><?= View::e($inventoryErrors) ?></div><?php endif; ?></div>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>IP</th><th>Estado</th><?php foreach ($livePaths as $lp): ?><th><?= View::e($lp['probe'] . ' · ' . $lp['path']) ?></th><?php endforeach; ?><th>Dominios</th></tr></thead>
            <tbody>
            <?php if (!$states): ?><tr><td colspan="<?= 3 + count($livePaths) ?>" class="text-center text-muted p-3">Sin datos todavía: pulsa «Leer Cloudflare».</td></tr><?php endif; ?>
            <?php foreach ($states as $ip => $s):
                $view = match (true) {
                    in_array($s['state'], ['blocked', 'unknown'], true) => $s['state'],
                    in_array(false, $s['paths'], true) => 'glitch',
                    $s['state'] === 'recovering' && !empty($s['had_fail']) => 'recovering',
                    default => 'clear',
                };
                [$bc, $bl] = $stateBadge[$view]; $names = $byIp[$ip] ?? []; ?>
                <tr>
                    <td><code><?= View::e($ip) ?></code></td>
                    <td><span class="badge bg-<?= $bc ?>"><?= View::e($bl) ?></span></td>
                    <?php foreach ($livePaths as $k => $lp): $v = $s['paths'][$k] ?? null; ?>
                        <td><?php if ($v === null): ?><span class="text-muted">—</span><?php elseif ($v): ?><i class="bi bi-check-circle-fill text-success"></i><?php else: ?><i class="bi bi-x-circle-fill text-danger"></i><?php endif; ?></td>
                    <?php endforeach; ?>
                    <td><?= count($names) ?> <?php if ($names): ?><span class="text-muted">· <?= View::e(implode(', ', array_slice($names, 0, 4))) ?><?= count($names) > 4 ? '…' : '' ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-globe2 me-2"></i>Tus dominios en Cloudflare
        <div class="text-muted small mt-1"><?= count($domains) ?> nombres: <strong><?= $dWith ?></strong> configurados con nube naranja y <strong><?= count($domains) - $dWith ?></strong> sin ella (directos; nunca se les pone el proxy).
        <?php if ($dOff): ?> Ahora mismo <strong class="text-danger"><?= $dOff ?></strong> están sin proxy por un bloqueo.<?php endif; ?> Pulsa el badge de la configuración original para cambiarla.</div></div>
    <div class="card-body small p-0">
        <details <?= $dOff ? 'open' : '' ?>>
            <summary class="px-3 py-2 text-info" style="cursor:pointer;">Ver la lista completa</summary>
            <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Nombre</th><th>Apunta a</th><th>Configuración original</th><th>Ahora</th></tr></thead>
                <tbody>
                <?php foreach ($domains as $d):
                    if ($d['original']) {
                        $what = !$d['now'] ? 'Ahora ya va directo por un bloqueo, así que no se toca Cloudflare: solo se anula el regreso. Cuando acabe el bloqueo no se le volverá a poner el proxy.'
                                           : 'Se quitará la nube naranja en Cloudflare ahora mismo: irá directo al servidor.';
                    } else {
                        $what = ($d['zone_blocked'] && !$d['excluded']) ? 'Su zona tiene IPs de Cloudflare bloqueadas ahora (' . implode(', ', $d['zone_blocked']) . '): seguirá directo de momento y se le pondrá el proxy él solo cuando acabe el bloqueo.'
                                                                         : 'Se pondrá la nube naranja en Cloudflare ahora mismo.';
                    } ?>
                    <tr>
                        <td><strong><?= View::e($d['name']) ?></strong><?php if ($d['excluded']): ?> <span class="badge bg-secondary">nunca tocar</span><?php endif; ?></td>
                        <td class="text-muted"><?= View::e($d['type'] . ' ' . $d['content']) ?></td>
                        <td>
                            <button type="button" class="badge border-0 cfg-original <?= $d['original'] ? '' : 'bg-dark border' ?>" style="cursor:pointer;<?= $d['original'] ? 'background:#f6821f;' : '' ?>" <?= $isSlave ? 'disabled' : '' ?>
                                    data-record="<?= View::e($d['record_id']) ?>" data-name="<?= View::e($d['name']) ?>" data-to="<?= $d['original'] ? '0' : '1' ?>" data-what="<?= View::e($what) ?>" title="Pulsa para cambiar la configuración original">
                                <?= $d['original'] ? '<i class="bi bi-cloud-fill"></i> Con proxy' : '<i class="bi bi-cloud"></i> Sin proxy (directo)' ?> <i class="bi bi-pencil ms-1" style="font-size:.7em;opacity:.7;"></i>
                            </button>
                        </td>
                        <td>
                            <?php if ($d['original'] && !$d['now'] && $d['manual']): ?><span class="badge bg-warning text-dark">Pendiente de poner proxy</span> <span class="text-muted">cuando acabe el bloqueo (cambio manual)</span>
                            <?php elseif ($d['original'] && !$d['now']): ?><span class="badge bg-danger">Sin proxy por bloqueo</span> <span class="text-muted">desde <?= View::e(G::fmt($d['changed_at'])) ?></span>
                            <?php else: ?><span class="text-muted">Igual que el original</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Cambios (<?= count($active) ?> activos)</div>
    <div class="card-body small p-0">
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Dominio</th><th>Estado</th><th>Motivo</th><th>Quitado</th><th>Restaurado</th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="5" class="text-center text-muted p-3">Ningún cambio todavía.</td></tr><?php endif; ?>
            <?php foreach ($history as $c): [$sc, $sl] = $statusBadge[$c['status']] ?? ['secondary', $c['status']]; ?>
                <tr>
                    <td><strong><?= View::e($c['name']) ?></strong> <?php if ((int) $c['simulated']): ?><span class="badge bg-info text-dark">simulado</span><?php endif; ?></td>
                    <td><span class="badge bg-<?= $sc ?>"><?= View::e((int) $c['simulated'] && $c['status'] === 'active' ? 'Lo habría quitado' : $sl) ?></span><?php if ($c['note']): ?><div class="text-muted"><?= View::e($c['note']) ?></div><?php endif; ?></td>
                    <td><?= View::e($causeLabel[$c['cause']] ?? $c['cause']) ?><div class="text-muted"><?= View::e(str_replace(',', ', ', $c['edge_ips'])) ?></div></td>
                    <td class="text-nowrap"><?= View::e(G::fmt($c['changed_at'])) ?></td>
                    <td class="text-nowrap"><?= View::e(G::fmt($c['restored_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text me-2"></i>Registro</span>
        <?php if (!$isSlave): ?>
        <form method="POST" action="/settings/cf-guard/clear-log" onsubmit="return confirm('¿Vaciar el registro y el historial cerrado? Los cambios activos se conservan.');"><?= View::csrf() ?>
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Vaciar registro</button></form>
        <?php endif; ?>
    </div>
    <div class="card-body small p-0">
        <table class="table table-sm mb-0">
            <tbody>
            <?php if (!$events): ?><tr><td class="text-center text-muted p-3">Sin entradas.</td></tr><?php endif; ?>
            <?php foreach ($events as $ev): ?>
                <tr><td class="text-nowrap text-muted" style="width:170px;"><?= View::e(G::fmt($ev['created_at'])) ?></td>
                    <td><span class="badge <?= $ev['level'] === 'error' ? 'bg-danger' : ($ev['level'] === 'warn' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= View::e($ev['level']) ?></span> <?= View::e($ev['message']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="POST" action="/settings/cf-guard/original" id="cfg-original-form" style="display:none;"><?= View::csrf() ?>
    <input type="hidden" name="record_id"><input type="hidden" name="proxied"><input type="hidden" name="admin_password">
</form>

<script>
(function () {
    const S = window.SwalDark || window.Swal;
    let modalOpen = false;
    const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    document.querySelectorAll('.cfg-original').forEach(b => b.addEventListener('click', () => {
        modalOpen = true;
        const to = b.dataset.to === '1' ? '<strong style="color:#f6821f;">Con proxy</strong>' : '<strong>Sin proxy (directo)</strong>';
        S.fire({ title: 'Cambiar la configuración original', input: 'password', inputPlaceholder: 'Tu contraseña de administrador',
            html: '<div style="text-align:left;font-size:.95rem;"><p><strong>' + esc(b.dataset.name) + '</strong> pasará a: ' + to + '</p><p style="opacity:.75">' + esc(b.dataset.what) + '</p><p class="mb-0">El sistema respetará esta configuración a partir de ahora.</p></div>',
            showCancelButton: true, confirmButtonText: 'Cambiar', cancelButtonText: 'Cancelar', inputValidator: v => !v && 'Hace falta la contraseña' })
         .then(r => {
            modalOpen = false;
            if (!r.isConfirmed) return;
            const f = document.getElementById('cfg-original-form');
            f.record_id.value = b.dataset.record; f.proxied.value = b.dataset.to; f.admin_password.value = r.value; f.submit();
         });
    }));
    // Refresco automático cada minuto, salvo si se está escribiendo o hay una ventana abierta
    let left = 60, typing = false;
    const el = document.getElementById('cfg-countdown');
    document.addEventListener('focusin', e => { if (e.target.matches('input, textarea, select')) typing = true; });
    document.addEventListener('focusout', () => { typing = false; });
    setInterval(() => {
        if (typing || modalOpen) { el.textContent = '—'; return; }
        left--; el.textContent = left;
        if (left <= 0) location.reload();
    }, 1000);
})();
</script>
