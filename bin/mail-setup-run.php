<?php
/**
 * Background mail node setup — runs on the NODE, not the master.
 *
 * Launched by MailService::nodeSetupMail() via nohup.
 * Writes progress to a JSON file + detailed log file.
 * Master polls via: POST /api/cluster/action { action: "mail_setup_status" }
 *
 * Usage: nohup php mail-setup-run.php <task_id> <payload_json_base64> > /dev/null 2>&1 &
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/bootstrap.php';

use MuseDockPanel\Settings;

$taskId     = $argv[1] ?? '';
$payloadB64 = $argv[2] ?? '';

if (!$taskId || !$payloadB64) {
    file_put_contents("php://stderr", "Usage: php mail-setup-run.php <task_id> <payload_base64>\n");
    exit(1);
}

$payload = json_decode(base64_decode($payloadB64), true);
if (!$payload) {
    exit(1);
}

$safeTaskId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
$progressFile = $baseDir . '/storage/mail-setup-' . $safeTaskId . '.json';
$logFile      = $baseDir . '/storage/logs/mail-setup-' . $safeTaskId . '.log';

// Ensure log directory exists
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0750, true);
}

// ── Step definitions ─────────────────────────────────────────

$STEPS = [
    1 => ['id' => 'verify_database',       'label' => 'Verificar conectividad PostgreSQL'],
    2 => ['id' => 'create_vmail_user',     'label' => 'Crear usuario vmail'],
    3 => ['id' => 'install_packages',      'label' => 'Instalar paquetes (Postfix, Dovecot, OpenDKIM, Rspamd)'],
    4 => ['id' => 'configure_postfix',     'label' => 'Configurar Postfix (SQL lookups, SASL, TLS)'],
    5 => ['id' => 'configure_dovecot',     'label' => 'Configurar Dovecot (SQL auth, quota, LMTP)'],
    6 => ['id' => 'configure_ssl',         'label' => 'Configurar certificados SSL/TLS'],
    7 => ['id' => 'configure_opendkim',    'label' => 'Configurar OpenDKIM (firma DKIM)'],
    8 => ['id' => 'configure_rspamd',      'label' => 'Configurar Rspamd (antispam)'],
    9 => ['id' => 'start_services',        'label' => 'Iniciar y habilitar servicios'],
   10 => ['id' => 'verify',                'label' => 'Verificar servicios y puertos'],
];
$totalSteps = count($STEPS);

// ── Helpers ──────────────────────────────────────────────────

function logLine(string $file, string $level, string $message): void
{
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$level}] {$message}\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function writeProgress(string $file, array $stepDef, int $stepNum, int $total, string $status, array $errors, array $extra = []): void
{
    $data = [
        'status'      => $status,       // running | completed | completed_with_errors | failed | stale
        'step'        => $stepNum,
        'total_steps' => $total,
        'current'     => $stepDef['id'] ?? 'unknown',
        'label'       => $stepDef['label'] ?? '',
        'errors'      => $errors,
        'pid'         => getmypid(),
        'started_at'  => $GLOBALS['_startedAt'] ?? date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
    file_put_contents($file, json_encode(array_merge($data, $extra), JSON_PRETTY_PRINT));
}

/**
 * Execute a shell command with full logging.
 * Returns [bool $success, string $output].
 */
function run(string $cmd, string $logFile, array &$errors, string $context = ''): array
{
    $label = $context ? "[{$context}] " : '';
    logLine($logFile, 'CMD', "{$label}{$cmd}");

    $startTime = microtime(true);
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $elapsed = round(microtime(true) - $startTime, 2);
    $outputStr = implode("\n", $output);

    if ($code === 0) {
        logLine($logFile, 'OK', "{$label}exit 0 ({$elapsed}s)");
        if ($outputStr && strlen($outputStr) < 500) {
            logLine($logFile, 'OUT', $outputStr);
        }
        return [true, $outputStr];
    } else {
        $lastLines = implode("\n", array_slice($output, -15));
        logLine($logFile, 'FAIL', "{$label}exit {$code} ({$elapsed}s)");
        logLine($logFile, 'ERR', $lastLines);
        $errors[] = [
            'step'    => $context,
            'command' => $cmd,
            'exit'    => $code,
            'output'  => $lastLines,
        ];
        return [false, $outputStr];
    }
}

// ── Extract payload ──────────────────────────────────────────

$dbHost    = $payload['db_host'] ?? 'localhost';
$dbPort    = $payload['db_port'] ?? '5433';
$dbName    = $payload['db_name'] ?? 'musedock_panel';
$dbUser    = $payload['db_user'] ?? 'musedock_mail';
$dbPass    = $payload['db_pass'] ?? '';
$hostname  = $payload['mail_hostname'] ?? '';
$sslMode   = $payload['ssl_mode'] ?? 'letsencrypt';
$mailMode  = in_array(($payload['mail_mode'] ?? 'full'), ['satellite', 'full', 'external'], true) ? $payload['mail_mode'] : 'full';
$outboundDomain = strtolower(trim((string)($payload['outbound_domain'] ?? '')));
$localMode = !empty($payload['local_mode']);
$vmailUid  = 5000;
$vmailGid  = 5000;
$mailDir   = '/var/mail/vhosts';

