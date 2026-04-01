<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$hasAccounts): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-cloud" style="font-size: 3rem; color: #f97316;"></i>
        <h5 class="mt-3">No hay cuentas de Cloudflare configuradas</h5>
        <p class="text-muted">Para gestionar DNS desde aqui, primero configura tus cuentas de Cloudflare con API Token en:</p>
        <a href="/settings/cluster#failover" class="btn btn-outline-light btn-sm">
            <i class="bi bi-gear me-1"></i>Settings &rarr; Cluster &rarr; Failover &rarr; Cloudflare Accounts
        </a>
    </div>
</div>
<?php else: ?>

<?= View::csrf() ?>

<div class="row g-3">
    <!-- Left: Zone selector -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-cloud-fill me-2" style="color:#f97316;"></i>Cloudflare Zones
            </div>
            <div class="card-body">
                <!-- Account selector -->
                <label class="form-label text-muted small">Cuenta Cloudflare</label>
                <select id="cfAccount" class="form-select form-select-sm mb-3" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                    <?php foreach ($accounts as $i => $acc): ?>
                    <option value="<?= $i ?>"><?= View::e($acc['name'] ?? "Account #{$i}") ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Zones list -->
                <div id="zonesList" class="list-group list-group-flush">
                    <div class="text-center text-muted py-3">
                        <div class="spinner-border spinner-border-sm me-1"></div> Loading zones...
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick domains -->
        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-globe2 me-2"></i>Dominios del Sistema
            </div>
            <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                <div class="list-group list-group-flush">
                    <?php foreach ($hostingDomains as $hd): ?>
                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-1 px-3" style="border-color:#334155;">
                        <small><?= View::e($hd['domain']) ?></small>
                        <span class="badge bg-info" style="font-size:0.65rem;">hosting</span>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($aliasDomains as $ad): ?>
                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-1 px-3" style="border-color:#334155;">
                        <small class="text-muted"><?= View::e($ad['domain']) ?></small>
                        <span class="badge bg-dark" style="font-size:0.65rem;">alias/redirect</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: DNS Records -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-2"></i>DNS Records <span id="zoneName" class="text-muted"></span></span>
                <div class="d-flex gap-2">
                    <select id="filterType" class="form-select form-select-sm" style="width:auto;background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="">All Types</option>
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                        <option value="MX">MX</option>
                        <option value="TXT">TXT</option>
                        <option value="NS">NS</option>
                        <option value="SRV">SRV</option>
                    </select>
                    <button class="btn btn-sm btn-info" onclick="showAddRecord()" id="btnAddRecord" style="display:none;">
                        <i class="bi bi-plus-circle me-1"></i>Add
                    </button>
                </div>
            </div>

            <!-- Bulk actions bar -->
            <div id="bulkBar" class="px-3 py-2 align-items-center gap-2" style="display:none; background:rgba(56,189,248,0.08); border-bottom:1px solid #334155;">
                <span class="small text-muted"><strong id="bulkCount">0</strong> seleccionados</span>
                <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="bulkDelete()" title="Eliminar seleccionados">
                    <i class="bi bi-trash me-1"></i>Eliminar
                </button>
                <button class="btn btn-sm py-0 px-2" style="border-color:#f97316;color:#f97316;" onclick="bulkProxy(true)" title="Activar proxy en seleccionados">
                    <i class="bi bi-cloud-fill me-1"></i>Proxy On
                </button>
                <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="bulkProxy(false)" title="Desactivar proxy en seleccionados">
                    <i class="bi bi-cloud me-1"></i>DNS Only
                </button>
                <button class="btn btn-sm btn-outline-info py-0 px-2" onclick="bulkEdit()" title="Editar seleccionados (cambiar tipo, contenido, TTL)">
                    <i class="bi bi-pencil-square me-1"></i>Editar
                </button>
                <button class="btn btn-sm btn-outline-light py-0 px-2 ms-auto" onclick="clearSelection()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="card-body p-0">
                <div id="noZoneSelected" class="text-center text-muted py-5">
                    <i class="bi bi-arrow-left" style="font-size: 2rem;"></i>
                    <p class="mt-2">Selecciona una zona a la izquierda para ver sus registros DNS</p>
                </div>
                <div id="recordsLoading" class="text-center py-4" style="display:none;">
                    <div class="spinner-border spinner-border-sm me-1"></div> Loading records...
                </div>
                <div id="recordsTable" style="display:none;">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width:35px;"><input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)" title="Seleccionar todos"></th>
                                <th style="width:60px;">Type</th>
                                <th>Name</th>
                                <th>Content</th>
                                <th style="width:60px;">TTL</th>
                                <th style="width:70px;">Proxy</th>
                                <th style="width:90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="recordsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="mt-3 p-3 rounded" style="background: rgba(249,115,22,0.05); border: 1px solid #334155;">
    <small class="text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Proxy <i class="bi bi-cloud-fill" style="color:#f97316;"></i></strong> = Trafico pasa por Cloudflare (DDoS protection, CDN, SSL edge). La IP real del servidor queda oculta.
        <strong>DNS Only <i class="bi bi-cloud" style="color:#94a3b8;"></i></strong> = Trafico directo al servidor. Caddy genera su propio certificado SSL via Let's Encrypt.
        <br>
        <i class="bi bi-info-circle me-1 mt-1"></i>
        Los cambios se aplican en tiempo real en Cloudflare. TTL Auto = Cloudflare elige el optimo (normalmente 300s).
    </small>
