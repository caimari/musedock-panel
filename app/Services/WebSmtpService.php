<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * SMTP autenticado para las webs: que la aplicación (WordPress, Laravel…) mande el correo identificándose en el
 * servidor de correo del propio panel, en vez de por el sendmail local sin autenticar.
 *
 *  - Cuenta de correo: «web-<usuario>» en el dominio del propio hosting, si ese dominio es un dominio de correo del
 *    panel; si no, en el dominio de correo por defecto (ajuste web_smtp_default_domain; sin fijar, el primero activo).
 *    El servidor firma con el DKIM de ese dominio y el SPF/DMARC son los del dominio verificado.
 *  - Credenciales: fichero 0600 del usuario del hosting FUERA de la carpeta pública ({home}/.musedock-smtp.php).
 *  - WordPress: mu-plugin (wp-content/mu-plugins/musedock-smtp.php) que lee ese fichero; no necesita plugin SMTP.
 *    Si la dirección de origen de la web no es de ese dominio, la reescribe a la de la cuenta y deja la original en
 *    Reply-To (así el DKIM alinea y Gmail/Outlook no la rechazan).
 *  - Laravel: claves MAIL_* del .env (con copia previa).
 *  - Otra aplicación: se crea la cuenta y se dan los datos para configurarla.
 * Nunca se imprime ni se guarda la contraseña en el registro; solo va al fichero del hosting.
 */
final class WebSmtpService
{
    public const MU_PLUGIN = 'musedock-smtp.php';

    /** Dominio de correo con el que firma esta web. @return array{0:?array,1:string} [fila mail_domains|null, motivo] */
    public static function mailDomainFor(array $account): array
    {
        $own = MailService::getDomainByName(strtolower((string)($account['domain'] ?? '')));
        if ($own && ($own['status'] ?? 'active') === 'active') {
            return [$own, ''];
        }
        $def = strtolower(trim((string)Settings::get('web_smtp_default_domain', '')));
        if ($def !== '') {
            $d = MailService::getDomainByName($def);
            return $d ? [$d, ''] : [null, "El dominio de correo por defecto ({$def}) no existe en este panel."];
        }
        $d = Database::fetchOne("SELECT * FROM mail_domains WHERE status = 'active' ORDER BY id LIMIT 1");
        return $d ? [$d, ''] : [null, 'No hay ningún dominio de correo activo en este panel: crea uno (Correo) o fija web_smtp_default_domain.'];
    }

    /** Parte local de la cuenta de la web. Puro. */
    public static function localPart(string $username): string
    {
        $u = strtolower(preg_replace('/[^a-z0-9]/i', '', $username));
        return 'web-' . substr($u !== '' ? $u : 'site', 0, 40);
    }

    public static function smtpHost(array $domain): string
    {
        return (string)(Settings::get('mail_outbound_hostname', '') ?: Settings::get('mail_local_hostname', '') ?: ('mail.' . (string)$domain['domain']));
    }

    /** Carpeta del hosting (un nivel por encima de la pública, que es lo que ve el usuario fuera de la web). */
    public static function homeDir(array $account): string
    {
        $root = rtrim((string)($account['document_root'] ?? ''), '/');
        return $root !== '' ? dirname($root) : '';
    }

    public static function credFile(array $account): string
    {
        return self::homeDir($account) . '/.musedock-smtp.php';
    }