$errors = [];
$GLOBALS['_startedAt'] = date('Y-m-d H:i:s');

logLine($logFile, 'INFO', '═══════════════════════════════════════════════════');
logLine($logFile, 'INFO', "Mail node setup started — task: {$taskId}");
logLine($logFile, 'INFO', "PID: " . getmypid() . " | hostname: {$hostname} | ssl: {$sslMode} | mail_mode: {$mailMode}");
logLine($logFile, 'INFO', "DB: {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}");
logLine($logFile, 'INFO', 'Modo: ' . ($localMode ? 'LOCAL (master)' : 'REMOTO (nodo)'));
logLine($logFile, 'INFO', '═══════════════════════════════════════════════════');

if ($outboundDomain === '' && str_contains($hostname, '.')) {
    $parts = explode('.', $hostname);
    $outboundDomain = implode('.', array_slice($parts, -2));
}

if ($mailMode === 'external') {
    $step = 1;
    writeProgress($progressFile, ['id' => 'save_external_smtp', 'label' => 'Guardar SMTP externo'], $step, 1, 'running', $errors);
    $smtp = $payload['smtp'] ?? [];
    $smtpHost = trim((string)($smtp['host'] ?? ''));
    $smtpPort = (int)($smtp['port'] ?? 587);
    $smtpUser = trim((string)($smtp['username'] ?? ''));
    $smtpPass = (string)($smtp['password'] ?? '');
    $smtpEncryption = in_array(($smtp['encryption'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? $smtp['encryption'] : 'tls';
    $fromAddress = trim((string)($smtp['from_address'] ?? ''));
    $fromName = trim((string)($smtp['from_name'] ?? 'MuseDock'));

    if ($smtpHost === '' || $fromAddress === '') {
        $errors[] = ['step' => 'save_external_smtp', 'command' => 'validate smtp payload', 'exit' => 1, 'output' => 'smtp_host y from_address son obligatorios'];
        writeProgress($progressFile, ['id' => 'save_external_smtp', 'label' => 'Guardar SMTP externo'], $step, 1, 'failed', $errors, ['finished_at' => date('Y-m-d H:i:s')]);
        exit(1);
    }

    Settings::set('mail_mode', 'external');
    Settings::set('mail_smtp_host', $smtpHost);
    Settings::set('mail_smtp_port', (string)$smtpPort);
    Settings::set('mail_smtp_user', $smtpUser);
    Settings::set('mail_smtp_password_enc', \MuseDockPanel\Services\ReplicationService::encryptPassword($smtpPass));
    Settings::set('mail_smtp_encryption', $smtpEncryption);
    Settings::set('mail_from_address', $fromAddress);
    Settings::set('mail_from_name', $fromName);
    Settings::set('mail_enabled', '1');

    @mkdir(PANEL_ROOT . '/config', 0750, true);
    file_put_contents(PANEL_ROOT . '/config/smtp-relay.json', json_encode([
        'mode' => 'external',
        'host' => $smtpHost,
        'port' => $smtpPort,
        'username' => $smtpUser,
        'password' => $smtpPass !== '' ? '***' : '',
        'encryption' => $smtpEncryption,
        'from_address' => $fromAddress,
        'from_name' => $fromName,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod(PANEL_ROOT . '/config/smtp-relay.json', 0640);

    logLine($logFile, 'OK', "SMTP externo guardado: {$smtpHost}:{$smtpPort}");
    writeProgress($progressFile, ['id' => 'save_external_smtp', 'label' => 'Guardar SMTP externo'], 1, 1, 'completed', $errors, [
        'mail_mode' => 'external',
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
    exit(0);
}

if ($mailMode === 'satellite') {
    $satSteps = [
        1 => ['id' => 'install_packages', 'label' => 'Instalar Postfix + OpenDKIM'],
        2 => ['id' => 'configure_postfix', 'label' => 'Configurar Postfix solo envio'],
        3 => ['id' => 'configure_opendkim', 'label' => 'Configurar DKIM saliente'],
        4 => ['id' => 'start_services', 'label' => 'Iniciar servicios'],
        5 => ['id' => 'verify', 'label' => 'Verificar modo satellite'],
    ];
    $totalSteps = count($satSteps);

    $step = 1;
    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, 'running', $errors);
    run('export DEBIAN_FRONTEND=noninteractive && apt-get update -qq', $logFile, $errors, 'apt-update');
    run("printf %s\\\\n " . escapeshellarg("postfix postfix/mailname string {$hostname}") . " | debconf-set-selections", $logFile, $errors, 'postfix-preseed');
    run("printf %s\\\\n " . escapeshellarg("postfix postfix/main_mailer_type string Satellite system") . " | debconf-set-selections", $logFile, $errors, 'postfix-preseed');
    run('export DEBIAN_FRONTEND=noninteractive && apt-get install -yqq postfix opendkim opendkim-tools', $logFile, $errors, 'apt-install-satellite');

    $step = 2;
    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, 'running', $errors);
    $domain = $outboundDomain ?: $hostname;
    $satPostconf = [
        'myhostname' => $hostname,
        'mydomain' => $domain,
        'myorigin' => '$mydomain',
        'inet_interfaces' => 'loopback-only',
        'inet_protocols' => 'ipv4',
        'mydestination' => '',
        'local_transport' => 'error:local mail delivery is disabled',
        'mynetworks' => '127.0.0.0/8',
        'smtp_tls_security_level' => 'may',
        'smtp_tls_loglevel' => '1',
        'smtp_tls_CAfile' => '/etc/ssl/certs/ca-certificates.crt',
        'milter_default_action' => 'accept',
        'milter_protocol' => '6',
        'smtpd_milters' => 'unix:/run/opendkim/opendkim.sock',
        'non_smtpd_milters' => 'unix:/run/opendkim/opendkim.sock',
        'default_destination_concurrency_limit' => '5',
        'default_destination_rate_delay' => '1s',
        'maximal_queue_lifetime' => '3d',
        'bounce_queue_lifetime' => '1d',
        'message_size_limit' => '26214400',
        'header_checks' => 'regexp:/etc/postfix/header_checks',
    ];
    foreach ($satPostconf as $key => $val) {
        run('postconf -e ' . escapeshellarg("{$key} = {$val}"), $logFile, $errors, "postconf:{$key}");
    }
    file_put_contents('/etc/postfix/header_checks', "/^Received:.*127\\.0\\.0\\.1/    IGNORE\n/^X-Originating-IP:/          IGNORE\n/^X-Mailer:/                  IGNORE\n");
    logLine($logFile, 'WRITE', '/etc/postfix/header_checks');

    $step = 3;
    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, 'running', $errors);
    $dkimDomain = $domain;
    $dkimDir = "/etc/opendkim/keys/{$dkimDomain}";
    @mkdir($dkimDir, 0700, true);
    if (!is_file("{$dkimDir}/default.private")) {
        run("opendkim-genkey -D " . escapeshellarg($dkimDir) . " -d " . escapeshellarg($dkimDomain) . " -s default", $logFile, $errors, 'dkim-genkey');
    }
    run('chown -R opendkim:opendkim /etc/opendkim', $logFile, $errors, 'dkim-chown');
    $dkimConf = "Syslog yes\nUMask 007\nMode s\nCanonicalization relaxed/simple\nDomain {$dkimDomain}\nSelector default\nKeyFile {$dkimDir}/default.private\nSocket local:/run/opendkim/opendkim.sock\nOversignHeaders From\nUserID opendkim\n";
    file_put_contents('/etc/opendkim.conf', $dkimConf);
    logLine($logFile, 'WRITE', '/etc/opendkim.conf (satellite)');

    $step = 4;
    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, 'running', $errors);
    run('systemctl disable --now dovecot rspamd 2>/dev/null || true', $logFile, $errors, 'disable-unused-mail-services');
    run('systemctl enable postfix opendkim 2>&1', $logFile, $errors, 'systemd-enable-satellite');
    run('systemctl restart opendkim 2>&1', $logFile, $errors, 'restart-opendkim');
    run('systemctl restart postfix 2>&1', $logFile, $errors, 'restart-postfix');

    $step = 5;
    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, 'running', $errors);
    $serviceStatus = [];
    foreach (['postfix', 'opendkim'] as $svc) {
        exec("systemctl is-active {$svc} 2>&1", $svcOut, $svcCode);
        $serviceStatus[$svc] = $svcCode === 0 ? 'running' : 'failed';
    }
    $listenOut = [];
    exec("ss -tlnp 2>/dev/null | grep ':25 ' | grep -E '127\\.0\\.0\\.1|localhost' || true", $listenOut);
    $loopbackOnly = !empty($listenOut);

    Settings::set('mail_mode', 'satellite');
    Settings::set('mail_node_configured', '1');
    Settings::set('mail_hostname', $hostname);
    Settings::set('mail_outbound_hostname', $hostname);
    Settings::set('mail_outbound_domain', $domain);
    Settings::set('mail_enabled', '1');

    $dnsTxt = is_file("{$dkimDir}/default.txt") ? trim((string)file_get_contents("{$dkimDir}/default.txt")) : '';
    Settings::set('mail_satellite_dkim_domain', $dkimDomain);
    Settings::set('mail_satellite_dkim_txt', $dnsTxt);

    writeProgress($progressFile, $satSteps[$step], $step, $totalSteps, empty($errors) ? 'completed' : 'completed_with_errors', $errors, [
        'mail_mode' => 'satellite',
        'services' => $serviceStatus,
        'smtp_loopback_only' => $loopbackOnly,
        'dkim_domain' => $dkimDomain,
        'dkim_txt' => $dnsTxt,
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
    exit(0);
}

// ══════════════════════════════════════════════════════════════
// Step 1/10: Verify PostgreSQL connectivity
// ══════════════════════════════════════════════════════════════
$step = 1;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

// Check PostgreSQL is running
$pgOut = [];
exec("pg_isready -h {$dbHost} -p {$dbPort} 2>&1", $pgOut, $pgCode);
if ($pgCode !== 0) {
    $msg = $localMode
        ? "PostgreSQL no accesible en {$dbHost}:{$dbPort}. Verifica que el servicio esta corriendo."
        : "PostgreSQL no accesible en {$dbHost}:{$dbPort}. No se detecta replica local activa. Configurala primero desde Cluster → Nodos → Replicacion.";
    logLine($logFile, 'FATAL', $msg);
    logLine($logFile, 'FATAL', implode("\n", $pgOut));
    $errors[] = ['step' => 'verify_database', 'command' => "pg_isready -h {$dbHost} -p {$dbPort}", 'exit' => $pgCode, 'output' => $msg];
    writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'failed', $errors, [
        'log_tail' => $msg,
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
    exit(1);
}
logLine($logFile, 'OK', "PostgreSQL responde en {$dbHost}:{$dbPort}" . ($localMode ? ' (local/master)' : ' (replica)'));

// Check musedock_mail user can connect and read mail tables
$dbTestOut = [];
exec("PGPASSWORD=" . escapeshellarg($dbPass) . " psql -h {$dbHost} -p {$dbPort} -U {$dbUser} -d {$dbName} -c 'SELECT COUNT(*) FROM mail_domains' 2>&1", $dbTestOut, $dbTestCode);
if ($dbTestCode !== 0) {
    $dbErr = implode("\n", $dbTestOut);
    $msg = $localMode
        ? "No se puede conectar como {$dbUser} a {$dbName}:{$dbPort}. Verifica que el usuario musedock_mail fue creado correctamente."
        : "No se puede conectar como {$dbUser} a {$dbName}:{$dbPort}. Verifica que el usuario musedock_mail fue creado en el master y que la replica esta sincronizada.";
    logLine($logFile, 'FATAL', $msg);
    logLine($logFile, 'FATAL', $dbErr);
    $errors[] = ['step' => 'verify_database', 'command' => "psql -U {$dbUser} -d {$dbName}", 'exit' => $dbTestCode, 'output' => "{$msg}\n{$dbErr}"];
    writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'failed', $errors, [
        'log_tail' => "{$msg}\n{$dbErr}",
        'finished_at' => date('Y-m-d H:i:s'),
    ]);
    exit(1);
}
logLine($logFile, 'OK', "Conectividad DB verificada: {$dbUser}@{$dbHost}:{$dbPort}/{$dbName} — mail_domains accesible");

// ══════════════════════════════════════════════════════════════
// Step 2/10: Create vmail user
// ══════════════════════════════════════════════════════════════
$step = 2;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

exec('id vmail 2>&1', $vmailOut, $vmailExists);
if ($vmailExists !== 0) {
    logLine($logFile, 'INFO', 'vmail user does not exist, creating...');
    run("groupadd -g {$vmailGid} vmail 2>/dev/null; useradd -r -u {$vmailUid} -g {$vmailGid} -d {$mailDir} -s /usr/sbin/nologin vmail", $logFile, $errors, 'vmail-create');
} else {
    logLine($logFile, 'SKIP', 'vmail user already exists (uid=' . trim($vmailOut[0] ?? '?') . ')');
}

foreach ([$mailDir, '/var/mail/trash'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        logLine($logFile, 'INFO', "Created directory: {$dir}");
    }
}
run("chown vmail:vmail {$mailDir} /var/mail/trash", $logFile, $errors, 'vmail-chown');

// ══════════════════════════════════════════════════════════════
// Step 3/10: Install packages
// ══════════════════════════════════════════════════════════════
$step = 3;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

// Check each package individually
$allPackages = [
    'postfix', 'postfix-pgsql',
    'dovecot-core', 'dovecot-imapd', 'dovecot-pop3d', 'dovecot-lmtpd', 'dovecot-pgsql',
    'opendkim', 'opendkim-tools', 'certbot',
];

$missing = [];
$installed = [];
foreach ($allPackages as $pkg) {
    $chkOut = [];
    exec("dpkg -s {$pkg} 2>/dev/null | grep -q 'Status: install ok installed'", $chkOut, $chkCode);
    if ($chkCode !== 0) {
        $missing[] = $pkg;
    } else {
        $installed[] = $pkg;
    }
}

if (!empty($installed)) {
    logLine($logFile, 'SKIP', 'Already installed: ' . implode(', ', $installed));
}

if (!empty($missing)) {
    logLine($logFile, 'INFO', 'Packages to install: ' . implode(', ', $missing));

    run('export DEBIAN_FRONTEND=noninteractive && apt-get update -qq', $logFile, $errors, 'apt-update');

    if (in_array('postfix', $missing)) {
        run("debconf-set-selections <<< 'postfix postfix/mailname string {$hostname}'", $logFile, $errors, 'postfix-preseed');
        run("debconf-set-selections <<< \"postfix postfix/main_mailer_type string 'Internet Site'\"", $logFile, $errors, 'postfix-preseed');
    }

    $pkgList = implode(' ', $missing);
    run("export DEBIAN_FRONTEND=noninteractive && apt-get install -yqq {$pkgList}", $logFile, $errors, 'apt-install');
} else {
    logLine($logFile, 'SKIP', 'All base packages already installed');
}

// Rspamd (separate repo)
$chkOut = [];
exec("dpkg -s rspamd 2>/dev/null | grep -q 'Status: install ok installed'", $chkOut, $rspamdCode);
if ($rspamdCode !== 0) {
    logLine($logFile, 'INFO', 'Rspamd not installed, adding repo and installing...');
    writeProgress($progressFile, ['id' => 'install_rspamd', 'label' => 'Instalando Rspamd...'], $step, $totalSteps, 'running', $errors);

    if (!file_exists('/etc/apt/trusted.gpg.d/rspamd.gpg')) {
        run('curl -fsSL https://rspamd.com/apt-stable/gpg.key | gpg --dearmor -o /etc/apt/trusted.gpg.d/rspamd.gpg', $logFile, $errors, 'rspamd-gpg');
    }
    if (!file_exists('/etc/apt/sources.list.d/rspamd.list')) {
        run('echo "deb http://rspamd.com/apt-stable/ $(lsb_release -cs) main" > /etc/apt/sources.list.d/rspamd.list', $logFile, $errors, 'rspamd-repo');
        run('export DEBIAN_FRONTEND=noninteractive && apt-get update -qq', $logFile, $errors, 'rspamd-update');
    }
    run('export DEBIAN_FRONTEND=noninteractive && apt-get install -yqq rspamd', $logFile, $errors, 'rspamd-install');
} else {
    logLine($logFile, 'SKIP', 'Rspamd already installed');
}

// ══════════════════════════════════════════════════════════════
// Step 4/10: Configure Postfix
// ══════════════════════════════════════════════════════════════
$step = 4;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

$postfixDir = '/etc/postfix';

// SQL lookup files (always overwrite — source of truth)
$sqlFiles = [
    'pgsql-virtual-domains.cf' => "hosts = {$dbHost}:{$dbPort}\ndbname = {$dbName}\nuser = {$dbUser}\npassword = {$dbPass}\nquery = SELECT domain FROM mail_domains WHERE domain = '%s' AND status = 'active'\n",
    'pgsql-virtual-mailboxes.cf' => "hosts = {$dbHost}:{$dbPort}\ndbname = {$dbName}\nuser = {$dbUser}\npassword = {$dbPass}\nquery = SELECT CONCAT(md.domain, '/', ma.local_part, '/Maildir/') FROM mail_accounts ma JOIN mail_domains md ON md.id = ma.mail_domain_id WHERE ma.email = '%s' AND ma.status = 'active'\n",
    'pgsql-virtual-aliases.cf' => "hosts = {$dbHost}:{$dbPort}\ndbname = {$dbName}\nuser = {$dbUser}\npassword = {$dbPass}\nquery = SELECT destination FROM mail_aliases WHERE (source = '%s' OR (is_catchall = true AND source = CONCAT('@', split_part('%s', '@', 2)))) AND is_active = true LIMIT 1\n",
];

foreach ($sqlFiles as $filename => $content) {
    file_put_contents("{$postfixDir}/{$filename}", $content);
    logLine($logFile, 'WRITE', "{$postfixDir}/{$filename}");
}
run("chmod 640 {$postfixDir}/pgsql-*.cf && chown root:postfix {$postfixDir}/pgsql-*.cf", $logFile, $errors, 'postfix-sql-perms');

// main.cf via postconf (idempotent — postconf overwrites keys)
$postconfCmds = [
    'myhostname' => $hostname,
    'mydestination' => 'localhost',
    'mynetworks' => '127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128',
    'virtual_transport' => 'lmtp:unix:private/dovecot-lmtp',
    'virtual_mailbox_base' => $mailDir,
    'virtual_mailbox_domains' => "pgsql:{$postfixDir}/pgsql-virtual-domains.cf",
    'virtual_mailbox_maps' => "pgsql:{$postfixDir}/pgsql-virtual-mailboxes.cf",
    'virtual_alias_maps' => "pgsql:{$postfixDir}/pgsql-virtual-aliases.cf",
    'virtual_uid_maps' => "static:{$vmailUid}",
    'virtual_gid_maps' => "static:{$vmailGid}",
    'smtpd_use_tls' => 'yes',
    'smtpd_tls_auth_only' => 'yes',
    'smtpd_tls_security_level' => 'may',
    'smtpd_tls_protocols' => '!SSLv2,!SSLv3,!TLSv1,!TLSv1.1',
    'smtp_tls_security_level' => 'may',
    'smtpd_sasl_type' => 'dovecot',
    'smtpd_sasl_path' => 'private/auth',
    'smtpd_sasl_auth_enable' => 'yes',
    'smtpd_recipient_restrictions' => 'permit_sasl_authenticated,permit_mynetworks,reject_unauth_destination',
    'message_size_limit' => '26214400',
    'milter_protocol' => '6',
    'milter_default_action' => 'accept',
    'smtpd_milters' => 'unix:opendkim/opendkim.sock,inet:localhost:11332',
    'non_smtpd_milters' => 'unix:opendkim/opendkim.sock,inet:localhost:11332',
];

$postconfCount = 0;
foreach ($postconfCmds as $key => $val) {
    run("postconf -e " . escapeshellarg("{$key} = {$val}"), $logFile, $errors, "postconf:{$key}");
    $postconfCount++;
}
logLine($logFile, 'INFO', "Applied {$postconfCount} postconf directives");

// Submission port 587 in master.cf
if (file_exists('/etc/postfix/master.cf')) {
    $masterCf = file_get_contents('/etc/postfix/master.cf');
    if (!str_contains($masterCf, 'submission inet')) {
        $submission = "\nsubmission inet n       -       y       -       -       smtpd\n" .
            "  -o syslog_name=postfix/submission\n" .
            "  -o smtpd_tls_security_level=encrypt\n" .
            "  -o smtpd_sasl_auth_enable=yes\n" .
            "  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject\n" .
            "  -o milter_macro_daemon_name=ORIGINATING\n";
        file_put_contents('/etc/postfix/master.cf', $masterCf . $submission);
        logLine($logFile, 'WRITE', 'Added submission (587) to master.cf');
    } else {
        logLine($logFile, 'SKIP', 'Submission already in master.cf');
    }
}

// ══════════════════════════════════════════════════════════════
// Step 5/10: Configure Dovecot
// ══════════════════════════════════════════════════════════════
$step = 5;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

// SQL config (always overwrite)
$dovecotSql = "driver = pgsql\n" .
    "connect = host={$dbHost} port={$dbPort} dbname={$dbName} user={$dbUser} password={$dbPass}\n" .
    "default_pass_scheme = BLF-CRYPT\n\n" .
    "password_query = SELECT email AS user, password_hash AS password FROM mail_accounts WHERE email = '%u' AND status = 'active'\n\n" .
    "user_query = SELECT email AS user, {$vmailUid} AS uid, {$vmailGid} AS gid, home_dir AS home, CONCAT('*:bytes=', quota_mb * 1048576) AS quota_rule FROM mail_accounts WHERE email = '%u' AND status = 'active'\n\n" .
    "iterate_query = SELECT email AS user FROM mail_accounts WHERE status = 'active'\n";

file_put_contents('/etc/dovecot/dovecot-sql.conf', $dovecotSql);
run('chmod 640 /etc/dovecot/dovecot-sql.conf && chown root:dovecot /etc/dovecot/dovecot-sql.conf', $logFile, $errors, 'dovecot-sql-perms');
logLine($logFile, 'WRITE', '/etc/dovecot/dovecot-sql.conf (password_query, user_query, iterate_query)');

// Main config (always overwrite)
$dovecotConf = <<<DOVECONF
# MuseDock mail node configuration — managed by panel, do not edit manually

auth_mechanisms = plain login

passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf
}

userdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf
}

mail_location = maildir:~/Maildir
mail_uid = {$vmailUid}
mail_gid = {$vmailGid}
mail_privileged_group = vmail
first_valid_uid = {$vmailUid}

# Quota
mail_plugins = \$mail_plugins quota
protocol imap {
  mail_plugins = \$mail_plugins imap_quota
}

plugin {
  quota = maildir:User quota
  quota_grace = 10%%
  quota_status_success = DUNNO
  quota_status_nouser = DUNNO
  quota_status_overquota = "552 5.2.2 Mailbox is full"
}

# LMTP for Postfix delivery
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    mode = 0600
    user = postfix
    group = postfix
  }
}

# Auth socket for Postfix SASL
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
  unix_listener auth-userdb {
    mode = 0660
    user = vmail
    group = vmail
  }
}

