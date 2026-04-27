<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<div class="row g-3">
    <!-- Server Info (read-only) -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Informacion del Servidor</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted" style="width:40%">Hostname</td><td><strong><?= View::e($hostname) ?></strong></td></tr>
                    <tr><td class="text-muted">OS</td><td><strong><?= View::e($distro) ?></strong></td></tr>
                    <tr><td class="text-muted">Kernel</td><td><?= View::e($os) ?></td></tr>
                    <tr><td class="text-muted">IP del servidor</td><td>
                        <strong><?= View::e($serverIp) ?></strong>
                        <button class="btn btn-sm btn-outline-light ms-2 py-0" onclick="navigator.clipboard.writeText('<?= View::e($serverIp) ?>')"><i class="bi bi-clipboard"></i></button>
                    </td></tr>
                    <tr><td class="text-muted">Uptime</td><td><?= View::e($uptime) ?></td></tr>
                    <tr><td class="text-muted">PHP</td><td><?= PHP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Panel</td><td>v<?= View::e($panelVersion ?? '0.1.0') ?></td></tr>
                    <tr><td class="text-muted">Timezone</td><td><?= View::e($currentTz) ?> — <?= date('H:i:s T') ?></td></tr>
                    <tr>
                        <td class="text-muted">NTP</td>
                        <td>
                            <?php if ($ntpActive && $ntpSynced): ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;"><i class="bi bi-check-circle me-1"></i>Synchronized</span>
                            <?php elseif ($ntpActive): ?>
                                <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Active (not synced)</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                            <?php endif; ?>
                            <?php if (!empty($ntpServer)): ?>
                                <small class="text-muted ms-2"><?= View::e($ntpServer) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel URL & Timezone (editable) -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-gear me-1"></i> Configuracion del Panel</div>
            <div class="card-body">
                <form method="POST" action="/settings/server/save" id="server-settings-form">
                    <?= View::csrf() ?>
                    <input type="hidden" name="panel_acme_firewall_assist" id="panel_acme_firewall_assist" value="0">
                    <input type="hidden" name="admin_password" id="panel_acme_admin_password" value="">

                    <div class="mb-3">
                        <label class="form-label">Zona horaria del servidor</label>
                        <select name="timezone" class="form-select">
                            <?php foreach ($timezones as $tz): ?>
                            <option value="<?= View::e($tz) ?>" <?= $tz === $currentTz ? 'selected' : '' ?>><?= View::e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Actual: <?= View::e($currentTz) ?> — <?= date('H:i:s T') ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Dominio del panel <small class="text-muted">(opcional)</small></label>
                        <input type="text" name="panel_hostname" class="form-control" value="<?= View::e($settings['panel_hostname'] ?? '') ?>" placeholder="panel.ejemplo.com">
                        <small class="text-muted">Al guardar, el panel crea/actualiza la ruta HTTPS en Caddy para este dominio en el puerto <?= (int)$panelPort ?>.</small>
                    </div>

                    <div class="mb-3">
                        <?php
                            $panelTlsMode = (string)($settings['panel_tls_mode'] ?? 'self_signed');
                            if (!in_array($panelTlsMode, ['self_signed', 'http01', 'dns01'], true)) {
                                $panelTlsMode = 'self_signed';
                            }
                            $panelHostnameConfigured = trim((string)($settings['panel_hostname'] ?? '')) !== '';
                            $panelDnsProviders = array_values(array_filter(array_map('strval', $panelDnsProviders ?? [])));
                            $installedDnsProviderSet = array_fill_keys($panelDnsProviders, true);
                            $panelDnsProviderCatalog = is_array($panelDnsProviderCatalog ?? null) ? $panelDnsProviderCatalog : [];
                            $installableDnsProviders = array_values(array_filter(array_keys($panelDnsProviderCatalog), static fn($name) => !isset($installedDnsProviderSet[$name])));
                            $selectedDnsProvider = strtolower(trim((string)($settings['panel_dns_provider'] ?? '')));
                            if ($selectedDnsProvider !== '' && !in_array($selectedDnsProvider, $panelDnsProviders, true)) {
                                array_unshift($panelDnsProviders, $selectedDnsProvider);
                            }
                            $dnsProviderExamples = [];
                            foreach ($panelDnsProviders as $dnsProviderName) {
                                $dnsProviderExamples[$dnsProviderName] = (string)($panelDnsProviderCatalog[$dnsProviderName]['example'] ?? '{"api_token":"..."}');
                            }
                        ?>
                        <label class="form-label">TLS del panel (puerto <?= (int)$panelPort ?>)</label>
                        <select class="form-select" name="panel_tls_mode" id="panel_tls_mode">
                            <option value="self_signed" <?= $panelTlsMode === 'self_signed' ? 'selected' : '' ?>>Certificado interno/autofirmado (recomendado para admin privado)</option>
                            <option value="http01" <?= $panelTlsMode === 'http01' ? 'selected' : '' ?>>Let's Encrypt HTTP-01 / TLS-ALPN-01</option>
                            <option value="dns01" <?= $panelTlsMode === 'dns01' ? 'selected' : '' ?>>Let's Encrypt DNS-01 (proveedor DNS con API)</option>
                        </select>
                        <small class="text-muted">
                            Recomendado: <strong>Let's Encrypt</strong> para dominios publicos. Usa <strong>self-signed</strong> solo para acceso por IP o hostname privado.
                        </small>
                        <?php if ($panelHostnameConfigured && $panelTlsMode === 'http01'): ?>
                            <div class="small text-warning mt-1">Con dominio configurado, el panel evitara certificados internos para prevenir bloqueos HSTS del navegador.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3" id="acme_email_wrap" style="<?= in_array($panelTlsMode, ['http01', 'dns01'], true) ? '' : 'display:none;' ?>">
                        <label class="form-label">Email ACME</label>
                        <input type="email" name="panel_acme_email" class="form-control" value="<?= View::e($settings['panel_acme_email'] ?? '') ?>" placeholder="webmaster@domain.com">
                        <small class="text-muted">Solo se usa en modos HTTP-01/DNS-01 para Let's Encrypt (avisos de expiracion).</small>
                    </div>

                    <div class="mb-3" id="dns_provider_wrap" style="<?= $panelTlsMode === 'dns01' ? '' : 'display:none;' ?>">
                        <label class="form-label">Proveedor DNS (modulo Caddy)</label>
                        <?php if (!empty($panelDnsProviders)): ?>
                            <select name="panel_dns_provider" id="panel_dns_provider" class="form-select">
                                <option value="">Selecciona proveedor instalado...</option>
                                <?php foreach ($panelDnsProviders as $dnsProviderName): ?>
                                    <?php
                                        $isInstalledDnsProvider = isset($installedDnsProviderSet[$dnsProviderName]);
                                        $dnsProviderLabel = (string)($panelDnsProviderCatalog[$dnsProviderName]['label'] ?? ucfirst(str_replace(['_', '-'], ' ', $dnsProviderName)));
                                        $dnsProviderSuffix = $isInstalledDnsProvider ? '' : ' (no instalado)';
                                    ?>
                                    <option value="<?= View::e($dnsProviderName) ?>"
                                            data-example="<?= View::e($dnsProviderExamples[$dnsProviderName] ?? '{"api_token":"..."}') ?>"
                                            <?= $selectedDnsProvider === $dnsProviderName ? 'selected' : '' ?>>
                                        <?= View::e($dnsProviderLabel . $dnsProviderSuffix) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Detectados desde <code>caddy list-modules</code>. El modulo usado sera <code>dns.providers.&lt;proveedor&gt;</code>.</small>
                        <?php else: ?>
                            <input type="text" name="panel_dns_provider" id="panel_dns_provider" class="form-control" value="<?= View::e($settings['panel_dns_provider'] ?? '') ?>" placeholder="cloudflare / route53 / digitalocean / hetzner / ...">
                            <small class="text-warning">Este Caddy no reporta modulos <code>dns.providers.*</code>. DNS-01 no funcionara hasta instalar un build de Caddy con el proveedor elegido.</small>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3" id="dns_provider_cfg_wrap" style="<?= $panelTlsMode === 'dns01' ? '' : 'display:none;' ?>">
                        <label class="form-label">Configuracion JSON del proveedor DNS</label>
                        <textarea name="panel_dns_provider_config" id="panel_dns_provider_config" class="form-control" rows="5" placeholder='<?= View::e($dnsProviderExamples[$selectedDnsProvider] ?? '{"api_token":"..."}') ?>'><?= View::e($settings['panel_dns_provider_config'] ?? '') ?></textarea>
                        <small class="text-muted">
                            JSON exacto que espera el modulo DNS de Caddy. No incluyas <code>name</code>; MuseDock lo rellena con el proveedor seleccionado. Cloudflare puede heredar <code>CLOUDFLARE_API_TOKEN</code> si dejas este campo vacio.
                        </small>
                    </div>

                    <div class="mb-3" id="dns_provider_install_wrap" style="<?= $panelTlsMode === 'dns01' ? '' : 'display:none;' ?>">
                        <div class="rounded p-3" style="border:1px solid rgba(56,189,248,0.28); background:rgba(14,165,233,0.08);">
                            <div class="fw-semibold mb-2"><i class="bi bi-box-arrow-down me-1"></i>Instalar modulo DNS en Caddy</div>
                            <div class="small text-muted mb-2">
                                Caddy no carga proveedores DNS en caliente: MuseDock recompila el binario con <code>xcaddy</code>, guarda backup, reinicia Caddy y hace rollback si no queda activo.
                            </div>
                            <?php $dnsInstallStatus = is_array($caddyDnsProviderInstallStatus ?? null) ? $caddyDnsProviderInstallStatus : []; ?>
                            <?php if (!empty($dnsInstallStatus)): ?>
                                <?php
                                    $dnsInstallState = (string)($dnsInstallStatus['status'] ?? '');
                                    $dnsInstallClass = $dnsInstallState === 'ok' ? 'success' : ($dnsInstallState === 'running' ? 'info' : 'danger');
                                ?>
                                <div class="alert alert-<?= View::e($dnsInstallClass) ?> py-2 small mb-3">
                                    <strong><?= View::e((string)($dnsInstallStatus['provider'] ?? 'provider')) ?>:</strong>
                                    <?= View::e((string)($dnsInstallStatus['message'] ?? '')) ?>
                                    <?php if (!empty($dnsInstallStatus['backup'])): ?>
                                        <span class="d-block text-muted mt-1">Backup: <code><?= View::e((string)$dnsInstallStatus['backup']) ?></code></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($installableDnsProviders)): ?>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-7">
                                        <label class="form-label small">Proveedor a instalar</label>
                                        <select name="dns_provider" id="caddy_dns_provider_install" class="form-select form-select-sm">
                                            <?php foreach ($installableDnsProviders as $dnsProviderName): ?>
                                                <?php $dnsProviderLabel = (string)($panelDnsProviderCatalog[$dnsProviderName]['label'] ?? ucfirst(str_replace(['_', '-'], ' ', $dnsProviderName))); ?>
                                                <option value="<?= View::e($dnsProviderName) ?>">
                                                    <?= View::e($dnsProviderLabel) ?> — dns.providers.<?= View::e($dnsProviderName) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <button type="button" class="btn btn-sm btn-outline-info w-100" id="install-caddy-dns-provider-btn">
                                            <i class="bi bi-tools me-1"></i>Instalar proveedor
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="small text-success">Todos los proveedores DNS del catalogo MuseDock ya estan instalados en este Caddy.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="rounded p-3" style="border:1px solid rgba(148,163,184,0.28); background:rgba(15,23,42,0.45);">
                            <div class="fw-semibold mb-2">Guia rapida de certificacion del panel</div>
                            <div class="small text-muted mb-1"><strong>A) Self-signed:</strong> no depende de Internet ni ACME. Ideal para panel privado con firewall estricto.</div>
                            <div class="small text-muted mb-1"><strong>B) HTTP-01/TLS-ALPN-01:</strong> requiere puertos 80/443 alcanzables desde Internet durante emision/renovacion.</div>
                            <div class="small text-muted"><strong>C) DNS-01:</strong> no requiere abrir 80/443 para certificar, pero exige proveedor DNS con API y modulo Caddy instalado.</div>
                        </div>
                    </div>

                    <?php
                        $panelAcmeFirewallStatus = is_array($panelAcmeFirewallStatus ?? null) ? $panelAcmeFirewallStatus : [];
                        $panelAcmeMissingPorts = array_values(array_filter(array_map('intval', $panelAcmeFirewallStatus['missing'] ?? [])));
                        $panelAcmeFirewallType = (string)($panelAcmeFirewallStatus['type'] ?? 'none');
                    ?>
                    <?php if (!empty($panelAcmeMissingPorts)): ?>
                        <div class="mb-3" id="panel-acme-firewall-card">
                            <div class="rounded p-3" style="border:1px solid rgba(251,191,36,0.35); background:rgba(234,179,8,0.08);">
                                <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Firewall y Let's Encrypt</div>
                                <div class="small text-muted mb-1">
                                    Firewall detectado: <strong><?= View::e($panelAcmeFirewallType) ?></strong>. No hay apertura publica para:
                                    <strong><?= View::e(implode(', ', $panelAcmeMissingPorts)) ?></strong>.
                                </div>
                                <div class="small text-muted">
                                    Si guardas con HTTP-01/TLS-ALPN-01, el panel puede pedir password y abrir esos puertos temporalmente para emitir el certificado.
                                </div>
                                <button type="button"
                                        class="btn btn-sm btn-warning mt-3 panel-acme-assist-btn">
                                    <i class="bi bi-unlock me-1"></i>Abrir 80/443 y emitir certificado
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <div class="rounded p-3" style="border:1px solid rgba(251,191,36,0.35); background:rgba(234,179,8,0.08);">
                            <div class="fw-semibold mb-2"><i class="bi bi-lightning-charge me-1"></i>Formula rapida: renovar cert con firewall estricto (HTTP-01)</div>
                            <div class="small text-muted mb-2">
                                Si tu panel usa <strong>HTTP-01/TLS-ALPN-01</strong> y normalmente tienes firewall cerrado, abre temporalmente 80/443, repara Caddy y vuelve a cerrar.
                                En <strong>Ajustes &gt; Firewall</strong> ahora activar/desactivar UFW pide contrasena de administrador.
                            </div>
                            <div class="p-2 rounded mb-2" style="background:rgba(2,6,23,0.55); border:1px solid rgba(148,163,184,0.2); font-family:monospace; font-size:0.86rem; line-height:1.45;">
                                <code>ufw allow 80/tcp</code><br>
                                <code>ufw allow 443/tcp</code><br>
                                <code>cd /opt/musedock-panel &amp;&amp; php cli/repair-caddy-routes.php</code><br>
                                <code>curl -kI https://PANEL_DOMINIO:<?= (int)$panelPort ?>/login</code><br>
                                <code>ufw delete allow 80/tcp</code><br>
                                <code>ufw delete allow 443/tcp</code>
                            </div>
                            <div class="small text-muted">
                                Si no quieres abrir puertos nunca, usa modo <strong>DNS-01</strong> o <strong>self-signed</strong>.
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="rounded p-3" style="border:1px solid rgba(56,189,248,0.32); background:rgba(2,132,199,0.08);">
                            <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Notas operativas TLS (admin)</div>
                            <div class="small text-muted mb-1">Acceso de emergencia recomendado: <code>https://IP_DEL_SERVIDOR:<?= (int)$panelPort ?></code> (fallback por IP activo).</div>
                            <div class="small text-muted mb-1">Si el dominio raiz usa HSTS con <code>includeSubDomains</code>, el navegador puede bloquear subdominios admin con cert no publico.</div>
                            <div class="small text-muted">Para eliminar warnings en hostname admin sin abrir puertos, usa modo <strong>DNS-01</strong> con proveedor DNS API.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Protocolo</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <?php
                                    // Auto-detect protocol from real connection (X-Forwarded-Proto from Caddy, or HTTPS flag)
                                    $detectedProto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                                        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                                        ? 'https' : 'http';
                                    $currentProto = $detectedProto;
                                ?>
                                <input class="form-check-input" type="radio" name="panel_protocol" value="http" id="proto_http"
                                    <?= $currentProto === 'http' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_http">HTTP</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="panel_protocol" value="https" id="proto_https"
                                    <?= $currentProto === 'https' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="proto_https">HTTPS</label>
                            </div>
                        </div>
                        <small class="text-muted">HTTPS requiere un dominio configurado arriba o certificado autofirmado.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL de acceso actual</label>
                        <?php
                        $host = !empty($settings['panel_hostname']) ? $settings['panel_hostname'] : $serverIp;
                        $panelUrl = "https://{$host}:{$panelPort}";
                        $fallbackUrl = "https://{$host}:{$panelPort}";
                        ?>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= View::e($panelUrl) ?>" disabled>
                            <button class="btn btn-outline-light" type="button" onclick="navigator.clipboard.writeText('<?= View::e($panelUrl) ?>')"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <small class="text-muted d-block mt-1">Fallback emergencia: <?= View::e($fallbackUrl) ?>.</small>
                    </div>

                    <div class="mb-3">
                        <div class="rounded p-3" style="border:1px solid rgba(56,189,248,0.32); background:rgba(2,132,199,0.08);">
                            <div class="fw-semibold mb-1"><i class="bi bi-bell me-1"></i>Avisos de reinicio y paradas</div>
                            <div class="small text-muted mb-2">
                                Estos avisos se gestionan desde <a href="/settings/notifications" class="text-info">Settings → Notifications</a>.
                            </div>
                            <div class="small text-muted">
                                Estado actual reinicio:
                                <?php if (!empty($rebootNotifyEnabled)): ?>
                                    <span class="badge bg-success">activado</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">desactivado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="server-save-btn">
                        <i class="bi bi-check-lg me-1"></i><span class="server-save-label">Guardar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const modeSel = document.getElementById('panel_tls_mode');
    const acmeWrap = document.getElementById('acme_email_wrap');
    const providerWrap = document.getElementById('dns_provider_wrap');
    const providerCfgWrap = document.getElementById('dns_provider_cfg_wrap');
    const providerInstallWrap = document.getElementById('dns_provider_install_wrap');
    const providerSel = document.getElementById('panel_dns_provider');
    const providerCfg = document.getElementById('panel_dns_provider_config');

    if (!modeSel) return;
    const refreshProviderExample = () => {
        if (!providerSel || !providerCfg) return;
        const selected = providerSel.options ? providerSel.options[providerSel.selectedIndex] : null;
        const example = selected ? selected.getAttribute('data-example') : '';
        if (example) providerCfg.setAttribute('placeholder', example);
    };
    const refresh = () => {
        const mode = modeSel.value || 'self_signed';
        const acmeVisible = mode === 'http01' || mode === 'dns01';
        acmeWrap.style.display = acmeVisible ? '' : 'none';
        const dnsVisible = mode === 'dns01';
        providerWrap.style.display = dnsVisible ? '' : 'none';
        providerCfgWrap.style.display = dnsVisible ? '' : 'none';
        if (providerInstallWrap) providerInstallWrap.style.display = dnsVisible ? '' : 'none';
        refreshProviderExample();
    };
    modeSel.addEventListener('change', refresh);
    if (providerSel) providerSel.addEventListener('change', refreshProviderExample);
    refresh();
})();

