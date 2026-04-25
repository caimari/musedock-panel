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
        'relay' => ['Relay Privado (WireGuard)', 'Postfix + OpenDKIM multi-dominio + SASL. Otros servidores envian por VPN; no recibe correo publico ni buzones.'],
        'full' => ['Correo Completo', 'Envia y recibe correo con buzones IMAP. Requiere MX, PTR y puertos 25/587/993 abiertos.'],
        'external' => ['SMTP Externo', 'Usa un proveedor como SES, Mailgun o Brevo. No instala servidor de correo local.'],
    ];
    $modeInfo = $modeLabels[$mailMode ?? 'full'] ?? $modeLabels['full'];
    $mailModeValue = (string)($mailMode ?? 'full');
    $localRepair = $localMailRepairStatus ?? [];
    $remoteNodeCount = 0;
    $remoteOnlineCount = 0;
    foreach (($mailNodes ?? []) as $node) {
        $remoteNodeCount++;
        if ((string)($node['status'] ?? '') === 'online') {
            $remoteOnlineCount++;
        }
    }
    $masterIp = \MuseDockPanel\Settings::get('cluster_master_ip', '');
    $mailInstallStatusClass = 'success';
    $mailInstallStatusTitle = 'Instalado y operativo';
    $mailInstallStatusDetail = '';

    if ($isSlave) {
        $mailInstallStatusClass = 'warning text-dark';
        $mailInstallStatusTitle = 'Servidor Slave (gestionado desde master)';
        $mailInstallStatusDetail = $masterIp !== ''
            ? 'Este nodo no se administra aqui. Gestiona correo en el master: https://' . $masterIp . ':8444/mail'
            : 'Este nodo no se administra aqui. Gestiona correo desde el panel master.';
    } elseif ($mailModeValue === 'external') {
        $smtpHost = trim((string)($smtpConfig['host'] ?? ''));
        $smtpUser = trim((string)($smtpConfig['username'] ?? ''));
        if ($smtpHost === '' || $smtpUser === '') {
            $mailInstallStatusClass = 'warning text-dark';
            $mailInstallStatusTitle = 'SMTP externo pendiente de configurar';
            $mailInstallStatusDetail = 'No hay servidor local de correo en este modo. Configura host/usuario SMTP externo para operar.';
        } else {
            $mailInstallStatusClass = 'info';
            $mailInstallStatusTitle = 'Operativo con SMTP externo';
            $mailInstallStatusDetail = 'Sin servidor local. El envio se realiza por proveedor externo configurado.';
        }
    } elseif (!($mailLocalConfigured ?? false) && $remoteNodeCount === 0) {
        $mailInstallStatusClass = 'danger';
        $mailInstallStatusTitle = 'No instalado';
        $mailInstallStatusDetail = 'No hay servidor de correo local ni nodos de mail remotos configurados.';
    } else {
        $parts = [];
        if (($mailLocalConfigured ?? false)) {
            $parts[] = 'local activo';
        }
        if ($remoteNodeCount > 0) {
            $parts[] = "nodos remotos online {$remoteOnlineCount}/{$remoteNodeCount}";
        }
        $mailInstallStatusDetail = 'Servicio detectado en: ' . implode(' · ', $parts) . '.';

        if ($remoteNodeCount > 0 && $remoteOnlineCount === 0 && !($mailLocalConfigured ?? false)) {
            $mailInstallStatusClass = 'danger';
            $mailInstallStatusTitle = 'Instalado pero no operativo';
            $mailInstallStatusDetail = 'Hay nodos remotos definidos, pero ninguno esta online y no hay mail local activo.';
        } elseif (!empty($mailHealthAlerts)) {
            $mailInstallStatusClass = 'warning text-dark';
            $mailInstallStatusTitle = 'Operativo con alertas';
            $mailInstallStatusDetail .= ' Revisa alertas de healthcheck.';
        }
    }
    $tabCandidates = ['general', 'webmail', 'deliverability', 'domains', 'infra'];
    if (($mailMode ?? 'full') === 'relay') {
        $tabCandidates[] = 'relay';
    }
    if (!$isSlave) {
        $tabCandidates[] = 'migration';
    }
    $requestedTab = (string)($_GET['tab'] ?? '');
    $activeTab = in_array($requestedTab, $tabCandidates, true) ? $requestedTab : '';
    if ($activeTab === '') {
        $activeTab = (!empty($_GET['setup']) && !$isSlave) ? 'infra' : 'general';
    }
    $tabLink = static function (string $tab): string {
        $query = $_GET;
        $query['tab'] = $tab;
        if ($tab !== 'infra') {
            unset($query['setup']);
        }
        $url = '/mail';
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        if ($tab === 'webmail') {
            $url .= '#webmail';
        }
        return $url;
    };
?>
<div class="card mb-4" style="border-color: rgba(148,163,184,.22);">
    <div class="card-body py-2">
        <div class="nav nav-pills gap-2 flex-wrap" role="tablist" aria-label="Navegacion Mail">
            <a class="btn btn-sm <?= $activeTab === 'general' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('general')) ?>" data-mail-tab-link="general">General</a>
            <a class="btn btn-sm <?= $activeTab === 'domains' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('domains')) ?>" data-mail-tab-link="domains">Dominios</a>
            <a class="btn btn-sm <?= $activeTab === 'webmail' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('webmail')) ?>" data-mail-tab-link="webmail">Webmail</a>
            <?php if (($mailMode ?? 'full') === 'relay'): ?>
                <a class="btn btn-sm <?= $activeTab === 'relay' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('relay')) ?>" data-mail-tab-link="relay">Relay</a>
            <?php endif; ?>
            <?php if (!$isSlave): ?>
                <a class="btn btn-sm <?= $activeTab === 'migration' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('migration')) ?>" data-mail-tab-link="migration">Migracion</a>
            <?php endif; ?>
            <a class="btn btn-sm <?= $activeTab === 'infra' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('infra')) ?>" data-mail-tab-link="infra">Infra</a>
            <a class="btn btn-sm <?= $activeTab === 'deliverability' ? 'btn-info' : 'btn-outline-light' ?>" href="<?= View::e($tabLink('deliverability')) ?>" data-mail-tab-link="deliverability">Entregabilidad</a>
        </div>
    </div>