    /** Contraseña aleatoria (sin caracteres que compliquen un .env o un fichero PHP). */
    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Ab'), '=');
    }

    /** Qué hay en esa web: tipo de aplicación y si ya envía por SMTP. Solo lectura. */
    public static function detect(array $account): array
    {
        $root = rtrim((string)($account['document_root'] ?? ''), '/');
        $out = ['app' => 'otra', 'smtp_plugin' => null, 'laravel_smtp' => false, 'managed' => false];
        if ($root === '' || !is_dir($root)) {
            return $out;
        }
        if (is_file($root . '/wp-config.php') || is_dir($root . '/wp-content')) {
            $out['app'] = 'wordpress';
            foreach (['wp-mail-smtp', 'post-smtp', 'easy-wp-smtp', 'fluent-smtp', 'wp-smtp', 'smtp-mailer', 'wpmailsmtp', 'mailin', 'sendgrid-email-delivery-simplified', 'mailgun'] as $p) {
                if (is_dir($root . '/wp-content/plugins/' . $p)) {
                    $out['smtp_plugin'] = $p;
                    break;
                }
            }
            $out['managed'] = is_file($root . '/wp-content/mu-plugins/' . self::MU_PLUGIN) && is_file(self::credFile($account));
        } elseif (is_file($root . '/artisan') || is_file(dirname($root) . '/artisan')) {
            $out['app'] = 'laravel';
            foreach ([$root . '/.env', dirname($root) . '/.env'] as $env) {
                if (is_file($env)) {
                    $t = (string)@file_get_contents($env);
                    $out['laravel_smtp'] = (bool)preg_match('/^MAIL_MAILER\s*=\s*smtp\b/mi', $t) && !preg_match('/^MAIL_HOST\s*=\s*(127\.0\.0\.1|localhost)?\s*$/mi', $t);
                    $out['managed'] = str_contains($t, '# musedock-smtp');
                    break;
                }
            }
        }
        return $out;
    }

    /** Código del mu-plugin de WordPress. Puro. */
    public static function muPluginSource(string $credFile): string
    {
        $cred = var_export($credFile, true);
        return <<<PHP
<?php
/**
 * Plugin Name: MuseDock SMTP
 * Description: Envía el correo de WordPress por el SMTP autenticado del servidor (credenciales en un fichero fuera de la web). Lo gestiona el panel.
 */
add_action('phpmailer_init', function (\$m) {
    \$c = @include {$cred};
    if (!is_array(\$c) || empty(\$c['host']) || empty(\$c['user'])) {
        return;     // sin credenciales: WordPress sigue como estaba
    }
    \$m->isSMTP();
    \$m->Host = \$c['host'];
    \$m->Port = (int)(\$c['port'] ?? 587);
    \$m->SMTPAuth = true;
    \$m->Username = \$c['user'];
    \$m->Password = \$c['pass'];
    \$m->SMTPSecure = 'tls';
    \$m->SMTPAutoTLS = true;
    \$m->Timeout = 15;
    \$m->Sender = \$c['user'];
    // La dirección de origen debe ser de un dominio que firme este servidor: si no, se usa la de la cuenta y la original va en Reply-To.
    \$domain = strtolower(substr((string)strrchr(\$c['user'], '@'), 1));
    \$from = (string)\$m->From;
    if (\$from !== '' && strtolower(substr((string)strrchr(\$from, '@'), 1)) !== \$domain) {
        if (empty(\$m->getReplyToAddresses())) {
            \$m->addReplyTo(\$from, (string)\$m->FromName);
        }
        \$m->setFrom(\$c['user'], (string)\$m->FromName, false);
    }
});

PHP;
    }

    /** Líneas MAIL_* de un .env de Laravel con SMTP. Puro. */
    public static function applyEnv(string $env, array $c): string
    {
        $set = ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => $c['host'], 'MAIL_PORT' => (string)($c['port'] ?? 587),
            'MAIL_USERNAME' => $c['user'], 'MAIL_PASSWORD' => $c['pass'], 'MAIL_ENCRYPTION' => 'tls'];
        foreach ($set as $k => $v) {
            $line = "{$k}=\"{$v}\"";
            if (preg_match('/^' . preg_quote($k, '/') . '\s*=.*$/m', $env)) {
                $env = preg_replace('/^' . preg_quote($k, '/') . '\s*=.*$/m', addcslashes($line, '\\$'), $env, 1);
            } else {
                $env = rtrim($env) . "\n{$line}\n";
            }
        }
        return str_contains($env, '# musedock-smtp') ? $env : rtrim($env) . "\n# musedock-smtp: MAIL_* gestionadas por el panel (copia previa: .env.bak-musedock-*)\n";
    }

    /**
     * Prepara (apply=false) o aplica (apply=true) el SMTP autenticado de una web.
     * @return array{ok:bool,steps:list<string>,error?:string,account?:string,host?:string}
     */
    public static function provision(array $account, bool $apply, string $thenMode = 'capture'): array
    {
        $steps = [];
        [$dom, $why] = self::mailDomainFor($account);
        if (!$dom) {
            return ['ok' => false, 'steps' => $steps, 'error' => $why];
        }
        $home = self::homeDir($account);
        if ($home === '' || !is_dir($home)) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se encuentra la carpeta del hosting.'];
        }
        $local = self::localPart((string)$account['username']);
        $email = $local . '@' . $dom['domain'];
        $host = self::smtpHost($dom);
        $det = self::detect($account);
        $existing = MailService::getAccountByEmail($email);
        $credFile = self::credFile($account);
        $steps[] = ($existing ? 'La cuenta ya existe: ' : 'Se crea la cuenta de correo: ') . $email . " (SMTP {$host}:587 con STARTTLS)";
        $steps[] = "Credenciales en {$credFile} (0600, usuario {$account['username']}), fuera de la carpeta pública";
        $steps[] = match ($det['app']) {
            'wordpress' => 'WordPress: se instala el mu-plugin wp-content/mu-plugins/' . self::MU_PLUGIN . ($det['smtp_plugin'] ? " (ojo: ya hay un plugin SMTP, {$det['smtp_plugin']}; se usaría el mu-plugin salvo que ese plugin lo reconfigure)" : ''),
            'laravel' => 'Laravel: se ponen las claves MAIL_* del .env (copia previa .env.bak-musedock-FECHA)',
            default => 'Aplicación no reconocida: se crea la cuenta y las credenciales; hay que configurar la app a mano con host, puerto 587, STARTTLS, usuario y contraseña (en el fichero de credenciales)',
        };
        if (in_array($thenMode, ['capture', 'block'], true)) {
            $steps[] = "Después el envío local sin autenticar de esta web pasa a «{$thenMode}» (queda registrado)";
        }
        $base = ['ok' => true, 'steps' => $steps, 'account' => $email, 'host' => $host];
        if (!$apply) {
            return $base;
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'Hay que ejecutarlo como root.'];
        }
        $pw = null;
        if (is_file($credFile)) {
            $c = @include $credFile;
            $pw = is_array($c) && ($c['user'] ?? '') === $email ? (string)($c['pass'] ?? '') : null;
        }
        try {
            if ($existing && $pw === null) {
                $pw = self::generatePassword();
                MailService::updateAccount((int)$existing['id'], ['password' => $pw]);   // también a las réplicas de correo
            } elseif (!$existing) {
                $pw = $pw ?: self::generatePassword();
                MailService::createAccount((int)$dom['id'], $local, $pw, ['display_name' => 'Correo de la web ' . $account['domain'], 'quota_mb' => 100]);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo crear la cuenta de correo: ' . $e->getMessage()];
        }
        $cred = ['host' => $host, 'port' => 587, 'user' => $email, 'pass' => (string)$pw];
        $php = "<?php\n// Credenciales SMTP de la web (las gestiona el panel). No las pongas en la carpeta pública.\nreturn " . var_export($cred, true) . ";\n";
        $tmp = $credFile . '.new';
        if (@file_put_contents($tmp, $php) === false) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'No se pudo escribir el fichero de credenciales.'];
        }
        @chown($tmp, (string)$account['username']);
        @chgrp($tmp, (string)$account['username']);
        @chmod($tmp, 0600);
        @rename($tmp, $credFile);

        $root = rtrim((string)$account['document_root'], '/');
        if ($det['app'] === 'wordpress') {
            $dir = $root . '/wp-content/mu-plugins';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                @chown($dir, (string)$account['username']);
            }
            $file = $dir . '/' . self::MU_PLUGIN;
            @file_put_contents($file, self::muPluginSource($credFile));
            @chown($file, (string)$account['username']);
            @chmod($file, 0644);
        } elseif ($det['app'] === 'laravel') {
            $env = is_file($root . '/.env') ? $root . '/.env' : (is_file(dirname($root) . '/.env') ? dirname($root) . '/.env' : '');
            if ($env !== '') {
                @copy($env, $env . '.bak-musedock-' . date('YmdHis'));
                @file_put_contents($env, self::applyEnv((string)file_get_contents($env), $cred));
            }
        }
        if (in_array($thenMode, ['capture', 'block'], true)) {
            $r = MailCaptureService::setMode($account, $thenMode);
            if (!$r['ok']) {
                $base['steps'][] = 'Aviso: no se pudo cambiar el modo del envío local: ' . ($r['error'] ?? '');
            }
        }
        try {
            LogService::log('account.web_smtp', (string)$account['domain'], "SMTP autenticado de la web: cuenta {$email}");
        } catch (\Throwable) {
        }
        return $base;
    }

    /** Quita el mu-plugin y el fichero de credenciales (la cuenta de correo se deja). */
    public static function remove(array $account): array
    {
        $root = rtrim((string)$account['document_root'], '/');
        $n = 0;
        foreach ([$root . '/wp-content/mu-plugins/' . self::MU_PLUGIN, self::credFile($account)] as $f) {
            if (is_file($f) && @unlink($f)) {
                $n++;
            }
        }
        return ['ok' => true, 'removed' => $n, 'note' => 'La cuenta de correo no se borra; en Laravel, restaura el .env.bak-musedock-* si hace falta.'];
    }
}