(() => {
    const form = document.getElementById('server-settings-form');
    const btn = document.getElementById('server-save-btn');
    if (!form || !btn) return;
    const assistField = document.getElementById('panel_acme_firewall_assist');
    const passwordField = document.getElementById('panel_acme_admin_password');
    const assistButtons = form.querySelectorAll('.panel-acme-assist-btn');
    const installDnsProviderBtn = document.getElementById('install-caddy-dns-provider-btn');
    const installDnsProviderSelect = document.getElementById('caddy_dns_provider_install');
    const missingAcmePorts = <?= json_encode(array_values($panelAcmeMissingPorts ?? []), JSON_UNESCAPED_SLASHES) ?>;
    const firewallCard = document.getElementById('panel-acme-firewall-card');

    const hostnameNeedsPublicTls = (value) => {
        const host = String(value || '').trim().toLowerCase().replace(/^https?:\/\//, '').split('/')[0].replace(/:\d+$/, '').replace(/\.$/, '');
        return host.includes('.') && !host.endsWith('.local') && !host.endsWith('.localhost') && !host.endsWith('.lan') && !host.endsWith('.internal') && !host.endsWith('.test');
    };

    const setSaving = () => {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando y aplicando TLS...';
    };

    const scrollToAcmeCard = () => {
        if (!firewallCard) return;
        firewallCard.scrollIntoView({behavior: 'smooth', block: 'center'});
        firewallCard.classList.add('shadow');
        window.setTimeout(() => firewallCard.classList.remove('shadow'), 1800);
    };

    const askAdminPassword = (message, confirmText, callback, onCancel = null) => {
        if (window.Swal) {
            Swal.fire({
                icon: 'warning',
                title: 'Confirmacion de firewall',
                html: '<div class="text-start small">' + message + '</div>' +
                    '<label for="swal-panel-acme-password" class="form-label small mt-3 mb-1">Contrasena admin</label>' +
                    '<input type="password" id="swal-panel-acme-password" class="swal2-input m-0" style="width:100%;background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Tu contrasena de administrador" autocomplete="current-password">',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancelar',
                focusConfirm: false,
                preConfirm: () => {
                    const pwd = document.getElementById('swal-panel-acme-password')?.value || '';
                    if (!pwd) {
                        Swal.showValidationMessage('Introduce la contrasena de administrador');
                        return false;
                    }
                    return pwd;
                }
            }).then((result) => {
                if (!result.isConfirmed) {
                    if (typeof onCancel === 'function') onCancel();
                    return;
                }
                callback(result.value || '');
            });
            return;
        }

        const ok = window.confirm(message + '\n\nAceptar para introducir la contrasena y continuar.');
        if (!ok) {
            if (typeof onCancel === 'function') onCancel();
            return;
        }
        const pwd = window.prompt('Contrasena admin');
        if (!pwd) {
            if (typeof onCancel === 'function') onCancel();
            return;
        }
        callback(pwd);
    };

    assistButtons.forEach((assistBtn) => {
        assistBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const message = 'Se abriran temporalmente los puertos ' + (missingAcmePorts.length ? missingAcmePorts.join(', ') : '80, 443') + ' durante 30 minutos para que Lets Encrypt pueda validar el dominio. Despues el panel quitara solo esas reglas temporales.';
            askAdminPassword(message, 'Abrir y emitir', (pwd) => {
                assistField.value = '1';
                passwordField.value = pwd;
                assistBtn.disabled = true;
                assistBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Abriendo y emitiendo...';
                form.action = '/settings/server/acme-assist';
                form.submit();
            });
        });
    });

    if (installDnsProviderBtn && installDnsProviderSelect) {
        installDnsProviderBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const selected = installDnsProviderSelect.value || '';
            if (!selected) return;
            const message = 'MuseDock va a lanzar en segundo plano la compilacion de Caddy con dns.providers.' + selected + '. Se hara backup del binario actual, se reemplazara Caddy, se reiniciara el servicio y se hara rollback automatico si no queda activo. Durante unos segundos el panel puede pausar conexiones.';
            askAdminPassword(message, 'Iniciar instalacion', (pwd) => {
                passwordField.value = pwd;
                installDnsProviderBtn.disabled = true;
                installDnsProviderBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Iniciando...';
                form.action = '/settings/server/dns-provider/install';
                form.submit();
            });
        });
    }

    form.addEventListener('submit', (ev) => {
        const mode = document.getElementById('panel_tls_mode')?.value || 'self_signed';
        const hostname = form.querySelector('[name="panel_hostname"]')?.value || '';
        const alreadyAssisted = assistField && assistField.value === '1';

        if (mode === 'http01' && missingAcmePorts.length > 0 && hostnameNeedsPublicTls(hostname) && !alreadyAssisted) {
            ev.preventDefault();
            const message = 'El firewall no tiene apertura publica para los puertos ' + missingAcmePorts.join(', ') + '. Lets Encrypt HTTP-01/TLS-ALPN-01 puede fallar con timeout. Puedes cancelar e ir al bloque Firewall y Lets Encrypt para usar el boton "Abrir 80/443 y emitir certificado", o aceptar ahora para abrirlos temporalmente durante 30 minutos y guardar.';

            askAdminPassword(message, 'Abrir y guardar', (pwd) => {
                assistField.value = '1';
                passwordField.value = pwd;
                setSaving();
                form.submit();
            }, scrollToAcmeCard);
            return;
        }

        setSaving();
    });
})();

</script>
