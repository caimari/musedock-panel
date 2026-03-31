<?php use MuseDockPanel\View; ?>

<div class="mb-3">
    <a href="/accounts/<?= $account['id'] ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver a <?= View::e($account['domain']) ?></a>
</div>

<?php
// Show migration log if available
$migrationLog = $_SESSION['migration_log'] ?? null;
$migrationErrors = $_SESSION['migration_errors'] ?? [];
$migrationDbPass = $_SESSION['migration_db_pass'] ?? null;
unset($_SESSION['migration_log'], $_SESSION['migration_errors'], $_SESSION['migration_db_pass']);
?>

<?php if ($migrationLog): ?>
<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-terminal me-2"></i>Log de Migracion
        <?php if (empty($migrationErrors)): ?>
            <span class="badge ms-2" style="background:rgba(34,197,94,0.15);color:#22c55e;">Completado</span>
        <?php else: ?>
            <span class="badge ms-2" style="background:rgba(251,191,36,0.15);color:#fbbf24;">Con advertencias</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div style="max-height: 300px; overflow-y: auto; padding: 1rem; font-family: monospace; font-size: 0.8rem; background: #0f172a; border-radius: 0 0 12px 12px;">
            <?php foreach ($migrationLog as $i => $line): ?>
                <?php
                    $color = '#94a3b8';
                    if (str_contains($line, 'ERROR')) $color = '#ef4444';
                    elseif (str_contains($line, 'AVISO')) $color = '#fbbf24';
                    elseif (str_contains($line, 'completada') || str_contains($line, 'Completado') || str_contains($line, 'completado')) $color = '#22c55e';
                    elseif (str_contains($line, 'Conectando') || str_contains($line, 'Descargando') || str_contains($line, 'Ejecutando') || str_contains($line, 'Importando') || str_contains($line, 'Creando') || str_contains($line, 'composer')) $color = '#38bdf8';
                ?>
                <div style="color:<?= $color ?>;">
                    <span style="color:#475569;">[<?= $i + 1 ?>]</span> <?= View::e($line) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($migrationDbPass): ?>
        <div class="p-3" style="border-top: 1px solid #334155;">
            <div class="p-2 rounded" style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2);">
                <small style="color:#22c55e;">
                    <i class="bi bi-key me-1"></i><strong>Password de la BD local (se muestra solo una vez):</strong>
                    <code style="font-size: 0.9rem;"><?= View::e($migrationDbPass) ?></code>
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Option 1: URL Download -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-cloud-download me-2"></i>Opcion 1: Descargar por URL
            </div>
            <div class="card-body">
                <p class="text-muted small">Descarga un archivo (tar.gz, zip) directamente desde una URL al document root.</p>
                <form method="POST" action="/accounts/<?= $account['id'] ?>/migrate/url" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Descargando...';">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="mb-3">
                        <label class="form-label">URL del archivo</label>
                        <input type="url" name="url" class="form-control" placeholder="https://example.com/backup.tar.gz" required>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="decompress" id="decompress" class="form-check-input" checked>
                        <label for="decompress" class="form-check-label">Descomprimir despues de descargar</label>
                    </div>
                    <div class="mb-2 p-2 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                        <small class="text-muted"><i class="bi bi-folder me-1"></i>Destino: <code><?= View::e($account['document_root']) ?></code></small>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="bi bi-download me-1"></i>Descargar y Extraer</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Option 2: SSH Full Migration -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-terminal me-2"></i>Opcion 2: Migracion SSH Completa
            </div>
            <div class="card-body">
                <p class="text-muted small">Migracion completa en un click: archivos + BD + composer. Solo necesitas las credenciales SSH del servidor remoto.</p>
                <?php
                $domainParts = explode('.', $account['domain']);
                $isSubdomain = count($domainParts) > 2;
                $parentDomain = $isSubdomain ? implode('.', array_slice($domainParts, 1)) : null;
                ?>
                <?php if ($isSubdomain): ?>
                <div class="p-2 mb-2 rounded" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);">
                    <small style="color:#38bdf8;"><i class="bi bi-info-circle me-1"></i><strong><?= View::e($account['domain']) ?></strong> es un subdominio. En Plesk estara dentro de <code>/var/www/vhosts/<?= View::e($parentDomain) ?>/<?= View::e($account['domain']) ?>/</code>. El sistema lo detectara automaticamente al probar la conexion SSH.</small>
                </div>
                <?php endif; ?>
                <form method="POST" action="/accounts/<?= $account['id'] ?>/migrate/ssh" id="sshMigrateForm">
                    <?= \MuseDockPanel\View::csrf() ?>
                    <div class="mb-2">
                        <label class="form-label">Servidor remoto (IP o hostname)</label>
                        <div class="input-group">
                            <input type="text" name="ssh_host" id="sshHost" class="form-control" placeholder="123.45.67.89 o host.servidor.com" required>
                            <span class="input-group-text" style="background:#0f172a;border-color:#334155;color:#94a3b8;">:</span>
                            <input type="number" name="ssh_port" id="sshPort" class="form-control" value="22" style="max-width:80px;">
                        </div>
                        <small class="text-muted">IP o hostname del servidor remoto donde estan los archivos</small>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Usuario SSH</label>
                            <input type="text" name="ssh_user" id="sshUser" class="form-control" placeholder="root" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Password SSH</label>
                            <div class="input-group">
                                <input type="password" name="ssh_password" id="sshPass" class="form-control" required>
                                <button type="button" class="btn btn-outline-light" onclick="var p=document.getElementById('sshPass'); var i=this.querySelector('i'); if(p.type==='password'){p.type='text';i.className='bi bi-eye-slash';}else{p.type='password';i.className='bi bi-eye';}"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <!-- Test SSH button -->
                    <button type="button" id="sshTestBtn" class="btn btn-outline-info btn-sm w-100 mb-2">
                        <i class="bi bi-plug me-1"></i>Probar conexion SSH
                    </button>
                    <div id="sshTestResult" style="display:none;" class="mb-2"></div>

                    <!-- Auto-filled from account domain, editable if different -->
                    <div class="mb-2 p-2 rounded" style="background: rgba(56,189,248,0.03); border: 1px solid #334155;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted"><i class="bi bi-gear me-1"></i>Configuracion auto-detectada (editable)</small>
                            <button type="button" class="btn btn-outline-light btn-sm py-0 px-1" style="font-size:0.7rem;" onclick="document.getElementById('sshAdvanced').style.display = document.getElementById('sshAdvanced').style.display === 'none' ? '' : 'none';">
                                <i class="bi bi-sliders me-1"></i>Ajustar
                            </button>
                        </div>
                        <div id="sshAdvanced" style="display:none;">
                            <div class="row g-2 mt-1">
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.8rem;">Dominio remoto (descarga HTTPS)</label>
                                    <input type="text" name="remote_domain" id="sshRemoteDomain" class="form-control form-control-sm" value="<?= View::e($account['domain']) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.8rem;">Ruta remota completa</label>
                                    <input type="text" name="remote_path" id="sshRemotePath" class="form-control form-control-sm" value="/var/www/vhosts/<?= View::e($isSubdomain ? $parentDomain : $account['domain']) ?>">
                                </div>
                            </div>
                            <div class="mt-1">
                                <label class="form-label" style="font-size:0.8rem;">Subcarpeta web (document root)</label>
                                <input type="text" name="remote_docroot" id="sshDocRoot" class="form-control form-control-sm" value="<?= $isSubdomain ? '/' . View::e($account['domain']) : '/httpdocs' ?>">
                                <small class="text-muted" style="font-size:0.75rem;">Carpeta dentro del vhost que contiene los archivos web. En Plesk: /httpdocs para dominios principales, /sub.dominio.com para subdominios</small>
                            </div>
                            <div class="mt-2">
                                <label class="form-label" style="font-size:0.8rem;">Destino local</label>
                                <input type="text" name="local_target" id="sshLocalTarget" class="form-control form-control-sm" value="<?= View::e($account['document_root']) ?>">
                                <small class="text-muted" style="font-size:0.75rem;">Carpeta local donde se descomprimiran los archivos. Por defecto: document_root del hosting</small>
                            </div>
                        </div>
                        <div id="sshAutoInfo">
                            <small class="text-muted" style="font-size:0.8rem;">
                                Remoto: <code>/var/www/vhosts/<?= View::e($isSubdomain ? $parentDomain . '/' . $account['domain'] : $account['domain'] . '/httpdocs') ?></code> &rarr; Local:
                                <code><?= View::e($account['document_root']) ?></code>
                            </small>
                        </div>
                    </div>

                    <div class="form-check mb-1 mt-2">
                        <input type="checkbox" name="include_db" id="includeDb" class="form-check-input" checked>
                        <label for="includeDb" class="form-check-label">Incluir migracion de base de datos</label>
                        <small class="d-block text-muted">Auto-detecta credenciales de MuseDock (.env), Laravel (.env) o WordPress (wp-config.php)</small>
                    </div>
                    <div class="form-check mb-1">
                        <input type="checkbox" name="exclude_vendor" id="excludeVendor" class="form-check-input">
                        <label for="excludeVendor" class="form-check-label">Omitir vendor/ y ejecutar <code>composer install</code></label>
                        <small class="d-block text-muted">Solo si las dependencias estan disponibles en packagist. Si es proyecto antiguo, dejalo desmarcado.</small>
                    </div>
                    <div class="form-check mb-1">
                        <input type="checkbox" name="copy_everything" id="copyEverything" class="form-check-input" onchange="
                            var folders = document.getElementById('vhostFolderSelector');
                            if (this.checked) {
                                if (folders) folders.style.display = 'none';
                            } else {
                                if (folders) folders.style.display = '';
                            }
                        ">
                        <label for="copyEverything" class="form-check-label">Copiar absolutamente todo <small style="color:#fbbf24;">(sin exclusiones)</small></label>
                        <small class="d-block text-muted">Copia el 100% del directorio remoto sin excluir nada. No necesitas seleccionar carpetas manualmente.</small>
                    </div>

                    <!-- Subdomain selector (populated after SSH test) -->
                    <div id="subdomainSelector" style="display:none;" class="mb-2 p-2 rounded" style="border: 1px solid #334155;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-info fw-bold"><i class="bi bi-layers me-1"></i>Subdominios detectados en el vhost remoto</small>
                            <div>
                                <button type="button" class="btn btn-outline-info btn-sm py-0 px-1" style="font-size:0.7rem;" onclick="toggleAllSubdomains(true)">Todos</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:0.7rem;" onclick="toggleAllSubdomains(false)">Ninguno</button>
                            </div>
                        </div>
                        <div id="subdomainList"></div>
                        <small class="text-muted d-block mt-1">Los subdominios seleccionados se migraran como subdominios independientes en el sistema, incluyendo archivos y BD si se detectan.</small>
                    </div>

                    <!-- Vhost folders browser (non-subdomain folders) -->
                    <div id="vhostFolderSelector" style="display:none;" class="mb-2 p-2 rounded" style="border: 1px solid #334155;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-warning fw-bold"><i class="bi bi-folder me-1"></i>Otras carpetas en el vhost</small>
                        </div>
                        <div id="vhostFolderList"></div>
                        <small class="text-muted d-block mt-1">Carpetas adicionales (no subdominios). Selecciona las que quieras incluir en la copia.</small>
                    </div>

                    <div class="p-2 rounded mb-2" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                        <small class="text-muted">
                            <i class="bi bi-list-check me-1"></i><strong>El sistema hara todo automaticamente:</strong><br>
                            Conectar SSH &rarr; Detectar proyecto &rarr; Comprimir archivos &rarr;
                            Descargar por HTTPS &rarr; Descomprimir &rarr;
                            mysqldump &rarr; Crear BD local &rarr; Importar &rarr; Actualizar .env/wp-config &rarr; Limpiar
                            <?php if (true): ?><br><i class="bi bi-layers me-1"></i>Subdominios: se crean como hosting independiente + migracion completa de cada uno<?php endif; ?>
                        </small>
                    </div>

                    <div class="p-2 rounded mb-2" style="background: rgba(251,191,36,0.05); border: 1px solid #334155;">
                        <small style="color:#fbbf24;"><i class="bi bi-shield-lock me-1"></i>Las credenciales SSH solo se usan en memoria, nunca se guardan. El backup temporal tiene nombre aleatorio imposible de adivinar.</small>
                    </div>

                    <button type="button" id="sshStartBtn" class="btn btn-primary w-100 mt-2"><i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Option 3: Database Migration (standalone) -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-database me-2"></i>Opcion 3: Solo Base de Datos (MySQL)</span>
                <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#dbMigrateSection">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
            <div class="collapse" id="dbMigrateSection">
                <div class="card-body">
                    <p class="text-muted small">Migrar solo la base de datos MySQL. Util si ya subiste los archivos por URL o manualmente.</p>

                    <div class="mb-3">
                        <label class="form-label">Fuente de credenciales</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcAuto" value="auto" checked>
                            <label class="btn btn-outline-success" for="srcAuto"><i class="bi bi-magic me-1"></i>Auto</label>
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcManual" value="manual">
                            <label class="btn btn-outline-light" for="srcManual"><i class="bi bi-keyboard me-1"></i>Manual</label>
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcMusedock" value="musedock">
                            <label class="btn btn-outline-light" for="srcMusedock"><i class="bi bi-box me-1"></i>MuseDock</label>
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcLaravel" value="laravel">
                            <label class="btn btn-outline-light" for="srcLaravel"><i class="bi bi-file-code me-1"></i>Laravel</label>
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcWordpress" value="wordpress">
                            <label class="btn btn-outline-light" for="srcWordpress"><i class="bi bi-wordpress me-1"></i>WordPress</label>
                            <input type="radio" class="btn-check" name="db_source_toggle" id="srcZend" value="zend">
                            <label class="btn btn-outline-light" for="srcZend"><i class="bi bi-filetype-php me-1"></i>Zend</label>
                        </div>
                    </div>

                    <form method="POST" action="/accounts/<?= $account['id'] ?>/migrate/db" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Migrando BD...';">
                    <?= \MuseDockPanel\View::csrf() ?>
                        <input type="hidden" name="db_source" id="dbSourceInput" value="auto">

                        <div id="manualFields">
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label">Host remoto</label>
                                    <input type="text" name="db_host" class="form-control" placeholder="servidor-remoto.com">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Puerto</label>
                                    <input type="number" name="db_port" class="form-control" value="3306">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Nombre BD</label>
                                    <input type="text" name="db_name" class="form-control" placeholder="mi_base_datos">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tipo</label>
                                    <select name="db_type" class="form-select">
                                        <option value="mysql">MySQL / MariaDB</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Usuario BD</label>
                                    <input type="text" name="db_user" class="form-control" placeholder="db_user">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Password BD</label>
                                    <input type="password" name="db_password" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div id="autoDetectInfo" style="display:none;">
                            <div class="p-3 mb-3 rounded" style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2);">
                                <p class="mb-1" style="color:#22c55e;"><i class="bi bi-magic me-1"></i><strong>Modo auto-deteccion</strong></p>
                                <small class="text-muted">Las credenciales se leeran automaticamente de <code id="autoDetectFile"></code> en<br><code><?= View::e($account['document_root']) ?></code></small>
                            </div>
                        </div>

                        <!-- SSH connection for remote mysqldump -->
                        <input type="hidden" id="dbSshToggle" checked>
                        <div class="p-2 mb-3 rounded" style="background: rgba(56,189,248,0.08); border: 1px solid rgba(56,189,248,0.2);">
                            <small class="text-muted"><i class="bi bi-hdd-network me-1" style="color:#38bdf8;"></i>Conecta por SSH al servidor remoto para ejecutar mysqldump.</small>
                        </div>
                        <div id="dbSshFields">
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label">SSH Host</label>
                                    <input type="text" name="ssh_host" id="dbSshHost" class="form-control" placeholder="servidor-remoto.com">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Puerto SSH</label>
                                    <input type="number" name="ssh_port" id="dbSshPort" class="form-control" value="22">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Usuario SSH</label>
                                    <input type="text" name="ssh_user" id="dbSshUser" class="form-control" placeholder="root">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Password SSH</label>
                                    <input type="password" name="ssh_password" id="dbSshPassword" class="form-control">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-12">
                                    <label class="form-label">Ruta remota del proyecto <small class="text-muted">(donde esta wp-config.php o .env en el servidor remoto)</small></label>
                                    <?php
                                        // Pre-fill with likely remote path (strip /public suffix added locally)
                                        $remotePath = $account['document_root'];
                                        if (str_ends_with($remotePath, '/public')) {
                                            $remotePath = substr($remotePath, 0, -7);
                                        }
                                    ?>
                                    <input type="text" name="ssh_remote_path" class="form-control" value="<?= View::e($remotePath) ?>">
                                </div>
                            </div>
                            <div class="p-2 mb-2 rounded" style="background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.2);">
                                <small style="color:#22c55e;"><i class="bi bi-info-circle me-1"></i>Con SSH + WordPress/Laravel: las credenciales de BD se leeran del <strong>servidor remoto</strong>, no del local. Asi se obtienen las credenciales originales aunque el archivo local ya haya sido modificado.</small>
                            </div>
                        </div>

                        <div class="p-2 mb-2 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>Proceso:
                                1) mysqldump remoto &mdash;
                                2) Crear BD local &mdash;
                                3) Importar dump &mdash;
                                4) Actualizar config
                            </small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="bi bi-database-down me-1"></i>Migrar Base de Datos</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick migration tip -->
