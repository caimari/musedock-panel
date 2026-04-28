<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php
    $catalog = is_array($panelDnsProviderCatalog ?? null) ? $panelDnsProviderCatalog : [];
    $installed = is_array($installedDnsProviders ?? null) ? $installedDnsProviders : [];
    $installedSet = is_array($installedDnsProviderSet ?? null) ? $installedDnsProviderSet : [];
    $selected = (string)($selectedProvider ?? '');
    $providerConfigRaw = (string)($providerConfigRaw ?? '');
    $panelHostname = trim((string)($settings['panel_hostname'] ?? ''));
    $panelTlsMode = (string)($panelTlsMode ?? 'self_signed');
    $installStatus = is_array($caddyDnsProviderInstallStatus ?? null) ? $caddyDnsProviderInstallStatus : [];
    $cloudflareEnvTokenPresent = !empty($cloudflareEnvTokenPresent);
    $panelPort = (int)($panelPort ?? 8444);
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">TLS del panel</div>
                <div class="h4 mb-1"><?= View::e(strtoupper($panelTlsMode)) ?></div>
                <div class="small text-muted">
                    <?= $panelHostname !== '' ? View::e($panelHostname . ':' . $panelPort) : 'Sin dominio de panel configurado' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Proveedor DNS-01 guardado</div>
                <div class="h4 mb-1"><?= $selected !== '' ? View::e($catalog[$selected]['label'] ?? $selected) : 'Ninguno' ?></div>
                <div class="small <?= $selected !== '' && isset($installedSet[$selected]) ? 'text-success' : 'text-muted' ?>">
                    <?= $selected !== '' && isset($installedSet[$selected]) ? 'Modulo Caddy instalado' : 'Selecciona proveedor y modulo' ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Modulos DNS en Caddy</div>
                <div class="h4 mb-1"><?= count($installed) ?></div>
                <div class="small text-muted"><?= !empty($installed) ? View::e(implode(', ', $installed)) : 'Ningun dns.providers.* detectado' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-globe2 me-1"></i> Proveedor DNS para DNS-01 del panel</span>
                <a href="/docs/panel-tls-dns01" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-journal-text me-1"></i>Guia DNS-01
                </a>
            </div>
            <div class="card-body">
                <form method="POST" action="/settings/dns/save" id="dns-provider-form">
                    <?= View::csrf() ?>

                    <div class="mb-3">
                        <label class="form-label">Proveedor DNS</label>
                        <select name="panel_dns_provider" id="panel_dns_provider" class="form-select">
                            <option value="">Selecciona proveedor...</option>
                            <?php foreach ($catalog as $name => $meta): ?>
                                <?php
                                    $isInstalled = isset($installedSet[$name]);
                                    $suffix = $isInstalled ? '' : ' (modulo no instalado)';
                                ?>
                                <option value="<?= View::e($name) ?>"
                                        data-example="<?= View::e((string)($meta['example'] ?? '{}')) ?>"
                                        data-required="<?= View::e(implode(',', array_map('strval', $meta['required'] ?? []))) ?>"
                                        <?= $selected === $name ? 'selected' : '' ?>>
                                    <?= View::e((string)($meta['label'] ?? $name) . $suffix) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Este proveedor se usa para emitir el certificado del dominio del panel sin abrir 80/443.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Credenciales JSON</label>
                        <textarea name="panel_dns_provider_config" id="panel_dns_provider_config" rows="6" class="form-control" placeholder='{"api_token":"..."}'><?= View::e($providerConfigRaw) ?></textarea>
                        <div class="form-text text-muted">
                            Campos esperados: <code id="dns-required-fields">-</code>.
                            No pongas <code>name</code>; MuseDock lo anade segun el proveedor.
                        </div>
                        <?php if ($cloudflareEnvTokenPresent): ?>
                            <div class="small text-success mt-1">
                                <i class="bi bi-check-circle me-1"></i>Cloudflare puede heredar <code>CLOUDFLARE_API_TOKEN</code> de <code>/etc/default/caddy</code> si dejas el JSON vacio.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="apply_panel_dns01" value="1" id="apply_panel_dns01" <?= $panelTlsMode === 'dns01' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="apply_panel_dns01">
                            Activar DNS-01 como TLS del panel al guardar
                        </label>
                        <div class="form-text text-muted">Requiere dominio del panel y email ACME ya configurados en <a href="/settings/server" class="text-info">Settings &gt; Servidor</a>.</div>
                    </div>

                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-save me-1"></i>Guardar DNS
                    </button>
                    <a href="/settings/server" class="btn btn-outline-light ms-2">
                        <i class="bi bi-server me-1"></i>Servidor
                    </a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-tools me-1"></i> Instalar modulo DNS en Caddy</div>
            <div class="card-body">
                <?php if (!empty($installStatus)): ?>
                    <?php
                        $state = (string)($installStatus['status'] ?? '');
                        $class = $state === 'ok' ? 'success' : ($state === 'running' ? 'info' : 'danger');
                    ?>
                    <div class="alert alert-<?= View::e($class) ?> py-2 small">
                        <strong><?= View::e((string)($installStatus['provider'] ?? 'provider')) ?>:</strong>
                        <?= View::e((string)($installStatus['message'] ?? '')) ?>
                        <?php if (!empty($installStatus['backup'])): ?>
                            <span class="d-block text-muted mt-1">Backup: <code><?= View::e((string)$installStatus['backup']) ?></code></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/settings/server/dns-provider/install">
                    <?= View::csrf() ?>
                    <input type="hidden" name="return_to" value="/settings/dns">
                    <div class="mb-3">
                        <label class="form-label">Proveedor a instalar</label>
                        <select name="dns_provider" class="form-select">
                            <?php foreach ($catalog as $name => $meta): ?>
                                <option value="<?= View::e($name) ?>" <?= isset($installedSet[$name]) ? 'disabled' : '' ?>>
                                    <?= View::e((string)($meta['label'] ?? $name)) ?><?= isset($installedSet[$name]) ? ' - instalado' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contrasena admin</label>
                        <input type="password" name="admin_password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-outline-info w-100" type="submit">
                        <i class="bi bi-box-arrow-down me-1"></i>Compilar e instalar modulo
                    </button>
                </form>
                <div class="small text-muted mt-3">
                    Caddy se recompila con <code>xcaddy</code>, crea backup del binario actual, reinicia el servicio y hace rollback si el nuevo binario no queda activo.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-cloud-fill me-1" style="color:#f97316;"></i> Cloudflare DNS</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Cloudflare mantiene su gestor completo aparte: zonas, registros, proxy naranja, DNS Only y cuentas Cloudflare del cluster.
                </p>
                <a href="/settings/cloudflare-dns" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-cloud-fill me-1"></i>Abrir Cloudflare DNS
                </a>
                <a href="/settings/cluster#failover" class="btn btn-sm btn-outline-light ms-2">
                    <i class="bi bi-key me-1"></i>Cuentas Cloudflare
                </a>
            </div>
        </div>
    </div>
