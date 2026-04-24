<?php use MuseDockPanel\View; ?>

<!-- Mail Node Setup — launched from mail index or cluster settings -->
<div class="card mb-4" id="mail-setup-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-gear-wide-connected me-2"></i>Configurar Servidor de Mail</span>
        <span id="setup-status-badge" class="badge bg-secondary">Pendiente</span>
    </div>
    <div class="card-body">

        <!-- Phase 1: Setup form (visible when no setup running) -->
        <div id="setup-form-section">

            <!-- Mode selector: local vs remote -->
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Donde instalar el servidor de mail</label>
                    <div class="d-flex gap-2">
                        <div class="form-check form-check-inline flex-fill">
                            <input class="form-check-input" type="radio" name="setup_mode" id="mode-local" value="local" checked onchange="toggleSetupMode()">
                            <label class="form-check-label" for="mode-local">
                                <i class="bi bi-pc-display me-1"></i> Este servidor (local)
                            </label>
                        </div>
                        <div class="form-check form-check-inline flex-fill">
                            <input class="form-check-input" type="radio" name="setup_mode" id="mode-remote" value="remote" onchange="toggleSetupMode()" <?= empty($clusterNodes) ? 'disabled' : '' ?>>
                            <label class="form-check-label <?= empty($clusterNodes) ? 'text-muted' : '' ?>" for="mode-remote">
                                <i class="bi bi-hdd-network me-1"></i> Nodo remoto del cluster
                                <?php if (empty($clusterNodes)): ?>
                                    <small class="text-muted">(sin nodos)</small>
                                <?php endif; ?>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Local mode info -->
            <div id="local-mode-info" class="mb-3">
                <div class="p-3 rounded" style="background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.15);">
                    <i class="bi bi-pc-display me-1 text-success"></i>
                    <strong class="small">Modo local:</strong>
                    <span class="small text-muted">
                        Postfix, Dovecot, OpenDKIM y Rspamd se instalaran en este mismo servidor.
                        La base de datos es local (localhost:<?= \MuseDockPanel\Env::int('DB_PORT', 5433) ?>), no necesita replica.
                        Ideal para servidores individuales o para probar antes de escalar a nodos remotos.
                    </span>
                </div>
            </div>

            <!-- Remote mode info (hidden by default) -->
            <div id="remote-mode-info" class="mb-3" style="display: none;">
                <div class="p-3 rounded" style="background: rgba(56,189,248,0.06); border: 1px solid rgba(56,189,248,0.15);">
                    <i class="bi bi-database me-1 text-info"></i>
                    <strong class="small">Requisito:</strong>
                    <span class="small text-muted">
                        El nodo debe tener una replica local de PostgreSQL (configurada en
                        <a href="/settings/cluster#nodos" class="text-info">Cluster &rarr; Nodos</a>).
                        Postfix y Dovecot consultaran la base de datos local para resolver dominios, cuentas y aliases.
                    </span>
                </div>
            </div>

            <form id="form-mail-setup" onsubmit="return startMailSetup(event)" autocomplete="off">
                <?= View::csrf() ?>
                <input type="hidden" name="setup_mode" id="hidden-setup-mode" value="local">
                <input type="hidden" name="db_host" value="localhost">
                <input type="text" name="mail_setup_autofill_user" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;opacity:0;">
                <input type="password" name="mail_setup_autofill_password" autocomplete="current-password" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;opacity:0;">

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Modo de correo</label>
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="mail-mode-card p-3 rounded d-block h-100" style="border:1px solid rgba(56,189,248,.35);background:rgba(56,189,248,.06);cursor:pointer;">
                                    <input class="form-check-input me-2" type="radio" name="mail_mode" value="satellite" onchange="toggleMailMode()">
                                    <strong>Solo Envio</strong>
                                    <small class="d-block text-muted mt-1">Para SaaS y notificaciones. Envia emails, no recibe correo y no abre puertos de entrada. Instala Postfix + OpenDKIM.</small>
                                </label>
                            </div>
                            <div class="col-md-3">
                                <label class="mail-mode-card p-3 rounded d-block h-100" style="border:1px solid rgba(14,165,233,.35);background:rgba(14,165,233,.08);cursor:pointer;">
                                    <input class="form-check-input me-2" type="radio" name="mail_mode" value="relay" onchange="toggleMailMode()">
                                    <strong>Relay Privado</strong>
                                    <small class="d-block text-muted mt-1">Relay SMTP privado por WireGuard. Otros servidores envian por VPN con usuario SMTP. DKIM independiente por dominio.</small>
                                </label>
                            </div>
                            <div class="col-md-3">
                                <label class="mail-mode-card p-3 rounded d-block h-100" style="border:1px solid rgba(34,197,94,.35);background:rgba(34,197,94,.06);cursor:pointer;">
                                    <input class="form-check-input me-2" type="radio" name="mail_mode" value="full" checked onchange="toggleMailMode()">
                                    <strong>Correo Completo</strong>
                                    <small class="d-block text-muted mt-1">Envia y recibe correo, con buzones IMAP. Requiere MX, PTR y puertos abiertos. Instala Postfix + Dovecot + OpenDKIM + Rspamd.</small>
                                </label>
                            </div>
                            <div class="col-md-3">
                                <label class="mail-mode-card p-3 rounded d-block h-100" style="border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.06);cursor:pointer;">
                                    <input class="form-check-input me-2" type="radio" name="mail_mode" value="external" onchange="toggleMailMode()">
                                    <strong>SMTP Externo</strong>
                                    <small class="d-block text-muted mt-1">Usa SES, Mailgun, Brevo u otro proveedor. No instala servidor de correo local.</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Node selector (remote only) -->
                    <div class="col-md-6" id="node-selector-row" style="display: none;">
                        <label class="form-label">Nodo de mail</label>
                        <select name="node_id" id="setup-node-id" class="form-select">
                            <option value="">Seleccionar nodo...</option>
                            <?php foreach ($clusterNodes as $n): ?>
                                <option value="<?= $n['id'] ?>"><?= View::e($n['name']) ?> (<?= View::e($n['api_url']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Hostname de mail</label>
                        <input type="text" name="mail_hostname" class="form-control" placeholder="mail.dominio.com" value="" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" required>
                        <small class="text-muted mode-help mode-full">Nombre publico del servidor de correo. Se usara para MX, certificado TLS y conexiones SMTP/IMAP. Ej: mail.dominio.com.</small>
                        <small class="text-muted mode-help mode-satellite" style="display:none;">Nombre de salida/EHLO del servidor. Debe tener A y PTR/rDNS correctos. Ej: mailout.dominio.com.</small>
                        <small class="text-muted mode-help mode-relay" style="display:none;">Nombre publico del relay. Debe coincidir con el PTR/rDNS de la IP publica. Ej: relay.dominio.com.</small>
                    </div>
                    <div class="col-md-6 mb-2 mode-satellite-field mode-relay-field" style="display:none;">
                        <label class="form-label">Dominio remitente</label>
                        <input type="text" name="outbound_domain" class="form-control" placeholder="dominio.com" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true">
                        <small class="text-muted">Dominio que firmara DKIM y tendra SPF/DMARC. Ej: una aplicacion envia desde noreply@dominio.com.</small>
                    </div>
                    <div class="col-12 mt-2 mode-relay-field" style="display:none;">
                        <div class="row g-3 p-3 rounded" style="border:1px solid rgba(14,165,233,.25);background:rgba(14,165,233,.06);">
                            <div class="col-md-4">
                                <label class="form-label">IP WireGuard del relay</label>
                                <input type="text" name="wireguard_ip" class="form-control" placeholder="10.10.70.10" autocomplete="off" inputmode="decimal" data-lpignore="true" data-1p-ignore="true">
                                <small class="text-muted">Postfix 587 escuchara solo en esta IP privada, no en la IP publica.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Red WireGuard autorizada</label>
                                <input type="text" name="wireguard_cidr" class="form-control" value="10.10.70.0/24" autocomplete="off" inputmode="decimal" data-lpignore="true" data-1p-ignore="true">
                                <small class="text-muted">Rango privado que podra usar el relay. Normalmente 10.10.70.0/24.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">IP publica del relay</label>
                                <input type="text" name="relay_public_ip" class="form-control" placeholder="Se detecta automaticamente si lo dejas vacio" autocomplete="off" inputmode="decimal" data-lpignore="true" data-1p-ignore="true">
                                <small class="text-muted">Opcional. Si se deja vacia, el instalador detecta la IPv4 publica del nodo. Se usa para SPF, PTR y blacklists.</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 mt-2 mode-satellite-field" style="display:none;">
                        <div class="row g-3 p-3 rounded" style="border:1px solid rgba(56,189,248,.22);background:rgba(56,189,248,.05);">
                            <div class="col-12">
                                <strong class="small">Failover opcional: relay privado → SMTP externo</strong>
                                <div class="small text-muted">Si rellenas estos campos, Postfix local enviara por el relay WireGuard y cambiara al SMTP externo si el relay no responde.</div>
                            </div>
                            <div class="col-md-3"><label class="form-label">Relay host/IP</label><input name="relay_host" class="form-control" placeholder="10.10.70.10" autocomplete="off" data-lpignore="true" data-1p-ignore="true"></div>
                            <div class="col-md-2"><label class="form-label">Puerto relay</label><input name="relay_port" type="number" class="form-control" value="587" autocomplete="off"></div>
                            <div class="col-md-3"><label class="form-label">Usuario relay</label><input name="relay_user" class="form-control" autocomplete="off" data-lpignore="true" data-1p-ignore="true"></div>
                            <div class="col-md-4"><label class="form-label">Password relay</label><input name="relay_password" type="password" class="form-control" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true"></div>
                            <div class="col-md-3"><label class="form-label">Fallback SMTP</label><input name="fallback_smtp_host" class="form-control" placeholder="smtp.proveedor.com" autocomplete="off" data-lpignore="true" data-1p-ignore="true"></div>
                            <div class="col-md-2"><label class="form-label">Puerto fallback</label><input name="fallback_smtp_port" type="number" class="form-control" value="587" autocomplete="off"></div>
                            <div class="col-md-3"><label class="form-label">Usuario fallback</label><input name="fallback_smtp_user" class="form-control" autocomplete="off" data-lpignore="true" data-1p-ignore="true"></div>
                            <div class="col-md-4"><label class="form-label">Password fallback</label><input name="fallback_smtp_password" type="password" class="form-control" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true"></div>
                        </div>
                    </div>
                    <div class="col-md-6 mode-full-field">
                        <label class="form-label">Certificado SSL del correo</label>
                        <select name="ssl_mode" class="form-select">
                            <option value="letsencrypt">Let's Encrypt (automatico)</option>
                            <option value="selfsigned">Auto-firmado (testing)</option>
                            <option value="manual">Manual (ya tengo certificados)</option>
                        </select>
                        <small class="text-muted">
                            Solo aplica a <strong>Correo Completo</strong>. El certificado se instala en el servidor elegido:
                            si eliges modo local, en este master; si eliges nodo remoto, en ese slave. Se usa para SMTP/IMAP seguros del hostname indicado.
                        </small>
                    </div>
                    <div class="col-12 mt-3 mode-external-field" style="display:none;">
                        <div class="row g-3 p-3 rounded" style="border:1px solid rgba(148,163,184,.25);background:rgba(15,23,42,.35);">
                            <div class="col-md-6">
                                <label class="form-label">Servidor SMTP</label>
                                <input type="text" name="smtp_host" class="form-control" placeholder="smtp.proveedor.com" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Puerto</label>
                                <input type="number" name="smtp_port" class="form-control" value="587" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cifrado</label>
                                <select name="smtp_encryption" class="form-select">
                                    <option value="tls">TLS / STARTTLS</option>
                                    <option value="ssl">SSL</option>
                                    <option value="none">Sin cifrado</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Usuario SMTP</label>
                                <input type="text" name="smtp_user" class="form-control" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password SMTP</label>
                                <input type="password" name="smtp_password" class="form-control" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">From address</label>
                                <input type="email" name="from_address" class="form-control" placeholder="noreply@dominio.com" autocomplete="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">From name</label>
                                <input type="text" name="from_name" class="form-control" placeholder="Nombre visible del remitente" value="" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
                            </div>
                            <div class="col-12">
                                <small class="text-muted">Estos datos solo se guardan para que el panel y las apps locales puedan enviar por tu proveedor SMTP. El nombre visible es opcional.</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tu contrase&ntilde;a del panel</label>
                        <input type="password" name="admin_password" class="form-control" value="" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" required>
                        <small class="text-muted">Para confirmar esta operacion.</small>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" id="btn-start-setup">
                            <i class="bi bi-play-fill me-1"></i> Iniciar instalacion
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Phase 2: Progress (visible during setup) -->
        <div id="setup-progress-section" style="display:none;">
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span id="progress-label" class="fw-semibold">Iniciando...</span>
                    <span id="progress-step" class="text-muted">0/9</span>
                </div>
                <div class="progress" style="height: 12px;">
                    <div id="progress-bar" class="progress-bar bg-info" role="progressbar" style="width: 0%"></div>
                </div>
            </div>

            <!-- Step list -->
            <div id="step-list" class="mb-3">
                <div class="list-group list-group-flush" style="background: transparent;">
                    <?php
                    $steps = [
                        1 => 'Verificar conectividad PostgreSQL',
                        2 => 'Crear usuario vmail',
                        3 => 'Instalar paquetes (Postfix, Dovecot, OpenDKIM, Rspamd)',
                        4 => 'Configurar Postfix (SQL lookups, SASL, TLS)',
                        5 => 'Configurar Dovecot (SQL auth, quota, LMTP)',
                        6 => 'Configurar certificados SSL/TLS',
                        7 => 'Configurar OpenDKIM (firma DKIM)',
                        8 => 'Configurar Rspamd (antispam)',
                        9 => 'Iniciar y habilitar servicios',
                       10 => 'Verificar servicios y puertos',
                    ];
                    foreach ($steps as $num => $label):
                    ?>
                    <div class="list-group-item d-flex align-items-center gap-2 px-0 py-2" id="step-item-<?= $num ?>" style="background: transparent; border-color: #334155;">
                        <span class="step-icon" id="step-icon-<?= $num ?>" style="width: 24px; text-align: center;">
                            <i class="bi bi-circle text-muted" style="font-size: 0.7rem;"></i>
                        </span>
                        <span class="step-label" id="step-label-<?= $num ?>"><?= $num ?>. <?= $label ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Elapsed time -->
            <div class="text-muted small" id="progress-elapsed"></div>

            <!-- Error display -->
            <div id="setup-errors" class="mt-3" style="display:none;">
                <div class="alert alert-danger mb-0">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i> Errores:</strong>
                    <pre id="error-details" class="mb-0 mt-2" style="font-size: 0.8rem; white-space: pre-wrap; color: #fca5a5;"></pre>
                </div>
            </div>

            <!-- Final result -->
            <div id="setup-result" class="mt-3" style="display:none;"></div>
        </div>

    </div>
</div>

<script>
let pollTimer = null;
let pollNodeId = null;
let pollTaskId = null;
let pollStartTime = null;
let setupMode = 'local';

function currentMailMode() {
    return document.querySelector('input[name="mail_mode"]:checked')?.value || 'full';
}

function toggleMailMode() {
    const mode = currentMailMode();
    document.querySelectorAll('.mode-full-field').forEach(el => el.style.display = mode === 'full' ? '' : 'none');
    document.querySelectorAll('.mode-satellite-field').forEach(el => el.style.display = mode === 'satellite' ? '' : 'none');
    document.querySelectorAll('.mode-relay-field').forEach(el => el.style.display = mode === 'relay' ? '' : 'none');
    document.querySelectorAll('.mode-external-field').forEach(el => el.style.display = mode === 'external' ? '' : 'none');
    document.querySelectorAll('.mode-help').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.mode-' + mode).forEach(el => {
        if (el.classList.contains('mode-help')) el.style.display = '';
    });

    const hostname = document.querySelector('[name="mail_hostname"]');
    const outbound = document.querySelector('[name="outbound_domain"]');
    const smtpHost = document.querySelector('[name="smtp_host"]');
    const fromAddress = document.querySelector('[name="from_address"]');
    const wgIp = document.querySelector('[name="wireguard_ip"]');
    if (hostname) hostname.required = mode !== 'external';
    if (outbound) outbound.required = mode === 'satellite' || mode === 'relay';
    if (wgIp) wgIp.required = mode === 'relay';
    if (smtpHost) smtpHost.required = mode === 'external';
    if (fromAddress) fromAddress.required = mode === 'external';

    const btn = document.getElementById('btn-start-setup');
    if (btn) {
        btn.innerHTML = mode === 'external'
            ? '<i class="bi bi-save me-1"></i> Guardar SMTP externo'
            : (mode === 'relay'
                ? '<i class="bi bi-hdd-network me-1"></i> Instalar relay privado'
                : '<i class="bi bi-play-fill me-1"></i> Iniciar instalacion');
    }
}