<div class="mt-3 p-3 rounded" style="background: rgba(56,189,248,0.05); border: 1px solid #334155;">
    <small class="text-muted">
        <i class="bi bi-lightbulb me-1" style="color:#fbbf24;"></i>
        <strong>Tip migracion masiva desde Plesk:</strong> En el servidor origen, genera backups de todos los proyectos:
        <code class="d-block mt-1 mb-1" style="font-size:0.8rem;">for proj in domain1.com domain2.com; do cd /var/www/vhosts/$proj/ && tar czf httpdocs/migration-backup.tar.gz -C httpdocs/ . && echo "https://$proj/migration-backup.tar.gz"; done</code>
        Luego usa la Opcion 1 (URL) para descargar cada uno. Recuerda borrar los tar.gz del servidor origen al terminar.
    </small>
</div>

<script>
// Sync remote domain and path if user edits one
document.getElementById('sshRemotePath').addEventListener('input', function() {
    var match = this.value.match(/\/vhosts\/([^\/]+)/);
    if (match) {
        document.getElementById('sshRemoteDomain').value = match[1];
    }
    updateAutoInfo();
});
document.getElementById('sshDocRoot').addEventListener('input', updateAutoInfo);
document.getElementById('sshLocalTarget').addEventListener('input', updateAutoInfo);

