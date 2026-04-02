<?php
use MuseDockPanel\View;
$clusterRole = \MuseDockPanel\Settings::get('cluster_role', 'standalone');
$totalAccounts = count($accounts);
$totalActive = count(array_filter($accounts, fn($a) => $a['status'] === 'active'));
$totalSuspended = $totalAccounts - $totalActive;
$totalDiskMb = array_sum(array_column($accounts, 'disk_used_mb'));
$totalBwBytes = array_sum(array_column($accounts, 'bw_bytes'));
$totalDiskStr = $totalDiskMb >= 1024 ? round($totalDiskMb / 1024, 1) . ' GB' : $totalDiskMb . ' MB';
if ($totalBwBytes >= 1073741824) $totalBwStr = round($totalBwBytes / 1073741824, 1) . ' GB';
elseif ($totalBwBytes >= 1048576) $totalBwStr = round($totalBwBytes / 1048576, 1) . ' MB';
elseif ($totalBwBytes > 0) $totalBwStr = round($totalBwBytes / 1024, 1) . ' KB';
else $totalBwStr = '0';
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
            <span class="ms-3"><i class="bi bi-hdd me-1"></i><?= $totalDiskStr ?></span>
            <span class="ms-2"><i class="bi bi-speedometer2 me-1"></i><?= $totalBwStr ?>/mes</span>
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
        <form method="POST" action="/accounts/bulk-disable-wp-cron" class="d-inline" id="bulkWpCronForm">
            <?= View::csrf() ?>
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmBulkWpCron()" title="Desactivar WP-Cron en todos los WordPress"><i class="bi bi-wordpress me-1"></i>Desactivar WP-Cron</button>
        </form>
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
                        <th class="ps-3 sortable-th" data-col="0" data-type="text" style="cursor:pointer;user-select:none;">Domain <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="1" data-type="text" style="cursor:pointer;user-select:none;">Customer <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="2" data-type="text" style="cursor:pointer;user-select:none;">User <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="3" data-type="text" style="cursor:pointer;user-select:none;">PHP <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th>Alias / Redir</th>
                        <th class="sortable-th" data-col="5" data-type="num" style="cursor:pointer;user-select:none;">Disk <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="6" data-type="num" style="cursor:pointer;user-select:none;">BW <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="7" data-type="text" style="cursor:pointer;user-select:none;">Status <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="sortable-th" data-col="8" data-type="date" style="cursor:pointer;user-select:none;">Created <i class="bi bi-chevron-expand text-muted" style="font-size:0.6rem;"></i></th>
                        <th class="text-end pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr class="account-row" data-sort-disk="<?= (int)$acc['disk_used_mb'] ?>" data-sort-bw="<?= (int)($acc['bw_bytes'] ?? 0) ?>" data-sort-date="<?= $acc['created_at'] ?>" data-account-id="<?= (int)$acc['id'] ?>">
                        <td class="ps-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="text-info text-decoration-none fw-semibold"><?= View::e($acc['domain']) ?></a>
                            <a href="https://<?= View::e($acc['domain']) ?>" target="_blank" rel="noopener" class="text-muted ms-1" style="font-size:0.7rem;" title="Abrir sitio"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php if ($acc['subdomain_count'] > 0): ?>
                                <button type="button" class="badge bg-dark ms-1 border-0 btn-toggle-subs" style="font-size:0.6rem;cursor:pointer;"
                                        data-account-id="<?= (int)$acc['id'] ?>"
                                        title="<?= $acc['subdomain_count'] ?> subdominio<?= $acc['subdomain_count'] > 1 ? 's' : '' ?> — click para expandir">
                                    <i class="bi bi-diagram-3"></i> <?= $acc['subdomain_count'] ?>
                                </button>
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
                            <?php
                            $bwB = $acc['bw_bytes'] ?? 0;
                            if ($bwB >= 1073741824) $bwStr = round($bwB/1073741824, 1) . ' GB';
                            elseif ($bwB >= 1048576) $bwStr = round($bwB/1048576, 1) . ' MB';
                            elseif ($bwB > 0) $bwStr = round($bwB/1024, 1) . ' KB';
                            else $bwStr = '-';
                            ?>
                            <small class="text-muted" title="Ancho de banda este mes"><?= $bwStr ?></small>
                        </td>
                        <td>
                            <span class="badge badge-<?= $acc['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $acc['status'] ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/Y', strtotime($acc['created_at'])) ?></small></td>
                        <td class="text-end pe-3">
                            <a href="/accounts/<?= $acc['id'] ?>" class="btn btn-outline-light btn-sm" title="Configuracion"><i class="bi bi-gear"></i></a>
                        </td>
                    </tr>
                    <?php if (!empty($acc['subdomain_details'])): ?>
                    <?php foreach ($acc['subdomain_details'] as $sub): ?>
                    <tr class="sub-row sub-of-<?= (int)$acc['id'] ?>" style="display:none;">
                        <td class="ps-3" style="padding-left:2.2rem!important;">
                            <i class="bi bi-corner-down-right text-muted me-1" style="font-size:0.75rem;"></i>
                            <a href="/accounts/<?= (int)$acc['id'] ?>/subdomains/<?= (int)$sub['id'] ?>/edit" class="text-decoration-none" style="color:#a5b4fc;"><?= View::e($sub['subdomain']) ?></a>
                            <a href="https://<?= View::e($sub['subdomain']) ?>" target="_blank" class="text-muted ms-1" style="font-size:0.7rem;"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                        <!-- Customer + User + PHP + Alias/Redir + Disk = 5 cols for doc root -->
                        <td colspan="5" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= View::e($sub['document_root']) ?>"><code class="small text-muted"><?= View::e($sub['document_root']) ?></code></td>
                        <!-- BW -->
                        <td>
                            <?php
                            $sBw = $sub['bw_bytes'] ?? 0;
                            if ($sBw >= 1073741824) $sBwStr = round($sBw/1073741824, 1) . ' GB';
                            elseif ($sBw >= 1048576) $sBwStr = round($sBw/1048576, 1) . ' MB';
                            elseif ($sBw > 0) $sBwStr = round($sBw/1024, 1) . ' KB';
                            else $sBwStr = '-';
                            ?>
                            <small class="text-muted"><?= $sBwStr ?></small>
                        </td>
                        <!-- Status -->
                        <td>
                            <span class="badge badge-<?= $sub['status'] === 'active' ? 'active' : 'suspended' ?>" style="font-size:0.6rem;"><?= View::e($sub['status']) ?></span>
                        </td>
                        <!-- Created (empty) -->
                        <td></td>
                        <!-- Actions -->
                        <td class="text-end pe-3">
                            <a href="/accounts/<?= (int)$acc['id'] ?>/subdomains/<?= (int)$sub['id'] ?>/edit" class="btn btn-outline-light btn-sm py-0 px-1" title="Editar subdominio"><i class="bi bi-pencil" style="font-size:0.7rem;"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
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
                // Also search in subdomain rows for this account
                var accountId = row.querySelector('.btn-toggle-subs')?.dataset?.accountId;
                var subText = '';
                if (accountId) {
                    document.querySelectorAll('.sub-of-' + accountId).forEach(function(sr) {
                        subText += ' ' + sr.textContent.toLowerCase();
                    });
                }
                var match = !query || text.indexOf(query) !== -1 || subText.indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                // Hide sub-rows when filtering (user can re-expand)
                if (accountId) {
                    document.querySelectorAll('.sub-of-' + accountId).forEach(function(sr) {
                        sr.style.display = 'none';
                    });
                }
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

    // ─── Subdomain accordion ────────────────────────────────
    document.querySelectorAll('.btn-toggle-subs').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var accountId = btn.dataset.accountId;
            var subRows = document.querySelectorAll('.sub-of-' + accountId);
            var icon = btn.querySelector('i');
            var visible = subRows[0] && subRows[0].style.display !== 'none';

            subRows.forEach(function(row) {
                row.style.display = visible ? 'none' : '';
            });

            // Toggle icon
            if (visible) {
                icon.className = 'bi bi-diagram-3';
            } else {
                icon.className = 'bi bi-diagram-3-fill';
            }
        });
    });

    // ─── Column sorting ─────────────────────────────────────
    var currentSortCol = -1;
    var currentSortAsc = true;

    document.querySelectorAll('.sortable-th').forEach(function(th) {
        th.addEventListener('click', function() {
            var col = parseInt(th.dataset.col);
            var type = th.dataset.type; // text, num, date

            // Toggle direction
            if (currentSortCol === col) {
                currentSortAsc = !currentSortAsc;
            } else {
                currentSortCol = col;
                currentSortAsc = true;
            }

            // Update header icons
            document.querySelectorAll('.sortable-th i').forEach(function(icon) {
                icon.className = 'bi bi-chevron-expand text-muted';
                icon.style.fontSize = '0.6rem';
            });
            var icon = th.querySelector('i');
            icon.className = 'bi ' + (currentSortAsc ? 'bi-chevron-up' : 'bi-chevron-down') + ' text-info';
            icon.style.fontSize = '0.6rem';

            // Gather account rows with their subdomain rows
            var tbody = document.querySelector('#accountsTable tbody');
            var accountRows = Array.from(tbody.querySelectorAll('.account-row'));

            var groups = accountRows.map(function(row) {
                var accId = row.dataset.accountId;
                var subRows = Array.from(tbody.querySelectorAll('.sub-of-' + accId));
                return { main: row, subs: subRows };
            });

            // Sort groups
            groups.sort(function(a, b) {
                var valA, valB;

                if (type === 'num') {
                    if (col === 5) { // Disk
                        valA = parseInt(a.main.dataset.sortDisk) || 0;
                        valB = parseInt(b.main.dataset.sortDisk) || 0;
                    } else if (col === 6) { // BW
                        valA = parseInt(a.main.dataset.sortBw) || 0;
                        valB = parseInt(b.main.dataset.sortBw) || 0;
                    }
                    return currentSortAsc ? valA - valB : valB - valA;
                }

                if (type === 'date') {
                    valA = a.main.dataset.sortDate || '';
                    valB = b.main.dataset.sortDate || '';
                    return currentSortAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                }

                // Text: read from cell
                var cellA = a.main.children[col]?.textContent?.trim().toLowerCase() || '';
                var cellB = b.main.children[col]?.textContent?.trim().toLowerCase() || '';
                return currentSortAsc ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
            });

            // Re-append in sorted order
            groups.forEach(function(g) {
                tbody.appendChild(g.main);
                g.subs.forEach(function(s) { tbody.appendChild(s); });
            });
        });
    });

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

function confirmBulkWpCron() {
    if (typeof SwalDark === 'undefined') {
        if (confirm('Desactivar WP-Cron en todos los WordPress?')) {
            document.getElementById('bulkWpCronForm').submit();
        }
        return;
    }
    SwalDark.fire({
        title: 'Desactivar WP-Cron',
        html: '<p>Se anadira <code>DISABLE_WP_CRON</code> en el <code>wp-config.php</code> de <strong>todos los WordPress</strong> activos.</p>' +
              '<p style="color:#94a3b8;">WordPress dejara de ejecutar tareas programadas en cada visita, reduciendo el consumo de CPU.</p>' +
              '<p style="color:#fbbf24;font-size:0.85rem;"><i class="bi bi-info-circle me-1"></i>Las cuentas que ya lo tengan desactivado no se modificaran.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Desactivar todos',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f59e0b'
    }).then(function(result) {
        if (result.isConfirmed) {
            document.getElementById('bulkWpCronForm').submit();
        }
    });
}
</script>
