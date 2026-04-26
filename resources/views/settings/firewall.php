<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<!-- Banner de advertencia -->
<div class="alert alert-danger d-flex align-items-start mb-3" style="background:rgba(220,53,69,0.15);border-color:rgba(220,53,69,0.4);">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-5 text-danger"></i>
    <div>
        <strong>Advertencia:</strong> Modificar el firewall incorrectamente puede bloquear el acceso al servidor.
        Asegurese de tener acceso por consola antes de hacer cambios.
    </div>
</div>

<?php if ($type === 'none'): ?>
    <!-- No firewall detected -->
    <div class="card">
        <div class="card-header"><i class="bi bi-shield-x me-1"></i> Firewall no detectado</div>
        <div class="card-body">
            <p class="text-muted mb-3">No se detecto ningun firewall activo en este servidor (ni UFW ni iptables).</p>
            <p class="mb-2">Para instalar UFW (recomendado), ejecuta:</p>
            <div class="p-2 rounded mb-3" style="background:rgba(255,255,255,0.05);font-family:monospace;font-size:0.9rem;">
                <code>apt update && apt install ufw -y</code>
            </div>
            <p class="mb-2">Luego activa UFW con reglas basicas:</p>
            <div class="p-2 rounded mb-3" style="background:rgba(255,255,255,0.05);font-family:monospace;font-size:0.9rem;">
                <code>ufw default deny incoming</code><br>
                <code>ufw default allow outgoing</code><br>
                <code>ufw allow ssh</code><br>
                <code>ufw allow 80/tcp</code><br>
                <code>ufw allow 443/tcp</code><br>
                <code>ufw allow 8444/tcp</code><br>
                <code>ufw enable</code>
            </div>
            <p class="text-muted small mb-0">Despues de instalarlo y activarlo, recarga esta pagina.</p>
        </div>
    </div>