function updateAutoInfo() {
    var remotePath = document.getElementById('sshRemotePath').value.replace(/\/+$/, '');
    var docRoot = document.getElementById('sshDocRoot').value;
    var localTarget = document.getElementById('sshLocalTarget');
    var match = remotePath.match(/\/vhosts\/([^\/]+)/);
    var domain = match ? match[1] : '<?= View::e($account['domain']) ?>';
    var fullRemote = remotePath + (docRoot ? '/' + docRoot.replace(/^\//, '') : '');

    // Auto-adjust local target based on remote path
    var homeDir = '<?= View::e($account['home_dir']) ?>';
    var docRoot = '<?= View::e($account['document_root']) ?>';
    if (remotePath.match(/\/httpdocs\/?$/) || fullRemote.match(/\/httpdocs\/?$/)) {
        // Remote points to httpdocs — extract to document_root
        localTarget.value = docRoot;
    } else if (remotePath.match(/\/vhosts\/[^\/]+\/?$/)) {
        // Remote is vhost root — extract to home_dir
        localTarget.value = homeDir;
    }

    document.getElementById('sshAutoInfo').innerHTML =
        '<small class="text-muted" style="font-size:0.8rem;">' +
        'Remoto: <code>' + fullRemote + '</code> &rarr; Local: <code>' + localTarget.value + '</code></small>';
}

// ================================================================
// SSH Test Connection (standalone button)
// ================================================================
var sshTestPassed = false;
var cachedSshData = null;

document.getElementById('sshTestBtn').addEventListener('click', function() {
    var form = document.getElementById('sshMigrateForm');
    var btn = this;
    var resultDiv = document.getElementById('sshTestResult');
    var accountId = <?= (int) $account['id'] ?>;

    var host = document.getElementById('sshHost').value.trim();
    var user = document.getElementById('sshUser').value.trim();
    var pass = document.getElementById('sshPass').value;

    if (!host || !user || !pass) {
        resultDiv.style.display = '';
        resultDiv.innerHTML = '<div class="p-2 rounded" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);">' +
            '<small style="color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Rellena host, usuario y password.</small></div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Probando...';
    resultDiv.style.display = 'none';

    var formData = new FormData(form);

    fetch('/accounts/' + accountId + '/migrate/test-ssh', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        resultDiv.style.display = '';

        if (!data.ok) {
            sshTestPassed = false;
            btn.innerHTML = '<i class="bi bi-plug me-1"></i>Probar conexion SSH';
            resultDiv.innerHTML = '<div class="p-2 rounded" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);">' +
                '<small style="color:#ef4444;"><i class="bi bi-x-circle me-1"></i>' + data.message + '</small></div>';
        } else {
            sshTestPassed = true;
            cachedSshData = data;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Conexion OK';
            btn.className = 'btn btn-outline-success btn-sm w-100 mb-2';
            resultDiv.innerHTML = '<div class="p-2 rounded" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);">' +
                '<small style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i><strong>Conexion SSH exitosa</strong></small>' +
                '<table class="mt-1" style="width:100%;font-size:0.8rem;">' +
                '<tr><td style="color:#64748b;padding:1px 8px 1px 0;">Ruta remota:</td><td style="color:#e2e8f0;"><code>' + data.path + '</code></td></tr>' +
                '<tr><td style="color:#64748b;padding:1px 8px 1px 0;">Archivos:</td><td style="color:#e2e8f0;">' + data.files.toLocaleString() + '</td></tr>' +
                '<tr><td style="color:#64748b;padding:1px 8px 1px 0;">Tamano:</td><td style="color:#e2e8f0;">' + data.size + '</td></tr>' +
                '<tr><td style="color:#64748b;padding:1px 8px 1px 0;">Proyecto:</td><td style="color:#e2e8f0;">' + data.project + '</td></tr>' +
                '</table></div>';

            // Update paths if server resolved to a different location
            if (data.remote_path) {
                document.getElementById('sshRemotePath').value = data.remote_path;
            }
            if (data.path) {
                // Extract docroot relative to remote_path
                var rp = data.remote_path || '';
                if (data.path.startsWith(rp + '/')) {
                    document.getElementById('sshDocRoot').value = '/' + data.path.substring(rp.length + 1);
                } else if (data.path === rp) {
                    document.getElementById('sshDocRoot').value = '';
                }
            }
            updateAutoInfo();

            // Populate subdomain/folder selectors
            populateVhostFolders(data.vhost_folders || []);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i>Probar conexion SSH';
        resultDiv.style.display = '';
        resultDiv.innerHTML = '<div class="p-2 rounded" style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);">' +
            '<small style="color:#ef4444;"><i class="bi bi-x-circle me-1"></i>Error de red. Intenta de nuevo.</small></div>';
    });
});

// Reset test state when credentials change
['sshHost', 'sshPort', 'sshUser', 'sshPass'].forEach(function(id) {
    document.getElementById(id).addEventListener('input', function() {
        sshTestPassed = false;
        cachedSshData = null;
        var testBtn = document.getElementById('sshTestBtn');
        testBtn.className = 'btn btn-outline-info btn-sm w-100 mb-2';
        testBtn.innerHTML = '<i class="bi bi-plug me-1"></i>Probar conexion SSH';
        document.getElementById('sshTestResult').style.display = 'none';
    });
});

// ================================================================
// SSH Migration: Start migration (with built-in test if not done)
// ================================================================
document.getElementById('sshStartBtn').addEventListener('click', function() {
    var form = document.getElementById('sshMigrateForm');
    var btn = this;
    var accountId = <?= (int) $account['id'] ?>;

    // Basic validation
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    var formData = new FormData(form);
    var localTarget = document.getElementById('sshLocalTarget').value;

    btn.disabled = true;

    if (sshTestPassed && cachedSshData) {
        // Already tested — go straight to checkLocal
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando destino local...';
        proceedWithMigration(btn, accountId, formData, cachedSshData, localTarget);
    } else {
        // Test SSH first
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Probando conexion SSH...';

        fetch('/accounts/' + accountId + '/migrate/test-ssh', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
                SwalDark.fire({
                    icon: 'error',
                    title: 'Error de conexion SSH',
                    html: '<p style="color:#ef4444;font-size:0.95rem;">' + data.message + '</p>' +
                          '<p style="color:#64748b;font-size:0.85rem;margin-top:0.5rem;">Verifica:<br>' +
                          '&bull; Que el host sea accesible<br>' +
                          '&bull; Que el puerto SSH este abierto<br>' +
                          '&bull; Que el usuario y password sean correctos</p>',
                    confirmButtonText: 'Entendido'
                });
                return;
            }
            sshTestPassed = true;
            cachedSshData = data;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando destino local...';
            proceedWithMigration(btn, accountId, formData, data, localTarget);
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
            SwalDark.fire({ icon: 'error', title: 'Error de red', text: 'No se pudo conectar con el panel.' });
        });
    }
});

function proceedWithMigration(btn, accountId, formData, sshData, localTarget) {
    var infoHtml = '<div style="text-align:left;color:#94a3b8;font-size:0.9rem;">' +
        '<p style="color:#22c55e;margin-bottom:0.5rem;"><i class="bi bi-check-circle me-1"></i><strong>Conexion SSH exitosa</strong></p>' +
        '<table style="width:100%;font-size:0.85rem;">' +
        '<tr><td style="color:#64748b;padding:2px 8px 2px 0;">Ruta remota:</td><td><code>' + sshData.path + '</code></td></tr>' +
        '<tr><td style="color:#64748b;padding:2px 8px 2px 0;">Archivos:</td><td>' + sshData.files.toLocaleString() + '</td></tr>' +
        '<tr><td style="color:#64748b;padding:2px 8px 2px 0;">Tamano:</td><td>' + sshData.size + '</td></tr>' +
        '<tr><td style="color:#64748b;padding:2px 8px 2px 0;">Proyecto:</td><td>' + sshData.project + '</td></tr>' +
        '<tr><td style="color:#64748b;padding:2px 8px 2px 0;">Destino local:</td><td><code>' + localTarget + '</code></td></tr>' +
        '</table></div>';

    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando destino local...';

    // Get CSRF token from the form
    var csrfToken = document.querySelector('#sshMigrateForm input[name="_csrf_token"]').value;

    fetch('/accounts/' + accountId + '/migrate/check-local', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_csrf_token=' + encodeURIComponent(csrfToken) + '&local_target=' + encodeURIComponent(localTarget)
    })
    .then(function(r) { return r.json(); })
    .then(function(localData) {
        if (localData.has_content) {
            SwalDark.fire({
                icon: 'warning',
                title: 'El destino ya tiene archivos',
                html: infoHtml +
                    '<hr style="border-color:#334155;margin:0.75rem 0;">' +
                    '<p style="color:#fbbf24;font-size:0.9rem;">' +
                    '<i class="bi bi-exclamation-triangle me-1"></i>' +
                    '<strong>La carpeta local ya contiene ' + localData.file_count + ' archivos (' + localData.size + ')</strong><br>' +
                    'Los archivos coincidentes se sobreescribiran con los del remoto.<br>' +
                    '<span style="color:#94a3b8;font-size:0.8rem;">Los archivos locales que no existan en el remoto NO se borran.</span></p>',
                showCancelButton: true,
                confirmButtonText: 'Sobreescribir y migrar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545',
            }).then(function(result) {
                if (result.isConfirmed) {
                    startSshMigration(btn);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
                }
            });
        } else {
            SwalDark.fire({
                icon: 'info',
                title: 'Confirmar migracion',
                html: infoHtml +
                    '<hr style="border-color:#334155;margin:0.75rem 0;">' +
                    '<p style="color:#94a3b8;font-size:0.85rem;">Se migraran los archivos' +
                    (document.getElementById('includeDb').checked ? ' y la base de datos' : '') +
                    ' a <code>' + localTarget + '</code></p>',
                showCancelButton: true,
                confirmButtonText: 'Iniciar migracion',
                cancelButtonText: 'Cancelar',
            }).then(function(result) {
                if (result.isConfirmed) {
                    startSshMigration(btn);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
                }
            });
        }
    });
}

