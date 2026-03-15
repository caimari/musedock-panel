<?php use MuseDockPanel\View; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<?php if (!$apiAvailable): ?>
<div class="card">
    <div class="card-body text-center py-4">
        <i class="bi bi-exclamation-triangle text-warning" style="font-size:2rem;"></i>
        <p class="mt-2 text-muted">La API de Caddy no esta disponible. Verifica que Caddy esta corriendo.</p>
    </div>
</div>
<?php else: ?>

<div class="row g-3">
    <!-- Certificates -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-lock me-1"></i> Certificados SSL/TLS</span>
                <span class="badge bg-info"><?= count($certificates) ?> dominio(s)</span>
            </div>
            <div class="card-body">
                <?php if (empty($certificates)): ?>
                <p class="text-muted text-center py-3">No hay dominios con SSL configurado. Al crear una cuenta de hosting con dominio, Caddy generara el certificado automaticamente.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Dominio</th>
                                <th>Servidor</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certificates as $cert): ?>
                            <tr>
                                <td><i class="bi bi-lock-fill text-success me-1"></i><?= View::e($cert['domain']) ?></td>
                                <td><small class="text-muted"><?= View::e($cert['server']) ?></small></td>
                                <td><span class="badge bg-info">Auto (Let's Encrypt)</span></td>
                                <td><span class="badge bg-success">Activo</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TLS Info -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> TLS Info</div>
            <div class="card-body">
                <p class="text-muted small">Caddy gestiona los certificados SSL automaticamente:</p>
                <ul class="text-muted small">
                    <li>Certificados Let's Encrypt gratuitos</li>
                    <li>Renovacion automatica (30 dias antes)</li>
                    <li>HTTP → HTTPS redirect automatico</li>
                    <li>TLS 1.2+ / HSTS</li>
                </ul>

                <?php if (!empty($tlsPolicies)): ?>
                <h6 class="mt-3 mb-2">Politicas TLS</h6>
                <?php foreach ($tlsPolicies as $i => $policy): ?>
                <div class="mb-2 p-2" style="background:#0f172a;border-radius:6px;">
                    <small class="text-muted">Politica <?= $i + 1 ?></small>
                    <?php if (!empty($policy['issuers'])): ?>
                        <?php foreach ($policy['issuers'] as $issuer): ?>
                        <br><small><span class="text-info"><?= View::e($issuer['module'] ?? 'auto') ?></span></small>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($policy['subjects'])): ?>
                        <br><small class="text-muted"><?= View::e(implode(', ', $policy['subjects'])) ?></small>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