</div>

<script>
let currentZoneId = '';
let currentZoneName = '';
let currentAccountIdx = 0;
let currentRecords = []; // Store loaded records for reference
const autoSelectDomain = new URLSearchParams(window.location.search).get('domain') || '';

document.addEventListener('DOMContentLoaded', () => {
    if (autoSelectDomain) {
        autoSelectAccount();
    } else {
        loadZones();
    }
    document.getElementById('cfAccount').addEventListener('change', () => { loadZones(); });
    document.getElementById('filterType').addEventListener('change', () => {
        if (currentZoneId) loadRecords(currentZoneId, currentZoneName);
    });
});

function autoSelectAccount() {
    const select = document.getElementById('cfAccount');
    const totalAccounts = select.options.length;
    let tried = 0;

    function tryAccount(idx) {
        select.value = idx;
        currentAccountIdx = idx;
        fetch(`/settings/cloudflare-dns/zones?account=${idx}`)
            .then(r => r.json())
            .then(data => {
                tried++;
                if (data.ok && data.zones.find(z => z.name === autoSelectDomain)) {
                    loadZones();
                } else if (tried < totalAccounts) {
                    tryAccount(tried);
                } else {
                    select.value = 0;
                    currentAccountIdx = 0;
                    loadZones();
                }
            })
            .catch(() => { loadZones(); });
    }
    tryAccount(0);
}