function startSshMigration(btn) {
    var form = document.getElementById('sshMigrateForm');
    var accountId = <?= (int) $account['id'] ?>;
    var formData = new FormData(form);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Preparando...';

    // Step 1: POST to ssh-prepare to get stream token
    fetch('/accounts/' + accountId + '/migrate/ssh-prepare', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
            SwalDark.fire({ icon: 'error', title: 'Error', text: 'No se pudo preparar la migracion.' });
            return;
        }

        // Save token for reconnection
        localStorage.setItem('mdp_migration_token', data.token);
        localStorage.setItem('mdp_migration_account', accountId);

        // Open SSE modal
        openMigrationModal(accountId, data.token);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
        SwalDark.fire({ icon: 'error', title: 'Error de red', text: 'No se pudo conectar con el panel.' });
    });
}

function openMigrationModal(accountId, token) {
    SwalDark.fire({
        title: '<i class="bi bi-rocket-takeoff me-2"></i>Migracion en curso',
        html: buildMigrationModalHtml(),
        showConfirmButton: false,
        showCancelButton: false,
        allowOutsideClick: false,
        allowEscapeKey: false,
        width: 620,
        didOpen: function() {
            connectSSE(accountId, token);
        }
    });
}

function buildMigrationModalHtml() {
    return '<div id="migrationModal">' +
        '<div id="migStepBadge" style="margin-bottom:8px;">' +
            '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;">' +
                '<span class="spinner-border spinner-border-sm me-1"></span>Iniciando...' +
            '</span>' +
        '</div>' +
        '<div id="migProgressArea" style="display:none;margin-bottom:10px;">' +
            '<div style="display:flex;align-items:center;gap:8px;">' +
                '<div class="progress" style="flex:1;height:18px;background:#1e293b;">' +
                    '<div id="migProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" style="width:0%;transition:width 0.3s;"></div>' +
                '</div>' +
                '<small id="migProgressText" style="color:#94a3b8;min-width:60px;text-align:right;">0%</small>' +
            '</div>' +
        '</div>' +
        '<div id="migLog" style="max-height:280px;overflow-y:auto;padding:10px;font-family:monospace;font-size:0.78rem;background:#0f172a;border-radius:8px;text-align:left;border:1px solid #1e293b;"></div>' +
        '<div id="migResult" style="display:none;margin-top:10px;"></div>' +
    '</div>';
}

