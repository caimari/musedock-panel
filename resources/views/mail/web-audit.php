<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Correo de las webs: auditoría</h4>
        <div class="text-muted small">Qué webs mandan correo por el Postfix local (sin autenticar), con qué resultado, si ya tienen SMTP autenticado y qué conviene hacer con cada una. Solo lectura, salvo los botones de cada fila.</div>
        <div class="text-muted small mt-1">Informe de <?= View::e(str_replace('T', ' ', substr((string)($report['generated'] ?? ''), 0, 16))) ?> UTC · registros leídos: <?= View::e(implode(', ', (array)($report['logs'] ?? [])) ?: 'ninguno') ?> (la hora del registro es la de este servidor).</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/docs/web-mail" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Guía</a>
        <a href="/mail/web-capture" class="btn btn-outline-light btn-sm"><i class="bi bi-inbox me-1"></i> Capturado</a>
        <a href="/mail/web-audit?refresh=1" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Refrescar</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-globe me-2"></i>Webs</div>
    <div class="card-body p-0">
        <table class="table table-dark table-sm mb-0 small align-middle">
            <thead><tr><th class="ps-3">Web</th><th>Msgs</th><th>Env.</th><th>Reb.</th><th>Motivos</th><th>SMTP</th><th>Local</th><th>SPF / DMARC</th><th>Propuesta</th><th></th></tr></thead>
            <tbody>
            <?php foreach (($report['hostings'] ?? []) as $r): ?>
                <tr>
                    <td class="ps-3"><strong><?= View::e($r['domain']) ?></strong><br><span class="text-muted"><?= View::e($r['username']) ?></span></td>
                    <td><?= (int)$r['messages'] ?></td>
                    <td><?= (int)$r['sent'] ?></td>
                    <td><?= (int)$r['bounced'] ?></td>
                    <td><?= View::e(implode(', ', array_map(fn($k, $v) => "{$k}×{$v}", array_keys($r['reasons']), array_values($r['reasons']))) ?: '—') ?></td>
                    <td>
                        <?php if (!empty($r['smtp']['managed'])): ?><span class="badge bg-success">panel</span>
                        <?php elseif (!empty($r['smtp']['smtp_plugin'])): ?><span class="badge bg-info text-dark"><?= View::e($r['smtp']['smtp_plugin']) ?></span>
                        <?php elseif (!empty($r['smtp']['laravel_smtp'])): ?><span class="badge bg-info text-dark">.env</span>
                        <?php else: ?><span class="text-muted">no</span><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $r['mode'] === 'allow' ? 'bg-secondary' : ($r['mode'] === 'block' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= View::e($r['mode'] === 'allow' ? 'permitir' : ($r['mode'] === 'capture' ? 'capturar' : ($r['mode'] === 'block' ? 'bloquear' : 'propio'))) ?></span></td>
                    <td><?php if (is_array($r['dns'] ?? null)): ?>
                        <?= !empty($r['dns']['spf']) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?> /
                        <?= !empty($r['dns']['dmarc']) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?>
                    <?php else: ?>—<?php endif; ?></td>
                    <td style="max-width:340px"><?= View::e($r['proposal']) ?></td>
                    <td class="text-nowrap pe-3">
                        <form method="POST" action="/mail/web-audit/smtp" class="d-inline" onsubmit="return confirm('Se crea una cuenta de correo para esta web, se guardan sus credenciales en un fichero del hosting (fuera de la carpeta pública) y se configura la aplicación. ¿Seguir?')">
                            <?= View::csrf() ?>
                            <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
                            <input type="hidden" name="domain" value="<?= View::e($r['domain']) ?>">
                            <button class="btn btn-outline-info btn-sm py-0" title="Configurar SMTP autenticado"><i class="bi bi-shield-lock"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($report['system'])): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-gear me-2"></i>Correo del sistema (cron, root…): no son webs</div>
    <div class="card-body p-0">
        <table class="table table-dark table-sm mb-0 small">
            <thead><tr><th class="ps-3">Usuario</th><th>Msgs</th><th>Enviados</th><th>Rebotados</th><th>Aplazados</th></tr></thead>
            <tbody>
            <?php foreach ($report['system'] as $s): ?>
                <tr><td class="ps-3"><?= View::e($s['name']) ?> (<?= (int)$s['uid'] ?>)</td><td><?= (int)$s['messages'] ?></td><td><?= (int)$s['sent'] ?></td><td><?= (int)$s['bounced'] ?></td><td><?= (int)$s['deferred'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
