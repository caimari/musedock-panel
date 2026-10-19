<?php use MuseDockPanel\View; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h4 class="mb-1">Correo de las webs capturado</h4>
        <div class="text-muted small">Mensajes que una web intentó mandar con <code>mail()</code> o <code>wp_mail()</code> por el servidor, sin autenticar, y que el panel ha guardado (modo «capturar») o bloqueado.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/mail/web-audit" class="btn btn-outline-info btn-sm"><i class="bi bi-clipboard-data me-1"></i> Auditoría</a>
        <a href="/docs/web-mail" class="btn btn-outline-light btn-sm"><i class="bi bi-journal-text me-1"></i> Guía</a>
        <form method="POST" action="/mail/web-capture/purge" onsubmit="return confirm('¿Borrar los mensajes de más de 30 días?')">
            <?= View::csrf() ?>
            <button class="btn btn-outline-light btn-sm"><i class="bi bi-trash me-1"></i> Limpiar antiguos</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-4 text-muted text-center">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                No hay nada capturado.
                <?php if (empty($spoolOk)): ?><br><small>La carpeta de captura todavía no existe en este servidor: se crea al poner una web en «capturar» o «bloquear».</small><?php endif; ?>
            </div>
        <?php else: ?>
        <table class="table table-dark table-sm table-hover mb-0 small">
            <thead><tr><th class="ps-3">Cuándo (UTC)</th><th>Web</th><th>Modo</th><th>Para</th><th>Asunto</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= View::e(str_replace('T', ' ', substr((string)$r['time'], 0, 16))) ?></td>
                    <td><?= View::e($r['site'] ?: $r['user']) ?></td>
                    <td><span class="badge <?= $r['mode'] === 'block' ? 'bg-danger' : 'bg-info text-dark' ?>"><?= $r['mode'] === 'block' ? 'bloqueado' : 'capturado' ?></span></td>
                    <td><?= View::e(mb_strimwidth((string)$r['to'], 0, 50, '…')) ?></td>
                    <td><?= View::e(mb_strimwidth((string)$r['subject'], 0, 60, '…')) ?></td>
                    <td class="text-end pe-3 text-nowrap">
                        <a class="btn btn-outline-light btn-sm py-0" href="/mail/web-capture/view?f=<?= View::e(urlencode($r['file'])) ?>"><i class="bi bi-eye"></i></a>
                        <form method="POST" action="/mail/web-capture/delete" class="d-inline">
                            <?= View::csrf() ?>
                            <input type="hidden" name="f" value="<?= View::e($r['file']) ?>">
                            <button class="btn btn-outline-light btn-sm py-0" title="Borrar"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
