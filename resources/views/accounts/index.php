<?php
use MuseDockPanel\View;
$clusterRole = \MuseDockPanel\Settings::get('cluster_role', 'standalone');
$totalAccounts = count($accounts);
$totalActive = count(array_filter($accounts, fn($a) => $a['status'] === 'active'));
$totalSuspended = $totalAccounts - $totalActive;
?>

<?php if ($clusterRole === 'slave'): ?>
<div class="alert d-flex align-items-center mb-3" style="background:rgba(13,202,240,0.1);border:1px solid rgba(13,202,240,0.3);color:#0dcaf0;">
    <i class="bi bi-lock me-2"></i>
    Este servidor es <strong class="mx-1">Slave</strong>. La creacion de hostings esta deshabilitada. Los hostings se reciben del servidor Master.
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <span class="text-muted">
            <?= $totalAccounts ?> hosting<?= $totalAccounts !== 1 ? 's' : '' ?>
            <span class="ms-2 badge bg-success" style="font-size:0.7rem;"><?= $totalActive ?> activo<?= $totalActive !== 1 ? 's' : '' ?></span>
            <?php if ($totalSuspended > 0): ?>
                <span class="badge bg-danger ms-1" style="font-size:0.7rem;"><?= $totalSuspended ?> suspendido<?= $totalSuspended !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($totalAccounts > 5): ?>
        <div class="input-group input-group-sm" style="width:250px;">
            <span class="input-group-text" style="background:#1e293b;border-color:#334155;color:#94a3b8;"><i class="bi bi-search"></i></span>
            <input type="text" id="accountSearch" class="form-control form-control-sm" style="background:#0f172a;border-color:#334155;color:#e2e8f0;" placeholder="Buscar dominio, usuario, cliente...">
        </div>
        <?php endif; ?>
        <?php if ($clusterRole !== 'slave'): ?>
        <a href="/accounts/import" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>Importar Existente</a>
        <a href="/accounts/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Account</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($accounts)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-server" style="font-size: 2rem;"></i>
                <p class="mt-2">No hosting accounts yet.</p>
                <?php if ($clusterRole !== 'slave'): ?>
                <a href="/accounts/create" class="btn btn-primary btn-sm">Create first account</a>
                <?php else: ?>
                <p class="small text-warning mb-0"><i class="bi bi-lock me-1"></i>Servidor Slave: la creacion de hostings esta bloqueada.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0" id="accountsTable">
                <thead>
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>Customer</th>
                        <th>User</th>
                        <th>PHP</th>
                        <th>Alias / Redir</th>
                        <th>Disk</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr class="account-row">
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                            <?php if ($acc['subdomain_count'] > 0): ?>
                                <span class="badge bg-dark ms-1" style="font-size:0.6rem;" title="<?= $acc['subdomain_count'] ?> subdominio<?= $acc['subdomain_count'] > 1 ? 's' : '' ?>">
                                    <i class="bi bi-diagram-3"></i> <?= $acc['subdomain_count'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($acc['customer_name']): ?>
                                <a href="/customers/<?= $acc['customer_id'] ?>" class="text-decoration-none text-light"><?= View::e($acc['customer_name']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= View::e($acc['username']) ?></code></td>
                        <td><?= View::e($acc['php_version']) ?></td>
                        <td>
                            <?php if ($acc['alias_count'] > 0 || $acc['redirect_count'] > 0): ?>
                                <button type="button" class="btn btn-sm p-0 border-0 btn-show-aliases"
                                        data-domain="<?= View::e($acc['domain']) ?>"
                                        data-account-id="<?= $acc['id'] ?>"
                                        data-aliases="<?= View::e(json_encode($acc['alias_details'])) ?>"
                                        title="Ver alias y redirecciones">
                                    <?php if ($acc['alias_count'] > 0): ?>
                                        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.7rem;cursor:pointer;">
                                            <i class="bi bi-link-45deg"></i> <?= $acc['alias_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($acc['redirect_count'] > 0): ?>
                                        <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:0.7rem;cursor:pointer;">
                                            <i class="bi bi-arrow-right-short"></i> <?= $acc['redirect_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($acc['disk_quota_mb'] > 0): ?>
                                    <?php $diskPercent = round(($acc['disk_used_mb'] / $acc['disk_quota_mb']) * 100); ?>
                                    <div class="progress" style="width: 60px;">
                                        <div class="progress-bar bg-<?= $diskPercent > 85 ? 'danger' : 'info' ?>" style="width: <?= min($diskPercent, 100) ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?= $acc['disk_used_mb'] ?>MB / <?= $acc['disk_quota_mb'] ?>MB</small>
                                <?php else: ?>
                                    <small class="text-muted"><?= number_format($acc['disk_used_mb']) ?>MB</small>
                                    <span class="badge bg-dark ms-1" style="font-size:0.65rem;">&#8734;</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $acc['status'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/Y', strtotime($acc['created_at'])) ?></small></td>
                        <td class="text-end pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm" title="Configuracion"><i class="bi bi-gear"></i></a>
                                <a href="https://<?= View::e($acc['domain']) ?>" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm" title="Visitar sitio"><i class="bi bi-box-arrow-up-right"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="noResults" class="p-3 text-center text-muted" style="display:none;">
                <i class="bi bi-search me-1"></i> No se encontraron resultados.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    // ─── Search ──────────────────────────────────────────────
    var searchInput = document.getElementById('accountSearch');
    if (searchInput) {
        var rows = document.querySelectorAll('.account-row');
        var noResults = document.getElementById('noResults');

        searchInput.addEventListener('input', function() {
            var query = this.value.toLowerCase().trim();
            var visible = 0;

            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                var match = !query || text.indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            noResults.style.display = visible === 0 ? '' : 'none';
        });

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }

    // ─── Alias/Redirect modal ────────────────────────────────
    document.querySelectorAll('.btn-show-aliases').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (typeof SwalDark === 'undefined') return;

            var domain = btn.dataset.domain;
            var accountId = btn.dataset.accountId;
            var aliases = JSON.parse(btn.dataset.aliases || '[]');

            var aliasItems = aliases.filter(function(a) { return a.type === 'alias'; });
            var redirectItems = aliases.filter(function(a) { return a.type === 'redirect'; });

            var html = '';

            if (aliasItems.length > 0) {
                html += '<div class="text-start mb-3">';
                html += '<h6 style="color:#38bdf8;"><i class="bi bi-link-45deg me-1"></i>Alias (' + aliasItems.length + ')</h6>';
                html += '<table class="table table-sm mb-0" style="color:#e2e8f0;">';
                aliasItems.forEach(function(a) {
                    html += '<tr><td><a href="https://' + a.domain + '" target="_blank" rel="noopener" class="text-info text-decoration-none">' + a.domain + ' <i class="bi bi-box-arrow-up-right" style="font-size:0.7rem;"></i></a></td>';
                    html += '<td class="text-end"><span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;">Alias</span></td></tr>';
                });
                html += '</table></div>';
            }

            if (redirectItems.length > 0) {
                html += '<div class="text-start">';
                html += '<h6 style="color:#fbbf24;"><i class="bi bi-arrow-right-short me-1"></i>Redirecciones (' + redirectItems.length + ')</h6>';
                html += '<table class="table table-sm mb-0" style="color:#e2e8f0;">';
                redirectItems.forEach(function(a) {
                    var code = a.redirect_code || '301';
                    var path = a.preserve_path == '1' || a.preserve_path === true ? 'Si' : 'No';
                    html += '<tr><td><a href="https://' + a.domain + '" target="_blank" rel="noopener" class="text-warning text-decoration-none">' + a.domain + ' <i class="bi bi-box-arrow-up-right" style="font-size:0.7rem;"></i></a></td>';
                    html += '<td class="text-center"><span class="badge bg-dark">' + code + '</span></td>';
                    html += '<td class="text-end" title="Preservar ruta"><small class="text-muted">Path: ' + path + '</small></td></tr>';
                });
                html += '</table></div>';
            }

            if (!html) {
                html = '<p class="text-muted">Sin alias ni redirecciones.</p>';
            }

            html += '<div class="mt-3"><a href="/accounts/' + accountId + '" class="btn btn-sm btn-outline-light"><i class="bi bi-gear me-1"></i>Gestionar</a></div>';

            SwalDark.fire({
                title: domain,
                html: html,
                showConfirmButton: false,
                showCloseButton: true,
                width: 520
            });
        });
    });
})();
</script>