function connectSSE(accountId, token) {
    var logEl = document.getElementById('migLog');
    var stepEl = document.getElementById('migStepBadge');
    var progressArea = document.getElementById('migProgressArea');
    var progressBar = document.getElementById('migProgressBar');
    var progressText = document.getElementById('migProgressText');
    var resultEl = document.getElementById('migResult');

    var url = '/accounts/' + accountId + '/migrate/ssh-stream?token=' + token;
    var es = new EventSource(url);

    function addLog(text, color) {
        var line = document.createElement('div');
        line.style.color = color || '#94a3b8';
        line.textContent = text;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }

    es.addEventListener('log', function(e) {
        var color = '#94a3b8';
        var t = e.data;
        if (t.indexOf('ERROR') !== -1) color = '#ef4444';
        else if (t.indexOf('AVISO') !== -1) color = '#fbbf24';
        else if (t.indexOf('completad') !== -1 || t.indexOf('Completad') !== -1) color = '#22c55e';
        else if (t.indexOf('Conectando') !== -1 || t.indexOf('Descargando') !== -1 || t.indexOf('Ejecutando') !== -1 || t.indexOf('Importando') !== -1 || t.indexOf('Creando') !== -1 || t.indexOf('composer') !== -1) color = '#38bdf8';
        addLog(t, color);
    });

    es.addEventListener('step', function(e) {
        stepEl.innerHTML = '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;">' +
            '<span class="spinner-border spinner-border-sm me-1"></span>' + e.data + '</span>';
    });

    es.addEventListener('progress', function(e) {
        try {
            var p = JSON.parse(e.data);
            if (p.indeterminate) {
                progressArea.style.display = '';
                progressBar.style.width = '100%';
                progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
                progressText.textContent = p.type === 'extract' ? 'Descomprimiendo...' :
                                           p.type === 'composer' ? 'Instalando...' :
                                           p.type === 'mysqldump' ? 'Dump remoto...' :
                                           p.type === 'import' ? 'Importando...' : '...';
            } else if (p.percent !== undefined) {
                progressArea.style.display = '';
                progressBar.style.width = p.percent + '%';
                if (p.percent >= 100) {
                    progressBar.className = 'progress-bar bg-success';
                } else {
                    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
                }
                var label = p.percent + '%';
                if (p.total && p.current) {
                    label = formatBytes(p.current) + ' / ' + formatBytes(p.total) + ' (' + p.percent + '%)';
                    if (p.speed && p.speed > 0) {
                        label += ' — ' + formatBytes(p.speed) + '/s';
                    }
                    if (p.eta && p.eta > 0) {
                        label += ' — ' + formatEta(p.eta);
                    }
                }
                progressText.textContent = label;
            }
        } catch(ex) {}
    });

    es.addEventListener('error', function(e) {
        if (e.data) {
            addLog('ERROR: ' + e.data, '#ef4444');
        }
    });

    es.addEventListener('done', function(e) {
        es.close();
        localStorage.removeItem('mdp_migration_token');
        localStorage.removeItem('mdp_migration_account');

        try {
            var result = JSON.parse(e.data);
            progressArea.style.display = 'none';

            if (result.ok) {
                stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;">' +
                    '<i class="bi bi-check-circle me-1"></i>Migracion completada</span>';
            } else {
                stepEl.innerHTML = '<span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:0.85rem;">' +
                    '<i class="bi bi-exclamation-triangle me-1"></i>Completado con advertencias</span>';
            }

            var html = '';
            if (result.summary) {
                html += '<div class="p-2 rounded mb-2" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);">' +
                    '<small style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i>' + result.summary + '</small></div>';
            }
            if (result.db_pass) {
                html += '<div class="p-2 rounded mb-2" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);">' +
                    '<small style="color:#38bdf8;"><i class="bi bi-key me-1"></i><strong>Password BD local (se muestra solo una vez):</strong> ' +
                    '<code id="dbPassCode" style="font-size:0.9rem;user-select:all;">' + result.db_pass + '</code> ' +
                    '<button type="button" class="btn btn-outline-light btn-sm py-0 px-1" style="font-size:0.7rem;" onclick="navigator.clipboard.writeText(\'' + result.db_pass + '\');this.innerHTML=\'<i class=\\\'bi bi-check\\\'></i>\';">' +
                    '<i class="bi bi-clipboard"></i></button></small></div>';
            }
            if (result.errors && result.errors.length > 0) {
                html += '<div class="p-2 rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">' +
                    '<small style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Advertencias: ' + result.errors.join(', ') + '</small></div>';
            }
            resultEl.innerHTML = html;
            resultEl.style.display = '';

            // Add close button
            var closeBtn = document.createElement('button');
            closeBtn.className = 'btn btn-outline-light btn-sm mt-3';
            closeBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Cerrar';
            closeBtn.onclick = function() { Swal.close(); resetSshBtn(); };
            resultEl.appendChild(closeBtn);

        } catch(ex) {
            addLog('Error procesando resultado.', '#ef4444');
        }
    });

    es.onerror = function() {
        // SSE connection lost — migration continues server-side
        addLog('Conexion SSE perdida. La migracion sigue en el servidor.', '#fbbf24');
        addLog('Puedes recargar la pagina para reconectar.', '#fbbf24');
        es.close();
    };
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

function formatEta(seconds) {
    if (seconds < 60) return seconds + 's';
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    return m + 'm ' + s + 's';
}

function resetSshBtn() {
    var btn = document.getElementById('sshStartBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-rocket-takeoff me-1"></i>Iniciar Migracion Completa';
}

// Check for active migration on page load (reconnection after reload)
(function checkActiveMigration() {
    var token = localStorage.getItem('mdp_migration_token');
    var migAccountId = localStorage.getItem('mdp_migration_account');
    if (!token || !migAccountId) return;

    // Check if migration is still active via status endpoint
    fetch('/accounts/' + migAccountId + '/migrate/ssh-status?token=' + token)
    .then(function(r) { return r.json(); })
    .then(function(status) {
        if (status.done) {
            // Migration finished while we were away — show result
            localStorage.removeItem('mdp_migration_token');
            localStorage.removeItem('mdp_migration_account');

            SwalDark.fire({
                title: '<i class="bi bi-check-circle me-2"></i>Migracion finalizada',
                html: buildReconnectResultHtml(status),
                confirmButtonText: 'Cerrar',
                width: 620
            });
        } else if (status.active) {
            // Migration still running — show log so far and reconnect SSE
            SwalDark.fire({
                title: '<i class="bi bi-rocket-takeoff me-2"></i>Migracion en curso (reconectando)',
                html: buildMigrationModalHtml() +
                    '<div id="migStaleActions" style="margin-top:12px;">' +
                        (status.has_pending_dump ?
                            '<button onclick="resumeMigration()" class="btn btn-outline-success btn-sm me-2"><i class="bi bi-play-circle me-1"></i>Continuar importacion BD (' + status.pending_dump_size + ' MB)</button>' : '') +
                        '<button onclick="cancelMigration()" class="btn btn-outline-danger btn-sm me-2"><i class="bi bi-x-circle me-1"></i>Cancelar y cerrar</button>' +
                        '<button onclick="cancelMigration(); setTimeout(function(){ location.reload(); }, 300);" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Reintentar todo</button>' +
                    '</div>',
                showConfirmButton: false,
                showCancelButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                width: 620,
                didOpen: function() {
                    // Show existing logs
                    var logEl = document.getElementById('migLog');
                    if (status.logs && status.logs.length) {
                        status.logs.forEach(function(line) {
                            var div = document.createElement('div');
                            div.style.color = line.indexOf('ERROR') !== -1 ? '#ef4444' : '#94a3b8';
                            div.textContent = line;
                            logEl.appendChild(div);
                        });
                        logEl.scrollTop = logEl.scrollHeight;
                    }
                    if (status.step) {
                        document.getElementById('migStepBadge').innerHTML =
                            '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;">' +
                            '<span class="spinner-border spinner-border-sm me-1"></span>' + status.step + '</span>';
                    }
                    // Note: we can't reconnect to the same SSE stream (token consumed),
                    // so we poll status until done
                    pollMigrationStatus(migAccountId, token);
                }
            });
        } else {
            // Stale token
            localStorage.removeItem('mdp_migration_token');
            localStorage.removeItem('mdp_migration_account');
        }
    })
    .catch(function() {
        localStorage.removeItem('mdp_migration_token');
        localStorage.removeItem('mdp_migration_account');
    });
})();

function resumeMigration() {
    var token = localStorage.getItem('mdp_migration_token') || 'none';
    var accountId = localStorage.getItem('mdp_migration_account') || '<?= $account['id'] ?? '' ?>';
    if (!accountId) return;

    var staleEl = document.getElementById('migStaleActions');
    if (staleEl) staleEl.style.display = 'none';

    var logEl = document.getElementById('migLog');
    if (!logEl) {
        // No modal open — create a simple one
        SwalDark.fire({ title: 'Importando BD...', html: buildMigrationModalHtml(), showConfirmButton: false, width: 620 });
        logEl = document.getElementById('migLog');
    }
    var stepEl = document.getElementById('migStepBadge');
    if (stepEl) stepEl.innerHTML = '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;"><span class="spinner-border spinner-border-sm me-1"></span>Importando BD...</span>';

    var div = document.createElement('div');
    div.style.color = '#38bdf8';
    div.textContent = 'Retomando importacion de base de datos...';
    logEl.appendChild(div);
    logEl.scrollTop = logEl.scrollHeight;

    var formData = new FormData();
    formData.append('token', token);

    fetch('/accounts/' + accountId + '/migrate/ssh-resume', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        localStorage.removeItem('mdp_migration_token');
        localStorage.removeItem('mdp_migration_account');

        // Show logs
        if (result.logs) {
            result.logs.forEach(function(line) {
                var d = document.createElement('div');
                d.style.color = line.indexOf('ERROR') !== -1 ? '#ef4444' : '#94a3b8';
                d.textContent = line;
                logEl.appendChild(d);
            });
            logEl.scrollTop = logEl.scrollHeight;
        }

        if (result.ok) {
            stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;"><i class="bi bi-check-circle me-1"></i>BD importada</span>';
        } else {
            stepEl.innerHTML = '<span class="badge" style="background:rgba(239,68,68,0.15);color:#ef4444;font-size:0.85rem;"><i class="bi bi-x-circle me-1"></i>Error</span>';
        }

        var resultEl = document.getElementById('migResult');
        if (resultEl) {
            resultEl.innerHTML = buildResultInnerHtml(result);
            resultEl.style.display = '';
            var closeBtn = document.createElement('button');
            closeBtn.className = 'btn btn-outline-light btn-sm mt-3';
            closeBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Cerrar';
            closeBtn.onclick = function() { Swal.close(); resetSshBtn(); };
            resultEl.appendChild(closeBtn);
        }
    })
    .catch(function(err) {
        var d = document.createElement('div');
        d.style.color = '#ef4444';
        d.textContent = 'Error: ' + err.message;
        logEl.appendChild(d);
        if (staleEl) staleEl.style.display = '';
    });
}

function cancelMigration() {
    var token = localStorage.getItem('mdp_migration_token');
    var accountId = localStorage.getItem('mdp_migration_account');
    if (token && accountId) {
        fetch('/accounts/' + accountId + '/migrate/ssh-cancel', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''},
            body: 'token=' + encodeURIComponent(token) + '&_csrf=' + encodeURIComponent(document.querySelector('input[name=_csrf]')?.value || '')
        });
    }
    localStorage.removeItem('mdp_migration_token');
    localStorage.removeItem('mdp_migration_account');
    Swal.close();
    if (typeof resetSshBtn === 'function') resetSshBtn();
}