</div>

<div class="mail-tab-pane<?= $activeTab === 'general' ? '' : ' d-none' ?>" data-mail-tab="general">
<div class="card mb-4" style="border-color: rgba(56,189,248,.28);">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="text-muted small mb-1">Modo actual de correo</div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info"><?= View::e($modeInfo[0]) ?></span>
                    <span class="text-muted small"><?= View::e($modeInfo[1]) ?></span>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                    <span class="text-muted small">Estado real:</span>
                    <span class="badge bg-<?= $mailInstallStatusClass ?>"><?= View::e($mailInstallStatusTitle) ?></span>
                    <?php if ($mailInstallStatusDetail !== ''): ?>
                        <span class="text-muted small"><?= View::e($mailInstallStatusDetail) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$isSlave): ?>
            <div class="d-flex flex-wrap gap-2">
                <a href="/mail?tab=infra&amp;setup=1" class="btn btn-outline-light btn-sm"><i class="bi bi-sliders me-1"></i>Cambiar modo</a>
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
<?php if (!$isSlave && !empty($localRepair['needs_repair'])): ?>
<div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-tools me-2 text-warning"></i>Instalacion local de mail incompleta</span>
        <span class="badge bg-warning text-dark">Reparacion disponible</span>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-7">
                <div class="small text-muted mb-2">
                    Se detecta una instalacion parcial o servicios locales no activos. El reparador prepara OpenDKIM,
                    corrige el socket, reinicia OpenDKIM/Postfix y marca el mail local como configurado si queda operativo.
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge bg-<?= !empty($localRepair['postfix_active']) ? 'success' : 'secondary' ?>">Postfix <?= !empty($localRepair['postfix_active']) ? 'activo' : 'no activo' ?></span>
                    <span class="badge bg-<?= !empty($localRepair['opendkim_active']) ? 'success' : 'secondary' ?>">OpenDKIM <?= !empty($localRepair['opendkim_active']) ? 'activo' : 'no activo' ?></span>
                    <?php if (!empty($localRepair['relay_ip'])): ?>
                        <span class="badge bg-<?= ($localRepair['relay_ip_assigned'] ?? null) === false ? 'danger' : 'info' ?>">
                            WG <?= View::e($localRepair['relay_ip']) ?><?= ($localRepair['relay_ip_assigned'] ?? null) === false ? ' no asignada' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <form method="post" action="/mail/repair-local" class="d-flex gap-2" data-mail-repair-form>
                    <?= View::csrf() ?>
                    <input type="password" name="admin_password" class="form-control form-control-sm" placeholder="Password admin" required autocomplete="current-password">
                    <button class="btn btn-warning btn-sm text-dark fw-semibold">
                        <i class="bi bi-wrench-adjustable me-1"></i>Reparar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</div>

<?php
    $webmailConfig = $webmailConfig ?? [];
    $webmailProviders = $webmailProviders ?? [];
    $webmailInstallStatus = $webmailInstallStatus ?? ['status' => 'idle'];
    $webmailHostValue = ($webmailConfig['host'] ?? '') ?: \MuseDockPanel\Services\WebmailService::defaultHost();
    $webmailMailHost = ($webmailConfig['imap_host'] ?? '') ?: \MuseDockPanel\Services\WebmailService::defaultMailHost();
    $webmailStatus = (string)($webmailInstallStatus['status'] ?? 'idle');
    $webmailStage = (string)($webmailInstallStatus['stage'] ?? '');
    $webmailMessage = (string)($webmailInstallStatus['message'] ?? '');
