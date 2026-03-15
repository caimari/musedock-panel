<?php use MuseDockPanel\View; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-cloud-arrow-up me-2"></i>Crear Nuevo Backup
            </div>
            <div class="card-body">
                <form method="POST" action="/backups/store">
                    <?= View::csrf() ?>

                    <div class="mb-4">
                        <label class="form-label">Cuenta de Hosting</label>
                        <select name="account_id" class="form-select" required>
                            <option value="">-- Seleccionar cuenta --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= View::e($acc['username']) ?> — <?= View::e($acc['domain']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Solo se muestran cuentas activas.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Que incluir en el backup</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="include_files" id="includeFiles" value="1" checked>
                            <label class="form-check-label" for="includeFiles">
                                <i class="bi bi-folder me-1"></i> Archivos (httpdocs/)
                            </label>
                            <br><small class="text-muted ms-4">Se creara un archivo files.tar.gz con todo el contenido de httpdocs/</small>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="include_databases" id="includeDatabases" value="1" checked>
                            <label class="form-check-label" for="includeDatabases">
                                <i class="bi bi-database me-1"></i> Bases de datos
                            </label>
                            <br><small class="text-muted ms-4">Se hara un dump SQL de todas las bases de datos asociadas a esta cuenta</small>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="/backups" class="btn btn-outline-light">
                            <i class="bi bi-arrow-left me-1"></i> Volver
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cloud-arrow-up me-1"></i> Crear Backup
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <h6 class="mb-2"><i class="bi bi-info-circle me-1" style="color: #38bdf8;"></i> Informacion</h6>
                <ul class="mb-0 small text-muted">
                    <li>Los backups se guardan en <code>/opt/musedock-panel/storage/backups/</code></li>
                    <li>El nombre del backup sera: <code>{usuario}_{fecha_hora}/</code></li>
                    <li>Los dumps de MySQL usan el metodo de autenticacion configurado en <code>.env</code></li>
                    <li>Los dumps de PostgreSQL se ejecutan con <code>pg_dump</code> como usuario postgres</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
    .form-check-input { background-color: #0f172a; border-color: #334155; }
    .form-check-input:checked { background-color: #0ea5e9; border-color: #0ea5e9; }
    .form-check-input:focus { box-shadow: 0 0 0 2px rgba(56,189,248,0.2); border-color: #38bdf8; }
</style>
