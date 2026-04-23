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
                <form method="POST" action="/settings/server/save">
                    <?= View::csrf() ?>

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
                        ?>
                        <label class="form-label">TLS del panel (puerto <?= (int)$panelPort ?>)</label>
                        <select class="form-select" name="panel_tls_mode" id="panel_tls_mode">
                            <option value="self_signed" <?= $panelTlsMode === 'self_signed' ? 'selected' : '' ?>>Certificado interno/autofirmado (recomendado para admin privado)</option>
                            <option value="http01" <?= $panelTlsMode === 'http01' ? 'selected' : '' ?>>Let's Encrypt HTTP-01 / TLS-ALPN-01 (+ fallback interno)</option>
                            <option value="dns01" <?= $panelTlsMode === 'dns01' ? 'selected' : '' ?>>Let's Encrypt DNS-01 (+ fallback interno, proveedor DNS con API)</option>
                        </select>
                        <small class="text-muted">
                            Recomendado: <strong>self-signed</strong> si el panel esta cerrado por firewall. Usa DNS-01 si quieres certificado publico sin abrir puertos.
                        </small>
                    </div>

                    <div class="mb-3" id="acme_email_wrap" style="<?= in_array($panelTlsMode, ['http01', 'dns01'], true) ? '' : 'display:none;' ?>">
                        <label class="form-label">Email ACME</label>
                        <input type="email" name="panel_acme_email" class="form-control" value="<?= View::e($settings['panel_acme_email'] ?? '') ?>" placeholder="webmaster@domain.com">
                        <small class="text-muted">Solo se usa en modos HTTP-01/DNS-01 para Let's Encrypt (avisos de expiracion).</small>
                    </div>

                    <div class="mb-3" id="dns_provider_wrap" style="<?= $panelTlsMode === 'dns01' ? '' : 'display:none;' ?>">
                        <label class="form-label">Proveedor DNS (modulo Caddy)</label>
                        <input type="text" name="panel_dns_provider" class="form-control" value="<?= View::e($settings['panel_dns_provider'] ?? '') ?>" placeholder="cloudflare / route53 / digitalocean / hetzner / ...">
                        <small class="text-muted">Nombre del modulo en Caddy: <code>dns.providers.&lt;proveedor&gt;</code>.</small>
                    </div>

                    <div class="mb-3" id="dns_provider_cfg_wrap" style="<?= $panelTlsMode === 'dns01' ? '' : 'display:none;' ?>">
                        <label class="form-label">Configuracion JSON del proveedor DNS</label>
                        <textarea name="panel_dns_provider_config" class="form-control" rows="5" placeholder='{"api_token":"..."}'><?= View::e($settings['panel_dns_provider_config'] ?? '') ?></textarea>
                        <small class="text-muted">
                            Ejemplos: Cloudflare <code>{"api_token":"..."}</code>, DigitalOcean <code>{"token":"..."}</code>, Route53 <code>{"access_key_id":"...","secret_access_key":"..."}</code>.
                        </small>
                    </div>

                    <div class="mb-3">
                        <div class="rounded p-3" style="border:1px solid rgba(148,163,184,0.28); background:rgba(15,23,42,0.45);">
                            <div class="fw-semibold mb-2">Guia rapida de certificacion del panel</div>
                            <div class="small text-muted mb-1"><strong>A) Self-signed:</strong> no depende de Internet ni ACME. Ideal para panel privado con firewall estricto.</div>
                            <div class="small text-muted mb-1"><strong>B) HTTP-01/TLS-ALPN-01:</strong> requiere puertos 80/443 alcanzables desde Internet durante emision/renovacion (si falla, entra fallback interno para no bloquear acceso).</div>
                            <div class="small text-muted"><strong>C) DNS-01:</strong> no requiere abrir 80/443 para certificar, pero exige proveedor DNS con API y modulo Caddy instalado (tambien con fallback interno).</div>
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

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
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

    if (!modeSel) return;
    const refresh = () => {
        const mode = modeSel.value || 'self_signed';
        const acmeVisible = mode === 'http01' || mode === 'dns01';
        acmeWrap.style.display = acmeVisible ? '' : 'none';
        const dnsVisible = mode === 'dns01';
        providerWrap.style.display = dnsVisible ? '' : 'none';
        providerCfgWrap.style.display = dnsVisible ? '' : 'none';
    };
    modeSel.addEventListener('change', refresh);
    refresh();
})();
</script>
