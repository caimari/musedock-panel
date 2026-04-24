<?php use MuseDockPanel\View; ?>

<?php $isSlave = ($clusterRole ?? '') === 'slave'; ?>

<?php if ($isSlave): ?>
<div class="card mb-4" style="border: 1px solid rgba(251,191,36,0.3);">
    <div class="card-body py-3">
        <i class="bi bi-info-circle text-warning me-2"></i>
        <strong>Modo Slave:</strong>
        <span class="text-muted">
            Este servidor es un nodo slave. La configuracion y gestion del correo se realiza desde el panel master.
            <?php
                $masterIp = \MuseDockPanel\Settings::get('cluster_master_ip', '');
                if ($masterIp):
            ?>
                <a href="https://<?= View::e($masterIp) ?>:8444/mail" class="text-info" target="_blank">Abrir panel master <i class="bi bi-box-arrow-up-right"></i></a>
            <?php endif; ?>
        </span>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($mailHealthAlerts) && !$isSlave): ?>
    <?php foreach ($mailHealthAlerts as $nodeId => $health): ?>
        <?php
            $nodeName = '#'.$nodeId;
            foreach (($mailNodes ?? []) as $mn) {
                if ((int)$mn['id'] === (int)$nodeId) {
                    $nodeName = $mn['name'];
                    break;
                }
            }
            $status = (string)($health['status'] ?? 'degraded');
            $isDown = $status === 'down';
            $lag = $health['replication_lag_seconds'] !== null ? (float)$health['replication_lag_seconds'] : null;
        ?>
        <div class="alert <?= $isDown ? 'alert-danger' : 'alert-warning' ?> mb-3">
            <div class="fw-semibold">
                <i class="bi bi-<?= $isDown ? 'x-octagon' : 'exclamation-triangle' ?> me-1"></i>
                Nodo de correo <?= View::e($nodeName) ?> <?= $isDown ? 'caido' : 'degradado' ?>
            </div>
            <div class="small mt-1">
                <?= View::e($health['message'] ?? 'Healthcheck de mail no saludable.') ?>
                <?php if ($lag !== null && $lag > 0): ?>
                    Lag replica: <strong><?= number_format($lag, 1) ?>s</strong>.
                <?php endif; ?>
                <?php if (array_key_exists('ptr_ok', $health) && $health['ptr_ok'] !== null && !$health['ptr_ok']): ?>
                    PTR/rDNS no coincide<?= !empty($health['ptr_value']) ? ': '.View::e($health['ptr_value']) : '' ?>.
                <?php endif; ?>
            </div>
            <div class="small text-muted mt-1">
                Las acciones `mail_*` se pausan automaticamente si PostgreSQL no responde, si `musedock_mail` no puede leer o si el lag supera el umbral critico.
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
    $modeLabels = [
        'satellite' => ['Solo Envio (Satellite)', 'Postfix + OpenDKIM solo para enviar. No recibe correo ni abre puertos de entrada. Ideal para SaaS y notificaciones.'],
        'full' => ['Correo Completo', 'Envia y recibe correo con buzones IMAP. Requiere MX, PTR y puertos 25/587/993 abiertos.'],
        'external' => ['SMTP Externo', 'Usa un proveedor como SES, Mailgun o Brevo. No instala servidor de correo local.'],
    ];
    $modeInfo = $modeLabels[$mailMode ?? 'full'] ?? $modeLabels['full'];
