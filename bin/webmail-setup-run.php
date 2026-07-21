<?php
/**
 * MuseDock Webmail installer.
 * Installs/configures Roundcube on demand. This script is intentionally not run
 * from update.sh; the admin starts it from /mail after confirming the operation.
 */
require_once __DIR__ . '/../app/bootstrap.php';

use MuseDockPanel\Settings;
use MuseDockPanel\Services\WebmailService;

$taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($argv[1] ?? ''));
$payloadRaw = (string)($argv[2] ?? '');
if ($taskId === '') {
    fwrite(STDERR, "Missing task id\n");
    exit(1);
}

$payload = json_decode(base64_decode($payloadRaw, true) ?: '', true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid payload\n");
    exit(1);
}

$stateFile = PANEL_ROOT . "/storage/webmail-setup-{$taskId}.json";
$logFile = PANEL_ROOT . "/storage/logs/webmail-setup-{$taskId}.log";
@mkdir(dirname($stateFile), 0775, true);
@mkdir(dirname($logFile), 0775, true);

function webmail_log(string $message): void
{
    global $logFile;
    file_put_contents($logFile, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL, FILE_APPEND);
}

function webmail_state(string $status, string $stage, array $extra = []): void
{
    global $stateFile, $taskId;
    $data = array_merge([
        'task_id' => $taskId,
        'status' => $status,
        'stage' => $stage,
        'updated_at' => gmdate('c'),
    ], $extra);
    file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function webmail_run(string $cmd, bool $allowFailure = false): string
{
    webmail_log('$ ' . $cmd);
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $text = trim(implode("\n", $output));
    if ($text !== '') webmail_log($text);
    if ($code !== 0 && !$allowFailure) {
        throw new RuntimeException("Command failed ({$code}): {$cmd}\n{$text}");
    }
    return $text;
}

function webmail_latest_roundcube_url(): string
{
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: MuseDock-Panel\r\n", 'timeout' => 20]]);
    $json = @file_get_contents('https://api.github.com/repos/roundcube/roundcubemail/releases/latest', false, $ctx);
    if (!$json) {
        throw new RuntimeException('No se pudo consultar la ultima release de Roundcube en GitHub.');
    }
    $release = json_decode($json, true);
    foreach (($release['assets'] ?? []) as $asset) {
        $url = (string)($asset['browser_download_url'] ?? '');
        $name = strtolower((string)($asset['name'] ?? ''));
        if ($url !== '' && str_contains($name, 'complete') && str_ends_with($name, '.tar.gz')) {
            return $url;
        }
    }
    foreach (($release['assets'] ?? []) as $asset) {
        $url = (string)($asset['browser_download_url'] ?? '');
        $name = strtolower((string)($asset['name'] ?? ''));
        if ($url !== '' && str_ends_with($name, '.tar.gz')) {
            return $url;
        }
    }
    throw new RuntimeException('La release de Roundcube no contiene un tar.gz descargable.');
}

function webmail_php_value(string $value): string
{
    return var_export($value, true);
}

try {
    $provider = strtolower(trim((string)($payload['provider'] ?? 'roundcube')));
    $host = strtolower(trim((string)($payload['host'] ?? '')));
    $imapHost = trim((string)($payload['imap_host'] ?? '')) ?: '127.0.0.1';
    $smtpHost = trim((string)($payload['smtp_host'] ?? '')) ?: $imapHost;
    $phpVersion = preg_replace('/[^0-9.]/', '', (string)($payload['php_version'] ?? '8.3')) ?: '8.3';

    if ($provider !== 'roundcube') {
        throw new RuntimeException('Proveedor no soportado por este instalador: ' . $provider);
    }
    if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
        throw new RuntimeException('Hostname webmail no valido.');
    }

    webmail_state('running', 'install-packages', ['message' => 'Instalando dependencias PHP/Roundcube']);
    Settings::set('mail_webmail_install_status', 'running');

    webmail_run('apt-get update -y', true);
    $versionedPkgs = [
        'curl', 'ca-certificates', 'tar', 'gzip', 'unzip', 'dovecot-core',
        "php{$phpVersion}-imap", "php{$phpVersion}-mbstring", "php{$phpVersion}-intl",
        "php{$phpVersion}-xml", "php{$phpVersion}-curl", "php{$phpVersion}-pgsql",
        "php{$phpVersion}-gd", "php{$phpVersion}-zip",
    ];
    try {
        webmail_run('DEBIAN_FRONTEND=noninteractive apt-get install -y ' . implode(' ', array_map('escapeshellarg', $versionedPkgs)));
    } catch (Throwable $e) {
        $genericPkgs = ['curl', 'ca-certificates', 'tar', 'gzip', 'unzip', 'dovecot-core', 'php-imap', 'php-mbstring', 'php-intl', 'php-xml', 'php-curl', 'php-pgsql', 'php-gd', 'php-zip'];
        webmail_log('Versioned PHP package install failed, retrying generic packages: ' . $e->getMessage());
        webmail_run('DEBIAN_FRONTEND=noninteractive apt-get install -y ' . implode(' ', array_map('escapeshellarg', $genericPkgs)));
    }

    webmail_state('running', 'download-roundcube', ['message' => 'Descargando Roundcube']);
    $installRoot = '/opt/musedock-webmail/roundcube';
    $releaseBase = $installRoot . '/releases/' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    @mkdir($releaseBase, 0755, true);
    $tarball = "/tmp/roundcube-{$taskId}.tar.gz";
    $url = webmail_latest_roundcube_url();
    webmail_run('curl -L --fail --connect-timeout 20 --max-time 180 -o ' . escapeshellarg($tarball) . ' ' . escapeshellarg($url));
    webmail_run('tar -xzf ' . escapeshellarg($tarball) . ' -C ' . escapeshellarg($releaseBase));

    $currentDir = $releaseBase;
    $candidates = array_merge([$releaseBase], glob($releaseBase . '/*', GLOB_ONLYDIR) ?: []);
    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/index.php') || is_file($candidate . '/public_html/index.php')) {
            $currentDir = $candidate;
            break;
        }
    }
    if (!is_file($currentDir . '/index.php') && !is_file($currentDir . '/public_html/index.php')) {
        throw new RuntimeException('No se encontro index.php de Roundcube tras extraer el paquete.');
    }

    webmail_state('running', 'configure-roundcube', ['message' => 'Configurando Roundcube']);
    $dataDir = '/var/lib/musedock-webmail/roundcube';
    @mkdir($dataDir . '/logs', 0770, true);
    @mkdir($dataDir . '/temp', 0770, true);
    @mkdir($currentDir . '/config', 0755, true);

    $desKey = bin2hex(random_bytes(16));
    $dbHost = \MuseDockPanel\Env::get('DB_HOST', '127.0.0.1');
    $dbPort = \MuseDockPanel\Env::get('DB_PORT', '5433');
    $dbName = \MuseDockPanel\Env::get('DB_NAME', 'musedock_panel');
    $dbUser = \MuseDockPanel\Env::get('DB_USER', 'musedock_panel');
    $dbPass = \MuseDockPanel\Env::get('DB_PASS', '');

    // Roundcube uses PostgreSQL (a dedicated 'roundcube' database on the panel
    // cluster), NOT SQLite. SQLite is a local file that wouldn't replicate to a
    // slave — PostgreSQL keeps identities/contacts/prefs in the DB we already
    // replicate for HA. Create the DB + a dedicated role, then load Roundcube's
    // PostgreSQL schema. All done as the postgres superuser (panel runs as root).
    $rcDb   = 'roundcube';
    $rcUser = 'roundcube';
    $rcPass = bin2hex(random_bytes(18));
    $psql = function (string $sql, string $db = 'postgres') use ($dbPort) {
        return 'sudo -u postgres psql -p ' . (int)$dbPort . ' -d ' . escapeshellarg($db)
             . ' -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql) . ' 2>&1';
    };
    $escPass = str_replace("'", "''", $rcPass);
    // Role (idempotent).
    webmail_run($psql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='{$rcUser}') THEN CREATE ROLE {$rcUser} WITH LOGIN PASSWORD '{$escPass}'; ELSE ALTER ROLE {$rcUser} WITH LOGIN PASSWORD '{$escPass}'; END IF; END \$\$;"), true);
    // Database (CREATE DATABASE can't run in a DO block; guard with a check).
    $exists = trim((string)shell_exec('sudo -u postgres psql -p ' . (int)$dbPort . " -tAc \"SELECT 1 FROM pg_database WHERE datname='{$rcDb}'\" 2>/dev/null"));
    if ($exists !== '1') {
        webmail_run($psql("CREATE DATABASE {$rcDb} OWNER {$rcUser}"), true);
        // Load Roundcube's PostgreSQL schema into the fresh DB.
        $pgSchema = $currentDir . '/SQL/postgres.initial.sql';
        if (!is_file($pgSchema)) {
            throw new RuntimeException('No se encontro SQL/postgres.initial.sql de Roundcube.');
        }
        webmail_run('sudo -u postgres psql -p ' . (int)$dbPort . ' -d ' . escapeshellarg($rcDb)
            . ' -v ON_ERROR_STOP=1 -f ' . escapeshellarg($pgSchema) . ' 2>&1');
        webmail_run($psql("GRANT ALL ON ALL TABLES IN SCHEMA public TO {$rcUser}; GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO {$rcUser};", $rcDb), true);
    }

    $roundcubeDsn = sprintf('pgsql://%s:%s@%s:%s/%s',
        rawurlencode($rcUser), rawurlencode($rcPass), rawurlencode($dbHost), rawurlencode((string)$dbPort), rawurlencode($rcDb));

    // Password plugin still points at the panel DB (to change mailbox passwords).
    $roundcubePasswordDsn = sprintf('pgsql://%s:%s@%s:%s/%s',
        rawurlencode($dbUser), rawurlencode($dbPass), rawurlencode($dbHost), rawurlencode((string)$dbPort), rawurlencode($dbName));

    $config = "<?php\n"
        . "\$config = [];\n"
        . "\$config['db_dsnw'] = " . webmail_php_value($roundcubeDsn) . ";\n"
        . "\$config['default_host'] = " . webmail_php_value('ssl://' . $imapHost) . ";\n"
        . "\$config['default_port'] = 993;\n"
        . "\$config['smtp_host'] = " . webmail_php_value('tls://' . $smtpHost) . ";\n"
        . "\$config['smtp_port'] = 587;\n"
        . "\$config['smtp_user'] = '%u';\n"
        . "\$config['smtp_pass'] = '%p';\n"
        . "\$config['product_name'] = 'MuseDock Webmail';\n"
        . "\$config['des_key'] = " . webmail_php_value($desKey) . ";\n"
        . "\$config['plugins'] = ['archive', 'zipdownload', 'password', 'managesieve'];\n"
        . "\$config['temp_dir'] = " . webmail_php_value($dataDir . '/temp') . ";\n"
        . "\$config['log_dir'] = " . webmail_php_value($dataDir . '/logs') . ";\n"
        . "\$config['enable_installer'] = false;\n"
        . "\n"
        . "// Password plugin: changes MuseDock mailbox password in panel DB.\n"
        . "\$config['password_driver'] = 'sql';\n"
        . "\$config['password_db_dsn'] = " . webmail_php_value($roundcubePasswordDsn) . ";\n"
        . "\$config['password_query'] = \"UPDATE mail_accounts SET password_hash = %P, updated_at = NOW() WHERE email = %u AND status = 'active'\";\n"
        . "\$config['password_algorithm'] = 'dovecot';\n"
        . "\$config['password_dovecotpw'] = '/usr/bin/doveadm pw';\n"
        . "\$config['password_dovecotpw_method'] = 'BLF-CRYPT';\n"
        . "\$config['password_dovecotpw_with_method'] = true;\n"
        . "\n"
        . "// ManageSieve plugin: filters, vacation/autoresponder and forwards.\n"
        . "\$config['managesieve_host'] = " . webmail_php_value($imapHost) . ";\n"
        . "\$config['managesieve_port'] = 4190;\n"
        . "\$config['managesieve_usetls'] = true;\n"
        . "\$config['managesieve_auth_type'] = null;\n";
    file_put_contents($currentDir . '/config/config.inc.php', $config);

    $docRoot = is_file($currentDir . '/public_html/index.php') ? ($currentDir . '/public_html') : $currentDir;
    @unlink($installRoot . '/current');
    symlink($currentDir, $installRoot . '/current');
    // Roundcube runs under PHP-FPM as www-data. The code stays root-owned (read
    // only) but the config file and the writable data dir MUST be accessible to
    // www-data, or Roundcube dies with "internal error":
    //   - config.inc.php: group www-data + 0640 (readable by the FPM user, not world)
    //   - data dir (sqlite/logs/temp): owned by www-data and writable
    webmail_run('chown -R root:root ' . escapeshellarg($currentDir), true);
    webmail_run('chown -R www-data:www-data ' . escapeshellarg($dataDir), true);
    webmail_run('chgrp www-data ' . escapeshellarg($currentDir . '/config/config.inc.php'), true);
    webmail_run('chmod 640 ' . escapeshellarg($currentDir . '/config/config.inc.php'), true);
    // Roundcube also needs writable temp/logs inside the tree; point them at the
    // www-data-owned data dir via config, but ensure the in-tree temp/logs (if used)
    // are group-writable by www-data as a safety net.
    foreach (['temp', 'logs'] as $sub) {
        $d = $currentDir . '/' . $sub;
        if (is_dir($d)) {
            webmail_run('chgrp -R www-data ' . escapeshellarg($d), true);
            webmail_run('chmod -R g+w ' . escapeshellarg($d), true);
        }
    }

    webmail_state('running', 'caddy-route', ['message' => 'Publicando ruta Caddy para ' . $host]);
    $route = WebmailService::ensureRoundcubeCaddyRoute($host, $docRoot, $phpVersion);
    if (!($route['ok'] ?? false)) {
        throw new RuntimeException($route['error'] ?? 'No se pudo crear la ruta Caddy.');
    }

    Settings::set('mail_webmail_enabled', '1');
    Settings::set('mail_webmail_provider', 'roundcube');
    Settings::set('mail_webmail_host', $host);
    Settings::set('mail_webmail_url', 'https://' . $host);
    Settings::set('mail_webmail_imap_host', $imapHost);
    Settings::set('mail_webmail_smtp_host', $smtpHost);
    Settings::set('mail_webmail_doc_root', $docRoot);
    Settings::set('mail_webmail_installed_at', gmdate('Y-m-d H:i:s'));
    Settings::set('mail_webmail_install_status', 'completed');

    webmail_state('completed', 'done', [
        'message' => 'Roundcube instalado y publicado.',
        'url' => 'https://' . $host,
        'doc_root' => $docRoot,
        'route_id' => $route['route_id'] ?? '',
    ]);
    webmail_log('DONE Roundcube ' . $host);
} catch (Throwable $e) {
    Settings::set('mail_webmail_install_status', 'failed');
    webmail_state('failed', 'error', ['message' => $e->getMessage()]);
    webmail_log('ERROR: ' . $e->getMessage());
    exit(1);
}