function toggleSetupMode() {
    setupMode = document.getElementById('mode-local').checked ? 'local' : 'remote';
    document.getElementById('hidden-setup-mode').value = setupMode;
    document.getElementById('local-mode-info').style.display = setupMode === 'local' ? '' : 'none';
    document.getElementById('remote-mode-info').style.display = setupMode === 'remote' ? '' : 'none';
    document.getElementById('node-selector-row').style.display = setupMode === 'remote' ? '' : 'none';

    const nodeSelect = document.getElementById('setup-node-id');
    if (setupMode === 'remote') {
        nodeSelect.required = true;
    } else {
        nodeSelect.required = false;
        nodeSelect.value = '';
    }
}

function startMailSetup(e) {
    e.preventDefault();
    const form = document.getElementById('form-mail-setup');
    const btn = document.getElementById('btn-start-setup');
    const fd = new FormData(form);
    const isLocal = setupMode === 'local';
    const mode = currentMailMode();

    if (!isLocal && !fd.get('node_id')) {
        alert('Selecciona un nodo del cluster');
        return false;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + (mode === 'external' ? 'Guardando...' : (isLocal ? 'Iniciando...' : 'Conectando...'));

    const url = isLocal ? '/settings/cluster/setup-mail-local' : '/settings/cluster/setup-mail-node';

    fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok && data.async && data.task_id) {
                pollNodeId = data.node_id;
                pollTaskId = data.task_id;
                pollStartTime = Date.now();
                showProgressPhase();
                startPolling();
            } else {
                btn.disabled = false;
                toggleMailMode();
                alert('Error: ' + (data.error || JSON.stringify(data)));
            }
        })
        .catch(err => {
            btn.disabled = false;
            toggleMailMode();
            alert('Error de conexion: ' + err.message);
        });

    return false;
}