</div>

<div class="mt-3 p-3 rounded" style="background:rgba(14,165,233,0.08);border:1px solid rgba(56,189,248,0.22);">
    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Como queda organizado</div>
    <div class="small text-muted">
        <strong>Settings &gt; DNS</strong> guarda el proveedor que usa Caddy para DNS-01 del panel.
        <strong>Settings &gt; Cloudflare DNS</strong> sigue siendo el gestor de registros Cloudflare.
        Los hostings Cloudflare existentes no cambian por guardar esta pantalla.
    </div>
</div>

<script>
const providerSelect = document.getElementById('panel_dns_provider');
const providerConfig = document.getElementById('panel_dns_provider_config');
const requiredFields = document.getElementById('dns-required-fields');

function refreshDnsProviderHints() {
    const option = providerSelect?.selectedOptions?.[0];
    if (!option) return;
    const example = option.getAttribute('data-example') || '';
    const required = option.getAttribute('data-required') || '';
    if (providerConfig && !providerConfig.value.trim() && example) {
        providerConfig.placeholder = example;
    }
    if (requiredFields) {
        requiredFields.textContent = required ? required.replaceAll(',', ', ') : 'sin campos obligatorios detectados';
    }
}

providerSelect?.addEventListener('change', refreshDnsProviderHints);
refreshDnsProviderHints();
</script>