function loadZones() {
    currentAccountIdx = document.getElementById('cfAccount').value;
    const list = document.getElementById('zonesList');
    list.innerHTML = '<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm me-1"></div> Loading...</div>';

    fetch(`/settings/cloudflare-dns/zones?account=${currentAccountIdx}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                list.innerHTML = `<div class="text-danger p-3"><i class="bi bi-exclamation-triangle me-1"></i>${data.error}</div>`;
                return;
            }
            if (!data.zones.length) {
                list.innerHTML = '<div class="text-muted p-3">No zones found for this token</div>';
                return;
            }
            list.innerHTML = data.zones.map(z => `
                <a href="#" class="list-group-item list-group-item-action bg-transparent d-flex justify-content-between align-items-center py-2"
                   style="border-color:#334155;color:#e2e8f0;"
                   data-zone-id="${z.id}" data-zone-name="${z.name}"
                   onclick="event.preventDefault(); selectZone('${z.id}', '${z.name}', this)">
                    <div>
                        <i class="bi bi-globe me-1 text-info"></i>${z.name}
                    </div>
                    <div>
                        <span class="badge bg-dark" style="font-size:0.65rem;">${z.plan}</span>
                        <span class="badge ${z.status === 'active' ? 'bg-success' : 'bg-secondary'}" style="font-size:0.65rem;">${z.status}</span>
                    </div>
                </a>
            `).join('');

            // Auto-select domain if passed via ?domain= parameter
            if (autoSelectDomain) {
                const match = data.zones.find(z => z.name === autoSelectDomain);
                if (match) {
                    const el = list.querySelector(`[data-zone-name="${autoSelectDomain}"]`);
                    selectZone(match.id, match.name, el);
                }
            }
        })
        .catch(err => {
            list.innerHTML = `<div class="text-danger p-3">Error: ${err.message}</div>`;
        });
}

function selectZone(zoneId, zoneName, el) {
    document.querySelectorAll('#zonesList .list-group-item').forEach(e => e.classList.remove('active'));
    if (el) el.classList.add('active');

    currentZoneId = zoneId;
    currentZoneName = zoneName;
    document.getElementById('zoneName').textContent = '— ' + zoneName;
    document.getElementById('btnAddRecord').style.display = '';
    loadRecords(zoneId, zoneName);
}

function loadRecords(zoneId, zoneName) {
    const typeFilter = document.getElementById('filterType').value;
    document.getElementById('noZoneSelected').style.display = 'none';
    document.getElementById('recordsTable').style.display = 'none';
    document.getElementById('recordsLoading').style.display = '';
    clearSelection();

    let url = `/settings/cloudflare-dns/records?account=${currentAccountIdx}&zone_id=${zoneId}`;
    if (typeFilter) url += `&type=${typeFilter}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            document.getElementById('recordsLoading').style.display = 'none';
            if (!data.ok) {
                document.getElementById('noZoneSelected').innerHTML = `<div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${data.error}</div>`;
                document.getElementById('noZoneSelected').style.display = '';
                return;
            }

            currentRecords = data.records;
            const tbody = document.getElementById('recordsBody');
            if (!data.records.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No records found</td></tr>';
            } else {
                tbody.innerHTML = data.records.map(r => {
                    const isProxyable = ['A', 'AAAA', 'CNAME'].includes(r.type);
                    const shortName = r.name.replace('.' + zoneName, '').replace(zoneName, '@');
                    const escapedName = shortName.replace(/'/g, "\\'");
                    const proxyIcon = !isProxyable ? '<span class="text-muted">—</span>' :
                        (r.proxied
                            ? `<i class="bi bi-cloud-fill" style="color:#f97316;cursor:pointer;" title="Proxied — click to disable" onclick="confirmToggleProxy('${r.id}', false, '${escapedName}', '${r.type}')"></i>`
                            : `<i class="bi bi-cloud" style="color:#94a3b8;cursor:pointer;" title="DNS Only — click to enable" onclick="confirmToggleProxy('${r.id}', true, '${escapedName}', '${r.type}')"></i>`);

                    const ttlText = r.ttl === 1 ? 'Auto' : (r.ttl >= 3600 ? (r.ttl/3600)+'h' : (r.ttl >= 60 ? (r.ttl/60)+'m' : r.ttl+'s'));
                    const shortContent = r.content.length > 50 ? r.content.substring(0, 47) + '...' : r.content;

                    const typeBadge = {
                        'A': 'bg-info', 'AAAA': 'bg-info', 'CNAME': 'bg-warning text-dark',
                        'MX': 'bg-success', 'TXT': 'bg-secondary', 'NS': 'bg-dark', 'SRV': 'bg-primary'
                    }[r.type] || 'bg-secondary';

                    return `<tr>
                        <td class="ps-3"><input type="checkbox" class="form-check-input row-check" value="${r.id}" data-name="${r.name}" data-type="${r.type}" data-proxyable="${isProxyable}" onchange="updateBulkBar()"></td>
                        <td><span class="badge ${typeBadge}" style="font-size:0.7rem;">${r.type}</span></td>
                        <td><code style="font-size:0.8rem;">${shortName}</code></td>
                        <td><small class="text-muted" title="${r.content}">${shortContent}</small></td>
                        <td><small class="text-muted">${ttlText}</small></td>
                        <td class="text-center">${proxyIcon}</td>
                        <td>
                            <button class="btn btn-outline-light btn-sm py-0 px-1" onclick="editRecordById('${r.id}')" title="Edit"><i class="bi bi-pencil" style="font-size:0.7rem;"></i></button>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="deleteRecord('${r.id}', '${escapedName}')" title="Delete"><i class="bi bi-trash" style="font-size:0.7rem;"></i></button>
                        </td>
                    </tr>`;
                }).join('');
            }
            document.getElementById('recordsTable').style.display = '';
        })
        .catch(err => {
            document.getElementById('recordsLoading').style.display = 'none';
            document.getElementById('noZoneSelected').innerHTML = `<div class="text-danger">Error: ${err.message}</div>`;
            document.getElementById('noZoneSelected').style.display = '';
        });
}

