<?php
use MuseDockPanel\View;
use MuseDockPanel\Services\CloudflareService;
?>

<div class="row g-3">
    <!-- Account Info -->
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-server me-2"></i>Account Details</span>
                <div class="d-flex gap-2">
                    <a href="/accounts/<?= $account['id'] ?>/migrate" class="btn btn-outline-light btn-sm"><i class="bi bi-cloud-download me-1"></i>Migrate</a>
                    <a href="/accounts/<?= $account['id'] ?>/edit" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i> Edit</a>
                    <?php if ($account['status'] === 'active'): ?>
                        <?php $hasActiveMail = !empty($mailDomain) && $mailDomain['status'] === 'active'; ?>
                        <form id="suspendForm" method="POST" action="/accounts/<?= $account['id'] ?>/suspend">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <input type="hidden" name="suspend_mail" id="suspend-mail-input" value="0">
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmAction(document.getElementById('suspendForm'), {
                                title: 'Suspend <?= View::e($account['domain']) ?>?',
                                html: '<p style=\'color:#94a3b8;\'>This will:</p><ul style=\'text-align:left;color:#94a3b8;font-size:0.9rem;\'><li>Block SSH/SFTP access</li><li>Stop PHP-FPM pool</li><li>Website will go offline</li></ul><?= $hasActiveMail ? "<div style=\"margin-top:12px;padding:10px;background:rgba(251,191,36,0.1);border-radius:6px;text-align:left;\"><label style=\"color:#fbbf24;font-size:0.85rem;cursor:pointer;\"><input type=\"checkbox\" id=\"swal-suspend-mail\" style=\"margin-right:6px;\">Suspender tambien el correo (" . count($mailAccounts) . " cuenta/s)</label></div>" : "" ?>',
                                icon: 'warning',
                                confirmText: 'Yes, suspend it'
                            }, function() { <?= $hasActiveMail ? "document.getElementById('suspend-mail-input').value = document.getElementById('swal-suspend-mail')?.checked ? '1' : '0';" : "" ?> })"><i class="bi bi-pause-circle"></i> Suspend</button>
                        </form>
                    <?php else: ?>
                        <form id="activateForm" method="POST" action="/accounts/<?= $account['id'] ?>/activate">
                    <?= \MuseDockPanel\View::csrf() ?>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="confirmAction(document.getElementById('activateForm'), {
                                title: 'Activate <?= View::e($account['domain']) ?>?',
                                text: 'This will restore SSH/SFTP access, restart PHP-FPM, and bring the website back online.',
                                icon: 'question',
                                confirmText: 'Yes, activate it'
                            })"><i class="bi bi-play-circle"></i> Activate</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="ps-3 text-muted" style="width:35%">Domain</td><td><a href="https://<?= View::e($account['domain']) ?>" target="_blank" class="text-info"><?= View::e($account['domain']) ?> <i class="bi bi-box-arrow-up-right small"></i></a></td></tr>
                    <tr><td class="ps-3 text-muted">System User</td><td><code><?= View::e($account['username']) ?></code> (UID: <?= $account['system_uid'] ?? 'N/A' ?>)</td></tr>
                    <tr><td class="ps-3 text-muted">Status</td><td><span class="badge badge-<?= $account['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $account['status'] ?></span></td></tr>
                    <tr><td class="ps-3 text-muted">Home Directory</td><td><code><?= View::e($account['home_dir']) ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">Document Root</td><td><code><?= View::e($account['document_root']) ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">PHP Version</td><td><?= View::e($account['php_version']) ?></td></tr>
                    <tr><td class="ps-3 text-muted">FPM Socket</td><td><code><?= View::e($account['fpm_socket'] ?? 'N/A') ?></code></td></tr>
                    <tr><td class="ps-3 text-muted">Caddy Route</td><td><code><?= View::e($account['caddy_route_id'] ?? 'N/A') ?></code></td></tr>
                    <tr>
                        <td class="ps-3 text-muted">Disk Usage</td>
                        <td>
                            <?php $diskPercent = $account['disk_quota_mb'] > 0 ? round(($account['disk_used_mb'] / $account['disk_quota_mb']) * 100) : 0; ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width: 120px;">
                                    <div class="progress-bar bg-<?= $diskPercent > 85 ? 'danger' : 'info' ?>" style="width: <?= $diskPercent ?>%"></div>
                                </div>
                                <?= $account['disk_used_mb'] ?> MB / <?= $account['disk_quota_mb'] ?> MB (<?= $diskPercent ?>%)
                            </div>
                        </td>
                    </tr>
                    <?php if ($account['description']): ?>
                    <tr><td class="ps-3 text-muted">Description</td><td><?= View::e($account['description']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="ps-3 text-muted">Created</td><td><?= date('d/m/Y H:i', strtotime($account['created_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Domains & SSL -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-globe me-2"></i>Domains & SSL</span>
                <form id="renewSslForm" method="POST" action="/accounts/<?= $account['id'] ?>/renew-ssl" style="display:inline;">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="confirmAction(document.getElementById('renewSslForm'), {
                        title: 'Renew SSL Certificate?',
                        text: 'This will remove and re-create the Caddy route, triggering a new certificate request. The domain must have DNS pointing to this server.',
                        icon: 'info',
                        confirmText: 'Renew SSL'
                    })" title="Force SSL certificate renewal"><i class="bi bi-arrow-clockwise me-1"></i> Renew SSL</button>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th class="ps-3">Domain</th><th>DNS</th><th>Primary</th><th>SSL</th></tr></thead>
                    <tbody>
                        <?php
                            $serverIp = trim(shell_exec('curl -s -4 ifconfig.me 2>/dev/null') ?: '');
                        ?>
                        <?php foreach ($domains as $d): ?>
                        <?php
                            $dns = CloudflareService::checkDomainDns($d['domain'], $serverIp);
                            $pointsHere = $dns['status'] === 'ok';
                            $isCf = $dns['status'] === 'cloudflare';
                        ?>
                        <tr>
                            <td class="ps-3"><?= View::e($d['domain']) ?></td>
                            <td>
                                <?php if ($pointsHere): ?>
                                    <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle"></i> OK</span>
                                <?php elseif ($isCf): ?>
                                    <span class="badge" style="background:rgba(249,115,22,0.15);color:#f97316;"><i class="bi bi-cloud-fill"></i> Cloudflare</span>
                                <?php elseif (!empty($dns['ips'])): ?>
                                    <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;" title="Points to <?= implode(', ', $dns['ips']) ?>"><i class="bi bi-exclamation-triangle"></i> <?= implode(', ', $dns['ips']) ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle"></i> No DNS</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $d['is_primary'] ? '<span class="badge bg-info">Primary</span>' : '' ?></td>
                            <td>
                                <?php
                                    $hasCertOnDisk = false;
                                    if (!$pointsHere && !$isCf) {
                                        $hasCertOnDisk = \MuseDockPanel\Services\FileSyncService::hasCertForDomain($d['domain']);
                                    }
                                ?>
                                <?php if ($pointsHere): ?>
                                    <i class="bi bi-lock-fill text-success" title="SSL activo"></i>
                                <?php elseif ($isCf): ?>
                                    <i class="bi bi-lock-fill" style="color:#f97316;" title="SSL via Cloudflare"></i>
                                <?php elseif ($hasCertOnDisk): ?>
                                    <i class="bi bi-lock-fill text-info" title="SSL copiado del master (cert en disco)"></i>
                                <?php else: ?>
                                    <i class="bi bi-unlock text-warning" title="SSL pendiente — DNS debe apuntar a <?= $serverIp ?>"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                    // Use last domain's DNS for the info box
                    $lastDns = $dns ?? ['status' => 'none'];
                    $lastPointsHere = ($lastDns['status'] === 'ok');
                    $lastIsCf = ($lastDns['status'] === 'cloudflare');
                    $lastHasCert = $hasCertOnDisk ?? false;
                ?>
                <?php if ($lastIsCf): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);">
                    <small style="color:#f97316;"><i class="bi bi-cloud-fill me-1"></i>Dominio a traves de Cloudflare Proxy. SSL lo proporciona Cloudflare. Si desactivas el proxy (DNS Only), Caddy generara el certificado automaticamente.</small>
                </div>
                <?php elseif (!$lastPointsHere && !$lastHasCert): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">
                    <small style="color:#fbbf24;"><i class="bi bi-info-circle me-1"></i>El certificado SSL se obtendra automaticamente cuando el DNS A apunte a <code><?= $serverIp ?></code>. Actualiza tu proveedor DNS y pulsa "Renew SSL".</small>
                </div>
                <?php elseif (!$lastPointsHere && $lastHasCert): ?>
                <div class="p-2 m-2 rounded" style="background:rgba(13,202,240,0.08);border:1px solid rgba(13,202,240,0.2);">
                    <small style="color:#0dcaf0;"><i class="bi bi-info-circle me-1"></i>El certificado SSL fue copiado del master. HTTPS funciona aunque el DNS no apunte a este servidor. Cuando el DNS cambie, Caddy renovara el certificado automaticamente.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Domain Aliases -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 me-2"></i>Alias de Dominio</span>
                <span class="badge bg-info"><?= count($aliases ?? []) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Dominios que sirven el <strong>mismo contenido</strong> que <?= View::e($account['domain']) ?>. Caddy genera SSL para cada alias.
                </p>
                <?php if (!empty($aliases)): ?>
                <table class="table table-sm mb-3">
                    <thead><tr><th>Dominio</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($aliases as $alias): ?>
                        <tr>
                            <td>
                                <i class="bi bi-circle-fill text-success me-1" style="font-size:0.4rem;vertical-align:middle;"></i>
                                <?= View::e($alias['domain']) ?>
                                <span class="text-muted small ms-1">+ www</span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    onclick="confirmDeleteAlias(<?= (int)$account['id'] ?>, <?= (int)$alias['id'] ?>, '<?= View::e($alias['domain']) ?>', 'aliases')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="post" action="/accounts/<?= (int)$account['id'] ?>/aliases/add" class="d-flex gap-2 align-items-end">
                    <?= View::csrf() ?>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Nuevo alias</label>
                        <input type="text" name="domain" class="form-control form-control-sm" placeholder="ejemplo.net" required pattern="[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Añadir</button>
                </form>
            </div>
        </div>

        <!-- Domain Redirects -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-signpost me-2"></i>Redirecciones de Dominio</span>
                <span class="badge bg-warning text-dark"><?= count($redirects ?? []) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>Dominios que <strong>redirigen (301/302)</strong> a <?= View::e($account['domain']) ?>. Google transfiere el posicionamiento SEO con 301.
                </p>
                <?php if (!empty($redirects)): ?>
                <table class="table table-sm mb-3">
                    <thead><tr><th>Dominio</th><th>Código</th><th>Ruta</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($redirects as $redir): ?>
                        <tr>
                            <td>
                                <i class="bi bi-arrow-right-circle text-warning me-1"></i>
                                <?= View::e($redir['domain']) ?>
                                <span class="text-muted small">→ <?= View::e($account['domain']) ?></span>
                            </td>
                            <td><span class="badge <?= $redir['redirect_code'] == 301 ? 'bg-success' : 'bg-info' ?>"><?= (int)$redir['redirect_code'] ?></span></td>
                            <td><?= $redir['preserve_path'] ? '<i class="bi bi-check-lg text-success"></i> Conserva ruta' : '<i class="bi bi-x-lg text-muted"></i> Solo raíz' ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2"
                                    onclick="confirmDeleteAlias(<?= (int)$account['id'] ?>, <?= (int)$redir['id'] ?>, '<?= View::e($redir['domain']) ?>', 'redirects')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <form method="post" action="/accounts/<?= (int)$account['id'] ?>/redirects/add" class="d-flex gap-2 align-items-end flex-wrap">
                    <?= View::csrf() ?>
                    <div class="flex-grow-1">
                        <label class="form-label small text-muted mb-1">Dominio a redirigir</label>
                        <input type="text" name="domain" class="form-control form-control-sm" placeholder="musedock.net" required pattern="[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">
                    </div>
                    <div>
                        <label class="form-label small text-muted mb-1">Código</label>
                        <select name="redirect_code" class="form-select form-select-sm">
                            <option value="301">301 Permanente (SEO)</option>
                            <option value="302">302 Temporal</option>
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-1 pb-1" title="Si está marcado: .net/blog/articulo → .com/blog/articulo. Si no: .net/blog/articulo → .com/">
                        <input type="checkbox" name="preserve_path" value="1" checked class="form-check-input" id="preserve-path-<?= (int)$account['id'] ?>">
                        <label class="form-check-label small" for="preserve-path-<?= (int)$account['id'] ?>">Conservar ruta <i class="bi bi-question-circle text-muted" style="font-size:0.7rem;"></i></label>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-plus-circle me-1"></i>Añadir</button>
                </form>
            </div>
        </div>

        <!-- Mail -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-envelope me-2"></i>Mail</span>
                <?php if (!empty($mailDomain)): ?>
                    <a href="/mail/domains/<?= $mailDomain['id'] ?>" class="btn btn-outline-light btn-sm py-0 px-2"><i class="bi bi-eye me-1"></i>Gestionar</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($mailDomain)): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>
                            <span class="badge badge-<?= $mailDomain['status'] === 'active' ? 'active' : 'suspended' ?>"><?= $mailDomain['status'] ?></span>
                            <?php if (!empty($mailDomain['node_name'])): ?>
                                <span class="badge bg-secondary ms-1"><i class="bi bi-hdd-network me-1"></i><?= View::e($mailDomain['node_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary ms-1"><i class="bi bi-pc-display me-1"></i>Local</span>
                            <?php endif; ?>
                            <?php if ($mailDomain['dkim_public_key']): ?>
                                <span class="badge bg-success ms-1">DKIM</span>
                            <?php endif; ?>
                        </span>
                        <span class="text-muted small"><?= count($mailAccounts) ?> cuenta(s)</span>
                    </div>
                    <?php if (!empty($mailAccounts)): ?>
                        <div class="list-group list-group-flush" style="background:transparent;">
                            <?php foreach ($mailAccounts as $ma): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-1" style="background:transparent;border-color:#334155;">
                                <span class="small"><i class="bi bi-person me-1 text-muted"></i><?= View::e($ma['email']) ?></span>
                                <span>
                                    <span class="badge <?= $ma['status'] === 'active' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:0.65rem;"><?= $ma['status'] ?></span>
                                    <?php
                                        $usedMb = (int)($ma['used_mb'] ?? 0);
                                        $quotaMb = (int)$ma['quota_mb'];
                                        $usagePct = $quotaMb > 0 ? round(($usedMb / $quotaMb) * 100) : 0;
                                    ?>
                                    <span class="text-muted small ms-1"><?= $usedMb ?>/<?= $quotaMb ?> MB</span>
                                    <?php if ($usagePct > 85): ?>
                                        <i class="bi bi-exclamation-triangle text-warning ms-1" title="<?= $usagePct ?>% usado" style="font-size:0.7rem;"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Dominio de correo activo pero sin cuentas creadas.
                            <a href="/mail/domains/<?= $mailDomain['id'] ?>/accounts/create" class="text-info">Crear cuenta</a>
                        </p>
                    <?php endif; ?>
                <?php elseif ($mailEnabled && $hasMailNodes): ?>
                    <div class="text-center py-2">
                        <p class="text-muted small mb-2">Este dominio no tiene correo configurado.</p>
                        <a href="/mail/domains/create?domain=<?= urlencode($account['domain']) ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-plus-circle me-1"></i>Activar correo para <?= View::e($account['domain']) ?>
                        </a>
                    </div>
                <?php elseif ($mailEnabled && !$hasMailNodes): ?>
                    <div class="text-center py-2">
                        <p class="text-muted small mb-2">No hay servidor de mail configurado.</p>
                        <a href="/mail?setup=1" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-envelope me-1"></i>Configurar servidor de mail
                        </a>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0 text-center py-1">
                        <i class="bi bi-info-circle me-1"></i>Mail no habilitado.
                        <a href="/mail?setup=1" class="text-info">Configurar</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Databases -->
        <div class="card">
            <div class="card-header"><i class="bi bi-database me-2"></i>Databases</div>
            <div class="card-body p-0">
                <?php if (empty($databases)): ?>
                    <div class="p-3 text-center text-muted">No databases created yet.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Name</th><th>User</th><th>Type</th></tr></thead>
                        <tbody>
                            <?php foreach ($databases as $db): ?>
                            <tr>
                                <td class="ps-3"><code><?= View::e($db['db_name']) ?></code></td>
                                <td><code><?= View::e($db['db_user']) ?></code></td>
                                <td><?= View::e($db['db_type']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-md-4">
        <!-- Quick Actions -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>Quick Actions</div>
            <div class="card-body d-grid gap-2">
                <a href="https://<?= View::e($account['domain']) ?>" target="_blank" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-globe me-1"></i> Visit Site
                </a>
                <a href="/accounts/<?= $account['id'] ?>/edit" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-gear me-1"></i> Settings
                </a>
            </div>
        </div>

        <!-- Danger Zone -->
        <?php if ($account['status'] === 'suspended'): ?>
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Danger Zone</div>
            <div class="card-body">
                <p class="small text-muted">Delete this account permanently. This removes the system user, FPM pool, and Caddy route. The home directory is kept for manual backup.</p>
                <?php $hasMailForDelete = !empty($mailDomain); ?>
                <form id="deleteForm" method="POST" action="/accounts/<?= $account['id'] ?>/delete">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <input type="hidden" name="delete_mail" id="delete-mail-input" value="0">
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="confirmAction(document.getElementById('deleteForm'), {
                        title: 'Delete <?= View::e($account['domain']) ?>?',
                        html: '<p style=\'color:#ef4444;font-weight:600;\'>This action is PERMANENT and cannot be undone!</p><p style=\'color:#94a3b8;font-size:0.9rem;\'>This will remove:<br>&bull; System user<br>&bull; PHP-FPM pool<br>&bull; Caddy route<br><br>The home directory is kept for manual backup.</p><?= $hasMailForDelete ? "<div style=\"margin-top:12px;padding:10px;background:rgba(239,68,68,0.1);border-radius:6px;text-align:left;\"><label style=\"color:#ef4444;font-size:0.85rem;cursor:pointer;\"><input type=\"checkbox\" id=\"swal-delete-mail\" style=\"margin-right:6px;\">Eliminar tambien el correo (" . count($mailAccounts) . " cuenta/s, buzones, DKIM)</label></div>" : "" ?>',
                        icon: 'error',
                        confirmText: 'Yes, DELETE permanently'
                    }, function() { <?= $hasMailForDelete ? "document.getElementById('delete-mail-input').value = document.getElementById('swal-delete-mail')?.checked ? '1' : '0';" : "" ?> })"><i class="bi bi-trash me-1"></i> Delete Account</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmDeleteAlias(accountId, aliasId, domain, type) {
    const S = typeof SwalDark !== 'undefined' ? SwalDark : Swal;
    const label = type === 'aliases' ? 'alias' : 'redirección';

    S.fire({
        title: 'Eliminar ' + label,
        html: '<p>Eliminar ' + label + ' <strong>' + domain + '</strong>?</p>' +
              '<p class="small text-muted">Se eliminará la ruta de Caddy y el registro DNS deberá gestionarse manualmente.</p>' +
              '<div class="mb-2"><label class="form-label small">Contraseña de administrador</label>' +
              '<input type="password" id="alias-delete-pw" class="form-control" placeholder="Confirmar con tu contraseña" style="background:#2a2a3e;color:#fff;border-color:#444;"></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancelar',
        preConfirm: function() {
            var pw = document.getElementById('alias-delete-pw').value;
            if (!pw) { Swal.showValidationMessage('La contraseña es obligatoria'); return false; }
            return pw;
        }
    }).then(function(result) {
        if (!result.isConfirmed) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/accounts/' + accountId + '/' + type + '/' + aliasId + '/delete';

        var csrf = document.querySelector('input[name=_csrf_token]').value;
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden'; csrfInput.name = '_csrf_token'; csrfInput.value = csrf;
        form.appendChild(csrfInput);

        var pwInput = document.createElement('input');
        pwInput.type = 'hidden'; pwInput.name = 'admin_password'; pwInput.value = result.value;
        form.appendChild(pwInput);

        document.body.appendChild(form);
        form.submit();
    });
}
</script>
