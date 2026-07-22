<?php
/**
 * MuseDock CardDAV (Baïkal) installer.
 *
 * Installs/configures a Baïkal (SabreDAV) CardDAV server so mailbox owners get
 * shared, failover-safe contacts that also sync natively to iPhone/Android.
 *
 * Design (see resources/carddav/IMAPBasicAuth.php for the auth rationale):
 *  - Storage: PostgreSQL on the panel cluster (port 5433), so the contact tables
 *    (cards/addressbooks/principals/…) replicate to the slave through the SAME
 *    logical queue that already carries mail_accounts. No lsyncd, no Maildir.
 *  - Auth: every DAV request is validated against the LOCAL Dovecot via IMAP
 *    (single source of truth = the mailbox password). Principals are
 *    auto-provisioned on first login. No password hashes are duplicated.
 *  - Served by the existing php8.3-fpm-musedock pool via a Caddy vhost.
 *
 * Idempotent: safe to re-run. Started from the panel after admin confirmation,
 * NOT from update.sh.
 *
 * Usage: php bin/carddav-setup-run.php <taskId> <base64(json payload)>
 */
require_once __DIR__ . '/../app/bootstrap.php';

use MuseDockPanel\Settings;

const BAIKAL_VERSION = '0.10.1';
const BAIKAL_URL     = 'https://github.com/sabre-io/Baikal/releases/download/0.10.1/baikal-0.10.1.zip';
const BAIKAL_ROOT    = '/opt/musedock-carddav';           // install target
const BAIKAL_DIR     = '/opt/musedock-carddav/baikal';    // extracted app dir

$taskId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($argv[1] ?? ''));
$payloadRaw = (string)($argv[2] ?? '');
if ($taskId === '') { fwrite(STDERR, "Missing task id\n"); exit(1); }

$payload = json_decode(base64_decode($payloadRaw, true) ?: '', true);
if (!is_array($payload)) { fwrite(STDERR, "Invalid payload\n"); exit(1); }

$stateFile = PANEL_ROOT . "/storage/carddav-setup-{$taskId}.json";
$logFile   = PANEL_ROOT . "/storage/logs/carddav-setup-{$taskId}.log";
@mkdir(dirname($stateFile), 0775, true);
@mkdir(dirname($logFile), 0775, true);