function pollMigrationStatus(accountId, token) {
    var logEl = document.getElementById('migLog');
    var stepEl = document.getElementById('migStepBadge');
    var lastLogCount = logEl ? logEl.children.length : 0;
    var lastActivityTime = Date.now();
    var staleShown = false;

    var interval = setInterval(function() {
        fetch('/accounts/' + accountId + '/migrate/ssh-status?token=' + token)
        .then(function(r) { return r.json(); })
        .then(function(status) {
            if (!logEl) { clearInterval(interval); return; }

            // Add new log lines
            if (status.logs && status.logs.length > lastLogCount) {
                for (var i = lastLogCount; i < status.logs.length; i++) {
                    var div = document.createElement('div');
                    var line = status.logs[i];
                    div.style.color = line.indexOf('ERROR') !== -1 ? '#ef4444' : '#94a3b8';
                    div.textContent = line;
                    logEl.appendChild(div);
                }
                logEl.scrollTop = logEl.scrollHeight;
                lastLogCount = status.logs.length;
                lastActivityTime = Date.now();
                staleShown = false;
                var staleEl = document.getElementById('migStaleActions');
                if (staleEl) staleEl.style.display = 'none';
            }
            if (status.step) {
                stepEl.innerHTML = '<span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:0.85rem;">' +
                    '<span class="spinner-border spinner-border-sm me-1"></span>' + status.step + '</span>';
            }

            // Show stale actions if no activity for 30 seconds
            if (!staleShown && (Date.now() - lastActivityTime) > 30000) {
                staleShown = true;
                var staleEl = document.getElementById('migStaleActions');
                if (staleEl) staleEl.style.display = '';
            }

            if (status.done) {
                clearInterval(interval);
                localStorage.removeItem('mdp_migration_token');
                localStorage.removeItem('mdp_migration_account');

                var result = status.result || {};
                if (result.ok) {
                    stepEl.innerHTML = '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22c55e;font-size:0.85rem;">' +
                        '<i class="bi bi-check-circle me-1"></i>Migracion completada</span>';
                } else {
                    stepEl.innerHTML = '<span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-size:0.85rem;">' +
                        '<i class="bi bi-exclamation-triangle me-1"></i>Completado con advertencias</span>';
                }

                var staleEl = document.getElementById('migStaleActions');
                if (staleEl) staleEl.style.display = 'none';

                var resultEl = document.getElementById('migResult');
                if (resultEl) {
                    resultEl.innerHTML = buildResultInnerHtml(result);
                    resultEl.style.display = '';
                    var closeBtn = document.createElement('button');
                    closeBtn.className = 'btn btn-outline-light btn-sm mt-3';
                    closeBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Cerrar';
                    closeBtn.onclick = function() { Swal.close(); resetSshBtn(); };
                    resultEl.appendChild(closeBtn);
                }
            }
        })
        .catch(function() {}); // silently retry
    }, 2000);
}