<?php else: ?>

    <!-- Security Audit Warnings -->
    <?php if (!empty($securityWarnings)): ?>
        <div class="card mb-3 border-danger">
            <div class="card-header bg-danger bg-opacity-10 text-danger d-flex align-items-center">
                <i class="bi bi-shield-exclamation me-2 fs-5"></i>
                <strong>Auditoria de Seguridad</strong>
                <span class="badge bg-danger ms-2"><?= count($securityWarnings) ?> <?= count($securityWarnings) === 1 ? 'problema' : 'problemas' ?></span>
            </div>
            <div class="card-body p-0">
                <?php foreach ($securityWarnings as $i => $warn): ?>
                    <div class="d-flex align-items-start p-3 <?= $i > 0 ? 'border-top border-secondary' : '' ?>">
                        <i class="bi <?= View::e($warn['icon']) ?> me-3 fs-5 <?= $warn['severity'] === 'danger' ? 'text-danger' : ($warn['severity'] === 'warning' ? 'text-warning' : 'text-info') ?>"></i>
                        <div class="flex-grow-1">
                            <strong class="<?= $warn['severity'] === 'danger' ? 'text-danger' : ($warn['severity'] === 'warning' ? 'text-warning' : 'text-info') ?>">
                                <?= View::e($warn['title']) ?>
                            </strong>
                            <p class="mb-0 mt-1 small text-muted"><?= View::e($warn['message']) ?></p>
                        </div>
                        <?php if (!empty($warn['fix']) && $warn['fix'] === 'delete' && !empty($warn['rule_num'])): ?>
                            <form method="POST" action="/settings/firewall/delete-rule" class="ms-3" onsubmit="return confirmFixDelete(this, <?= (int)$warn['rule_num'] ?>)">
                                <?= View::csrf() ?>
                                <input type="hidden" name="rule_number" value="<?= (int)$warn['rule_num'] ?>">
                                <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                <?php if (!empty($warn['delete_backend'])): ?>
                                    <input type="hidden" name="backend" value="<?= View::e($warn['delete_backend']) ?>">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">
                                    <i class="bi bi-wrench me-1"></i>Corregir
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Card 1: Estado del Firewall -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-shield-check me-1"></i> Estado del Firewall</div>
        <div class="card-body">
            <table class="table table-sm mb-3">
                <tr>
                    <td class="text-muted" style="width:40%">Tipo detectado</td>
                    <td>
                        <span class="badge bg-primary"><?= View::e(strtoupper($type)) ?></span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Estado</td>
                    <td>
                        <?php if ($active): ?>
                            <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Inactivo</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Politica por defecto (INPUT)</td>
                    <td>
                        <?php
                            $policyUpper = strtoupper($policy);
                            $policyClass = 'bg-secondary';
                            $policyNote  = '';
                            if (str_contains($policyUpper, 'DROP')) {
                                $policyClass = 'bg-danger';
                                $policyNote  = 'Todo el trafico no permitido explicitamente sera bloqueado';
                            } elseif (str_contains($policyUpper, 'ACCEPT')) {
                                $policyClass = 'bg-success';
                                $policyNote  = 'Todo el trafico es permitido por defecto';
                            }
                        ?>
                        <span class="badge <?= $policyClass ?>"><?= View::e($policy) ?></span>
                        <?php if ($policyNote): ?>
                            <small class="text-muted ms-2"><?= $policyNote ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">IP del admin (conectado)</td>
                    <td>
                        <code><?= View::e($adminIp) ?></code>
                        <span class="badge bg-info ms-1">Tu conexion</span>
                    </td>
                </tr>
            </table>

            <!-- Network Interfaces -->
            <?php if (!empty($networkInterfaces)): ?>
                <h6 class="mb-2"><i class="bi bi-hdd-network me-1"></i>Interfaces de red del servidor</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm mb-0" style="max-width:500px;">
                        <thead>
                            <tr class="text-muted">
                                <th>Interfaz</th>
                                <th>Tipo</th>
                                <th>Direccion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($networkInterfaces as $iface): ?>
                                <tr>
                                    <td>
                                        <code><?= View::e($iface['interface']) ?></code>
                                        <?php if ($iface['interface'] === 'lo'): ?>
                                            <span class="badge bg-secondary ms-1">loopback</span>
                                        <?php elseif (str_starts_with($iface['interface'], 'wg')): ?>
                                            <span class="badge bg-info ms-1">VPN</span>
                                        <?php elseif (str_starts_with($iface['interface'], 'docker')): ?>
                                            <span class="badge bg-warning text-dark ms-1">Docker</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= View::e($iface['family']) ?></span></td>
                                    <td><code><?= View::e($iface['address']) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap">
                <?php if ($type === 'ufw' || $type === 'iptables'): ?>
                    <?php
                        $fwLabel = strtoupper($type === 'iptables' ? 'iptables' : 'UFW');
                    ?>
                    <?php if ($active): ?>
                        <form method="POST" action="/settings/firewall/disable" class="d-inline" onsubmit="return confirmDisable(this)">
                            <?= View::csrf() ?>
                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-shield-x me-1"></i>Desactivar <?= View::e($fwLabel) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="/settings/firewall/enable" class="d-inline" onsubmit="return confirmEnable(this)">
                            <?= View::csrf() ?>
                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-shield-check me-1"></i>Activar <?= View::e($fwLabel) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Emergency button -->
                <form method="POST" action="/settings/firewall/emergency" class="d-inline" onsubmit="return confirmEmergency(this)">
                    <?= View::csrf() ?>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-lightning-fill me-1"></i>Permitir todo desde mi IP (<?= View::e($adminIp) ?>)
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Card 2: Reglas Actuales -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-1"></i> Reglas Actuales</span>
            <?php if ($type === 'iptables'): ?>
                <form method="POST" action="/settings/firewall/save" class="d-inline" onsubmit="return confirmSaveRules(this)">
                    <?= View::csrf() ?>
                    <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                    <button type="submit" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-save me-1"></i>Guardar Reglas
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($rules)): ?>
                <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>No se encontraron reglas configuradas.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>#</th>
                                <?php if ($type === 'ufw'): ?>
                                    <th>Destino</th>
                                    <th>Accion</th>
                                    <th>Direccion</th>
                                    <th>Origen</th>
                                    <th>Comentario</th>
                                <?php else: ?>
                                    <th>Target</th>
                                    <th>Protocolo</th>
                                    <th>Interfaz</th>
                                    <th>Origen</th>
                                    <th>Puerto</th>
                                    <th>Descripcion</th>
                                <?php endif; ?>
                                <th class="text-end" style="width:120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td><?= (int)$rule['num'] ?></td>
                                    <?php if ($type === 'ufw'): ?>
                                        <td><code><?= View::e($rule['to']) ?></code></td>
                                        <td>
                                            <?php
                                                $actionClass = 'bg-secondary';
                                                $actionUpper = strtoupper($rule['action']);
                                                if (stripos($actionUpper, 'ALLOW') !== false) $actionClass = 'bg-success';
                                                elseif (stripos($actionUpper, 'DENY') !== false || stripos($actionUpper, 'REJECT') !== false) $actionClass = 'bg-danger';
                                                elseif (stripos($actionUpper, 'LIMIT') !== false) $actionClass = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?= $actionClass ?>"><?= View::e($rule['action']) ?></span>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= View::e($rule['direction'] ?? 'IN') ?></span></td>
                                        <td><?= View::e($rule['from']) ?></td>
                                        <td class="text-muted"><?= View::e($rule['comment']) ?></td>
                                    <?php else: ?>
                                        <td>
                                            <?php
                                                $targetClass = $rule['target'] === 'ACCEPT' ? 'bg-success' : ($rule['target'] === 'DROP' ? 'bg-danger' : ($rule['target'] === 'REJECT' ? 'bg-warning text-dark' : 'bg-secondary'));
                                            ?>
                                            <span class="badge <?= $targetClass ?>"><?= View::e($rule['target']) ?></span>
                                        </td>
                                        <td><span class="badge bg-secondary"><?= View::e(strtoupper($rule['protocol'])) ?></span></td>
                                        <td>
                                            <?php if (!empty($rule['in'])): ?>
                                                <code><?= View::e($rule['in']) ?></code>
                                                <?php if ($rule['in'] === 'lo'): ?>
                                                    <span class="badge bg-secondary ms-1">loopback</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">*</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= View::e($rule['source']) ?></code></td>
                                        <td><?= $rule['port'] ? '<code>' . View::e($rule['port']) . '</code>' : '-' ?></td>
                                        <td class="text-muted small">
                                            <?php if (!empty($rule['state'])): ?>
                                                <span class="badge bg-info bg-opacity-25 text-info"><?= View::e($rule['state']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-outline-primary btn-sm" title="Editar regla"
                                            onclick="openEditModal(<?= (int)$rule['num'] ?>, <?= htmlspecialchars(json_encode($rule), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="/settings/firewall/delete-rule" class="d-inline" onsubmit="return confirmDelete(this, <?= (int)$rule['num'] ?>)">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="rule_number" value="<?= (int)$rule['num'] ?>">
                                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar regla">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (str_contains(strtolower($policy), 'deny') || str_contains(strtoupper($policy), 'DROP')): ?>
                            <tr style="background:rgba(239,68,68,0.06);border-top:2px solid #334155;">
                                <td><i class="bi bi-lock-fill text-danger"></i></td>
                                <?php if ($type === 'ufw'): ?>
                                <td><code>*</code></td>
                                <td><span class="badge bg-danger">DENY</span></td>
                                <td>IN</td>
                                <td>Anywhere</td>
                                <td colspan="2"><em class="text-muted small"><i class="bi bi-shield-fill-check me-1"></i>Politica por defecto — todo lo demas se deniega automaticamente</em></td>
                                <?php else: ?>
                                <td><span class="badge bg-danger">DROP</span></td>
                                <td>all</td>
                                <td>*</td>
                                <td>0.0.0.0/0</td>
                                <td>*</td>
                                <td colspan="2"><em class="text-muted small"><i class="bi bi-shield-fill-check me-1"></i>Politica por defecto</em></td>
                                <?php endif; ?>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (str_contains(strtolower($policy), 'deny') || str_contains(strtoupper($policy), 'DROP')): ?>
                <div class="mt-3 p-3 rounded" style="background:rgba(34,197,94,0.06);border:1px solid rgba(34,197,94,0.2);">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-shield-fill-check text-success" style="font-size:1.2rem;"></i>
                        <div>
                            <strong class="text-success">Firewall seguro</strong>
                            <p class="text-muted small mb-1 mt-1">La politica por defecto es <strong>DENY</strong> — todo el trafico entrante esta <strong>bloqueado</strong> excepto lo que aparece como ALLOW en la tabla de arriba.</p>
                            <p class="text-muted small mb-0">Cualquier puerto, protocolo o IP que no tenga una regla ALLOW explicita recibe un DROP silencioso. No necesitas crear reglas DENY para cada puerto — ya estan todos cerrados por defecto.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php if ($type === 'iptables' && strtoupper($policy) === 'DROP'): ?>
                <div class="alert alert-warning py-2 mt-3 mb-0 small" style="background:rgba(255,193,7,0.1);border-color:rgba(255,193,7,0.3);">
                    <i class="bi bi-shield-fill-exclamation me-1"></i>
                    <strong>Politica DROP activa:</strong> Todo el trafico que no coincida con las reglas ACCEPT de arriba sera <strong>bloqueado automaticamente</strong>.
                    No necesitas reglas DENY explicitas — solo aparecen las reglas que permiten trafico.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Editar Regla -->
    <div class="modal fade" id="editRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <form method="POST" action="/settings/firewall/edit-rule" id="editRuleForm" onsubmit="return confirmEditRule(this)">
                    <?= View::csrf() ?>
                    <input type="hidden" name="rule_number" id="editRuleNumber">
                    <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar Regla #<span id="editRuleNumLabel"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info py-2 small" style="background:rgba(13,202,240,0.1);border-color:rgba(13,202,240,0.3);">
                            <i class="bi bi-info-circle me-1"></i>
                            <?php if ($type === 'ufw'): ?>
                                UFW no soporta edicion in-place. Se eliminara la regla original y se creara una nueva.
                            <?php else: ?>
                                Se eliminara la regla original y se creara una nueva al final de la cadena.
                            <?php endif; ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Accion</label>
                                <select name="action" class="form-select" id="editAction">
                                    <option value="allow">Permitir (ALLOW)</option>
                                    <option value="deny">Denegar (DENY)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Protocolo</label>
                                <select name="protocol" class="form-select" id="editProtocol" onchange="toggleEditPortField()">
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                    <?php if ($type === 'ufw'): ?>
                                        <option value="both">Ambos</option>
                                    <?php endif; ?>
                                    <option value="all">Todos</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">IP Origen</label>
                                <input type="text" name="from" class="form-control" id="editFrom" placeholder="Ej: 192.168.1.0/24">
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="editAnyIp" name="any_ip" onchange="toggleEditAnyIp(this)">
                                    <label class="form-check-label small text-muted" for="editAnyIp">Cualquiera</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Puerto</label>
                                <input type="text" name="port" class="form-control" id="editPort" placeholder="Ej: 80">
                            </div>
                            <?php if ($type === 'ufw'): ?>
                                <div class="col-12">
                                    <label class="form-label">Comentario</label>
                                    <input type="text" name="comment" class="form-control" id="editComment" placeholder="Opcional">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manual iptables rules (outside UFW) -->
    <?php if (!empty($manualIptables)): ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
                Reglas iptables manuales <small class="text-muted">(fuera de UFW)</small>
                <span class="badge bg-warning text-dark ms-2"><?= count($manualIptables) ?></span>
            </span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                <i class="bi bi-info-circle me-1"></i>Estas reglas fueron añadidas directamente con <code>iptables</code> y UFW no las gestiona.
                Se procesan <strong>antes</strong> que las reglas de UFW y pueden anularlas. Considera migrarlas a UFW o eliminarlas.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr class="text-muted">
                            <th>#</th>
                            <th>Accion</th>
                            <th>Protocolo</th>
                            <th>Origen</th>
                            <th>Puerto</th>
                            <th>Detalles</th>
                            <th class="text-end" style="width:100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manualIptables as $mr): ?>
                        <tr>
                            <td><?= (int)$mr['num'] ?></td>
                            <td>
                                <?php if ($mr['target'] === 'ACCEPT'): ?>
                                    <span class="badge bg-success">ACCEPT</span>
                                <?php elseif ($mr['target'] === 'DROP'): ?>
                                    <span class="badge bg-danger">DROP</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= View::e($mr['target']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= View::e($mr['protocol']) ?></code></td>
                            <td><code class="small"><?= View::e($mr['source']) ?></code></td>
                            <td><code><?= View::e($mr['port']) ?></code></td>
                            <td><small class="text-muted"><?= View::e($mr['extra'] ?: '-') ?></small></td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="/settings/firewall/delete-rule" class="d-inline" onsubmit="return confirmDelete(this, <?= (int)$mr['num'] ?>)">
                                    <?= View::csrf() ?>
                                    <input type="hidden" name="rule_number" value="<?= (int)$mr['num'] ?>">
                                    <input type="hidden" name="backend" value="iptables">
                                    <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar regla manual de iptables">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Card 3: Anadir Regla -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-plus-circle me-1"></i> Anadir Regla</div>
        <div class="card-body">
            <form method="POST" action="/settings/firewall/add-rule" id="addRuleForm">
                <?= View::csrf() ?>
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Accion</label>
                        <select name="action" class="form-select" id="ruleAction">
                            <option value="allow">Permitir</option>
                            <option value="deny">Denegar</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">IP Origen</label>
                        <div class="input-group">
                            <input type="text" name="from" class="form-control" id="ruleFrom" placeholder="Ej: 192.168.1.0/24">
                        </div>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="anyIpCheck" name="any_ip" onchange="toggleAnyIp(this)">
                            <label class="form-check-label small text-muted" for="anyIpCheck">Cualquiera</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Puerto</label>
                        <input type="number" name="port" class="form-control" id="rulePort" placeholder="Ej: 80" min="1" max="65535">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Protocolo</label>
                        <select name="protocol" class="form-select" id="ruleProtocol" onchange="togglePortField()">
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                            <?php if ($type === 'ufw'): ?>
                                <option value="both">Ambos</option>
                            <?php endif; ?>
                            <option value="all">Todos</option>
                        </select>
                    </div>
                    <?php if ($type === 'ufw'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Comentario</label>
                            <input type="text" name="comment" class="form-control" id="ruleComment" placeholder="Opcional">
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Anadir Regla
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Card 4: Snapshot completo -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-hdd-stack me-1"></i> Snapshot completo del firewall</div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Guarda y restaura la configuracion completa efectiva del firewall (estado real actual) en un solo paso.
            </p>

            <form method="POST" action="/settings/firewall/snapshots/save" id="fullSnapshotForm" class="mb-4" onsubmit="return confirmSaveFullSnapshot(this)">
                <?= View::csrf() ?>
                <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Nombre del snapshot</label>
                        <input type="text" name="snapshot_name" class="form-control" placeholder="Ej: Produccion estable 2026-04-26" maxlength="100" required>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Nota (opcional)</label>
                        <input type="text" name="snapshot_note" class="form-control" placeholder="Ej: Antes de cambios de puertos SSH y panel">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-camera me-1"></i>Guardar snapshot completo
                    </button>
                </div>
            </form>

            <?php if (empty($fullSnapshots ?? [])): ?>
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No hay snapshots completos guardados.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>Snapshot</th>
                                <th>Origen</th>
                                <th>Fecha</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($fullSnapshots ?? []) as $snap): ?>
                                <tr>
                                    <td>
                                        <strong><?= View::e($snap['name']) ?></strong>
                                        <?php if (!empty($snap['note'])): ?>
                                            <div class="small text-muted"><?= View::e($snap['note']) ?></div>
                                        <?php endif; ?>
                                        <div class="small text-muted">hash: <code><?= View::e(substr((string)($snap['hash'] ?? ''), 0, 12)) ?></code></div>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= View::e(strtoupper((string)($snap['source_type'] ?? 'unknown'))) ?></span></td>
                                    <td class="small text-muted"><code><?= View::e((string)($snap['created_at'] ?? '')) ?></code></td>
                                    <td class="text-end text-nowrap">
                                        <form method="POST" action="/settings/firewall/snapshots/apply" class="d-inline" onsubmit="return confirmApplyFullSnapshot(this, <?= htmlspecialchars(json_encode((string)$snap['name']), ENT_QUOTES, 'UTF-8') ?>)">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="snapshot_id" value="<?= View::e($snap['id']) ?>">
                                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                            <button type="submit" class="btn btn-outline-warning btn-sm" title="Restaurar snapshot completo">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="/settings/firewall/snapshots/delete" class="d-inline" onsubmit="return confirmDeleteFullSnapshot(this, <?= htmlspecialchars(json_encode((string)$snap['name']), ENT_QUOTES, 'UTF-8') ?>)">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="snapshot_id" value="<?= View::e($snap['id']) ?>">
                                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar snapshot">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 5: Exportar / Importar -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-left-right me-1"></i> Exportar / Importar configuracion</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-6">
                    <h6 class="mb-2"><i class="bi bi-box-arrow-down me-1"></i>Exportar</h6>
                    <p class="small text-muted mb-2">Descarga JSON con reglas normalizadas y bloque raw (iptables) para despliegue en otros nodos.</p>
                    <form method="POST" action="/settings/firewall/export" onsubmit="return confirmExportConfig(this)">
                        <?= View::csrf() ?>
                        <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-download me-1"></i>Exportar JSON
                        </button>
                    </form>
                </div>
                <div class="col-lg-6">
                    <h6 class="mb-2"><i class="bi bi-box-arrow-in-up me-1"></i>Importar</h6>
                    <p class="small text-muted mb-2">Carga JSON exportado. Puedes anadir reglas o reemplazar configuracion existente.</p>
                    <form method="POST" action="/settings/firewall/import" enctype="multipart/form-data" onsubmit="return confirmImportConfig(this)">
                        <?= View::csrf() ?>
                        <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                        <div class="input-group input-group-sm mb-2">
                            <input type="file" class="form-control" name="config_file" accept=".json,application/json" required>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="replaceExistingImport" name="replace_existing">
                            <label class="form-check-label small text-muted" for="replaceExistingImport">
                                Reemplazar configuracion actual (modo destructivo)
                            </label>
                        </div>
                        <button type="submit" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-upload me-1"></i>Importar JSON
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 6: Presets -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-bookmark-star me-1"></i> Presets de Reglas</div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Crea presets para reutilizar reglas frecuentes y aplicarlas con un clic.
            </p>

            <form method="POST" action="/settings/firewall/presets/save" id="presetRuleForm" class="mb-4" onsubmit="return confirmSavePreset(this)">
                <?= View::csrf() ?>
                <input type="hidden" name="preset_id" id="presetId">
                <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Nombre del preset</label>
                        <input type="text" name="preset_name" id="presetName" class="form-control" placeholder="Ej: SSH Oficina" maxlength="80" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Accion</label>
                        <select name="action" id="presetAction" class="form-select">
                            <option value="allow">Permitir</option>
                            <option value="deny">Denegar</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">IP Origen</label>
                        <input type="text" name="from" id="presetFrom" class="form-control" placeholder="Ej: 192.168.1.0/24">
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="presetAnyIp" name="any_ip" onchange="togglePresetAnyIp(this)">
                            <label class="form-check-label small text-muted" for="presetAnyIp">Cualquiera</label>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Puerto</label>
                        <input type="number" name="port" id="presetPort" class="form-control" min="1" max="65535" placeholder="80">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Protocolo</label>
                        <select name="protocol" id="presetProtocol" class="form-select" onchange="togglePresetPortField()">
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                            <?php if ($type === 'ufw'): ?>
                                <option value="both">Ambos</option>
                            <?php endif; ?>
                            <option value="all">Todos</option>
                        </select>
                    </div>
                    <?php if ($type === 'ufw'): ?>
                        <div class="col-md-12">
                            <label class="form-label">Comentario (opcional)</label>
                            <input type="text" name="comment" id="presetComment" class="form-control" placeholder="Ej: SSH desde oficina">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-save me-1"></i>Guardar preset
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetPresetForm()">
                        <i class="bi bi-eraser me-1"></i>Limpiar
                    </button>
                </div>
            </form>

            <?php if (empty($rulePresets ?? [])): ?>
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No hay presets guardados todavia.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>Preset</th>
                                <th>Regla</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($rulePresets ?? []) as $preset): ?>
                                <tr>
                                    <td>
                                        <strong><?= View::e($preset['name']) ?></strong>
                                        <div class="small text-muted">ID: <code><?= View::e($preset['id']) ?></code></div>
                                    </td>
                                    <td class="small">
                                        <span class="badge <?= ($preset['action'] ?? '') === 'deny' ? 'bg-danger' : 'bg-success' ?> me-1"><?= strtoupper(View::e($preset['action'] ?? 'allow')) ?></span>
                                        <code><?= View::e($preset['protocol'] ?? 'tcp') ?></code>
                                        <?php if (!empty($preset['port'])): ?>
                                            <code>dpt:<?= View::e($preset['port']) ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">all ports</span>
                                        <?php endif; ?>
                                        <span class="text-muted ms-1">from</span> <code><?= View::e($preset['from'] ?? '0.0.0.0/0') ?></code>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <form method="POST" action="/settings/firewall/presets/apply" class="d-inline" onsubmit="return confirmApplyPreset(this, <?= htmlspecialchars(json_encode((string)$preset['name']), ENT_QUOTES, 'UTF-8') ?>)">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="preset_id" value="<?= View::e($preset['id']) ?>">
                                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                            <button type="submit" class="btn btn-outline-success btn-sm" title="Aplicar preset">
                                                <i class="bi bi-lightning-charge"></i>
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-outline-primary btn-sm" title="Editar preset"
                                            onclick='editPreset(<?= htmlspecialchars(json_encode($preset), ENT_QUOTES, 'UTF-8') ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" action="/settings/firewall/presets/delete" class="d-inline" onsubmit="return confirmDeletePreset(this, <?= htmlspecialchars(json_encode((string)$preset['name']), ENT_QUOTES, 'UTF-8') ?>)">
                                            <?= View::csrf() ?>
                                            <input type="hidden" name="preset_id" value="<?= View::e($preset['id']) ?>">
                                            <input type="hidden" name="admin_password" class="fw-admin-password-field" value="">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar preset">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Card 7: Sugerencias -->
    <div class="card mb-3">
        <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#suggestionsCollapse" aria-expanded="false">
            <i class="bi bi-lightbulb me-1"></i> Sugerencias
            <i class="bi bi-chevron-down float-end"></i>
        </div>
        <div class="collapse" id="suggestionsCollapse">
            <div class="card-body">
                <!-- Hosting suggestions -->
                <h6 class="mb-2"><i class="bi bi-globe me-1"></i>Reglas basicas para hosting</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>Descripcion</th>
                                <th>Puerto</th>
                                <th>Protocolo</th>
                                <th class="text-end">Accion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hostSuggestions as $s): ?>
                                <tr>
                                    <td><?= View::e($s['label']) ?></td>
                                    <td><code><?= View::e($s['port']) ?></code></td>
                                    <td><?= View::e(strtoupper($s['protocol'])) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-outline-success btn-sm"
                                            onclick="fillRule('<?= View::e($s['action']) ?>', '<?= View::e($s['from']) ?>', '<?= View::e($s['port']) ?>', '<?= View::e($s['protocol']) ?>', '<?= View::e($s['comment']) ?>')">
                                            <i class="bi bi-arrow-down-circle me-1"></i>Aplicar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($replSuggestions)): ?>
                    <h6 class="mb-2"><i class="bi bi-arrow-repeat me-1"></i>Reglas sugeridas para replicacion</h6>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr class="text-muted">
                                    <th>Descripcion</th>
                                    <th>IP Origen</th>
                                    <th>Puerto</th>
                                    <th class="text-end">Accion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($replSuggestions as $s): ?>
                                    <tr>
                                        <td><?= View::e($s['label']) ?></td>
                                        <td><code><?= View::e($s['from']) ?></code></td>
                                        <td><code><?= View::e($s['port']) ?></code></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-success btn-sm"
                                                onclick="fillRule('<?= View::e($s['action']) ?>', '<?= View::e($s['from']) ?>', '<?= View::e($s['port']) ?>', '<?= View::e($s['protocol']) ?>', '<?= View::e($s['comment']) ?>')">
                                                <i class="bi bi-arrow-down-circle me-1"></i>Aplicar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i>No hay sugerencias de replicacion. Configura la replicacion en
                        <a href="/settings/replication" class="text-info">Ajustes &gt; Replicacion</a> para ver sugerencias.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card 8: Comandos utiles -->
    <div class="card mb-3">
        <div class="card-header">
            <i class="bi bi-terminal me-1"></i> Comandos utiles para verificar firewall (shell)
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Ejecuta estos comandos en consola para comparar el estado real del servidor con lo que muestra el panel.
            </p>

            <div class="mb-3">
                <div class="fw-semibold mb-1">1) Ver firewall real (lo que manda)</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>iptables -L -n --line-numbers</code></pre>
                <small class="text-muted">Compara con la tabla del panel: las reglas clave deben coincidir.</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1 text-danger">2) Detectar error critico: regla que abre todo</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>iptables -S | grep -E "ACCEPT.*0.0.0.0/0.*0.0.0.0/0"</code></pre>
                <small class="text-muted">Si ves una regla global tipo <code>-A INPUT -j ACCEPT</code>, el firewall esta abierto.</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1">3) Detectar duplicados</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>iptables -S | sort | uniq -d</code></pre>
                <small class="text-muted">Si devuelve lineas, hay reglas duplicadas.</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1">4) Comprobar politicas</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>iptables -S | grep "^-P"</code></pre>
                <small class="text-muted">Esperado tipico: <code>-P INPUT DROP</code>, <code>-P FORWARD DROP</code>, <code>-P OUTPUT ACCEPT</code>.</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1">5) Comprobar SSH solo a rango autorizado</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>iptables -L INPUT -n | grep dpt:22</code></pre>
                <small class="text-muted">Revisa rapidamente desde que origenes esta permitido SSH.</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1">6) Ver puertos escuchando realmente</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>ss -tuln</code></pre>
                <small class="text-muted">Muestra servicios escuchando (esto no es firewall).</small>
            </div>

            <div class="mb-3">
                <div class="fw-semibold mb-1">7) Test real de acceso</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>nc -zv 127.0.0.1 22</code></pre>
                <small class="text-muted">Prueba tambien desde otra IP: tu IP permitida debe entrar; otra no permitida debe fallar.</small>
            </div>

            <div>
                <div class="fw-semibold mb-1">8) Estado de Fail2Ban</div>
                <pre class="mb-1 p-2 rounded" style="background:rgba(15,23,42,0.6);"><code>fail2ban-client status</code></pre>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