?>
<div class="mail-tab-pane<?= $activeTab === 'webmail' ? '' : ' d-none' ?>" data-mail-tab="webmail">
<div id="webmail" class="card mb-4" style="border-color:rgba(34,197,94,.22);">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-envelope-at me-2"></i>Webmail</span>
        <span class="text-muted small">Proveedor configurable · Roundcube disponible</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="p-3 rounded h-100" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold mb-2">Fases</div>
                    <div class="small text-muted mb-2">
                        Fase 1 instala Roundcube como webmail IMAP/SMTP. Fase 2 lo enlaza desde dominios y portal de cliente.
                        Fase 3 queda preparada para filtros/autoresponder cuando se active ManageSieve.
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="badge bg-success">1 · Roundcube</span>
                        <span class="badge bg-secondary">2 · Portal cliente</span>
                        <span class="badge bg-<?= !empty($webmailConfig['sieve_enabled']) ? 'success' : 'secondary' ?>">3 · Sieve/filtros</span>
                    </div>
                    <div class="small text-muted mt-3">
                        URL actual:
                        <?php if (!empty($webmailConfig['enabled']) && !empty($webmailConfig['url'])): ?>
                            <a href="<?= View::e($webmailConfig['url']) ?>" target="_blank" class="text-info"><?= View::e($webmailConfig['url']) ?> <i class="bi bi-box-arrow-up-right"></i></a>
                        <?php else: ?>
                            <span>sin instalar</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($webmailConfig['aliases'])): ?>
                        <div class="small text-muted mt-3">Hostnames adicionales</div>
                        <div class="d-flex flex-column gap-1 mt-1">
                            <?php foreach ($webmailConfig['aliases'] as $aliasHost): ?>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <a class="text-info small" href="https://<?= View::e($aliasHost) ?>" target="_blank"><?= View::e($aliasHost) ?></a>
                                    <?php if (!$isSlave): ?>
                                    <form method="post" action="/mail/webmail/aliases/delete" class="m-0">
                                        <?= View::csrf() ?>
                                        <input type="hidden" name="host" value="<?= View::e($aliasHost) ?>">
                                        <button class="btn btn-outline-danger btn-sm py-0 px-1" onclick="return confirm('Eliminar hostname webmail?')" title="Eliminar"><i class="bi bi-x"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($webmailStatus !== 'idle'): ?>
                        <div class="alert <?= $webmailStatus === 'failed' ? 'alert-danger' : ($webmailStatus === 'completed' ? 'alert-success' : 'alert-info') ?> mt-3 mb-0 py-2">
                            <div class="fw-semibold">Estado: <?= View::e($webmailStatus) ?><?= $webmailStage ? ' · '.View::e($webmailStage) : '' ?></div>
                            <?php if ($webmailMessage): ?><div class="small"><?= View::e($webmailMessage) ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if ($isSlave): ?>
                    <div class="text-muted small">La configuracion de webmail se gestiona desde el master.</div>
                <?php else: ?>
                <form method="post" action="/mail/webmail/save" class="row g-3 mb-3" autocomplete="off">
                    <?= View::csrf() ?>
                    <div class="col-md-4">
                        <label class="form-label">Proveedor</label>
                        <select id="webmail_provider" name="provider" class="form-select">
                            <?php foreach ($webmailProviders as $key => $provider): ?>
                                <option value="<?= View::e($key) ?>" <?= ($webmailConfig['provider'] ?? 'roundcube') === $key ? 'selected' : '' ?> <?= ($provider['status'] ?? '') !== 'supported' ? 'disabled' : '' ?>>
                                    <?= View::e($provider['label']) ?><?= ($provider['status'] ?? '') !== 'supported' ? ' (futuro)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Hostname webmail</label>
                        <input id="webmail_host" name="host" class="form-control" value="<?= View::e($webmailHostValue) ?>" placeholder="webmail.dominio.com" autocomplete="off" required>
                        <div class="form-text text-muted">Debe apuntar por DNS a este servidor o al nodo donde publiques Roundcube.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Servidor IMAP</label>
                        <input id="webmail_imap_host" name="imap_host" class="form-control" value="<?= View::e($webmailMailHost) ?>" placeholder="mail.dominio.com" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Servidor SMTP</label>
                        <input id="webmail_smtp_host" name="smtp_host" class="form-control" value="<?= View::e(($webmailConfig['smtp_host'] ?? '') ?: $webmailMailHost) ?>" placeholder="mail.dominio.com" autocomplete="off">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-info btn-sm"><i class="bi bi-save me-1"></i>Guardar configuracion</button>
                    </div>
                </form>

                <form method="post" action="/mail/webmail/install" class="row g-2 align-items-end" autocomplete="off" onsubmit="syncWebmailInstallForm(); return confirm('Instalar o reconfigurar Roundcube ahora?')">
                    <?= View::csrf() ?>
                    <input id="webmail_install_provider" type="hidden" name="provider" value="<?= View::e($webmailConfig['provider'] ?? 'roundcube') ?>">
                    <input id="webmail_install_host" type="hidden" name="host" value="<?= View::e($webmailHostValue) ?>">
                    <input id="webmail_install_imap_host" type="hidden" name="imap_host" value="<?= View::e($webmailMailHost) ?>">
                    <input id="webmail_install_smtp_host" type="hidden" name="smtp_host" value="<?= View::e(($webmailConfig['smtp_host'] ?? '') ?: $webmailMailHost) ?>">
                    <div class="col-md-7">
                        <label class="form-label small">Password admin</label>
                        <input name="admin_password" type="password" class="form-control form-control-sm" autocomplete="new-password" required>
                        <div class="form-text text-muted">Instala paquetes, descarga Roundcube y crea la ruta Caddy del hostname webmail.</div>
                    </div>
                    <div class="col-md-5">
                        <button class="btn btn-success btn-sm w-100" <?= $webmailStatus === 'running' ? 'disabled' : '' ?>>
                            <i class="bi bi-download me-1"></i><?= $webmailStatus === 'running' ? 'Instalando...' : 'Instalar / reconfigurar Roundcube' ?>
                        </button>
                    </div>
                </form>

                <form method="post" action="/mail/webmail/aliases/store" class="row g-2 align-items-end mt-3" autocomplete="off">
                    <?= View::csrf() ?>
                    <div class="col-md-7">
                        <label class="form-label small">Webmail por dominio de cliente</label>
                        <input name="host" class="form-control form-control-sm" placeholder="webmail.cliente.com" autocomplete="off">
                        <div class="form-text text-muted">Añade un hostname extra apuntando al mismo Roundcube. El DNS debe apuntar a este servidor.</div>
                    </div>
                    <div class="col-md-5">
                        <button class="btn btn-outline-light btn-sm w-100" <?= empty($webmailConfig['enabled']) ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-lg me-1"></i>Añadir hostname webmail
                        </button>
                    </div>
                </form>

                <form method="post" action="/mail/webmail/sieve-enable" class="row g-2 align-items-end mt-3" autocomplete="off" onsubmit="return confirm('Activar Sieve/ManageSieve en los nodos de correo?')">
                    <?= View::csrf() ?>
                    <div class="col-md-7">
                        <label class="form-label small">Filtros, reenvios y vacaciones</label>
                        <input name="admin_password" type="password" class="form-control form-control-sm" autocomplete="new-password" placeholder="Password admin" required>
                        <div class="form-text text-muted">Instala/activa Dovecot Sieve y ManageSieve para que Roundcube pueda gestionar filtros y autoresponder.</div>
                    </div>
                    <div class="col-md-5">
                        <button class="btn btn-outline-warning btn-sm w-100">
                            <i class="bi bi-funnel me-1"></i>Activar Sieve/ManageSieve
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php if (($mailMode ?? 'full') === 'relay'): ?>
<?php
    $relayHost = \MuseDockPanel\Settings::get('mail_relay_wireguard_ip', '') ?: \MuseDockPanel\Settings::get('mail_relay_host', '');
    $relayPort = \MuseDockPanel\Settings::get('mail_relay_port', '587');
    $relayPublicIp = \MuseDockPanel\Settings::get('mail_relay_public_ip', '');
    $relayPublicHost = \MuseDockPanel\Settings::get('mail_outbound_hostname', '') ?: \MuseDockPanel\Settings::get('mail_relay_host', '');
    $relayTruthy = static fn($v): bool => in_array((string)$v, ['1', 't', 'true', 'yes', 'on'], true) || $v === true;