function buildResultInnerHtml(result) {
    var html = '';
    if (result.summary) {
        html += '<div class="p-2 rounded mb-2" style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);">' +
            '<small style="color:#22c55e;"><i class="bi bi-check-circle me-1"></i>' + result.summary + '</small></div>';
    }
    if (result.db_pass) {
        html += '<div class="p-2 rounded mb-2" style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);">' +
            '<small style="color:#38bdf8;"><i class="bi bi-key me-1"></i><strong>Password BD local:</strong> ' +
            '<code id="dbPassCode" style="font-size:0.9rem;user-select:all;">' + result.db_pass + '</code> ' +
                    '<button type="button" class="btn btn-outline-light btn-sm py-0 px-1" style="font-size:0.7rem;" onclick="navigator.clipboard.writeText(\'' + result.db_pass + '\');this.innerHTML=\'<i class=\\\'bi bi-check\\\'></i>\';">' +
                    '<i class="bi bi-clipboard"></i></button></small></div>';
    }
    if (result.errors && result.errors.length > 0) {
        html += '<div class="p-2 rounded" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2);">' +
            '<small style="color:#fbbf24;"><i class="bi bi-exclamation-triangle me-1"></i>Advertencias: ' + result.errors.join(', ') + '</small></div>';
    }
    return html;
}