ssl = required
DOVECONF;
file_put_contents('/etc/dovecot/conf.d/10-musedock.conf', $dovecotConf);
logLine($logFile, 'WRITE', '/etc/dovecot/conf.d/10-musedock.conf (auth, quota, lmtp, sasl)');

// ══════════════════════════════════════════════════════════════
// Step 6/10: SSL/TLS Certificates
// ══════════════════════════════════════════════════════════════
$step = 6;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

$certDir = "/etc/letsencrypt/live/{$hostname}";
$certExists = file_exists("{$certDir}/fullchain.pem") && file_exists("{$certDir}/privkey.pem");

if ($certExists) {
    logLine($logFile, 'SKIP', "SSL cert already exists at {$certDir}");
} elseif ($sslMode === 'letsencrypt') {
    logLine($logFile, 'INFO', "Requesting Let's Encrypt cert for {$hostname}...");
    run('systemctl stop postfix 2>/dev/null || true', $logFile, $errors, 'ssl-stop-postfix');
    run("certbot certonly --standalone -d {$hostname} --agree-tos --non-interactive --register-unsafely-without-email --preferred-challenges http", $logFile, $errors, 'certbot');
    run('systemctl start postfix 2>/dev/null || true', $logFile, $errors, 'ssl-start-postfix');

    if (!file_exists("{$certDir}/fullchain.pem")) {
        logLine($logFile, 'WARN', "Let's Encrypt failed, falling back to self-signed");
        $sslMode = 'selfsigned';
    } else {
        logLine($logFile, 'OK', "Let's Encrypt cert obtained");
        @mkdir('/etc/letsencrypt/renewal-hooks/post', 0755, true);
        file_put_contents('/etc/letsencrypt/renewal-hooks/post/mail-services.sh',
            "#!/bin/bash\nsystemctl reload postfix dovecot 2>/dev/null || true\n");
        chmod('/etc/letsencrypt/renewal-hooks/post/mail-services.sh', 0755);
        logLine($logFile, 'WRITE', '/etc/letsencrypt/renewal-hooks/post/mail-services.sh');
    }
}