var firewallType = '<?= View::e($type) ?>';
var firewallLabel = firewallType === 'iptables' ? 'iptables' : 'UFW';

// ─── Edit Modal ─────────────────────────────────────────
function openEditModal(ruleNum, ruleData) {
    document.getElementById('editRuleNumber').value = ruleNum;
    document.getElementById('editRuleNumLabel').textContent = ruleNum;

    var editAnyIp = document.getElementById('editAnyIp');
    var editFrom = document.getElementById('editFrom');

    if (firewallType === 'ufw') {
        // UFW rule: {num, to, action, direction, from, comment}
        var action = ruleData.action.toLowerCase();
        document.getElementById('editAction').value = (action.indexOf('allow') !== -1) ? 'allow' : 'deny';

        // Parse port and protocol from "to" field (e.g. "80/tcp", "443", "Anywhere")
        var to = ruleData.to || '';
        var port = '';
        var proto = 'both';
        if (to.match(/\/tcp/i)) {
            port = to.replace(/\/tcp.*/i, '');
            proto = 'tcp';
        } else if (to.match(/\/udp/i)) {
            port = to.replace(/\/udp.*/i, '');
            proto = 'udp';
        } else if (to.match(/^\d+$/)) {
            port = to;
        }
        document.getElementById('editPort').value = port;
        document.getElementById('editProtocol').value = proto;

        // From
        var from = ruleData.from || '';
        if (from === 'Anywhere' || from === 'Anywhere (v6)' || from === '') {
            editAnyIp.checked = true;
            editFrom.value = '';
            editFrom.disabled = true;
        } else {
            editAnyIp.checked = false;
            editFrom.disabled = false;
            editFrom.value = from;
        }

        // Comment
        var commentEl = document.getElementById('editComment');
        if (commentEl) commentEl.value = ruleData.comment || '';

    } else {
        // iptables rule: {num, target, protocol, source, port}
        document.getElementById('editAction').value = (ruleData.target === 'ACCEPT') ? 'allow' : 'deny';
        document.getElementById('editProtocol').value = ruleData.protocol || 'tcp';
        document.getElementById('editPort').value = ruleData.port || '';

        var source = ruleData.source || '';
        if (source === '0.0.0.0/0' || source === '') {
            editAnyIp.checked = true;
            editFrom.value = '';
            editFrom.disabled = true;
        } else {
            editAnyIp.checked = false;
            editFrom.disabled = false;
            editFrom.value = source;
        }
    }

    var modal = new bootstrap.Modal(document.getElementById('editRuleModal'));
    modal.show();
}