function buildReconnectResultHtml(status) {
    var html = '<div style="text-align:left;">';
    if (status.logs && status.logs.length) {
        html += '<div style="max-height:200px;overflow-y:auto;padding:10px;font-family:monospace;font-size:0.78rem;background:#0f172a;border-radius:8px;border:1px solid #1e293b;margin-bottom:10px;">';
        status.logs.forEach(function(line) {
            var color = line.indexOf('ERROR') !== -1 ? '#ef4444' : '#94a3b8';
            html += '<div style="color:' + color + ';">' + line + '</div>';
        });
        html += '</div>';
    }
    if (status.result) {
        html += buildResultInnerHtml(status.result);
    }
    html += '</div>';
    return html;
}

// Toggle between manual and auto-detect modes for standalone DB migration
document.querySelectorAll('input[name="db_source_toggle"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var val = this.value;
        document.getElementById('dbSourceInput').value = val;
        if (val === 'manual') {
            document.getElementById('manualFields').style.display = '';
            document.getElementById('autoDetectInfo').style.display = 'none';
        } else if (val === 'auto') {
            document.getElementById('manualFields').style.display = 'none';
            document.getElementById('autoDetectInfo').style.display = '';
            document.getElementById('autoDetectFile').textContent = 'auto (detecta MuseDock, Laravel, WordPress, Zend)';
        } else {
            document.getElementById('manualFields').style.display = 'none';
            document.getElementById('autoDetectInfo').style.display = '';
            var fileNames = {musedock: '.env', laravel: '.env', wordpress: 'wp-config.php', zend: 'application/settings/database.php'};
            document.getElementById('autoDetectFile').textContent = fileNames[val] || val;
        }
    });
});
// Trigger initial state for auto
(function() {
    var autoRadio = document.getElementById('srcAuto');
    if (autoRadio && autoRadio.checked) {
        document.getElementById('manualFields').style.display = 'none';
        document.getElementById('autoDetectInfo').style.display = '';
        document.getElementById('autoDetectFile').textContent = 'auto (detecta MuseDock, Laravel, WordPress, Zend)';
    }
})();

// ================================================================
// Subdomain / Vhost folder selector
// ================================================================
function populateVhostFolders(folders) {
    var subSelector = document.getElementById('subdomainSelector');
    var subList = document.getElementById('subdomainList');
    var folderSelector = document.getElementById('vhostFolderSelector');
    var folderList = document.getElementById('vhostFolderList');

    var subdomains = folders.filter(function(f) { return f.is_subdomain; });
    var otherFolders = folders.filter(function(f) { return !f.is_subdomain; });

    // Populate subdomains
    if (subdomains.length > 0) {
        subSelector.style.display = '';
        var html = '';
        subdomains.forEach(function(f) {
            var projectBadge = '';
            if (f.project) {
                var colors = {Laravel: '#ef4444', MuseDock: '#a855f7', WordPress: '#3b82f6', Zend: '#22c55e'};
                var color = colors[f.project] || '#6b7280';
                projectBadge = ' <span class="badge" style="background:' + color + ';font-size:0.65rem;">' + f.project + '</span>';
            }
            var httpdocsBadge = f.has_httpdocs ? ' <span class="badge bg-secondary" style="font-size:0.65rem;">httpdocs</span>' : '';
            html += '<div class="form-check mb-1">' +
                '<input type="checkbox" class="form-check-input migrate-subdomain-cb" name="migrate_subdomains[]" value="' + f.name + '" id="sub-' + f.name.replace(/\./g, '-') + '" checked>' +
                '<label class="form-check-label small" for="sub-' + f.name.replace(/\./g, '-') + '">' +
                '<i class="bi bi-globe2 text-info me-1"></i><strong>' + f.name + '</strong>' +
                ' <span class="text-muted">(' + f.size + ')</span>' +
                projectBadge + httpdocsBadge +
                '</label></div>';
        });
        subList.innerHTML = html;
    } else {
        subSelector.style.display = 'none';
        subList.innerHTML = '';
    }

    // Populate other folders
    if (otherFolders.length > 0) {
        folderSelector.style.display = '';
        var html2 = '';
        otherFolders.forEach(function(f) {
            html2 += '<div class="form-check mb-1">' +
                '<input type="checkbox" class="form-check-input migrate-folder-cb" name="migrate_folders[]" value="' + f.name + '" id="folder-' + f.name.replace(/[^a-zA-Z0-9]/g, '-') + '">' +
                '<label class="form-check-label small" for="folder-' + f.name.replace(/[^a-zA-Z0-9]/g, '-') + '">' +
                '<i class="bi bi-folder text-warning me-1"></i>' + f.name +
                ' <span class="text-muted">(' + f.size + ')</span>' +
                '</label></div>';
        });
        folderList.innerHTML = html2;
    } else {
        folderSelector.style.display = 'none';
        folderList.innerHTML = '';
    }
}

function toggleAllSubdomains(checked) {
    document.querySelectorAll('.migrate-subdomain-cb').forEach(function(cb) {
        cb.checked = checked;
    });
}
</script>