?>
<div class="mail-tab-pane<?= $activeTab === 'relay' ? '' : ' d-none' ?>" data-mail-tab="relay">
<div class="card mb-3" style="border-color:rgba(56,189,248,.28);">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-signpost-split me-2"></i>Como activar un dominio en Relay Privado</span>
        <a href="/mail?tab=deliverability" class="btn btn-outline-info btn-sm"><i class="bi bi-shield-check me-1"></i>Ir a Entregabilidad</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="small text-muted">1. Autoriza el dominio remitente</div>
                <div class="fw-semibold">Ej: <code>example.com</code></div>
                <div class="small text-muted">El panel genera una clave DKIM propia para ese dominio. No crea buzones.</div>
            </div>
            <div class="col-lg-4">
                <div class="small text-muted">2. Publica los DNS</div>
                <div class="fw-semibold">SPF, DKIM, DMARC y A/PTR</div>
                <div class="small text-muted">
                    Copia los TXT desde <a href="/mail?tab=deliverability" class="text-info">Entregabilidad</a>. El DKIM pendiente suele ser <code>default._domainkey.tudominio.com</code>.
                </div>
            </div>
            <div class="col-lg-4">
                <div class="small text-muted">3. Crea usuario SMTP y prueba</div>
                <div class="fw-semibold">Laravel/SaaS por WireGuard</div>
                <div class="small text-muted">Cuando SPF/DKIM/DMARC esten OK, el estado pasa de <code>pending</code> a <code>active</code>.</div>
            </div>
        </div>
        <div class="mt-3 p-3 rounded" style="background:#0f172a;border:1px solid #334155;">
            <div class="small text-muted mb-1">DNS base del relay</div>
            <div class="row g-2 small">
                <div class="col-md-4">A <code><?= View::e($relayPublicHost ?: 'mail.example.com') ?></code> → <code><?= View::e($relayPublicIp ?: 'IP_PUBLICA') ?></code></div>
                <div class="col-md-4">PTR/rDNS de <code><?= View::e($relayPublicIp ?: 'IP_PUBLICA') ?></code> → <code><?= View::e($relayPublicHost ?: 'mail.example.com') ?></code></div>
                <div class="col-md-4">SMTP privado: <code><?= View::e($relayHost ?: 'IP_WIREGUARD') ?>:<?= View::e($relayPort) ?></code> STARTTLS</div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100" style="border-color:rgba(14,165,233,.28);">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-globe2 me-2"></i>Dominios autorizados para relay</span>
                <span class="text-muted small">DKIM por dominio</span>
            </div>
            <div class="card-body">
                <?php if (!empty($relayNewCredentials)): ?>
                    <div class="alert alert-warning mb-3" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.3);">
                        <div class="fw-semibold mb-1">Credenciales SMTP creadas. Copia la contrasena ahora.</div>
                        <pre class="mb-2 small p-2 rounded" style="background:#020617;color:#e2e8f0;">Host: <?= View::e($relayNewCredentials['host'] ?? $relayHost) . "\n" ?>Puerto: <?= View::e($relayNewCredentials['port'] ?? $relayPort) . "\n" ?>Usuario: <?= View::e($relayNewCredentials['username'] ?? '') . "\n" ?>Password: <?= View::e($relayNewCredentials['password'] ?? '') . "\n" ?>Cifrado: STARTTLS</pre>
                        <button class="btn btn-outline-light btn-sm" type="button" onclick="copyDnsRecords(this)" data-records="<?= View::e("Host: ".($relayNewCredentials['host'] ?? $relayHost)."\nPuerto: ".($relayNewCredentials['port'] ?? $relayPort)."\nUsuario: ".($relayNewCredentials['username'] ?? '')."\nPassword: ".($relayNewCredentials['password'] ?? '')."\nCifrado: STARTTLS") ?>">
                            <i class="bi bi-clipboard me-1"></i>Copiar credenciales
                        </button>
                    </div>
                <?php endif; ?>

                <div class="small text-muted mb-3">
                    El relay escucha en <code><?= View::e($relayHost ?: '-') ?>:<?= View::e($relayPort) ?></code> por WireGuard. IP publica para SPF/PTR: <code><?= View::e($relayPublicIp ?: '-') ?></code>.
                </div>
                <div class="alert alert-info py-2 mb-3">
                    <div class="fw-semibold mb-1">Flujo correcto en Relay Privado</div>
                    <div class="small">
                        Aqui no se crean buzones. Primero autoriza el dominio remitente para DKIM/SPF; despues ve a
                        <a href="/mail?tab=deliverability" class="text-info">Entregabilidad</a>, copia los DNS recomendados en tu proveedor DNS y vuelve a refrescar esta fila.
                    </div>
                </div>

                <?php if (!$isSlave): ?>
                <form method="post" action="/mail/relay/domains/store" class="row g-2 mb-3">
                    <?= View::csrf() ?>
                    <div class="col">
                        <input class="form-control form-control-sm" name="domain" placeholder="example.com" required>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-info btn-sm"><i class="bi bi-plus-lg me-1"></i>Añadir dominio</button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if (empty($relayDomains)): ?>
                    <div class="text-muted small">Aun no hay dominios autorizados. Al añadir uno se genera DKIM y se muestran los TXT en Entregabilidad.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Dominio</th>
                                    <th>SPF</th>
                                    <th>DKIM</th>
                                    <th>DMARC</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($relayDomains as $rd): ?>
                                <?php
                                    $okBadge = static fn($ok) => $ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-warning text-dark">Pendiente</span>';
                                    $status = (string)($rd['status'] ?? 'pending');
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= View::e($rd['domain']) ?></td>
                                    <td><?= $okBadge($relayTruthy($rd['spf_verified'] ?? false)) ?></td>
                                    <td><?= $okBadge($relayTruthy($rd['dkim_verified'] ?? false)) ?></td>
                                    <td><?= $okBadge($relayTruthy($rd['dmarc_verified'] ?? false)) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $status === 'active' ? 'success' : 'secondary' ?>"><?= View::e($status) ?></span>
                                        <?php if ($status !== 'active'): ?>
                                            <div class="small text-muted mt-1">Publica DNS y refresca</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$isSlave): ?>
                                            <form method="post" action="/mail/relay/domains/<?= (int)$rd['id'] ?>/refresh" class="d-inline">
                                                <?= View::csrf() ?>
                                                <button class="btn btn-outline-info btn-sm" title="Revisar DNS"><i class="bi bi-arrow-clockwise"></i></button>
                                            </form>
                                            <form method="post" action="/mail/relay/domains/<?= (int)$rd['id'] ?>/delete" class="d-inline" onsubmit="return confirm('Eliminar dominio del relay?')">
                                                <?= View::csrf() ?>
                                                <button class="btn btn-outline-danger btn-sm" title="Eliminar"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100" style="border-color:rgba(14,165,233,.28);">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-lock me-2"></i>Usuarios SMTP del relay</span>
                <span class="text-muted small">SASL / sasldb2</span>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-3">
                    Laravel/SaaS remotos usan <code><?= View::e($relayHost ?: 'IP_WIREGUARD') ?>:<?= View::e($relayPort) ?></code>,
                    cifrado <code>STARTTLS</code>, usuario SMTP y la password generada al crear el usuario.
                </div>
                <?php if (!$isSlave): ?>
                <form method="post" action="/mail/relay/users/store" class="row g-2 mb-3">
                    <?= View::csrf() ?>
                    <div class="col-md-4"><input class="form-control form-control-sm" name="username" placeholder="web01-relay" required></div>
                    <div class="col-md-4"><input class="form-control form-control-sm" name="description" placeholder="Apps del servidor web"></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="rate_limit_per_hour" type="number" min="1" value="200"></div>
                    <div class="col-md-2"><button class="btn btn-info btn-sm w-100">Crear</button></div>
                    <div class="col-12"><input class="form-control form-control-sm" name="allowed_from_domains" placeholder="Dominios permitidos opcional: example.com, example.net"></div>
                </form>
                <?php endif; ?>

                <?php if (empty($relayUsers)): ?>
                    <div class="text-muted small">Aun no hay usuarios SMTP. Cada servidor/app cliente debe tener su propio usuario para poder rotarlo sin afectar al resto.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Descripcion</th>
                                    <th>Limite</th>
                                    <th>Recovery</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($relayUsers as $ru): ?>
                                <tr>
                                    <td class="fw-semibold"><?= View::e($ru['username']) ?></td>
                                    <td class="text-muted"><?= View::e($ru['description'] ?: '-') ?></td>
                                    <td><?= (int)($ru['rate_limit_per_hour'] ?? 200) ?>/h</td>
                                    <td>
                                        <?php if ($relayTruthy($ru['has_recoverable_password'] ?? false)): ?>
                                            <span class="badge bg-success">cifrada</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark" title="Usuario creado antes de guardar password recuperable. Regeneralo antes de migrar.">legacy</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $relayTruthy($ru['enabled'] ?? true) ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
                                    <td class="text-end">
                                        <?php if (!$isSlave): ?>
                                            <form method="post" action="/mail/relay/users/<?= (int)$ru['id'] ?>/delete" onsubmit="return confirm('Eliminar usuario SMTP del relay?')" class="d-inline">
                                                <?= View::csrf() ?>
                                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="mt-3 p-3 rounded" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold small mb-2">Ejemplo Laravel</div>
                    <pre class="small mb-0" style="color:#cbd5e1;white-space:pre-wrap;">MAIL_MAILER=smtp