if ($sslMode === 'selfsigned' && !$certExists) {
    logLine($logFile, 'INFO', 'Generating self-signed certificate...');
    @mkdir($certDir, 0755, true);
    run("openssl req -new -x509 -days 3650 -nodes -out {$certDir}/fullchain.pem -keyout {$certDir}/privkey.pem -subj '/CN={$hostname}'", $logFile, $errors, 'ssl-selfsigned');
}

if (file_exists("{$certDir}/fullchain.pem")) {
    run("postconf -e " . escapeshellarg("smtpd_tls_cert_file = {$certDir}/fullchain.pem"), $logFile, $errors, 'postfix-tls-cert');
    run("postconf -e " . escapeshellarg("smtpd_tls_key_file = {$certDir}/privkey.pem"), $logFile, $errors, 'postfix-tls-key');

    $sslConf = "ssl = required\nssl_cert = <{$certDir}/fullchain.pem\nssl_key = <{$certDir}/privkey.pem\nssl_min_protocol = TLSv1.2\nssl_prefer_server_ciphers = yes\n";
    file_put_contents('/etc/dovecot/conf.d/10-ssl.conf', $sslConf);
    logLine($logFile, 'WRITE', '/etc/dovecot/conf.d/10-ssl.conf');
} else {
    logLine($logFile, 'WARN', 'No SSL cert available — Dovecot/Postfix will fail TLS');
}