function toggleEditAnyIp(cb) {
    var fromEl = document.getElementById('editFrom');
    if (cb.checked) {
        fromEl.value = '';
        fromEl.disabled = true;
        fromEl.placeholder = 'Cualquiera (0.0.0.0/0)';
    } else {
        fromEl.disabled = false;
        fromEl.placeholder = 'Ej: 192.168.1.0/24';
    }
}

// ─── Add Rule Form ──────────────────────────────────────
function fillRule(action, from, port, protocol, comment) {
    var actionEl = document.getElementById('ruleAction');
    var fromEl = document.getElementById('ruleFrom');
    var portEl = document.getElementById('rulePort');
    var protocolEl = document.getElementById('ruleProtocol');
    var commentEl = document.getElementById('ruleComment');
    var anyIpCheck = document.getElementById('anyIpCheck');

    if (actionEl) actionEl.value = action;
    if (portEl) portEl.value = port;
    if (protocolEl) protocolEl.value = protocol;
    if (commentEl) commentEl.value = comment || '';

    if (from === '0.0.0.0/0' || from === '' || from === 'any') {
        if (anyIpCheck) {
            anyIpCheck.checked = true;
            toggleAnyIp(anyIpCheck);
        }
    } else {
        if (anyIpCheck) {
            anyIpCheck.checked = false;
            toggleAnyIp(anyIpCheck);
        }
        if (fromEl) fromEl.value = from;
    }

    document.getElementById('addRuleForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toggleAnyIp(cb) {
    var fromEl = document.getElementById('ruleFrom');
    if (cb.checked) {
        fromEl.value = '';
        fromEl.disabled = true;
        fromEl.placeholder = 'Cualquiera (0.0.0.0/0)';
    } else {
        fromEl.disabled = false;
        fromEl.placeholder = 'Ej: 192.168.1.0/24';
    }
}

// ─── Port field toggle ──────────────────────────────────
function togglePortField() {
    var proto = document.getElementById('ruleProtocol').value;
    var portEl = document.getElementById('rulePort');
    if (proto === 'all') {
        portEl.value = '';
        portEl.disabled = true;
        portEl.placeholder = 'No aplica';
    } else {
        portEl.disabled = false;
        portEl.placeholder = 'Ej: 80';
    }
}

function toggleEditPortField() {
    var proto = document.getElementById('editProtocol').value;
    var portEl = document.getElementById('editPort');
    if (proto === 'all') {
        portEl.value = '';
        portEl.disabled = true;
        portEl.placeholder = 'No aplica';
    } else {
        portEl.disabled = false;
        portEl.placeholder = 'Ej: 80';
    }
}

function togglePresetAnyIp(cb) {
    var fromEl = document.getElementById('presetFrom');
    if (!fromEl) return;
    if (cb.checked) {
        fromEl.value = '';
        fromEl.disabled = true;
        fromEl.placeholder = 'Cualquiera (0.0.0.0/0)';
    } else {
        fromEl.disabled = false;
        fromEl.placeholder = 'Ej: 192.168.1.0/24';
    }
}

function togglePresetPortField() {
    var protoEl = document.getElementById('presetProtocol');
    var portEl = document.getElementById('presetPort');
    if (!protoEl || !portEl) return;
    if (protoEl.value === 'all') {
        portEl.value = '';
        portEl.disabled = true;
        portEl.placeholder = 'No aplica';
    } else {
        portEl.disabled = false;
        portEl.placeholder = '80';
    }
}

function resetPresetForm() {
    var form = document.getElementById('presetRuleForm');
    if (!form) return;
    form.reset();
    var presetId = document.getElementById('presetId');
    if (presetId) presetId.value = '';
    togglePresetAnyIp(document.getElementById('presetAnyIp'));
    togglePresetPortField();
}

function editPreset(preset) {
    if (!preset || typeof preset !== 'object') return;
    var form = document.getElementById('presetRuleForm');
    if (!form) return;

    document.getElementById('presetId').value = preset.id || '';
    document.getElementById('presetName').value = preset.name || '';
    document.getElementById('presetAction').value = preset.action || 'allow';
    document.getElementById('presetProtocol').value = preset.protocol || 'tcp';
    document.getElementById('presetPort').value = preset.port || '';

    var any = (preset.from || '') === '0.0.0.0/0' || (preset.from || '') === '';
    var anyIp = document.getElementById('presetAnyIp');
    var fromEl = document.getElementById('presetFrom');
    if (anyIp) anyIp.checked = any;
    if (fromEl) fromEl.value = any ? '' : (preset.from || '');
    togglePresetAnyIp(anyIp);

    var commentEl = document.getElementById('presetComment');
    if (commentEl) commentEl.value = preset.comment || '';
    togglePresetPortField();
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ─── SwalDark Confirmations ─────────────────────────────
function setAdminPassword(form, password) {
    var fields = form.querySelectorAll('.fw-admin-password-field');
    fields.forEach(function(field) { field.value = password || ''; });
}

function confirmWithAdminPassword(form, opts) {
    opts = opts || {};
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: opts.title || 'Confirmar accion',
            html:
                (opts.html || '') +
                '<div class="mt-3 text-start">' +
                '<label for="swal-fw-admin-password" class="form-label small mb-1">Contrasena admin</label>' +
                '<input type="password" id="swal-fw-admin-password" class="swal2-input m-0" style="width:100%;background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Tu contrasena de administrador" autocomplete="current-password">' +
                '</div>',
            icon: opts.icon || 'warning',
            showCancelButton: true,
            confirmButtonText: opts.confirmText || 'Confirmar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: opts.confirmColor || '#dc3545',
            preConfirm: function() {
                var pwd = document.getElementById('swal-fw-admin-password').value;
                if (!pwd) {
                    Swal.showValidationMessage('Debes ingresar tu contrasena de administrador');
                    return false;
                }
                return pwd;
            }
        }).then(function(result) {
            if (!result.isConfirmed) return;
            setAdminPassword(form, result.value || '');
            form.onsubmit = null;
            form.submit();
        });
        return false;
    }

    var ok = confirm(opts.fallbackConfirm || 'Confirmar accion?');
    if (!ok) return false;
    var pwd = prompt('Contrasena de administrador:');
    if (!pwd) return false;
    setAdminPassword(form, pwd);
    return true;
}