?>
<div class="card mb-4" style="border-color: rgba(56,189,248,.28);">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="text-muted small mb-1">Modo actual de correo</div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info"><?= View::e($modeInfo[0]) ?></span>
                    <span class="text-muted small"><?= View::e($modeInfo[1]) ?></span>
                </div>
            </div>
            <?php if (!$isSlave): ?>
            <div class="d-flex flex-wrap gap-2">
                <a href="/mail?setup=1" class="btn btn-outline-light btn-sm"><i class="bi bi-sliders me-1"></i>Cambiar modo</a>
                <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#smtpIntegrationBox">
                    <i class="bi bi-code-square me-1"></i>Integracion apps
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="collapse mt-3" id="smtpIntegrationBox">
            <div class="p-3 rounded" style="background:#0f172a;border:1px solid #334155;">
                <div class="fw-semibold mb-2">Endpoint local para apps Laravel/PHP</div>
                <div class="small text-muted mb-2">
                    Solo responde desde localhost. Las apps del mismo servidor pueden leer esta configuracion para no duplicar SMTP en cada <code>.env</code>.
                </div>
                <code>GET http://localhost:8444/api/internal/smtp-config</code>
                <div class="small mt-2">Bearer token: <code><?= View::e(substr((string)($internalSmtpToken ?? ''), 0, 10)) ?>...<?= View::e(substr((string)($internalSmtpToken ?? ''), -6)) ?></code></div>
            </div>
        </div>
    </div>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted small">Domains</div>
                <div class="fs-3 fw-bold text-info"><?= $stats['domains'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted small">Mailboxes</div>
                <div class="fs-3 fw-bold text-info"><?= $stats['accounts'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted small">Aliases</div>
                <div class="fs-3 fw-bold text-info"><?= $stats['aliases'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <div class="text-muted small">Storage</div>
                <div class="fs-3 fw-bold text-info"><?= $stats['used_mb'] ?> <small class="text-muted fs-6">/ <?= $stats['quota_mb'] ?> MB</small></div>
            </div>
        </div>
    </div>
</div>

<!-- Local Mail Server Status -->
<?php if (($mailLocalConfigured ?? false) && !$isSlave): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pc-display me-2"></i>Servidor de Mail Local</span>
        <div>
            <button class="btn btn-outline-warning btn-sm py-0 px-2" onclick="rotateMailDbPassword()" title="Rotar contraseña del usuario PostgreSQL musedock_mail">
                <i class="bi bi-key me-1"></i>Rotar password DB
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Servidor</th>
                    <th>Hostname</th>
                    <th>Status</th>
                    <th>Domains</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="ps-3 fw-semibold"><i class="bi bi-pc-display me-1 text-success"></i> Este servidor (local)</td>
                    <td class="text-muted"><?= View::e($mailLocalHostname ?? '-') ?></td>
                    <td><span class="badge badge-active">activo</span></td>
                    <td>
                        <?php
                            $localCount = \MuseDockPanel\Database::fetchOne(
                                "SELECT COUNT(*) AS cnt FROM mail_domains WHERE mail_node_id IS NULL"
                            );
                            echo $localCount['cnt'] ?? 0;
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Mail Nodes Status (remote) -->
<?php if (!empty($mailNodes) && !$isSlave): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-network me-2"></i>Mail Nodes (remotos)</span>
        <?php if (!($mailLocalConfigured ?? false)): ?>
        <button class="btn btn-outline-warning btn-sm py-0 px-2" onclick="rotateMailDbPassword()" title="Rotar contraseña del usuario PostgreSQL musedock_mail en todos los nodos">
            <i class="bi bi-key me-1"></i>Rotar password DB
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Node</th>
                    <th>Status</th>
                    <th>DB Mail</th>
                    <th>Replica Lag</th>
                    <th>PTR</th>
                    <th>Last Seen</th>
                    <th>Domains</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mailNodes as $node): ?>
                <?php
                    $mh = $mailHealthByNode[(int)$node['id']] ?? null;
                    $mhStatus = $mh['status'] ?? 'unknown';
                    $mhClass = $mhStatus === 'active' ? 'success' : ($mhStatus === 'down' ? 'danger' : 'warning');
                    $lag = ($mh && $mh['replication_lag_seconds'] !== null) ? (float)$mh['replication_lag_seconds'] : null;
                    $ptrOk = $mh['ptr_ok'] ?? null;
                ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= View::e($node['name']) ?></td>
                    <td>
                        <span class="badge badge-<?= $node['status'] === 'online' ? 'active' : 'suspended' ?>">
                            <?= $node['status'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($mh): ?>
                            <span class="badge bg-<?= $mhClass ?>"><?= View::e($mhStatus) ?></span>
                            <small class="d-block text-muted"><?= View::e($mh['checked_at'] ?? '') ?></small>
                        <?php else: ?>
                            <span class="badge bg-secondary">pending</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $lag !== null ? number_format($lag, 1).'s' : '-' ?></td>
                    <td>
                        <?php if ($ptrOk === null): ?>
                            <span class="text-muted">-</span>
                        <?php elseif ($ptrOk): ?>
                            <span class="badge bg-success">OK</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark" title="<?= View::e($mh['ptr_value'] ?? '') ?>">Revisar</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $node['last_seen_at'] ?? 'Never' ?></td>
                    <td>
                        <?php
                            $count = \MuseDockPanel\Database::fetchOne(
                                "SELECT COUNT(*) AS cnt FROM mail_domains WHERE mail_node_id = :nid",
                                ['nid' => $node['id']]
                            );
                            echo $count['cnt'] ?? 0;
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!$isSlave): ?>
    <?php if ($showSetup ?? false): ?>
        <?php include __DIR__ . '/setup-node.php'; ?>
    <?php elseif (empty($mailNodes) && !($mailLocalConfigured ?? false)): ?>
        <div class="card mb-0" style="border: 1px solid rgba(13, 202, 240, 0.25);">
            <div class="card-body py-3">
                <i class="bi bi-info-circle text-info me-2"></i>
                <strong>Mail Setup:</strong> Para usar correo, primero
                <a href="/mail?setup=1" class="text-info">configura un servidor de mail</a> (local o en un nodo remoto del cluster).
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Deliverability -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shield-check me-2"></i>Entregabilidad DNS</span>
        <span class="text-muted small">SPF, DKIM, DMARC, PTR y blacklists</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Esta seccion te dice, en cristiano, si el dominio esta preparado para enviar sin caer en spam.
            Los checks leen DNS en tiempo real; los registros recomendados son los que debes copiar en tu proveedor DNS.
        </p>

        <?php if (($mailMode ?? 'full') === 'external'): ?>
            <div class="alert alert-info" style="background:rgba(56,189,248,.12);border-color:rgba(56,189,248,.25);">
                <strong>SMTP externo:</strong> la reputacion, DKIM y entrega final dependen principalmente del proveedor configurado.
                Aqui se muestran los DNS del remitente si hay <code>from_address</code>.
            </div>
        <?php endif; ?>

        <?php if (empty($deliverabilityRows)): ?>
            <div class="text-muted">No hay dominios de mail ni dominio remitente configurado todavia.</div>
        <?php else: ?>
            <?php foreach ($deliverabilityRows as $row): ?>
                <?php
                    $checks = $row['checks'] ?? [];
                    $recommendedLines = [];
                    foreach (($row['recommended'] ?? []) as $rec) {
                        $name = $rec['name'] ?? '';
                        $value = $rec['value'] ?? '';
                        $type = $rec['type'] ?? '';
                        $prio = isset($rec['priority']) ? (' ' . $rec['priority']) : '';
                        $recommendedLines[] = trim("{$type}{$prio} {$name} {$value}");
                    }
                    $copyText = implode("\n", $recommendedLines);
                    $badge = static function ($check) {
                        $ok = $check['ok'] ?? null;
                        if ($ok === true) return '<span class="badge bg-success">OK</span>';
                        if ($ok === false) return '<span class="badge bg-warning text-dark">Revisar</span>';
                        return '<span class="badge bg-secondary">N/D</span>';
                    };
                ?>
                <div class="p-3 rounded mb-3" style="background:#0f172a;border:1px solid #334155;">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                        <div>
                            <div class="fw-semibold"><?= View::e($row['domain'] ?? '') ?></div>
                            <div class="small text-muted">
                                Modo: <?= View::e($row['mode'] ?? ($mailMode ?? 'full')) ?> ·
                                Host salida: <code><?= View::e($row['mail_hostname'] ?? '-') ?></code> ·
                                IP: <code><?= View::e($row['ip'] ?? '-') ?></code>
                            </div>
                        </div>
                        <button class="btn btn-outline-light btn-sm" type="button" onclick="copyDnsRecords(this)" data-records="<?= View::e($copyText) ?>">
                            <i class="bi bi-clipboard me-1"></i>Copiar DNS
                        </button>
                    </div>

                    <div class="row g-2 mb-3">
                        <?php foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC', 'a' => 'A hostname', 'ptr' => 'PTR/rDNS'] as $key => $label): ?>
                            <?php $c = $checks[$key] ?? ['ok' => null, 'message' => 'No comprobado', 'value' => '']; ?>
                            <div class="col-md-2 col-sm-4">
                                <div class="small text-muted"><?= $label ?></div>
                                <div><?= $badge($c) ?></div>
                                <div class="small text-muted text-truncate" title="<?= View::e($c['value'] ?? '') ?>"><?= View::e($c['message'] ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php $bl = $checks['blacklists'] ?? ['ok' => null, 'message' => 'No comprobado']; ?>
                        <div class="col-md-2 col-sm-4">
                            <div class="small text-muted">Blacklists</div>
                            <div><?= $badge($bl) ?></div>
                            <div class="small text-muted text-truncate" title="<?= View::e($bl['message'] ?? '') ?>"><?= View::e($bl['message'] ?? '') ?></div>
                        </div>
                    </div>

                    <div class="small text-muted mb-1">Registros recomendados</div>
                    <pre class="mb-0 p-2 rounded small" style="background:#020617;color:#cbd5e1;white-space:pre-wrap;"><?= View::e($copyText) ?></pre>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$isSlave): ?>
        <form method="post" action="/mail/test-send" class="row g-2 align-items-end mt-3">
            <?= View::csrf() ?>
            <div class="col-md-5">
                <label class="form-label">Test de envio</label>
                <input type="email" name="test_email" class="form-control" placeholder="tu@email.com" required>
                <div class="form-text text-muted">Envia un correo simple y mira si Postfix lo acepta, lo envia, lo difiere o lo rebota.</div>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-outline-info"><i class="bi bi-send me-1"></i>Enviar test</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Domains list -->
<div class="mb-4"></div>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-globe2 me-2"></i>Mail Domains</h6>
    <?php if (!$isSlave): ?>
    <a href="/mail/domains/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Domain</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($domains)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-envelope" style="font-size: 2rem;"></i>
                <p class="mt-2">No mail domains yet.</p>
                <?php if (!$isSlave): ?>
                <a href="/mail/domains/create" class="btn btn-primary btn-sm">Add first mail domain</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Domain</th>
                        <th>Customer</th>
                        <th>Mail Node</th>
                        <th>Accounts</th>
                        <th>DKIM</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/mail/domains/<?= $d['id'] ?>" class="text-info text-decoration-none fw-semibold">
                                <?= View::e($d['domain']) ?>
                            </a>
                        </td>
                        <td><?= View::e($d['customer_name'] ?? '-') ?></td>
                        <td>
                            <?php if ($d['node_name']): ?>
                                <span class="badge bg-secondary"><?= View::e($d['node_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Local</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $d['account_count'] ?></td>
                        <td>
                            <?php if ($d['dkim_public_key']): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $d['status'] === 'active' ? 'active' : 'suspended' ?>">
                                <?= $d['status'] ?>
                            </span>
                        </td>
                        <td>
                            <a href="/mail/domains/<?= $d['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function copyDnsRecords(btn) {
    const text = btn.getAttribute('data-records') || '';
    navigator.clipboard.writeText(text).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
        setTimeout(() => btn.innerHTML = original, 1500);
    }).catch(() => alert(text));
}

<?php if (!empty($mailNodes) || ($mailLocalConfigured ?? false)): ?>
function rotateMailDbPassword() {
    const pwd = prompt('Esta accion regenera la contraseña de musedock_mail en el master y la propaga a todos los nodos de mail.\n\nIntroduce tu contraseña del panel para confirmar:');
    if (!pwd) return;

    const btn = document.querySelector('[onclick="rotateMailDbPassword()"]');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rotando...';

    const fd = new FormData();
    fd.append('_csrf_token', '<?= View::csrfToken() ?>');
    fd.append('admin_password', pwd);

    fetch('/settings/cluster/rotate-mail-db-password', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (data.ok) {
                let msg = 'Password rotada correctamente.\n\n';
                if (data.nodes && data.nodes.length > 0) {
                    data.nodes.forEach(n => {
                        msg += (n.ok ? '✓' : '✗') + ' ' + n.node + (n.error ? ': ' + n.error : '') + '\n';
                    });
                } else {
                    msg += 'No hay nodos de mail activos. La password se actualizo en el master.';
                }
                alert(msg);
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert('Error de conexion: ' + err.message);
        });
}
<?php endif; ?>
</script>