MAIL_HOST=<?= View::e($relayHost ?: 'IP_WIREGUARD') . "\n" ?>MAIL_PORT=<?= View::e($relayPort) . "\n" ?>MAIL_USERNAME=USUARIO_RELAY
MAIL_PASSWORD=PASSWORD_GENERADA
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com</pre>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card" style="border-color:rgba(14,165,233,.18);">
            <div class="card-header"><i class="bi bi-activity me-2"></i>Ultimos envios del relay</div>
            <div class="card-body p-0">
                <?php if (empty($relayLogs)): ?>
                    <div class="p-3 text-muted small">Sin entradas recientes con estado <code>sent</code>, <code>deferred</code> o <code>bounced</code> en mail.log.</div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Hora</th><th>Dominio</th><th>From</th><th>To</th><th>Estado</th><th>Detalle</th></tr></thead>
                        <tbody>
                            <?php foreach ($relayLogs as $log): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= View::e($log['timestamp'] ?? '') ?></td>
                                <td><?= View::e($log['domain'] ?: '-') ?></td>
                                <td class="text-muted"><?= View::e($log['from'] ?: '-') ?></td>
                                <td class="text-muted"><?= View::e($log['to'] ?: '-') ?></td>
                                <td><span class="badge bg-<?= ($log['status'] ?? '') === 'sent' ? 'success' : (($log['status'] ?? '') === 'bounced' ? 'danger' : 'warning') ?>"><?= View::e($log['status'] ?? '-') ?></span></td>
                                <td class="small text-muted" title="<?= View::e($log['line'] ?? '') ?>">
                                    <?php if (!empty($log['detail'])): ?>
                                        <?= View::e($log['detail']) ?>
                                    <?php else: ?>
                                        <?= View::e(trim((($log['dsn'] ?? '') ? 'dsn=' . $log['dsn'] : '') . ' ' . (($log['relay'] ?? '') ? 'relay=' . $log['relay'] : '')) ?: '-') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php if (!$isSlave): ?>