function cd_log(string $m): void {
    global $logFile;
    file_put_contents($logFile, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $m . PHP_EOL, FILE_APPEND);
}
function cd_state(string $status, string $stage, array $extra = []): void {
    global $stateFile, $taskId;
    file_put_contents($stateFile, json_encode(array_merge([
        'task_id' => $taskId, 'status' => $status, 'stage' => $stage,
        'updated_at' => gmdate('c'),
    ], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
function cd_run(string $cmd, bool $allowFailure = false): string {
    cd_log('$ ' . $cmd);
    $output = []; $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $text = trim(implode("\n", $output));
    if ($text !== '') cd_log($text);
    if ($code !== 0 && !$allowFailure) {
        throw new RuntimeException("Command failed ({$code}): {$cmd}\n{$text}");
    }
    return $text;
}

try {
    cd_state('running', 'start');

    // ── Inputs ────────────────────────────────────────────────────────────
    $host     = strtolower(trim((string)($payload['host'] ?? 'dav.musedock.com')));
    $imapHost = trim((string)($payload['imap_host'] ?? '')) ?: '127.0.0.1';
    $imapPort = (int)($payload['imap_port'] ?? 143);
    $phpVer   = preg_replace('/[^0-9.]/', '', (string)($payload['php_version'] ?? '8.3')) ?: '8.3';
    $fpmSock  = "/run/php/php{$phpVer}-fpm-musedock.sock";

    $dbHost = \MuseDockPanel\Env::get('DB_HOST', '127.0.0.1');
    $dbPort = \MuseDockPanel\Env::get('DB_PORT', '5433');
    $dbName = 'baikal';
    $dbUser = 'baikal';
    // Reuse a stable password across re-runs so replication + config stay valid.
    $dbPass = Settings::get('carddav_db_pass');
    if (!$dbPass) {
        $dbPass = bin2hex(random_bytes(18));
        Settings::set('carddav_db_pass', $dbPass);
    }

    if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && $host !== 'localhost') {
        throw new RuntimeException("Host CardDAV inválido: {$host}");
    }

    // ── 1. Download + extract Baïkal (idempotent) ─────────────────────────
    cd_state('running', 'download');
    if (!is_dir(BAIKAL_DIR)) {
        cd_run('mkdir -p ' . escapeshellarg(BAIKAL_ROOT));
        $zip = BAIKAL_ROOT . '/baikal.zip';
        cd_run('curl -fsSL -o ' . escapeshellarg($zip) . ' ' . escapeshellarg(BAIKAL_URL));
        cd_run('cd ' . escapeshellarg(BAIKAL_ROOT) . ' && unzip -q -o baikal.zip');
        // The zip extracts a top-level "baikal/" dir into BAIKAL_ROOT already.
        if (!is_dir(BAIKAL_DIR)) {
            throw new RuntimeException('Baïkal no se extrajo en ' . BAIKAL_DIR);
        }
        @unlink($zip);
    } else {
        cd_log('Baïkal ya presente, se conserva.');
    }

    // ── 2. PostgreSQL role + database on the 5433 cluster ─────────────────
    cd_state('running', 'database');
    $escPass = str_replace("'", "''", $dbPass);
    $psql = function (string $sql, ?string $db = null) use ($dbHost, $dbPort) {
        $target = $db ?: 'postgres';
        return 'sudo -u postgres psql -p ' . escapeshellarg($dbPort)
            . ' -d ' . escapeshellarg($target)
            . ' -v ON_ERROR_STOP=1 -c ' . escapeshellarg($sql);
    };
    cd_run($psql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='{$dbUser}') THEN CREATE ROLE {$dbUser} WITH LOGIN PASSWORD '{$escPass}'; ELSE ALTER ROLE {$dbUser} WITH LOGIN PASSWORD '{$escPass}'; END IF; END \$\$;"), true);
    $exists = cd_run($psql("SELECT 1 FROM pg_database WHERE datname='{$dbName}'"), true);
    if (!str_contains($exists, '1')) {
        cd_run($psql("CREATE DATABASE {$dbName} OWNER {$dbUser}"));
    }

    // Load the SabreDAV/Baïkal PgSQL schema if the tables aren't there yet.
    $schema = BAIKAL_DIR . '/Core/Resources/Db/PgSQL/db.sql';
    $hasTables = cd_run($psql("SELECT 1 FROM information_schema.tables WHERE table_name='cards'", $dbName), true);
    if (!str_contains($hasTables, '1')) {
        if (!is_file($schema)) throw new RuntimeException('Schema PgSQL de Baïkal no encontrado: ' . $schema);
        cd_run('sudo -u postgres psql -p ' . escapeshellarg($dbPort) . ' -d ' . escapeshellarg($dbName)
            . ' -v ON_ERROR_STOP=1 -f ' . escapeshellarg($schema));
    }

    // CRITICAL: Baïkal's PgSQL schema ships WITHOUT unique constraints on the
    // identity columns. Our IMAP auth backend auto-provisions principals on
    // first login and relies on ON CONFLICT to stay race-safe. Add the unique
    // indexes idempotently so concurrent first-logins can't create duplicate
    // principals / address books.
    foreach ([
        "CREATE UNIQUE INDEX IF NOT EXISTS principals_uri_uniq ON principals (uri)",
        "CREATE UNIQUE INDEX IF NOT EXISTS users_username_uniq ON users (username)",
        "CREATE UNIQUE INDEX IF NOT EXISTS addressbooks_principal_uri_uniq ON addressbooks (principaluri, uri)",
    ] as $ddl) {
        cd_run($psql($ddl, $dbName));
    }

    // Make sure the baikal role owns every table/sequence (schema was loaded as
    // postgres). Mirrors the Roundcube installer's owner-fix.
    $ownerFix = "DO \$\$ DECLARE r RECORD; BEGIN "
        . "FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' LOOP "
        . "EXECUTE 'ALTER TABLE public.' || quote_ident(r.tablename) || ' OWNER TO {$dbUser}'; END LOOP; "
        . "FOR r IN SELECT sequencename FROM pg_sequences WHERE schemaname='public' LOOP "
        . "EXECUTE 'ALTER SEQUENCE public.' || quote_ident(r.sequencename) || ' OWNER TO {$dbUser}'; END LOOP; END \$\$;";
    cd_run($psql($ownerFix, $dbName));
    cd_run($psql("GRANT ALL ON ALL TABLES IN SCHEMA public TO {$dbUser}; GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO {$dbUser}; GRANT ALL ON SCHEMA public TO {$dbUser};", $dbName), true);

    // ── 3. Install the IMAP auth backend + patch Server.php ───────────────
    cd_state('running', 'auth-backend');
    $backendSrc = PANEL_ROOT . '/resources/carddav/IMAPBasicAuth.php';
    $backendDst = BAIKAL_DIR . '/Core/Frameworks/Baikal/Core/IMAPBasicAuth.php';
    if (!is_file($backendSrc)) throw new RuntimeException('Falta el backend IMAP: ' . $backendSrc);
    cd_run('cp ' . escapeshellarg($backendSrc) . ' ' . escapeshellarg($backendDst));

    // Patch Server.php so authType 'IMAP' selects our backend. Idempotent: only
    // inject once. We add a branch BEFORE the 'Basic' branch.
    $serverPhp = BAIKAL_DIR . '/Core/Frameworks/Baikal/Core/Server.php';
    $src = file_get_contents($serverPhp);
    if ($src === false) throw new RuntimeException('No se pudo leer Server.php');
    if (!str_contains($src, "IMAPBasicAuth(")) {
        $needle = "if (\$this->authType === 'Basic') {";
        $inject = "if (\$this->authType === 'IMAP') {\n"
            . "            \$imapHost = getenv('MD_CARDDAV_IMAP_HOST') ?: '127.0.0.1';\n"
            . "            \$imapPort = getenv('MD_CARDDAV_IMAP_PORT') ?: 143;\n"
            . "            \$authBackend = new \\Baikal\\Core\\IMAPBasicAuth(\$this->pdo, \$this->authRealm, \$imapHost, \$imapPort);\n"
            . "        } else" . $needle;
        // "if (...) {" -> "if IMAP {...} else if Basic {" — but the original is
        // "if ($this->authType === 'Basic') {" so we turn it into "} else if(...)".
        // Simpler + safe: replace the first "if (...Basic...) {" with our block
        // that ends in "} else " + the original condition line.
        $replacement = "if (\$this->authType === 'IMAP') {\n"
            . "            \$imapHost = getenv('MD_CARDDAV_IMAP_HOST') ?: '127.0.0.1';\n"
            . "            \$imapPort = getenv('MD_CARDDAV_IMAP_PORT') ?: 143;\n"
            . "            \$authBackend = new \\Baikal\\Core\\IMAPBasicAuth(\$this->pdo, \$this->authRealm, \$imapHost, (int)\$imapPort);\n"
            . "        } else" . $needle;
        $patched = preg_replace('/' . preg_quote($needle, '/') . '/', $replacement, $src, 1);
        if ($patched === null || $patched === $src) {
            throw new RuntimeException('No se pudo parchear Server.php (patrón no encontrado)');
        }
        file_put_contents($serverPhp, $patched);
        cd_log('Server.php parcheado con la rama IMAP.');
    } else {
        cd_log('Server.php ya parcheado.');
    }

    // ── 4. Write Baïkal config (baikal.yaml) ──────────────────────────────
    cd_state('running', 'config');
    $configDir = BAIKAL_DIR . '/config';
    cd_run('mkdir -p ' . escapeshellarg($configDir));
    // A stable encryption key (Baïkal uses it for its own bits; keep it stable).
    $encKey = Settings::get('carddav_enc_key');
    if (!$encKey) { $encKey = bin2hex(random_bytes(24)); Settings::set('carddav_enc_key', $encKey); }

    $authRealm = 'MuseDock CardDAV';
    // A stable admin password for Baïkal's own admin UI (we never expose it; the
    // hash lets Framework::bootstrap() consider the install "configured"). The
    // plaintext is stored so the admin can log into /admin/ if ever needed.
    $adminPass = Settings::get('carddav_admin_pass');
    if (!$adminPass) { $adminPass = bin2hex(random_bytes(12)); Settings::set('carddav_admin_pass', $adminPass); }
    $adminHash = hash('sha256', 'admin:' . $authRealm . ':' . $adminPass);

    // The Flake Pgsql DSN is `pgsql:host=<host>;dbname=<db>` with no port field,
    // so we fold the cluster port (5433) into the host string. PDO parses the
    // extra `;port=` key correctly.
    $pgHost = $dbHost . ';port=' . $dbPort;

    // Format MUST match what Baïkal reads (Standard.php `system`, Database.php
    // `database`), or Framework::bootstrap() bounces to the web install tool.
    $yaml = "system:\n"
        . "  configured_version: '" . BAIKAL_VERSION . "'\n"
        . "  timezone: 'Europe/Madrid'\n"
        . "  card_enabled: true\n"
        . "  cal_enabled: true\n"
        . "  dav_auth_type: 'IMAP'\n"
        . "  admin_passwordhash: '{$adminHash}'\n"
        . "  failed_access_message: 'user %u authentication failure for MuseDock CardDAV'\n"
        . "  auth_realm: '{$authRealm}'\n"
        . "  base_uri: ''\n"
        . "  invite_from: 'noreply@{$host}'\n"
        . "database:\n"
        . "  backend: 'pgsql'\n"
        . "  sqlite_file: '" . BAIKAL_DIR . "/Specific/db/db.sqlite'\n"
        . "  encryption_key: '{$encKey}'\n"
        . "  pgsql_host: '{$pgHost}'\n"
        . "  pgsql_dbname: '{$dbName}'\n"
        . "  pgsql_username: '{$dbUser}'\n"
        . "  pgsql_password: '{$dbPass}'\n"
        . "  mysql_host: ''\n"
        . "  mysql_dbname: ''\n"
        . "  mysql_username: ''\n"
        . "  mysql_password: ''\n";
    file_put_contents($configDir . '/baikal.yaml', $yaml);
    // With configured_version + admin_passwordhash present, Framework::bootstrap()
    // considers Baïkal installed and never redirects to the web install tool.

    // Pass IMAP host/port to the patched Server.php via the FPM pool env.
    // We inject env into the php-fpm pool so getenv() works under Caddy.
    cd_state('running', 'fpm-env');
    $poolFile = "/etc/php/{$phpVer}/fpm/pool.d/musedock.conf";
    if (is_file($poolFile)) {
        $pool = file_get_contents($poolFile);
        $envLines = "env[MD_CARDDAV_IMAP_HOST] = {$imapHost}\nenv[MD_CARDDAV_IMAP_PORT] = {$imapPort}\n";
        if (!str_contains($pool, 'MD_CARDDAV_IMAP_HOST')) {
            file_put_contents($poolFile, rtrim($pool) . "\n" . $envLines);
            cd_run("systemctl reload php{$phpVer}-fpm", true);
        }
    } else {
        cd_log("Aviso: pool FPM no encontrado en {$poolFile}; el backend usará 127.0.0.1:143 por defecto.");
    }

    // ── 5. Permissions ────────────────────────────────────────────────────
    cd_state('running', 'permissions');
    cd_run('chown -R www-data:www-data ' . escapeshellarg(BAIKAL_DIR . '/config'), true);
    cd_run('chown -R www-data:www-data ' . escapeshellarg(BAIKAL_DIR . '/Specific'), true);

    // ── 5b. Roundcube integration: carddav plugin + SSO preset ────────────
    // Only if Roundcube is installed on this node. The plugin lets webmail read
    // the SAME contacts as the mobile clients, with no second login: Roundcube
    // fills %u/%p from the session, so the user authenticates to Baïkal with
    // their mailbox credentials (which our IMAP backend validates).
    cd_state('running', 'roundcube-plugin');
    $rcDir = '/opt/musedock-webmail/roundcube/current';
    if (is_dir($rcDir) && is_file($rcDir . '/config/config.inc.php')) {
        // Install the plugin via composer (idempotent — composer is a no-op if present).
        if (!is_dir($rcDir . '/plugins/carddav')) {
            cd_run('cd ' . escapeshellarg($rcDir) . ' && COMPOSER_ALLOW_SUPERUSER=1 composer require roundcube/carddav:^5 --no-interaction --update-no-dev 2>&1', true);
        }
        if (is_dir($rcDir . '/plugins/carddav')) {
            // The plugin's own config: a fixed, hidden preset pointing at our
            // local Baïkal. %u = full email, %p = session password, %d = domain
            // part. url uses %u so each user only reaches their own principal.
            $davUrl = "https://{$host}/dav.php/addressbooks/%u/default/";
            $pluginCfg = "<?php\n"
                . "// MuseDock CardDAV preset — auto-generated. SSO via %u/%p (session creds).\n"
                . "\$prefs['_GLOBAL']['pwstore_scheme'] = 'des_key';\n"
                . "\$prefs['_GLOBAL']['hide_preferences'] = false;\n"
                . "\$prefs['MuseDock'] = [\n"
                . "    'name'         => 'MuseDock Contactos',\n"
                . "    'username'     => '%u',\n"
                . "    'password'     => '%p',\n"
                . "    'url'          => " . var_export($davUrl, true) . ",\n"
                . "    'active'       => true,\n"
                . "    'readonly'     => false,\n"
                . "    'refresh_time' => '00:15:00',\n"
                . "    'fixed'        => ['username', 'password', 'url'],\n"
                . "    'hide'         => false,\n"
                . "    'use_categories' => false,\n"
                . "];\n";
            file_put_contents($rcDir . '/plugins/carddav/config.inc.php', $pluginCfg);
            cd_run('chgrp www-data ' . escapeshellarg($rcDir . '/plugins/carddav/config.inc.php'), true);
            cd_run('chmod 640 ' . escapeshellarg($rcDir . '/plugins/carddav/config.inc.php'), true);

            // Add 'carddav' to Roundcube's plugin list (idempotent regex edit).
            $rcCfgFile = $rcDir . '/config/config.inc.php';
            $rcCfg = file_get_contents($rcCfgFile);
            if ($rcCfg !== false && !preg_match("/'carddav'/", $rcCfg)) {
                $patched = preg_replace(
                    "/(\\\$config\\['plugins'\\]\\s*=\\s*\\[)([^\\]]*)(\\];)/",
                    "$1$2, 'carddav'$3",
                    $rcCfg, 1
                );
                if ($patched !== null && $patched !== $rcCfg) {
                    file_put_contents($rcCfgFile, $patched);
                    cd_log("Plugin 'carddav' añadido a la lista de plugins de Roundcube.");
                } else {
                    cd_log("Aviso: no se pudo añadir 'carddav' a config.inc.php (patrón no hallado).");
                }
            }
            Settings::set('carddav_roundcube_plugin', '1');
        } else {
            cd_log('Aviso: composer no instaló el plugin carddav; se omite integración webmail.');
        }
    } else {
        cd_log('Roundcube no presente en este nodo; se omite el plugin carddav.');
    }

    // ── 6. Record install state for the panel + replication ───────────────
    Settings::set('carddav_installed', '1');
    Settings::set('carddav_host', $host);
    Settings::set('carddav_version', BAIKAL_VERSION);
    Settings::set('carddav_db_name', $dbName);
    Settings::set('carddav_db_user', $dbUser);

    // ── 6b. Install the failover sync cron (staggered +25s) ───────────────
    // Only the current master pushes; the worker itself no-ops on slaves and
    // when CardDAV isn't installed, so it's safe to drop on every node.
    cd_state('running', 'cron');
    $cronFile = '/etc/cron.d/musedock-carddav-sync';
    $cron = "# MuseDock Panel — CardDAV/CalDAV sync worker (staggered: +25s)\n"
        . "# Only the current master pushes contacts/calendars to the other node(s).\n"
        . "# No-op on slaves and when CardDAV isn't installed.\n"
        . "* * * * * root sleep 25 && /usr/bin/php " . PANEL_ROOT . "/bin/carddav-sync-worker.php >> "
        . PANEL_ROOT . "/storage/logs/carddav-sync-worker.log 2>&1\n";
    if (@file_put_contents($cronFile, $cron) !== false) {
        @chmod($cronFile, 0644);
        cd_log('Cron de sync CardDAV instalado en ' . $cronFile);
    } else {
        cd_log('Aviso: no se pudo escribir ' . $cronFile . ' (¿permisos?). Instálalo a mano.');
    }

    // ── 7. Publish the Caddy route (serves Baïkal + autodiscovery) ────────
    cd_state('running', 'caddy-route');
    $routeRes = \MuseDockPanel\Services\CardDavService::ensureCaddyRoute($host, $phpVer);
    if (empty($routeRes['ok'])) {
        // Non-fatal: DB + app are installed; the admin can retry the route.
        cd_log('Aviso: no se pudo publicar la ruta Caddy: ' . ($routeRes['error'] ?? 'desconocido'));
    } else {
        cd_log('Ruta Caddy publicada: ' . ($routeRes['route_id'] ?? ''));
    }

    cd_state('done', 'complete', [
        'host' => $host,
        'db'   => $dbName,
        'discovery_url' => "https://{$host}/",
        'dav_url' => "https://{$host}/dav.php/",
    ]);
    cd_log('CardDAV (Baïkal) instalado correctamente en ' . $host);
    echo "OK\n";
} catch (\Throwable $e) {
    cd_log('ERROR: ' . $e->getMessage());
    cd_state('error', 'failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