function fwEsc(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function confirmFixDelete(form, ruleNum) {
    return confirmWithAdminPassword(form, {
        title: 'Corregir problema de seguridad',
        html: 'Se eliminara la regla <strong>#' + ruleNum + '</strong> que representa un riesgo de seguridad.<br><small class="text-muted">Las reglas se renumeraran automaticamente.</small>',
        icon: 'warning',
        confirmText: 'Si, corregir',
        confirmColor: '#dc3545',
        fallbackConfirm: 'Eliminar la regla #' + ruleNum + ' para corregir el problema?'
    });
}

function confirmDelete(form, ruleNum) {
    return confirmWithAdminPassword(form, {
        title: 'Eliminar regla #' + ruleNum,
        html: 'Seguro que quieres eliminar la regla <strong>#' + ruleNum + '</strong>?<br><small class="text-muted">Esta accion no se puede deshacer.</small>',
        icon: 'warning',
        confirmText: 'Si, eliminar',
        confirmColor: '#dc3545',
        fallbackConfirm: 'Eliminar la regla #' + ruleNum + '?'
    });
}

function confirmEditRule(form) {
    return confirmWithAdminPassword(form, {
        title: 'Guardar edicion de regla',
        html: 'Se aplicaran los cambios en la regla de firewall.',
        icon: 'warning',
        confirmText: 'Guardar cambios',
        confirmColor: '#0ea5e9',
        fallbackConfirm: 'Guardar cambios de la regla?'
    });
}

function confirmSaveRules(form) {
    return confirmWithAdminPassword(form, {
        title: 'Guardar reglas persistentes',
        html: 'Se guardara la configuracion actual de iptables en disco.',
        icon: 'question',
        confirmText: 'Guardar reglas',
        confirmColor: '#198754',
        fallbackConfirm: 'Guardar reglas de iptables?'
    });
}

function confirmSavePreset(form) {
    return confirmWithAdminPassword(form, {
        title: 'Guardar preset',
        html: 'Se creara o actualizara el preset de firewall.',
        icon: 'question',
        confirmText: 'Guardar preset',
        confirmColor: '#0ea5e9',
        fallbackConfirm: 'Guardar preset?'
    });
}

function confirmSaveFullSnapshot(form) {
    return confirmWithAdminPassword(form, {
        title: 'Guardar snapshot completo',
        html: 'Se guardara una copia completa del estado real actual del firewall.',
        icon: 'question',
        confirmText: 'Guardar snapshot',
        confirmColor: '#198754',
        fallbackConfirm: 'Guardar snapshot completo del firewall?'
    });
}

function confirmExportConfig(form) {
    return confirmWithAdminPassword(form, {
        title: 'Exportar configuracion',
        html: 'Se descargara un archivo JSON con la configuracion actual del firewall.',
        icon: 'question',
        confirmText: 'Exportar',
        confirmColor: '#0ea5e9',
        fallbackConfirm: 'Exportar configuracion firewall?'
    });
}

function confirmImportConfig(form) {
    var replaceCheck = form.querySelector('[name="replace_existing"]');
    var replace = !!(replaceCheck && replaceCheck.checked);
    var html = replace
        ? 'Se importara el archivo en modo <strong>reemplazar</strong>.<br><small class="text-muted">Esto puede sobrescribir la configuracion actual.</small>'
        : 'Se importara el archivo en modo <strong>anadir</strong> (append).';
    return confirmWithAdminPassword(form, {
        title: 'Importar configuracion',
        html: html,
        icon: 'warning',
        confirmText: 'Importar',
        confirmColor: '#f59e0b',
        fallbackConfirm: 'Importar configuracion firewall?'
    });
}

function confirmDeletePreset(form, presetName) {
    return confirmWithAdminPassword(form, {
        title: 'Eliminar preset',
        html: 'Seguro que quieres eliminar el preset <strong>' + fwEsc(presetName || '') + '</strong>?',
        icon: 'warning',
        confirmText: 'Eliminar preset',
        confirmColor: '#dc3545',
        fallbackConfirm: 'Eliminar preset?'
    });
}

function confirmApplyPreset(form, presetName) {
    return confirmWithAdminPassword(form, {
        title: 'Aplicar preset',
        html: 'Se aplicara el preset <strong>' + fwEsc(presetName || '') + '</strong> al firewall actual.',
        icon: 'warning',
        confirmText: 'Aplicar preset',
        confirmColor: '#198754',
        fallbackConfirm: 'Aplicar preset?'
    });
}

function confirmApplyFullSnapshot(form, snapshotName) {
    return confirmWithAdminPassword(form, {
        title: 'Restaurar snapshot completo',
        html:
            'Se restaurara el snapshot <strong>' + fwEsc(snapshotName || '') + '</strong> sobre la configuracion actual.<br>' +
            '<small class="text-muted">Esto reemplaza el estado efectivo de reglas cargado ahora.</small>',
        icon: 'warning',
        confirmText: 'Restaurar snapshot',
        confirmColor: '#f59e0b',
        fallbackConfirm: 'Restaurar snapshot completo?'
    });
}

function confirmDeleteFullSnapshot(form, snapshotName) {
    return confirmWithAdminPassword(form, {
        title: 'Eliminar snapshot completo',
        html: 'Seguro que quieres eliminar el snapshot <strong>' + fwEsc(snapshotName || '') + '</strong>?',
        icon: 'warning',
        confirmText: 'Eliminar snapshot',
        confirmColor: '#dc3545',
        fallbackConfirm: 'Eliminar snapshot completo?'
    });
}

function confirmEnable(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Activar ' + firewallLabel,
            html:
                '¿Seguro que quieres activar ' + firewallLabel + '?<br><small class="text-muted">Asegurate de que las reglas permiten tu acceso al servidor.</small>' +
                '<div class="mt-3 text-start">' +
                '<label for="swal-fw-enable-password" class="form-label small mb-1">Contrasena admin</label>' +
                '<input type="password" id="swal-fw-enable-password" class="swal2-input m-0" style="width:100%;background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Tu contrasena de administrador" autocomplete="current-password">' +
                '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Si, activar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#585b70',
            preConfirm: function() {
                var pwd = document.getElementById('swal-fw-enable-password').value;
                if (!pwd) {
                    Swal.showValidationMessage('Debes ingresar tu contrasena de administrador');
                    return false;
                }
                return pwd;
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                var pwdField = form.querySelector('.fw-admin-password-field');
                if (pwdField) pwdField.value = result.value || '';
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    var confirmed = confirm('¿Activar ' + firewallLabel + '?');
    if (!confirmed) return false;
    var pwd = prompt('Contrasena de administrador:');
    if (!pwd) return false;
    var pwdField = form.querySelector('.fw-admin-password-field');
    if (pwdField) pwdField.value = pwd;
    return true;
}

function confirmDisable(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Desactivar ' + firewallLabel,
            html:
                '<strong class="text-danger">Advertencia:</strong> Desactivar ' + firewallLabel + ' dejara el servidor sin proteccion.<br>Todas las reglas se desactivaran (pero se conservaran para reactivar).' +
                '<div class="mt-3 text-start">' +
                '<label for="swal-fw-disable-password" class="form-label small mb-1">Contrasena admin</label>' +
                '<input type="password" id="swal-fw-disable-password" class="swal2-input m-0" style="width:100%;background:#0f172a;color:#e2e8f0;border:1px solid #334155;" placeholder="Tu contrasena de administrador" autocomplete="current-password">' +
                '</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, desactivar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#585b70',
            preConfirm: function() {
                var pwd = document.getElementById('swal-fw-disable-password').value;
                if (!pwd) {
                    Swal.showValidationMessage('Debes ingresar tu contrasena de administrador');
                    return false;
                }
                return pwd;
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                var pwdField = form.querySelector('.fw-admin-password-field');
                if (pwdField) pwdField.value = result.value || '';
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    var confirmed = confirm('¿Desactivar ' + firewallLabel + '? El servidor quedara sin proteccion.');
    if (!confirmed) return false;
    var pwd = prompt('Contrasena de administrador:');
    if (!pwd) return false;
    var pwdField = form.querySelector('.fw-admin-password-field');
    if (pwdField) pwdField.value = pwd;
    return true;
}

function confirmEmergency(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Acceso de emergencia',
            html: 'Esto permitira <strong>todo el trafico</strong> desde tu IP actual.<br>Util si accidentalmente te bloqueaste a ti mismo.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, permitir mi IP',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#585b70',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Permitir todo el trafico desde tu IP actual?');
}

togglePresetPortField();
</script>
