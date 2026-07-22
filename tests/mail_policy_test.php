#!/usr/bin/env php
<?php
/**
 * MuseDock Panel — Mail send-policy tests (standalone, no PHPUnit).
 *
 * READ-ONLY / logic-only: never reloads Postfix/Rspamd/fail2ban, never writes to
 * /etc. Exercises the policy generation logic and guards.
 *
 * Usage: php tests/mail_policy_test.php
 */
define('PANEL_ROOT', dirname(__DIR__));
spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($file)) require $file;
});
\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');

use MuseDockPanel\Services\MailPolicyService as P;

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = '') { global $pass, $fail;
    if ($c) { $pass++; echo "  \033[0;32m✓\033[0m {$n}\n"; }
    else    { $fail++; echo "  \033[0;31m✗\033[0m {$n}" . ($d ? " — {$d}" : '') . "\n"; } }
function section(string $t) { echo "\n\033[1m{$t}\033[0m\n"; }

section('1. Estado y switches por defecto');
$s = P::status();
ok('status devuelve las claves esperadas',
    isset($s['whitelist_enabled'], $s['ratelimit_enabled'], $s['fail2ban_enabled'], $s['modes']));
ok('modos incluye normal/webmail_only/readonly',
    array_key_exists('normal', $s['modes']) && array_key_exists('webmail_only', $s['modes']) && array_key_exists('readonly', $s['modes']));

section('2. La query del sender-map refleja las capas');
// installSenderPolicy en dry-esque: no podemos escribir /etc, pero verificamos que
// el código construye la query correcta leyendo el fichero fuente.
$src = file_get_contents(PANEL_ROOT . '/app/Services/MailPolicyService.php');
ok('solo send_mode=normal puede enviar', str_contains($src, "ma.send_mode = 'normal'"));
ok('exige can_send=true', str_contains($src, 'ma.can_send = true'));
ok('exige status active (mismo gate que auth)', str_contains($src, "ma.status = 'active'"));
ok('la whitelist condiciona send_allowed', str_contains($src, 'md.send_allowed = true'));
ok('usa reject_authenticated_sender_login_mismatch', str_contains($src, 'reject_authenticated_sender_login_mismatch'));
ok('permite mynetworks (webmail local sigue enviando)', str_contains($src, 'permit_mynetworks'));

section('3. Rate limit');
ok('el ratelimit se aplica solo si está habilitado', str_contains($src, 'if (!self::rateLimitEnabled())'));
ok('usa el módulo ratelimit de Rspamd en local.d', str_contains($src, "/etc/rspamd/local.d/ratelimit.conf"));
ok('keyed por usuario (por buzón)', str_contains($src, "selector = 'user'"));

section('4. fail2ban');
ok('jails postfix-sasl y dovecot', str_contains($src, '[postfix-sasl]') && str_contains($src, '[dovecot]'));
ok('se puede desactivar (borra el jail)', str_contains($src, 'unlink(self::FAIL2BAN_JAIL)'));

section('5. Modos válidos / normalización');
$rc = new ReflectionClass('MuseDockPanel\\Services\\MailPolicyService');
$m = $rc->getMethod('normalizeMode'); $m->setAccessible(true);
ok('normaliza valor inválido a normal', $m->invoke(null, 'hacker') === 'normal');
ok('acepta webmail_only', $m->invoke(null, 'webmail_only') === 'webmail_only');
ok('acepta readonly', $m->invoke(null, 'readonly') === 'readonly');

section('6. Seguridad: nunca deshabilita un dominio por su cuenta');
ok('solo informa, no auto-suspende (comentario de diseño)',
    str_contains(file_get_contents(PANEL_ROOT . '/app/Services/CertMonitorService.php'), 'never disables'));

section('7. Migración es aditiva e idempotente');
$mig = file_get_contents(PANEL_ROOT . '/database/migrations/2026_07_18_000001_create_mail_send_policies.php');
ok('usa ADD COLUMN IF NOT EXISTS', substr_count($mig, 'ADD COLUMN IF NOT EXISTS') >= 6);
ok('settings con ON CONFLICT DO NOTHING', str_contains($mig, 'ON CONFLICT (key) DO NOTHING'));

section('8. Propagación de políticas al slave (mismas protecciones en el cluster)');
$mc = file_get_contents(PANEL_ROOT . '/app/Controllers/MailController.php');
$ps = file_get_contents(PANEL_ROOT . '/app/Services/MailPolicyService.php');
$apic = file_get_contents(PANEL_ROOT . '/app/Controllers/ClusterApiController.php');
ok('el toggle de política se propaga a los nodos de mail',
    str_contains($mc, 'function propagatePolicyToNodes') && str_contains($mc, "'mail_apply_policy'"));
ok('el rate por defecto también se propaga', str_contains($mc, "'mail_set_rate'"));
ok('el slave aplica la política recibida (nodeApplyPolicy)',
    str_contains($ps, 'function nodeApplyPolicy') && str_contains($ps, 'applyFail2ban'));
ok('el slave aplica el rate recibido (nodeSetRate)', str_contains($ps, 'function nodeSetRate'));
ok('acciones cluster registradas', str_contains($apic, 'mail_apply_policy') && str_contains($apic, 'mail_set_rate'));
ok('se propaga vía enqueue (reintenta si el nodo está caído)',
    str_contains($mc, "ClusterService::enqueue((int)\$node['id'], 'mail_apply_policy'"));

echo "\n\033[1m─────────────────────────────────────────\033[0m\n";
echo "  \033[0;32m{$pass} passed\033[0m" . ($fail ? ", \033[0;31m{$fail} failed\033[0m" : '') . "\n\n";
exit($fail > 0 ? 1 : 0);