// --- Selection & Bulk Actions ---

function toggleSelectAll(el) {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = el.checked; });
    updateBulkBar();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}

function getSelectedInfo() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => ({
        id: cb.value,
        name: cb.dataset.name,
        type: cb.dataset.type,
        proxyable: cb.dataset.proxyable === 'true',
    }));
}

function updateBulkBar() {
    const selected = getSelectedIds();
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = selected.length;
    bar.style.display = selected.length > 0 ? 'flex' : 'none';

    // Update select-all checkbox state
    const allChecks = document.querySelectorAll('.row-check');
    const selectAll = document.getElementById('selectAll');
    if (allChecks.length > 0 && selected.length === allChecks.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
    } else if (selected.length > 0) {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    } else {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = false; });
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = false;
    selectAll.indeterminate = false;
    document.getElementById('bulkBar').style.display = 'none';
}

function bulkDelete() {
    const selected = getSelectedInfo();
    if (!selected.length) return;

    const list = selected.map(s => `<code>${s.type} ${s.name}</code>`).join('<br>');

    SwalDark.fire({
        title: `Eliminar ${selected.length} registro${selected.length > 1 ? 's' : ''}?`,
        html: `<div class="text-start" style="max-height:250px;overflow-y:auto;font-size:0.85rem;">${list}</div>
               <p class="mt-2 small text-muted">Esta accion no se puede deshacer.</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: `<i class="bi bi-trash me-1"></i>Eliminar ${selected.length}`,
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancelar',
    }).then(result => {
        if (!result.isConfirmed) return;
        executeBulkAction('delete', selected.map(s => s.id));
    });
}

function bulkProxy(enable) {
    const selected = getSelectedInfo().filter(s => s.proxyable);
    if (!selected.length) {
        SwalDark.fire({icon: 'info', title: 'Sin registros compatibles', text: 'Solo los registros A, AAAA y CNAME soportan proxy.', timer: 2000, showConfirmButton: false});
        return;
    }

    const action = enable ? 'proxy_on' : 'proxy_off';
    const list = selected.map(s => `<code>${s.type} ${s.name}</code>`).join('<br>');
    const desc = enable
        ? 'El trafico pasara por Cloudflare (proxy activo, IP oculta).'
        : 'El trafico ira directo al servidor (DNS Only, Caddy generara SSL).';

    SwalDark.fire({
        title: enable ? 'Activar proxy?' : 'Desactivar proxy?',
        html: `<div class="text-start" style="max-height:200px;overflow-y:auto;font-size:0.85rem;">${list}</div>
               <p class="mt-2 small text-muted">${desc}</p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: enable
            ? `<i class="bi bi-cloud-fill me-1"></i>Proxy On (${selected.length})`
            : `<i class="bi bi-cloud me-1"></i>DNS Only (${selected.length})`,
        confirmButtonColor: enable ? '#f97316' : '#6c757d',
        cancelButtonText: 'Cancelar',
    }).then(result => {
        if (!result.isConfirmed) return;
        executeBulkAction(action, selected.map(s => s.id));
    });
}