// ══════════════════════════════════════════════════════════════
// Step 7/10: Configure OpenDKIM
// ══════════════════════════════════════════════════════════════
$step = 7;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

if (!is_dir('/etc/opendkim/keys')) {
    @mkdir('/etc/opendkim/keys', 0700, true);
    logLine($logFile, 'INFO', 'Created /etc/opendkim/keys');
}
run('chown -R opendkim:opendkim /etc/opendkim', $logFile, $errors, 'dkim-chown');

$dkimConf = "Syslog yes\nSyslogSuccess yes\nMode sv\nCanonicalization relaxed/simple\n" .
    "Domain *\nAutoRestart yes\nAutoRestartRate 10/1M\nBackground yes\n" .
    "KeyTable /etc/opendkim/key.table\nSigningTable refile:/etc/opendkim/signing.table\n" .
    "InternalHosts /etc/opendkim/trusted.hosts\n" .
    "Socket local:/var/spool/postfix/opendkim/opendkim.sock\n" .
    "PidFile /run/opendkim/opendkim.pid\nUMask 007\nUserID opendkim\n";
file_put_contents('/etc/opendkim.conf', $dkimConf);
logLine($logFile, 'WRITE', '/etc/opendkim.conf');

if (!file_exists('/etc/opendkim/key.table')) {
    touch('/etc/opendkim/key.table');
    logLine($logFile, 'INFO', 'Created empty /etc/opendkim/key.table');
}
if (!file_exists('/etc/opendkim/signing.table')) {
    touch('/etc/opendkim/signing.table');
    logLine($logFile, 'INFO', 'Created empty /etc/opendkim/signing.table');
}
file_put_contents('/etc/opendkim/trusted.hosts', "127.0.0.1\nlocalhost\n{$hostname}\n");
logLine($logFile, 'WRITE', '/etc/opendkim/trusted.hosts');