<div class="mail-tab-pane<?= $activeTab === 'migration' ? '' : ' d-none' ?>" data-mail-tab="migration">
<div class="card mb-4" style="border-color:rgba(59,130,246,.22);">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-truck me-2"></i>Migrador de correo</span>
        <span class="text-muted small">Preflight seguro y migracion relay privado</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold mb-2">Preflight</div>
                    <div class="small text-muted mb-3">
                        Comprueba el nodo destino antes de mover nada: paquetes instalados, claves recuperables, usuarios SASL, espacio y bloqueos conocidos.
                    </div>
                    <form method="post" action="/mail/migrations/preflight" class="row g-2 align-items-end">
                        <?= View::csrf() ?>
                        <div class="col-md-4">
                            <label class="form-label small">Modo</label>
                            <select name="mode" class="form-select form-select-sm">
                                <option value="relay" <?= ($mailMode ?? '') === 'relay' ? 'selected' : '' ?>>Relay privado</option>
                                <option value="satellite" <?= ($mailMode ?? '') === 'satellite' ? 'selected' : '' ?>>Solo envio</option>
                                <option value="full" <?= ($mailMode ?? '') === 'full' ? 'selected' : '' ?>>Correo completo</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">Nodo destino</label>
                            <select name="target_node_id" class="form-select form-select-sm" required>
                                <option value="">Selecciona nodo</option>
                                <?php foreach (($clusterNodes ?? []) as $node): ?>
                                    <option value="<?= (int)$node['id'] ?>"><?= View::e($node['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-outline-info btn-sm w-100">Comprobar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="p-3 rounded h-100" style="background:#0f172a;border:1px solid #334155;">
                    <div class="fw-semibold mb-2">Migrar relay privado</div>
                    <div class="small text-muted mb-3">
                        Copia dominios DKIM y usuarios SASL al nodo destino. Los usuarios antiguos sin password cifrada deben regenerarse antes.
                    </div>
                    <form method="post" action="/mail/migrations/relay/execute" class="row g-2 align-items-end">
                        <?= View::csrf() ?>
                        <div class="col-md-5">
                            <label class="form-label small">Nodo destino</label>
                            <select name="target_node_id" class="form-select form-select-sm" required>
                                <option value="">Selecciona nodo</option>
                                <?php foreach (($clusterNodes ?? []) as $node): ?>
                                    <option value="<?= (int)$node['id'] ?>"><?= View::e($node['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Password admin</label>
                            <input name="admin_password" type="password" class="form-control form-control-sm" autocomplete="new-password" required>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-warning btn-sm w-100" onclick="return confirm('Migrar relay privado al nodo destino?')">Migrar</button>
                        </div>
                        <div class="col-12">
                            <label class="small text-muted">
                                <input type="checkbox" name="switch_routing" value="1" class="form-check-input me-1">
                                Cambiar el nodo activo del relay al terminar
                            </label>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($mailMigrations)): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Modo</th>
                            <th>Destino</th>
                            <th>Estado</th>
                            <th>Etapa</th>
                            <th>Resumen</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mailMigrations as $mig): ?>
                            <?php
                                $progress = json_decode((string)($mig['progress_json'] ?? '{}'), true);
                                $progress = is_array($progress) ? $progress : [];
                                $status = (string)($mig['status'] ?? 'pending');
                                $statusClass = $status === 'completed' || $status === 'preflight_ok' ? 'success' : (in_array($status, ['failed', 'blocked'], true) ? 'danger' : 'warning');
                            ?>
                            <tr>
                                <td>#<?= (int)$mig['id'] ?></td>
                                <td><?= View::e($mig['mode'] ?? '-') ?></td>
                                <td><?= View::e($mig['target_node_name'] ?? '-') ?></td>
                                <td><span class="badge bg-<?= $statusClass ?>"><?= View::e($status) ?></span></td>
                                <td class="text-muted"><?= View::e($mig['stage'] ?? '-') ?></td>
                                <td class="small text-muted"><?= View::e($progress['summary'] ?? ($mig['error_message'] ?? '-')) ?></td>
                                <td class="small text-muted"><?= View::e($mig['created_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php endif; ?>

<!-- Stats cards -->
<div class="mail-tab-pane<?= $activeTab === 'general' ? '' : ' d-none' ?>" data-mail-tab="general">
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
</div>

<!-- Local Mail Server Status -->
<div class="mail-tab-pane<?= $activeTab === 'infra' ? '' : ' d-none' ?>" data-mail-tab="infra">
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
        <?php if (!empty($localRepair['partial']) || !empty($localRepair['needs_repair'])): ?>
            <div class="card mb-4" style="border-color:rgba(251,191,36,.35);">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <div class="fw-semibold"><i class="bi bi-tools me-2 text-warning"></i>Hay restos de una instalacion anterior</div>
                            <div class="small text-muted">Antes de reinstalar, prueba el reparador. Si la IP WireGuard anterior no era correcta, cambia modo y vuelve a instalar despues.</div>
                        </div>
                        <form method="post" action="/mail/repair-local" class="d-flex gap-2" data-mail-repair-form>
                            <?= View::csrf() ?>
                            <input type="password" name="admin_password" class="form-control form-control-sm" placeholder="Password admin" required autocomplete="current-password">
                            <button class="btn btn-warning btn-sm text-dark fw-semibold"><i class="bi bi-wrench-adjustable me-1"></i>Reparar</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php include __DIR__ . '/setup-node.php'; ?>
    <?php elseif (empty($mailNodes) && !($mailLocalConfigured ?? false)): ?>
        <div class="card mb-4" style="border: 1px solid rgba(13, 202, 240, 0.25);">
            <div class="card-body py-3">
                <i class="bi bi-info-circle text-info me-2"></i>
                <strong>Mail Setup:</strong> Para usar correo, primero
                <a href="/mail?tab=infra&amp;setup=1" class="text-info">configura un servidor de mail</a> (local o en un nodo remoto del cluster).
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Deliverability -->
<div class="mail-tab-pane<?= $activeTab === 'deliverability' ? '' : ' d-none' ?>" data-mail-tab="deliverability">
<div class="card mt-4 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-shield-check me-2"></i>Entregabilidad DNS</span>
        <span class="text-muted small">SPF, DKIM, DMARC, PTR y blacklists</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Comprueba si el dominio tiene los registros necesarios para una entrega de correo correcta.
            Los checks leen DNS en tiempo real; los registros recomendados se pueden copiar al proveedor DNS.
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
                    $score = (int)($row['score'] ?? 0);
                    $scoreTotal = (int)($row['score_total'] ?? 5);
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
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-<?= $score >= $scoreTotal ? 'success' : ($score >= 3 ? 'warning text-dark' : 'danger') ?>">
                                Puntuacion <?= $score ?>/<?= $scoreTotal ?>
                            </span>
                            <button class="btn btn-outline-light btn-sm" type="button" onclick="copyDnsRecords(this)" data-records="<?= View::e($copyText) ?>">
                                <i class="bi bi-clipboard me-1"></i>Copiar DNS
                            </button>
                        </div>
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
                <div class="form-text text-muted">Envia un correo de prueba y muestra si Postfix lo entrega, lo deja en cola o lo rechaza.</div>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-outline-info"><i class="bi bi-send me-1"></i>Enviar test</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Domains list -->
<div class="mail-tab-pane<?= $activeTab === 'domains' ? '' : ' d-none' ?>" data-mail-tab="domains">
<div class="mb-4"></div>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="mb-0"><i class="bi bi-globe2 me-2"></i>Mail Domains</h6>
    <?php if (!$isSlave && ($mailModeValue ?? '') === 'full'): ?>
    <a href="/mail/domains/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Domain</a>
    <?php endif; ?>
</div>
<?php if (($mailModeValue ?? '') !== 'full'): ?>
    <div class="alert alert-info">
        <strong>Este modo no usa Mail Domains de buzones.</strong>
        En <?= View::e($modeInfo[0] ?? 'este modo') ?> gestiona el correo desde su pestaña correspondiente. Para Relay Privado usa <a class="text-info" href="/mail?tab=relay">Dominios autorizados para relay</a>.
    </div>
<?php endif; ?>
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($domains)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-envelope" style="font-size: 2rem;"></i>
                <p class="mt-2">No mail domains yet.</p>
                <?php if (!$isSlave && ($mailModeValue ?? '') === 'full'): ?>
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
</div>

<script>
function syncWebmailInstallForm() {
    const map = [
        ['webmail_provider', 'webmail_install_provider'],
        ['webmail_host', 'webmail_install_host'],
        ['webmail_imap_host', 'webmail_install_imap_host'],
        ['webmail_smtp_host', 'webmail_install_smtp_host'],
    ];
    for (const [src, dst] of map) {
        const s = document.getElementById(src);
        const d = document.getElementById(dst);
        if (s && d) d.value = s.value;
    }
}

function copyDnsRecords(btn) {
    const text = btn.getAttribute('data-records') || '';
    navigator.clipboard.writeText(text).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado';
        setTimeout(() => btn.innerHTML = original, 1500);
    }).catch(() => alert(text));
}

(function initMailTabs() {
    const links = Array.from(document.querySelectorAll('[data-mail-tab-link]'));
    const panes = Array.from(document.querySelectorAll('.mail-tab-pane[data-mail-tab]'));
    if (!links.length || !panes.length) return;

    const validTabs = Array.from(new Set(panes.map((pane) => pane.dataset.mailTab)));
    const initialFallback = <?= json_encode($activeTab, JSON_UNESCAPED_SLASHES) ?>;

    const pickTabFromUrl = () => {
        const url = new URL(window.location.href);
        const fromQuery = url.searchParams.get('tab');
        if (fromQuery && validTabs.includes(fromQuery)) return fromQuery;
        if (url.hash === '#webmail' && validTabs.includes('webmail')) return 'webmail';
        return '';
    };

    const applyTab = (tab, updateUrl) => {
        let nextTab = validTabs.includes(tab) ? tab : initialFallback;
        if (!validTabs.includes(nextTab)) {
            nextTab = validTabs[0];
        }

        panes.forEach((pane) => pane.classList.toggle('d-none', pane.dataset.mailTab !== nextTab));

        links.forEach((link) => {
            const active = link.dataset.mailTabLink === nextTab;
            link.classList.toggle('btn-info', active);
            link.classList.toggle('btn-outline-light', !active);
            if (active) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        try { sessionStorage.setItem('musedock_mail_tab', nextTab); } catch (err) {}

        if (!updateUrl) return;

        const url = new URL(window.location.href);
        url.searchParams.set('tab', nextTab);
        if (nextTab !== 'infra') {
            url.searchParams.delete('setup');
        }
        url.hash = nextTab === 'webmail' ? 'webmail' : '';
        history.replaceState(null, '', url.toString());
    };

    let initialTab = pickTabFromUrl();
    if (!initialTab) {
        try {
            const savedTab = sessionStorage.getItem('musedock_mail_tab');
            if (savedTab && validTabs.includes(savedTab)) {
                initialTab = savedTab;
            }
        } catch (err) {}
    }
    if (!initialTab) initialTab = initialFallback;

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            applyTab(link.dataset.mailTabLink || '', true);
        });
    });

    window.addEventListener('popstate', () => {
        applyTab(pickTabFromUrl() || initialFallback, false);
    });

    applyTab(initialTab, false);
})();

