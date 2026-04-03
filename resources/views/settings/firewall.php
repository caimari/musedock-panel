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
                <?php if ($type === 'ufw'): ?>
                    <?php if ($active): ?>
                        <form method="POST" action="/settings/firewall/disable" class="d-inline" onsubmit="return confirmDisable(this)">
                            <?= View::csrf() ?>
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-shield-x me-1"></i>Desactivar UFW
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="/settings/firewall/enable" class="d-inline" onsubmit="return confirmEnable(this)">
                            <?= View::csrf() ?>
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-shield-check me-1"></i>Activar UFW
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
                <form method="POST" action="/settings/firewall/save" class="d-inline">
                    <?= View::csrf() ?>
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
                <form method="POST" action="/settings/firewall/edit-rule" id="editRuleForm">
                    <?= View::csrf() ?>
                    <input type="hidden" name="rule_number" id="editRuleNumber">
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

    <!-- Card 4: Sugerencias -->
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

<?php endif; ?>

<script>
var firewallType = '<?= View::e($type) ?>';

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

// ─── SwalDark Confirmations ─────────────────────────────
function confirmFixDelete(form, ruleNum) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Corregir problema de seguridad',
            html: 'Se eliminara la regla <strong>#' + ruleNum + '</strong> que representa un riesgo de seguridad.<br><small class="text-muted">Las reglas se renumeraran automaticamente.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, corregir',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#585b70',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Eliminar la regla #' + ruleNum + ' para corregir el problema de seguridad?');
}

function confirmDelete(form, ruleNum) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Eliminar regla #' + ruleNum,
            html: '¿Seguro que quieres eliminar la regla <strong>#' + ruleNum + '</strong>?<br><small class="text-muted">Esta accion no se puede deshacer.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, eliminar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#585b70',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Eliminar la regla #' + ruleNum + '?');
}

function confirmEnable(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Activar Firewall',
            html: '¿Seguro que quieres activar el firewall UFW?<br><small class="text-muted">Asegurate de que las reglas permiten tu acceso al servidor.</small>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Si, activar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#198754',
            cancelButtonColor: '#585b70',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Activar el firewall UFW?');
}

function confirmDisable(form) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Desactivar Firewall',
            html: '<strong class="text-danger">Advertencia:</strong> Desactivar el firewall dejara el servidor sin proteccion.<br>Todas las reglas se desactivaran (pero se conservaran para reactivar).',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Si, desactivar',
            cancelButtonText: 'Cancelar',
            background: '#1e1e2e',
            color: '#cdd6f4',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#585b70',
        }).then(function(result) {
            if (result.isConfirmed) {
                form.onsubmit = null;
                form.submit();
            }
        });
        return false;
    }
    return confirm('¿Desactivar el firewall UFW? El servidor quedara sin proteccion.');
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
</script>