if (!is_dir('/var/spool/postfix/opendkim')) {
    @mkdir('/var/spool/postfix/opendkim', 0750, true);
    logLine($logFile, 'INFO', 'Created /var/spool/postfix/opendkim');
}
run('chown opendkim:postfix /var/spool/postfix/opendkim', $logFile, $errors, 'dkim-socket-chown');

$groupsOut = [];
exec('id -nG postfix 2>&1', $groupsOut);
$groupsStr = $groupsOut[0] ?? '';
if (!str_contains($groupsStr, 'opendkim')) {
    run('usermod -aG opendkim postfix', $logFile, $errors, 'dkim-group');
} else {
    logLine($logFile, 'SKIP', 'postfix already in opendkim group');
}

// ══════════════════════════════════════════════════════════════
// Step 8/10: Configure Rspamd
// ══════════════════════════════════════════════════════════════
$step = 8;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

if (!is_dir('/etc/rspamd/local.d')) {
    @mkdir('/etc/rspamd/local.d', 0755, true);
}

file_put_contents('/etc/rspamd/local.d/actions.conf', "reject = 15;\nadd_header = 6;\ngreylist = 4;\n");
logLine($logFile, 'WRITE', '/etc/rspamd/local.d/actions.conf (reject=15, add_header=6, greylist=4)');

