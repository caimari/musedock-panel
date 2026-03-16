<?php use MuseDockPanel\View; ?>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$installed): ?>
    <!-- WireGuard no instalado -->
    <div class="card">
        <div class="card-header"><i class="bi bi-shield-lock me-1"></i> WireGuard no instalado</div>
        <div class="card-body">
            <p class="text-muted mb-3">
                WireGuard es una VPN moderna, rapida y segura que utiliza criptografia de ultima generacion.
                Permite crear tuneles seguros entre servidores para replicacion, administracion remota y comunicacion privada.
            </p>
            <ul class="text-muted mb-3">
                <li>Rendimiento superior a OpenVPN e IPSec</li>
                <li>Configuracion minima y superficie de ataque reducida</li>
                <li>Conexion instantanea (menos de 100ms de handshake)</li>
            </ul>
            <form method="post" action="/settings/wireguard/install" id="form-install">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-primary" onclick="confirmInstall()">
                    <i class="bi bi-download me-1"></i>Instalar WireGuard
                </button>
            </form>
        </div>
    </div>

    <script>
    function confirmInstall() {
        SwalDark.fire({
            title: 'Instalar WireGuard',
            text: 'Se instalara el paquete wireguard en este servidor. ¿Continuar?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Instalar',
            cancelButtonText: 'Cancelar'
        }).then(r => { if (r.isConfirmed) document.getElementById('form-install').submit(); });
    }
    </script>

<?php elseif (!$running): ?>
    <!-- WireGuard instalado pero no activo -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-exclamation-triangle me-1 text-warning"></i> WireGuard detenido
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                WireGuard esta instalado pero la interfaz <code>wg0</code> no esta activa.
                Asegurese de que existe el archivo <code>/etc/wireguard/wg0.conf</code> antes de iniciar.
            </p>
            <form method="post" action="/settings/wireguard/start" id="form-start">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-success" onclick="confirmStart()">
                    <i class="bi bi-play-circle me-1"></i>Iniciar Interfaz wg0
                </button>
            </form>
        </div>
    </div>

    <script>
    function confirmStart() {
        SwalDark.fire({
            title: 'Iniciar WireGuard',
            text: 'Se iniciara la interfaz wg0. ¿Continuar?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Iniciar',
            cancelButtonText: 'Cancelar'
        }).then(r => { if (r.isConfirmed) document.getElementById('form-start').submit(); });
    }
    </script>