toggleMailMode();

function showProgressPhase() {
    document.getElementById('setup-form-section').style.display = 'none';
    document.getElementById('setup-progress-section').style.display = '';
    document.getElementById('setup-status-badge').className = 'badge bg-info';
    document.getElementById('setup-status-badge').textContent = 'Instalando...';
}

function startPolling() {
    pollTimer = setInterval(pollProgress, 3000);
    pollProgress(); // First poll immediately
}

function pollProgress() {
    if (!pollTaskId) return;

    const isLocal = pollNodeId === 'local';
    const url = isLocal
        ? `/settings/cluster/mail-setup-progress-local?task_id=${encodeURIComponent(pollTaskId)}`
        : `/settings/cluster/mail-setup-progress?node_id=${pollNodeId}&task_id=${encodeURIComponent(pollTaskId)}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const p = data.progress || data;
            if (!p || !p.status) return;

            updateProgressUI(p);

            // Stop polling on terminal states
            if (['completed', 'completed_with_errors', 'failed', 'stale', 'timeout'].includes(p.status)) {
                clearInterval(pollTimer);
                pollTimer = null;
                showFinalResult(p);
            }

            if (p.status === 'node_unreachable') {
                // Don't stop polling — node might come back
                document.getElementById('progress-label').textContent = 'Nodo no accesible, reintentando...';
            }
        })
        .catch(() => {
            document.getElementById('progress-label').textContent = 'Error de conexion, reintentando...';
        });
}

function updateProgressUI(p) {
    const step = p.step || 0;
    const total = p.total_steps || 10;
    const pct = Math.round((step / total) * 100);

    document.getElementById('progress-bar').style.width = pct + '%';
    document.getElementById('progress-step').textContent = step + '/' + total;
    document.getElementById('progress-label').textContent = p.label || p.current || 'Procesando...';

    // Update step icons
    for (let i = 1; i <= 10; i++) {
        const icon = document.getElementById('step-icon-' + i);
        const label = document.getElementById('step-label-' + i);
        if (i < step) {
            icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
            label.style.color = '#22c55e';
        } else if (i === step) {
            if (p.status === 'running') {
                icon.innerHTML = '<span class="spinner-border spinner-border-sm text-info" style="width:14px;height:14px;"></span>';
                label.style.color = '#38bdf8';
                label.style.fontWeight = '600';
            } else if (p.status === 'failed' || p.status === 'stale') {
                icon.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i>';
                label.style.color = '#ef4444';
            } else {
                icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                label.style.color = '#22c55e';
            }
        } else {
            icon.innerHTML = '<i class="bi bi-circle text-muted" style="font-size:0.7rem;"></i>';
            label.style.color = '#64748b';
            label.style.fontWeight = 'normal';
        }
    }

    // Elapsed time
    if (pollStartTime) {
        const elapsed = Math.round((Date.now() - pollStartTime) / 1000);
        const min = Math.floor(elapsed / 60);
        const sec = elapsed % 60;
        document.getElementById('progress-elapsed').textContent =
            'Tiempo: ' + (min > 0 ? min + 'm ' : '') + sec + 's';
    }

    // Show errors as they happen
    if (p.errors && p.errors.length > 0) {
        document.getElementById('setup-errors').style.display = '';
        const details = p.errors.map(e =>
            `[${e.step}] ${e.command}\n  exit ${e.exit}: ${e.output}`
        ).join('\n\n');
        document.getElementById('error-details').textContent = details;
    }
}

function showFinalResult(p) {
    const badge = document.getElementById('setup-status-badge');
    const result = document.getElementById('setup-result');
    result.style.display = '';

    if (p.status === 'completed') {
        badge.className = 'badge bg-success';
        badge.textContent = 'Completado';
        document.getElementById('progress-bar').className = 'progress-bar bg-success';

        let html = '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><strong>Nodo de mail configurado correctamente</strong>';
        if (p.services) {
            html += '<div class="mt-2">';
            for (const [svc, status] of Object.entries(p.services)) {
                const icon = status === 'running' ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>';
                html += `<span class="me-3">${icon} ${svc}: ${status}</span>`;
            }
            html += '</div>';
        }
        if (p.ports) {
            html += '<div class="mt-1">';
            for (const [port, status] of Object.entries(p.ports)) {
                const icon = status === 'listening' ? '<i class="bi bi-check text-success"></i>' : '<i class="bi bi-x text-danger"></i>';
                html += `<span class="me-3">${icon} ${port}: ${status}</span>`;
            }
            html += '</div>';
        }
        if (p.elapsed_s) html += `<div class="mt-1 text-muted small">Tiempo total: ${p.elapsed_s}s</div>`;
        html += '</div>';
        result.innerHTML = html;

    } else if (p.status === 'completed_with_errors') {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = 'Con errores';
        document.getElementById('progress-bar').className = 'progress-bar bg-warning';
        result.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>' +
            '<strong>Instalacion completada con errores.</strong> Revisa los errores arriba y el log del nodo.</div>';

    } else if (p.status === 'failed') {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Fallido';
        document.getElementById('progress-bar').className = 'progress-bar bg-danger';
        result.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i>' +
            '<strong>La instalacion fallo.</strong> El proceso de background murio inesperadamente. Revisa el log del nodo.</div>';

    } else if (p.status === 'stale') {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = 'Colgado';
        document.getElementById('progress-bar').className = 'progress-bar bg-warning';
        result.innerHTML = '<div class="alert alert-warning"><i class="bi bi-hourglass-split me-2"></i>' +
            '<strong>El proceso parece colgado.</strong> Sin progreso en >10 minutos. Verifica el nodo directamente.</div>';

    } else if (p.status === 'timeout') {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Timeout';
        document.getElementById('progress-bar').className = 'progress-bar bg-danger';
        result.innerHTML = '<div class="alert alert-danger"><i class="bi bi-clock-history me-2"></i>' +
            '<strong>Timeout del master (15 min).</strong> La instalacion puede seguir en el nodo. Verifica directamente.</div>';
    }

    // Log tail
    if (p.log_tail) {
        result.innerHTML += '<div class="mt-2"><strong class="small">Ultimas lineas del log:</strong>' +
            '<pre class="mt-1 p-2 rounded" style="background:#0f172a;font-size:0.75rem;max-height:200px;overflow-y:auto;color:#94a3b8;">' +
            escapeHtml(p.log_tail) + '</pre></div>';
    }

    // Retry button for failed states
    if (['failed', 'stale', 'timeout'].includes(p.status)) {
        result.innerHTML += '<button class="btn btn-outline-warning btn-sm mt-2" onclick="resetSetupForm()">' +
            '<i class="bi bi-arrow-repeat me-1"></i> Reintentar</button>';
    }
}

function resetSetupForm() {
    document.getElementById('setup-form-section').style.display = '';
    document.getElementById('setup-progress-section').style.display = 'none';
    document.getElementById('setup-result').style.display = 'none';
    document.getElementById('setup-errors').style.display = 'none';
    document.getElementById('setup-status-badge').className = 'badge bg-secondary';
    document.getElementById('setup-status-badge').textContent = 'Pendiente';
    document.getElementById('btn-start-setup').disabled = false;
    document.getElementById('btn-start-setup').innerHTML = '<i class="bi bi-play-fill me-1"></i> Iniciar instalacion';
    document.getElementById('progress-bar').style.width = '0%';
    document.getElementById('progress-bar').className = 'progress-bar bg-info';
    for (let i = 1; i <= 10; i++) {
        document.getElementById('step-icon-' + i).innerHTML = '<i class="bi bi-circle text-muted" style="font-size:0.7rem;"></i>';
        const label = document.getElementById('step-label-' + i);
        label.style.color = '#64748b';
        label.style.fontWeight = 'normal';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
