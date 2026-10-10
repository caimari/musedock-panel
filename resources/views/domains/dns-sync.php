<?php use MuseDockPanel\View; ?>
<div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
    <div><h4>Revisar DNS de <?= View::e($domain) ?></h4><p class="text-muted">El dominio ya está creado. Confirma los registros antes de publicarlos.</p></div>
    <a href="/docs/settings/domain-dns" class="btn btn-outline-info align-self-start">Ayuda: DNS y certificados</a>
</div>
<div class="alert alert-info"> <?= $scope === 'mail' ? 'Solo correo: se revisan MX, SPF, DKIM, DMARC y el hostname de correo. La web conserva su destino.' : 'Hosting: se revisan el dominio y www, y los registros de correo si el dominio tiene correo en el panel.' ?>
    Los cambios se aplican únicamente después de aceptar el modal. Los registros de dirección se publican sin proxy.</div>
<?php if ($scope === 'hosting' && !$mailExists && !$readOnly): ?>
<div class="card mb-3"><div class="card-body"><strong>¿Quieres correo para este hosting?</strong><p class="small text-muted mb-2">Puedes añadir el dominio de correo y crear sus buzones. Sus DNS se revisarán antes de publicarlos.</p><a class="btn btn-outline-info btn-sm" href="/mail/domains/create?domain=<?= rawurlencode($domain) ?>">Crear correo para este hosting</a></div></div>
<?php endif; ?>
<?php if ($readOnly): ?><div class="alert alert-warning">Este nodo es Slave. Revisa y publica desde el master.</div><?php endif; ?>
<form id="dnsReviewForm">
    <?= View::csrf() ?>
    <input type="hidden" name="domain" value="<?= View::e($domain) ?>">
    <input type="hidden" name="scope" value="<?= View::e($scope) ?>">
    <button type="button" id="dnsRefresh" class="btn btn-outline-light mb-3">Volver a consultar DNS</button>
</form>
<div id="dnsReviewMessage" role="status" class="mb-3"></div>
<div id="dnsReviewWarnings"></div>
<div class="table-responsive"><table class="table"><thead><tr><th>Registro</th><th>Proveedor / cuenta</th><th>Actual</th><th>Propuesto</th><th>Acción</th></tr></thead><tbody id="dnsReviewRows"></tbody></table></div>
<div class="d-flex gap-2"><button id="dnsApply" class="btn btn-primary" disabled>Revisar y confirmar cambios</button><a id="dnsReturn" href="/domains" class="btn btn-outline-light">Continuar sin cambiar DNS</a></div>
<div class="modal fade" id="dnsConfirmModal" tabindex="-1" aria-labelledby="dnsConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content" data-bs-theme="dark" style="--bs-modal-bg:#1e293b;--bs-modal-color:#f8fafc;--bs-modal-border-color:#334155;--bs-modal-header-border-color:#334155;--bs-modal-footer-border-color:#334155;background:#1e293b;color:#f8fafc;">
    <div class="modal-header"><h5 class="modal-title" id="dnsConfirmTitle">Confirmar publicación DNS</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
    <div class="modal-body"><p>Los siguientes valores sustituirán los registros actuales, incluidos los que apunten a otra IP, CNAME o servidor de correo. Se conserva el resto de la zona.</p><div id="dnsConfirmRecords"></div><div id="dnsConfirmWarnings"></div>
    <label class="form-check mt-3"><input type="checkbox" id="dnsConsent" class="form-check-input"><span class="form-check-label">He revisado el destino y autorizo crear o sustituir estos registros DNS.</span></label></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button type="button" id="dnsConfirmApply" class="btn btn-danger" disabled <?= $readOnly ? 'data-readonly="1"' : '' ?>>Aceptar y publicar DNS</button></div>
</div></div></div>
<div class="mt-4"><button id="dnsVerify" class="btn btn-outline-info">Comprobar propagación y HTTPS</button><div id="dnsVerifyResult" class="mt-3" role="status"></div></div>
<script src="/assets/js/domain-dns-sync.js?v=<?= filemtime(PANEL_ROOT . '/public/assets/js/domain-dns-sync.js') ?>"></script>
