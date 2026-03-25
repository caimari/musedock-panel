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
                                <th class="ps-3" style="width:60px;">Type</th>
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

        <!-- Add/Edit Record form -->
        <div class="card mt-3" id="recordForm" style="display:none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pencil-square me-2"></i><span id="formTitle">Add Record</span></span>
                <button class="btn btn-sm btn-outline-secondary" onclick="hideRecordForm()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body">
                <input type="hidden" id="editRecordId" value="">
                <div class="row g-2">
                    <div class="col-md-2">
                        <label class="form-label small text-muted">Type</label>
                        <select id="recType" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                            <option value="A">A</option>
                            <option value="AAAA">AAAA</option>
                            <option value="CNAME">CNAME</option>
                            <option value="MX">MX</option>
                            <option value="TXT">TXT</option>
                            <option value="SRV">SRV</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Name</label>
                        <input type="text" id="recName" class="form-control form-control-sm" placeholder="@ or subdomain" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Content</label>
                        <input type="text" id="recContent" class="form-control form-control-sm" placeholder="IP or value" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted">TTL</label>
                        <select id="recTtl" class="form-select form-select-sm" style="background:#1e293b;border-color:#334155;color:#e2e8f0;">
                            <option value="1">Auto</option>
                            <option value="60">1m</option>
                            <option value="300">5m</option>
                            <option value="3600">1h</option>
                            <option value="86400">1d</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted">Proxy</label>
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" id="recProxied" checked>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-sm btn-info w-100" onclick="saveRecord()">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                    </div>
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

document.addEventListener('DOMContentLoaded', () => {
    loadZones();
    document.getElementById('cfAccount').addEventListener('change', loadZones);
    document.getElementById('filterType').addEventListener('change', () => {
        if (currentZoneId) loadRecords(currentZoneId, currentZoneName);
    });
});

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
        })
        .catch(err => {
            list.innerHTML = `<div class="text-danger p-3">Error: ${err.message}</div>`;
        });
}

function selectZone(zoneId, zoneName, el) {
    // Highlight selected
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

            const tbody = document.getElementById('recordsBody');
            if (!data.records.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No records found</td></tr>';
            } else {
                tbody.innerHTML = data.records.map(r => {
                    const isProxyable = ['A', 'AAAA', 'CNAME'].includes(r.type);
                    const proxyIcon = !isProxyable ? '<span class="text-muted">—</span>' :
                        (r.proxied
                            ? `<i class="bi bi-cloud-fill" style="color:#f97316;cursor:pointer;" title="Proxied — click to disable" onclick="toggleProxy('${r.id}', false)"></i>`
                            : `<i class="bi bi-cloud" style="color:#94a3b8;cursor:pointer;" title="DNS Only — click to enable" onclick="toggleProxy('${r.id}', true)"></i>`);

                    const ttlText = r.ttl === 1 ? 'Auto' : (r.ttl >= 3600 ? (r.ttl/3600)+'h' : (r.ttl >= 60 ? (r.ttl/60)+'m' : r.ttl+'s'));

                    // Shorten name: remove zone suffix
                    const shortName = r.name.replace('.' + zoneName, '').replace(zoneName, '@');

                    // Truncate content
                    const shortContent = r.content.length > 50 ? r.content.substring(0, 47) + '...' : r.content;

                    const typeBadge = {
                        'A': 'bg-info', 'AAAA': 'bg-info', 'CNAME': 'bg-warning text-dark',
                        'MX': 'bg-success', 'TXT': 'bg-secondary', 'NS': 'bg-dark', 'SRV': 'bg-primary'
                    }[r.type] || 'bg-secondary';

                    return `<tr>
                        <td class="ps-3"><span class="badge ${typeBadge}" style="font-size:0.7rem;">${r.type}</span></td>
                        <td><code style="font-size:0.8rem;">${shortName}</code></td>
                        <td><small class="text-muted" title="${r.content}">${shortContent}</small></td>
                        <td><small class="text-muted">${ttlText}</small></td>
                        <td class="text-center">${proxyIcon}</td>
                        <td>
                            <button class="btn btn-outline-light btn-sm py-0 px-1" onclick='editRecord(${JSON.stringify(r)})' title="Edit"><i class="bi bi-pencil" style="font-size:0.7rem;"></i></button>
                            <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="deleteRecord('${r.id}', '${r.name}')" title="Delete"><i class="bi bi-trash" style="font-size:0.7rem;"></i></button>
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

function showAddRecord() {
    document.getElementById('formTitle').textContent = 'Add Record';
    document.getElementById('editRecordId').value = '';
    document.getElementById('recType').value = 'A';
    document.getElementById('recName').value = '';
    document.getElementById('recContent').value = '';
    document.getElementById('recTtl').value = '1';
    document.getElementById('recProxied').checked = true;
    document.getElementById('recordForm').style.display = '';
}

function editRecord(r) {
    document.getElementById('formTitle').textContent = 'Edit Record';
    document.getElementById('editRecordId').value = r.id;
    document.getElementById('recType').value = r.type;

    // Show short name
    const shortName = r.name.replace('.' + currentZoneName, '').replace(currentZoneName, '@');
    document.getElementById('recName').value = shortName;
    document.getElementById('recContent').value = r.content;
    document.getElementById('recTtl').value = String(r.ttl);
    document.getElementById('recProxied').checked = r.proxied || false;
    document.getElementById('recordForm').style.display = '';
}

function hideRecordForm() {
    document.getElementById('recordForm').style.display = 'none';
}

function saveRecord() {
    const recordId = document.getElementById('editRecordId').value;
    const isEdit = !!recordId;
    const csrf = document.querySelector('input[name="_csrf_token"]').value;

    const body = new URLSearchParams({
        _csrf_token: csrf,
        account: currentAccountIdx,
        zone_id: currentZoneId,
        type: document.getElementById('recType').value,
        name: document.getElementById('recName').value,
        content: document.getElementById('recContent').value,
        ttl: document.getElementById('recTtl').value,
        proxied: document.getElementById('recProxied').checked ? '1' : '',
    });

    if (isEdit) body.append('record_id', recordId);

    const url = isEdit ? '/settings/cloudflare-dns/update-record' : '/settings/cloudflare-dns/create-record';

    fetch(url, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                hideRecordForm();
                loadRecords(currentZoneId, currentZoneName);
                SwalDark.fire({icon: 'success', title: isEdit ? 'Record updated' : 'Record created', timer: 1500, showConfirmButton: false});
            } else {
                SwalDark.fire({icon: 'error', title: 'Error', text: data.error});
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

function toggleProxy(recordId, proxied) {
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
    });
}
</script>

<?php endif; ?>