file_put_contents('/etc/rspamd/local.d/worker-proxy.inc', "milter = yes;\ntimeout = 120s;\nupstream \"local\" {\n  default = yes;\n  self_scan = yes;\n}\n");
logLine($logFile, 'WRITE', '/etc/rspamd/local.d/worker-proxy.inc (milter mode)');

// ══════════════════════════════════════════════════════════════
// Step 9/10: Start services
// ══════════════════════════════════════════════════════════════
$step = 9;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

run('systemctl enable postfix dovecot opendkim rspamd 2>&1', $logFile, $errors, 'systemd-enable');
run('systemctl restart postfix 2>&1', $logFile, $errors, 'restart-postfix');
run('systemctl restart dovecot 2>&1', $logFile, $errors, 'restart-dovecot');
run('systemctl restart opendkim 2>&1', $logFile, $errors, 'restart-opendkim');
run('systemctl restart rspamd 2>&1', $logFile, $errors, 'restart-rspamd');

// ══════════════════════════════════════════════════════════════
// Step 10/10: Verify
// ══════════════════════════════════════════════════════════════
$step = 10;
writeProgress($progressFile, $STEPS[$step], $step, $totalSteps, 'running', $errors);
logLine($logFile, 'STEP', "── {$step}/{$totalSteps}: {$STEPS[$step]['label']} ──");