<?php else: ?>
    <!-- WireGuard activo -->

    <!-- Card 1: Interfaz wg0 -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-network me-1"></i> Interfaz wg0</span>
            <form method="post" action="/settings/wireguard/restart" id="form-restart" class="d-inline">
                <?= View::csrf() ?>
                <button type="button" class="btn btn-outline-warning btn-sm" onclick="confirmRestart()">
                    <i class="bi bi-arrow-repeat me-1"></i>Reiniciar Interfaz
                </button>
            </form>
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tr>
                    <td class="text-muted" style="width:30%">IP asignada</td>
                    <td id="wg-ip"><strong><?= View::e($interfaceIp ?: 'No asignada') ?></strong></td>
                </tr>
                <tr>
                    <td class="text-muted">Puerto</td>
                    <td id="wg-port"><?= View::e($status['interface']['listening_port'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td class="text-muted">Clave Publica</td>
                    <td>
                        <code id="wg-pubkey"><?= View::e($status['interface']['public_key'] ?? 'N/A') ?></code>
                        <?php if (!empty($status['interface']['public_key'])): ?>
                            <button class="btn btn-outline-light btn-sm ms-2" onclick="copyToClipboard('wg-pubkey')" title="Copiar">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Estado</td>
                    <td><span class="badge bg-success">Activo</span></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Card 2: Peers Conectados -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people me-1"></i> Peers Conectados</span>
            <small class="text-muted" id="wg-last-update">Actualizacion automatica cada 10s</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="peers-table">
                    <thead>
                        <tr>
                            <th class="text-muted">Clave Publica</th>
                            <th class="text-muted">Endpoint</th>
                            <th class="text-muted">IPs Permitidas</th>
                            <th class="text-muted">Ultimo Handshake</th>
                            <th class="text-muted">Transferencia</th>
                            <th class="text-muted">Latencia</th>
                            <th class="text-muted">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="peers-tbody">
                        <?php if (empty($status['peers'] ?? [])): ?>
                            <tr id="no-peers-row">
                                <td colspan="7" class="text-muted text-center py-3">
                                    <i class="bi bi-info-circle me-1"></i>No hay peers configurados
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($status['peers'] as $peer): ?>
                                <?php
                                    $pk = $peer['public_key'];
                                    $pkShort = substr($pk, 0, 10) . '...' . substr($pk, -6);
                                    // Determine handshake color
                                    $hsColor = 'text-danger';
                                    $hsText = $peer['latest_handshake'] ?: 'Nunca';
                                    if ($peer['latest_handshake'] !== '' && $peer['latest_handshake'] !== 'Nunca') {
                                        if (preg_match('/(\d+)\s*seconds?\s*ago/i', $peer['latest_handshake'], $hm)) {
                                            $secs = (int)$hm[1];
                                            if ($secs < 120) $hsColor = 'text-success';
                                            elseif ($secs < 600) $hsColor = 'text-warning';
                                        } elseif (preg_match('/(\d+)\s*minutes?\s*ago/i', $peer['latest_handshake'], $hm)) {
                                            $mins = (int)$hm[1];
                                            if ($mins < 2) $hsColor = 'text-success';
                                            elseif ($mins < 10) $hsColor = 'text-warning';
                                        } else {
                                            $hsColor = 'text-warning';
                                        }
                                        $hsText = $peer['latest_handshake'];
                                    }
                                    // Extract IP for ping
                                    $peerIp = '';
                                    if (preg_match('/^([\d.]+)/', $peer['allowed_ips'], $ipm)) {
                                        $peerIp = $ipm[1];
                                    }
                                ?>
                                <tr data-pubkey="<?= View::e($pk) ?>">
                                    <td>
                                        <code title="<?= View::e($pk) ?>"><?= View::e($pkShort) ?></code>
                                        <button class="btn btn-link btn-sm p-0 ms-1" onclick="copyText('<?= View::e($pk) ?>')" title="Copiar clave completa">
                                            <i class="bi bi-clipboard small"></i>
                                        </button>
                                    </td>
                                    <td><?= View::e($peer['endpoint'] ?: '-') ?></td>
                                    <td><?= View::e($peer['allowed_ips'] ?: '-') ?></td>
                                    <td class="<?= $hsColor ?>"><?= View::e($hsText) ?></td>
                                    <td>
                                        <?php if ($peer['transfer_rx'] || $peer['transfer_tx']): ?>
                                            <small><i class="bi bi-arrow-down text-info"></i> <?= View::e($peer['transfer_rx']) ?></small><br>
                                            <small><i class="bi bi-arrow-up text-warning"></i> <?= View::e($peer['transfer_tx']) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($peerIp): ?>
                                            <button class="btn btn-outline-light btn-sm" onclick="pingPeer('<?= View::e($peerIp) ?>', this)">
                                                <i class="bi bi-broadcast me-1"></i>Ping
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-outline-warning btn-sm" onclick="editPeer('<?= View::e($pk) ?>', '<?= View::e($peer['allowed_ips']) ?>', '<?= View::e($peer['endpoint'] ?? '') ?>')" title="Editar">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" action="/settings/wireguard/remove-peer" class="d-inline">
                                                <?= View::csrf() ?>
                                                <input type="hidden" name="public_key" value="<?= View::e($pk) ?>">
                                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmRemovePeer(this.form, '<?= View::e($pkShort) ?>')" title="Eliminar">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                            <button class="btn btn-outline-success btn-sm" onclick="openRemoteConfigModal('<?= View::e($pk) ?>', '<?= View::e($peer['allowed_ips']) ?>')" title="Generar Config Remota">
                                                <i class="bi bi-file-earmark-code"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Card 3: Anadir Peer -->
    <div class="card mb-3">
        <div class="card-header" data-bs-toggle="collapse" data-bs-target="#addPeerCollapse" style="cursor:pointer;">
            <i class="bi bi-plus-circle me-1"></i> Anadir Peer
            <i class="bi bi-chevron-down float-end"></i>
        </div>
        <div class="collapse show" id="addPeerCollapse">
            <div class="card-body">
                <form method="post" action="/settings/wireguard/add-peer" id="form-add-peer">
                    <?= View::csrf() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Clave Publica</label>
                            <div class="input-group">
                                <input type="text" name="public_key" id="add-public-key" class="form-control" required placeholder="Clave publica del peer">
                                <button type="button" class="btn btn-outline-light" onclick="generateKeyPair()">
                                    <i class="bi bi-key me-1"></i>Generar Par de Claves
                                </button>
                            </div>
                            <div id="generated-private-key" class="mt-2" style="display:none;">
                                <div class="alert" style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);color:#fbbf24;">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <strong>Clave Privada generada (guardela, no se mostrara de nuevo):</strong>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <code id="generated-privkey-value" class="flex-grow-1"></code>
                                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="copyToClipboard('generated-privkey-value')">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Endpoint (opcional)</label>
                            <input type="text" name="endpoint" class="form-control" placeholder="IP:Puerto (ej: 1.2.3.4:51820)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IPs Permitidas</label>
                            <input type="text" name="allowed_ips" id="add-allowed-ips" class="form-control" required value="10.10.70.2/32" placeholder="10.10.70.X/32">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Clave Precompartida (opcional)</label>
                            <div class="input-group">
                                <input type="text" name="preshared_key" id="add-preshared-key" class="form-control" placeholder="Opcional, mejora la seguridad">
                                <button type="button" class="btn btn-outline-light" onclick="generatePsk()">
                                    <i class="bi bi-shield-lock me-1"></i>Generar
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-1"></i>Anadir Peer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Editar Peer -->
    <div class="modal fade" id="editPeerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background:#1e293b;border-color:#334155;">
                <div class="modal-header" style="border-color:#334155;">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Editar Peer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" action="/settings/wireguard/update-peer">
                    <?= View::csrf() ?>
                    <div class="modal-body">
                        <input type="hidden" name="public_key" id="edit-public-key">
                        <div class="mb-3">
                            <label class="form-label">Clave Publica</label>
                            <input type="text" class="form-control" id="edit-pubkey-display" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">IPs Permitidas</label>
                            <input type="text" name="allowed_ips" id="edit-allowed-ips" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Endpoint (opcional)</label>
                            <input type="text" name="endpoint" id="edit-endpoint" class="form-control" placeholder="IP:Puerto">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color:#334155;">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Configuracion Remota -->
    <div class="modal fade" id="remoteConfigModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background:#1e293b;border-color:#334155;">
                <div class="modal-header" style="border-color:#334155;">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-code me-1"></i>Configuracion Remota del Peer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Endpoint del Servidor (IP:Puerto)</label>
                            <input type="text" id="rc-server-endpoint" class="form-control" placeholder="IP publica del servidor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Puerto del Servidor</label>
                            <input type="number" id="rc-server-port" class="form-control" value="<?= View::e($status['interface']['listening_port'] ?? '51820') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Direccion del Peer (IP/mascara)</label>
                            <input type="text" id="rc-peer-address" class="form-control" placeholder="10.10.70.X/32">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">AllowedIPs para remoto</label>
                            <input type="text" id="rc-allowed-ips" class="form-control" value="10.10.70.0/24">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Clave Privada del Peer</label>
                            <div class="input-group">
                                <input type="text" id="rc-peer-private-key" class="form-control" placeholder="Clave privada del peer remoto">
                                <button type="button" class="btn btn-outline-light" onclick="generateRemoteKeyPair()">
                                    <i class="bi bi-key me-1"></i>Generar
                                </button>
                            </div>
                        </div>
                        <input type="hidden" id="rc-peer-public-key">
                        <input type="hidden" id="rc-server-public-key" value="<?= View::e($status['interface']['public_key'] ?? '') ?>">
                        <input type="hidden" id="rc-preshared-key">
                        <div class="col-12">
                            <button type="button" class="btn btn-primary" onclick="generateRemoteConfig()">
                                <i class="bi bi-gear me-1"></i>Generar Configuracion
                            </button>
                        </div>
                    </div>

                    <div id="rc-result" style="display:none;">
                        <label class="form-label">Configuracion generada (wg0.conf para el peer remoto):</label>
                        <textarea id="rc-config-text" class="form-control" rows="12" readonly style="font-family:monospace;background:rgba(0,0,0,0.3);color:#e2e8f0;"></textarea>
                        <div class="mt-2">
                            <button type="button" class="btn btn-outline-success" onclick="copyToClipboard('rc-config-text')">
                                <i class="bi bi-clipboard me-1"></i>Copiar al Portapapeles
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#334155;">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ─── CSRF Token ──────────────────────────────────────────
    const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';

    // ─── Confirmaciones SwalDark ─────────────────────────────
    function confirmRestart() {
        SwalDark.fire({
            title: 'Reiniciar WireGuard',
            text: 'Se reiniciara la interfaz wg0. Las conexiones activas se interrumpiran brevemente.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reiniciar',
            cancelButtonText: 'Cancelar'
        }).then(r => { if (r.isConfirmed) document.getElementById('form-restart').submit(); });
    }

    function confirmRemovePeer(form, peerName) {
        SwalDark.fire({
            title: 'Eliminar Peer',
            text: '¿Eliminar el peer ' + peerName + '? Esta accion no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#ef4444'
        }).then(r => { if (r.isConfirmed) form.submit(); });
    }

    // ─── Copiar al portapapeles ──────────────────────────────
    function copyToClipboard(elementId) {
        const el = document.getElementById(elementId);
        const text = el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' ? el.value : el.textContent;
        navigator.clipboard.writeText(text).then(() => {
            SwalDark.fire({ title: 'Copiado', icon: 'success', timer: 1200, showConfirmButton: false });
        });
    }

    function copyText(text) {
        navigator.clipboard.writeText(text).then(() => {
            SwalDark.fire({ title: 'Copiado', icon: 'success', timer: 1200, showConfirmButton: false });
        });
    }

    // ─── Generar par de claves ───────────────────────────────
    function generateKeyPair() {
        fetch('/settings/wireguard/generate-keys', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('add-public-key').value = data.public;
                document.getElementById('generated-privkey-value').textContent = data.private;
                document.getElementById('generated-private-key').style.display = 'block';
            } else {
                SwalDark.fire('Error', 'No se pudieron generar las claves', 'error');
            }
        })
        .catch(() => SwalDark.fire('Error', 'Error de conexion', 'error'));
    }

    function generatePsk() {
        fetch('/settings/wireguard/generate-keys', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                // Use private key as PSK (just need a random key)
                document.getElementById('add-preshared-key').value = data.private;
            }
        })
        .catch(() => SwalDark.fire('Error', 'Error de conexion', 'error'));
    }

    // ─── Ping peers ──────────────────────────────────────────
    function pingPeer(ip, btn) {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        fetch('/settings/wireguard/ping', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken) + '&ip=' + encodeURIComponent(ip)
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.ok) {
                btn.innerHTML = '<i class="bi bi-broadcast me-1"></i>' + data.display;
                btn.classList.remove('btn-outline-light', 'btn-outline-danger');
                btn.classList.add('btn-outline-success');
            } else {
                btn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Sin respuesta';
                btn.classList.remove('btn-outline-light', 'btn-outline-success');
                btn.classList.add('btn-outline-danger');
            }
            setTimeout(() => {
                btn.innerHTML = originalHtml;
                btn.classList.remove('btn-outline-success', 'btn-outline-danger');
                btn.classList.add('btn-outline-light');
            }, 5000);
        })
        .catch(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }

    // ─── Editar peer ─────────────────────────────────────────
    function editPeer(publicKey, allowedIps, endpoint) {
        document.getElementById('edit-public-key').value = publicKey;
        document.getElementById('edit-pubkey-display').value = publicKey;
        document.getElementById('edit-allowed-ips').value = allowedIps;
        document.getElementById('edit-endpoint').value = endpoint;
        new bootstrap.Modal(document.getElementById('editPeerModal')).show();
    }

    // ─── Configuracion remota ────────────────────────────────
    function openRemoteConfigModal(peerPublicKey, peerAllowedIps) {
        document.getElementById('rc-peer-public-key').value = peerPublicKey;
        // Pre-fill peer address from allowed IPs
        const ipMatch = peerAllowedIps.match(/^([\d.]+)/);
        if (ipMatch) {
            document.getElementById('rc-peer-address').value = ipMatch[1] + '/32';
        }
        document.getElementById('rc-result').style.display = 'none';
        document.getElementById('rc-config-text').value = '';
        document.getElementById('rc-peer-private-key').value = '';
        new bootstrap.Modal(document.getElementById('remoteConfigModal')).show();
    }

    function generateRemoteKeyPair() {
        fetch('/settings/wireguard/generate-keys', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('rc-peer-private-key').value = data.private;
            }
        })
        .catch(() => SwalDark.fire('Error', 'Error de conexion', 'error'));
    }

    function generateRemoteConfig() {
        const serverPubKey  = document.getElementById('rc-server-public-key').value;
        const serverEndpoint = document.getElementById('rc-server-endpoint').value;
        const serverPort    = document.getElementById('rc-server-port').value;
        const peerPrivKey   = document.getElementById('rc-peer-private-key').value;
        const peerAddress   = document.getElementById('rc-peer-address').value;
        const allowedIps    = document.getElementById('rc-allowed-ips').value;
        const presharedKey  = document.getElementById('rc-preshared-key').value;

        if (!serverEndpoint || !peerPrivKey || !peerAddress) {
            SwalDark.fire('Campos requeridos', 'Complete el endpoint del servidor, clave privada y direccion del peer.', 'warning');
            return;
        }

        fetch('/settings/wireguard/generate-config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrfToken)
                + '&server_public_key=' + encodeURIComponent(serverPubKey)
                + '&server_endpoint=' + encodeURIComponent(serverEndpoint)
                + '&server_port=' + encodeURIComponent(serverPort)
                + '&peer_private_key=' + encodeURIComponent(peerPrivKey)
                + '&peer_address=' + encodeURIComponent(peerAddress)
                + '&allowed_ips=' + encodeURIComponent(allowedIps)
                + '&preshared_key=' + encodeURIComponent(presharedKey)
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('rc-config-text').value = data.config;
                document.getElementById('rc-result').style.display = 'block';
            } else {
                SwalDark.fire('Error', data.error || 'Error al generar configuracion', 'error');
            }
        })
        .catch(() => SwalDark.fire('Error', 'Error de conexion', 'error'));
    }

    // ─── Auto-refresh cada 10s ───────────────────────────────
    let refreshTimer = null;

    function refreshStatus() {
        fetch('/settings/wireguard/status')
        .then(r => r.json())
        .then(data => {
            if (!data.running) return;

            // Update interface info
            const ipEl = document.getElementById('wg-ip');
            if (ipEl && data.interface_ip) {
                ipEl.innerHTML = '<strong>' + escapeHtml(data.interface_ip) + '</strong>';
            }

            const portEl = document.getElementById('wg-port');
            if (portEl && data.status?.interface?.listening_port) {
                portEl.textContent = data.status.interface.listening_port;
            }

            // Update peers table
            if (data.status?.peers) {
                updatePeersTable(data.status.peers);
            }

            document.getElementById('wg-last-update').textContent = 'Actualizado: ' + data.timestamp;
        })
        .catch(() => {});
    }

    function updatePeersTable(peers) {
        const tbody = document.getElementById('peers-tbody');
        if (!tbody) return;

        if (peers.length === 0) {
            tbody.innerHTML = '<tr id="no-peers-row"><td colspan="7" class="text-muted text-center py-3"><i class="bi bi-info-circle me-1"></i>No hay peers conectados</td></tr>';
            return;
        }

        peers.forEach(peer => {
            const row = tbody.querySelector('tr[data-pubkey="' + CSS.escape(peer.public_key) + '"]');
            if (!row) return;

            // Update handshake
            const hsCells = row.querySelectorAll('td');
            if (hsCells[3]) {
                let hsColor = 'text-danger';
                let hsText = peer.latest_handshake || 'Nunca';
                if (peer.latest_handshake) {
                    const secMatch = peer.latest_handshake.match(/(\d+)\s*seconds?\s*ago/i);
                    const minMatch = peer.latest_handshake.match(/(\d+)\s*minutes?\s*ago/i);
                    if (secMatch && parseInt(secMatch[1]) < 120) hsColor = 'text-success';
                    else if (secMatch && parseInt(secMatch[1]) < 600) hsColor = 'text-warning';
                    else if (minMatch && parseInt(minMatch[1]) < 2) hsColor = 'text-success';
                    else if (minMatch && parseInt(minMatch[1]) < 10) hsColor = 'text-warning';
                }
                hsCells[3].className = hsColor;
                hsCells[3].textContent = hsText;
            }

            // Update transfer
            if (hsCells[4]) {
                if (peer.transfer_rx || peer.transfer_tx) {
                    hsCells[4].innerHTML =
                        '<small><i class="bi bi-arrow-down text-info"></i> ' + escapeHtml(peer.transfer_rx) + '</small><br>' +
                        '<small><i class="bi bi-arrow-up text-warning"></i> ' + escapeHtml(peer.transfer_tx) + '</small>';
                }
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // Start auto-refresh
    refreshTimer = setInterval(refreshStatus, 10000);
    </script>

<?php endif; ?>