(function initMailRepairForms() {
    const forms = Array.from(document.querySelectorAll('[data-mail-repair-form]'));
    if (!forms.length) return;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));

    const formatMessages = (messages) => {
        const list = Array.isArray(messages) ? messages : [];
        if (!list.length) return '<div class="text-muted small">Sin detalle adicional.</div>';
        return '<pre class="text-start small mb-0 p-2 rounded" style="background:#0f172a;color:#e2e8f0;white-space:pre-wrap;max-height:320px;overflow:auto;">'
            + escapeHtml(list.join("\n"))
            + '</pre>';
    };

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const passwordInput = form.querySelector('[name="admin_password"]');
            if (passwordInput && !passwordInput.value) {
                await SwalDark.fire({ icon: 'warning', title: 'Password requerido', text: 'Introduce tu password admin para reparar mail.' });
                passwordInput.focus();
                return;
            }

            const confirm = await SwalDark.fire({
                icon: 'warning',
                title: 'Reparar instalacion local de mail',
                html: '<div class="text-start small">'
                    + '<p>Se preparara OpenDKIM, se corregira el socket local, se reiniciaran <code>opendkim</code> y <code>postfix</code>, y el panel solo marcara mail como instalado si ambos servicios quedan activos.</p>'
                    + '<p class="mb-0 text-warning">Puede tardar uno o dos minutos si systemd o apt estan lentos.</p>'
                    + '</div>',
                showCancelButton: true,
                confirmButtonText: 'Reparar ahora',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            });
            if (!confirm.isConfirmed) return;

            const button = form.querySelector('button[type="submit"], button:not([type])');
            const originalHtml = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reparando...';
            }

            SwalDark.fire({
                title: 'Reparando mail local...',
                html: '<div class="text-start small">'
                    + '<div>1. Preparando runtime de OpenDKIM</div>'
                    + '<div>2. Corrigiendo socket y permisos</div>'
                    + '<div>3. Reiniciando OpenDKIM/Postfix</div>'
                    + '<div>4. Verificando servicios</div>'
                    + '</div>',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const contentType = response.headers.get('content-type') || '';
                let data;
                if (contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error('Respuesta no JSON del servidor:\n' + text.slice(0, 1200));
                }

                if (!response.ok || !data.ok) {
                    await SwalDark.fire({
                        icon: 'error',
                        title: data.message || 'No se pudo reparar',
                        html: '<div class="text-start small mb-2">' + escapeHtml(data.error || 'El reparador devolvio error.') + '</div>' + formatMessages(data.messages),
                        width: 760,
                    });
                    return;
                }

                await SwalDark.fire({
                    icon: 'success',
                    title: data.message || 'Mail reparado',
                    html: formatMessages(data.messages),
                    width: 760,
                    confirmButtonText: 'Recargar Mail',
                });
                window.location.href = '/mail?tab=infra';
            } catch (err) {
                await SwalDark.fire({
                    icon: 'error',
                    title: 'Error interno o de conexion',
                    html: '<pre class="text-start small mb-0 p-2 rounded" style="background:#0f172a;color:#fca5a5;white-space:pre-wrap;max-height:320px;overflow:auto;">' + escapeHtml(err.message || err) + '</pre>',
                    width: 760,
                });
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                }
            }
        });
    });
})();

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