function executeBulkAction(action, recordIds) {
    const csrf = document.querySelector('input[name="_csrf_token"]').value;

    SwalDark.fire({
        title: 'Procesando...',
        html: `<div class="spinner-border spinner-border-sm me-2"></div>${recordIds.length} registros`,
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => { SwalDark.showLoading(); }
    });

    fetch('/settings/cloudflare-dns/bulk-action', {
        method: 'POST',
        body: new URLSearchParams({
            _csrf_token: csrf,
            account: currentAccountIdx,
            zone_id: currentZoneId,
            action: action,
            record_ids: JSON.stringify(recordIds),
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const label = {delete: 'eliminados', proxy_on: 'proxy activado', proxy_off: 'proxy desactivado'}[action] || action;
            SwalDark.fire({
                icon: 'success',
                title: `${data.success}/${data.total} ${label}`,
                text: data.errors.length ? `Errores: ${data.errors.join(', ')}` : '',
                timer: 2000,
                showConfirmButton: false,
            });
            loadRecords(currentZoneId, currentZoneName);
        } else {
            SwalDark.fire({icon: 'error', title: 'Error', text: data.error || 'Unknown error'});
        }
    })
    .catch(err => SwalDark.fire({icon: 'error', title: 'Error', text: err.message}));
}

// --- Bulk Edit ---

function bulkEdit() {
    const selected = getSelectedInfo();
    if (!selected.length) return;

    // Get full record data for selected
    const records = selected.map(s => currentRecords.find(r => r.id === s.id)).filter(Boolean);
    const list = records.map(r => {
        const sn = r.name.replace('.' + currentZoneName, '').replace(currentZoneName, '@');
        return `<code>${r.type} ${sn}</code> → <small class="text-muted">${r.content.length > 30 ? r.content.substring(0,27)+'...' : r.content}</small>`;
    }).join('<br>');

    SwalDark.fire({
        title: `Editar ${selected.length} registro${selected.length > 1 ? 's' : ''}`,
        html: `<div class="text-start">
            <div style="max-height:150px;overflow-y:auto;font-size:0.8rem;margin-bottom:12px;padding:8px;border:1px solid #334155;border-radius:6px;">${list}</div>
            <p class="small text-muted mb-2"><i class="bi bi-info-circle me-1"></i>Deja vacio los campos que no quieras cambiar.</p>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">Cambiar Type a</label>
                    <select id="bulkType" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="">— No cambiar —</option>
                        <option value="A">A</option>
                        <option value="AAAA">AAAA</option>
                        <option value="CNAME">CNAME</option>
                        <option value="MX">MX</option>
                        <option value="TXT">TXT</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">Cambiar Content a</label>
                    <input type="text" id="bulkContent" class="form-control form-control-sm" placeholder="Ej: 209.145.58.143" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">Cambiar TTL a</label>
                    <select id="bulkTtl" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="">— No cambiar —</option>
                        <option value="1">Auto</option>
                        <option value="60">1m</option>
                        <option value="300">5m</option>
                        <option value="3600">1h</option>
                        <option value="86400">1d</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">Cambiar Proxy a</label>
                    <select id="bulkProxied" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                        <option value="">— No cambiar —</option>
                        <option value="1">Proxy On</option>
                        <option value="0">DNS Only</option>
                    </select>
                </div>
            </div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: `<i class="bi bi-check-lg me-1"></i>Aplicar a ${selected.length}`,
        confirmButtonColor: '#38bdf8',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
            const type = document.getElementById('bulkType').value;
            const content = document.getElementById('bulkContent').value.trim();
            const ttl = document.getElementById('bulkTtl').value;
            const proxied = document.getElementById('bulkProxied').value;
            if (!type && !content && !ttl && proxied === '') {
                Swal.showValidationMessage('Debes cambiar al menos un campo');
                return false;
            }
            return { type, content, ttl: ttl ? parseInt(ttl) : null, proxied };
        },
    }).then(result => {
        if (!result.isConfirmed) return;
        executeBulkEdit(records, result.value);
    });
}

function executeBulkEdit(records, changes) {
    const csrf = document.querySelector('input[name="_csrf_token"]').value;
    const total = records.length;
    let done = 0;
    let errors = [];

    SwalDark.fire({
        title: 'Actualizando...',
        html: `<div class="spinner-border spinner-border-sm me-2"></div><span id="bulkProgress">0/${total}</span>`,
        allowOutsideClick: false,
        showConfirmButton: false,
    });

    // Process sequentially to avoid rate limits
    function next(i) {
        if (i >= records.length) {
            SwalDark.fire({
                icon: errors.length ? 'warning' : 'success',
                title: `${done}/${total} actualizados`,
                text: errors.length ? `Errores: ${errors.join(', ')}` : '',
                timer: 2000,
                showConfirmButton: false,
            });
            loadRecords(currentZoneId, currentZoneName);
            return;
        }

        const r = records[i];
        const data = {
            _csrf_token: csrf,
            account: currentAccountIdx,
            zone_id: currentZoneId,
            record_id: r.id,
            type: changes.type || r.type,
            name: r.name,
            content: changes.content || r.content,
            ttl: changes.ttl !== null ? changes.ttl : r.ttl,
        };

        // Handle proxy
        const newType = changes.type || r.type;
        const isProxyable = ['A', 'AAAA', 'CNAME'].includes(newType);
        if (isProxyable) {
            if (changes.proxied !== '') {
                data.proxied = changes.proxied === '1' ? '1' : '';
            } else {
                data.proxied = r.proxied ? '1' : '';
            }
        }

        fetch('/settings/cloudflare-dns/update-record', {
            method: 'POST',
            body: new URLSearchParams(data),
        })
        .then(res => res.json())
        .then(resp => {
            if (resp.ok) {
                done++;
            } else {
                errors.push(r.name.replace('.' + currentZoneName, '') + ': ' + (resp.error || '?'));
            }
            const el = document.getElementById('bulkProgress');
            if (el) el.textContent = `${i+1}/${total}`;
            next(i + 1);
        })
        .catch(err => {
            errors.push(r.name + ': ' + err.message);
            next(i + 1);
        });
    }

    next(0);
}

// --- Single record actions ---

function confirmToggleProxy(recordId, proxied, recordName, recordType) {
    const action = proxied ? 'Activar proxy' : 'Desactivar proxy';
    const icon = proxied
        ? '<i class="bi bi-cloud-fill" style="color:#f97316;font-size:1.3rem;"></i>'
        : '<i class="bi bi-cloud" style="color:#94a3b8;font-size:1.3rem;"></i>';
    const desc = proxied
        ? 'El trafico pasara por Cloudflare (proxy activo). SSL y proteccion DDoS via Cloudflare.'
        : 'El trafico ira directo al servidor (DNS Only). Caddy generara SSL automaticamente.';

    SwalDark.fire({
        title: action,
        html: `<p><strong>${recordType}</strong> <code>${recordName}</code></p>
               <p class="small text-muted mb-0">${desc}</p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: proxied ? '<i class="bi bi-cloud-fill me-1"></i>Proxy On' : '<i class="bi bi-cloud me-1"></i>DNS Only',
        confirmButtonColor: proxied ? '#f97316' : '#6c757d',
        cancelButtonText: 'Cancelar',
    }).then(result => {
        if (!result.isConfirmed) return;
        const csrf = document.querySelector('input[name="_csrf_token"]').value;
        fetch('/settings/cloudflare-dns/toggle-proxy', {
            method: 'POST',
            body: new URLSearchParams({
                _csrf_token: csrf,
                account: currentAccountIdx,
                zone_id: currentZoneId,
                record_id: recordId,
                proxied: proxied ? '1' : '',
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                loadRecords(currentZoneId, currentZoneName);
            } else {
                SwalDark.fire({icon: 'error', title: 'Error', text: data.error});
            }
        })
        .catch(err => SwalDark.fire({icon: 'error', title: 'Error', text: err.message}));
    });
}

function buildRecordFormHtml(type, name, content, ttl, proxied) {
    const inputStyle = 'background:#1e293b;border-color:#334155;color:#e2e8f0;';
    const types = ['A','AAAA','CNAME','MX','TXT','SRV'];
    const ttls = [['1','Auto'],['60','1m'],['300','5m'],['3600','1h'],['86400','1d']];

    return `<div class="text-start">
        <div class="row g-2">
            <div class="col-6">
                <label class="form-label small text-muted mb-1">Type</label>
                <select id="recType" class="form-select form-select-sm" style="${inputStyle}">
                    ${types.map(t => `<option value="${t}" ${t===type?'selected':''}>${t}</option>`).join('')}
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small text-muted mb-1">Name</label>
                <input type="text" id="recName" class="form-control form-control-sm" value="${name.replace(/"/g,'&quot;')}" placeholder="@ or subdomain" style="${inputStyle}">
            </div>
            <div class="col-12">
                <label class="form-label small text-muted mb-1">Content</label>
                <input type="text" id="recContent" class="form-control form-control-sm" value="${content.replace(/"/g,'&quot;')}" placeholder="IP or value" style="${inputStyle}">
            </div>
            <div class="col-6">
                <label class="form-label small text-muted mb-1">TTL</label>
                <select id="recTtl" class="form-select form-select-sm" style="${inputStyle}">
                    ${ttls.map(([v,l]) => `<option value="${v}" ${String(ttl)===v?'selected':''}>${l}</option>`).join('')}
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small text-muted mb-1">Proxy</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="recProxied" ${proxied?'checked':''}>
                    <label class="form-check-label small text-muted" for="recProxied" id="recProxiedLabel">${proxied?'On':'Off'}</label>
                </div>
            </div>
        </div>
    </div>`;
}

function showAddRecord() {
    SwalDark.fire({
        title: '<i class="bi bi-plus-circle me-2 text-info"></i>Add Record',
        html: buildRecordFormHtml('A', '', '', '1', true),
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check-lg me-1"></i>Crear',
        confirmButtonColor: '#38bdf8',
        cancelButtonText: 'Cancelar',
        didOpen: () => {
            document.getElementById('recProxied').addEventListener('change', function() {
                document.getElementById('recProxiedLabel').textContent = this.checked ? 'On' : 'Off';
            });
        },
        preConfirm: () => {
            const name = document.getElementById('recName').value.trim();
            const content = document.getElementById('recContent').value.trim();
            if (!name || !content) { Swal.showValidationMessage('Name y Content son obligatorios'); return false; }
            return {
                type: document.getElementById('recType').value,
                name, content,
                ttl: document.getElementById('recTtl').value,
                proxied: document.getElementById('recProxied').checked,
            };
        },
    }).then(result => {
        if (!result.isConfirmed) return;
        submitRecord(null, result.value);
    });
}

function editRecordById(id) {
    const r = currentRecords.find(rec => rec.id === id);
    if (!r) return;

    const shortName = r.name.replace('.' + currentZoneName, '').replace(currentZoneName, '@');

    SwalDark.fire({
        title: '<i class="bi bi-pencil-square me-2"></i>Edit Record',
        html: buildRecordFormHtml(r.type, shortName, r.content, String(r.ttl), r.proxied || false),
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check-lg me-1"></i>Guardar',
        confirmButtonColor: '#38bdf8',
        cancelButtonText: 'Cancelar',
        didOpen: () => {
            document.getElementById('recProxied').addEventListener('change', function() {
                document.getElementById('recProxiedLabel').textContent = this.checked ? 'On' : 'Off';
            });
        },
        preConfirm: () => {
            const name = document.getElementById('recName').value.trim();
            const content = document.getElementById('recContent').value.trim();
            if (!name || !content) { Swal.showValidationMessage('Name y Content son obligatorios'); return false; }
            return {
                type: document.getElementById('recType').value,
                name, content,
                ttl: document.getElementById('recTtl').value,
                proxied: document.getElementById('recProxied').checked,
            };
        },
    }).then(result => {
        if (!result.isConfirmed) return;
        submitRecord(r.id, result.value);
    });
}

function submitRecord(recordId, data) {
    const isEdit = !!recordId;
    const csrf = document.querySelector('input[name="_csrf_token"]').value;

    const body = new URLSearchParams({
        _csrf_token: csrf,
        account: currentAccountIdx,
        zone_id: currentZoneId,
        type: data.type,
        name: data.name,
        content: data.content,
        ttl: data.ttl,
        proxied: data.proxied ? '1' : '',
    });

    if (isEdit) body.append('record_id', recordId);

    const url = isEdit ? '/settings/cloudflare-dns/update-record' : '/settings/cloudflare-dns/create-record';

    fetch(url, { method: 'POST', body })
        .then(r => r.json())
        .then(resp => {
            if (resp.ok) {
                loadRecords(currentZoneId, currentZoneName);
                SwalDark.fire({icon: 'success', title: isEdit ? 'Record updated' : 'Record created', timer: 1500, showConfirmButton: false});
            } else {
                SwalDark.fire({icon: 'error', title: 'Error', text: resp.error});
            }
        })
        .catch(err => SwalDark.fire({icon: 'error', title: 'Error', text: err.message}));
}

function deleteRecord(recordId, name) {
    SwalDark.fire({
        title: 'Delete DNS Record?',
        html: `<code>${name}</code>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#ef4444',
    }).then(result => {
        if (!result.isConfirmed) return;

        const csrf = document.querySelector('input[name="_csrf_token"]').value;
        fetch('/settings/cloudflare-dns/delete-record', {
            method: 'POST',
            body: new URLSearchParams({
                _csrf_token: csrf,
                account: currentAccountIdx,
                zone_id: currentZoneId,
                record_id: recordId,
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                loadRecords(currentZoneId, currentZoneName);
                SwalDark.fire({icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false});
            } else {
                SwalDark.fire({icon: 'error', title: 'Error', text: data.error});
            }
        });
    });
}
</script>

<?php endif; ?>