// Service status
$serviceStatus = [];
foreach (['postfix', 'dovecot', 'opendkim', 'rspamd'] as $svc) {
    $svcOut = [];
    exec("systemctl is-active {$svc} 2>&1", $svcOut, $svcCode);
    $status = $svcCode === 0 ? 'running' : 'failed';
    $serviceStatus[$svc] = $status;
    logLine($logFile, $status === 'running' ? 'OK' : 'FAIL', "Service {$svc}: {$status}");
}

// Port checks
$portStatus = [];
foreach ([25 => 'smtp', 587 => 'submission', 993 => 'imaps'] as $port => $name) {
    $portOut = [];
    exec("ss -tlnp 2>/dev/null | grep ':{$port} '", $portOut);
    $listening = !empty($portOut);
    $portStatus[$name] = $listening ? 'listening' : 'not_listening';
    logLine($logFile, $listening ? 'OK' : 'FAIL', "Port {$port} ({$name}): " . ($listening ? 'listening' : 'NOT listening'));
}

// DB connectivity test
$dbOk = false;
$dbTestOut = [];
exec("PGPASSWORD=" . escapeshellarg($dbPass) . " psql -h {$dbHost} -p {$dbPort} -U {$dbUser} -d {$dbName} -c 'SELECT 1' 2>&1", $dbTestOut, $dbTestCode);
$dbOk = $dbTestCode === 0;
logLine($logFile, $dbOk ? 'OK' : 'WARN', 'Database connectivity: ' . ($dbOk ? 'OK' : 'FAILED — mail delivery will fail until DB is accessible'));

// Mark node as configured
Settings::set('mail_node_configured', '1');
Settings::set('mail_hostname', $hostname);
Settings::set('mail_ssl_mode', $sslMode);
Settings::set('mail_mode', 'full');

// ── Final status ─────────────────────────────────────────────
$elapsed = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 1);
$hasErrors = !empty($errors);
$finalStatus = $hasErrors ? 'completed_with_errors' : 'completed';

logLine($logFile, 'INFO', '═══════════════════════════════════════════════════');
logLine($logFile, 'INFO', "Setup {$finalStatus} in {$elapsed}s — " . count($errors) . ' error(s)');
if ($hasErrors) {
    foreach ($errors as $i => $err) {
        logLine($logFile, 'ERR', "Error #{$i}: [{$err['step']}] {$err['command']} → exit {$err['exit']}");
    }
}
logLine($logFile, 'INFO', '═══════════════════════════════════════════════════');

writeProgress($progressFile, $STEPS[10], 10, $totalSteps, $finalStatus, $errors, [
    'services'   => $serviceStatus,
    'ports'      => $portStatus,
    'db_ok'      => $dbOk,
    'ssl_mode'   => $sslMode,
    'elapsed_s'  => $elapsed,
    'log_file'   => $logFile,
    'finished_at' => date('Y-m-d H:i:s'),
]);
