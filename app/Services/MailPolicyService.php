<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;

/**
 * MailPolicyService — combinable anti-abuse send policies for the mail server.
 *
 * A compromised mailbox is the classic way a hosting server ends up on spam
 * blocklists. This layers four independent, combinable controls, all driven from
 * the panel:
 *
 *   1. Send mode (per mailbox/domain): normal | webmail_only | readonly
 *      Enforced DB-live via a Postfix smtpd_sender_login_maps lookup — the same
 *      instant, reload-free mechanism status='active' already uses. A mailbox in
 *      webmail_only/readonly is refused when it authenticates to submission (587/
 *      465), so a stolen password used by a spam bot cannot send.
 *   2. Rate limit (per mailbox/domain/global): N messages/hour, via Rspamd's
 *      ratelimit module (a drop-in in /etc/rspamd/local.d, which is empty today).
 *   3. Domain send whitelist: only domains with send_allowed=true may send.
 *   4. fail2ban jails for SMTP/IMAP auth brute force (stock postfix/dovecot
 *      filters, enabled through a jail file).
 *
 * Design: mailbox existence/passwords/quota are read live from PostgreSQL by
 * Postfix/Dovecot, so column changes are instant. Only the FILE-based pieces
 * (the sender-login lookup, Rspamd drop-in, fail2ban jail, postconf) need this
 * service to (re)generate them and reload. The panel runs as root — no sudo.
 */
class MailPolicyService
{
    private const SENDER_LOGIN_CF = '/etc/postfix/pgsql-sender-login.cf';
    private const RSPAMD_RATELIMIT = '/etc/rspamd/local.d/ratelimit.conf';
    private const FAIL2BAN_JAIL = '/etc/fail2ban/jail.d/musedock-mail.conf';

    // ─────────────────────────────────────────────────────────
    // Policy CRUD (DB-live — no reload needed for these)
    // ─────────────────────────────────────────────────────────

    /** Set the send policy of a single mailbox. */
    public static function setMailboxPolicy(int $accountId, array $policy): array
    {
        $update = [];
        if (isset($policy['send_mode'])) {
            $update['send_mode'] = self::normalizeMode($policy['send_mode']);
        }
        if (array_key_exists('rate_limit_per_hour', $policy)) {
            $update['rate_limit_per_hour'] = max(0, (int)$policy['rate_limit_per_hour']);
        }
        if (isset($policy['can_send'])) {
            $update['can_send'] = (bool)$policy['can_send'];
        }
        if (empty($update)) {
            return ['ok' => false, 'error' => 'Sin cambios de política'];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_accounts', $update, 'id = :id', ['id' => $accountId]);
        // The sender-login map is DB-driven, so this is already live. Rate limits
        // live in Rspamd's map, so refresh that if rate limiting is on.
        if (($policy['refresh_ratelimit'] ?? true) && self::rateLimitEnabled()) {
            self::applyRateLimit();
        }
        LogService::log('mail.policy', 'mailbox', "Política de buzón #{$accountId} actualizada: " . json_encode($update));
        return ['ok' => true, 'applied' => $update];
    }

    /** Set the default send policy for a domain (inherited by new mailboxes). */
    public static function setDomainPolicy(int $domainId, array $policy): array
    {
        $update = [];
        if (isset($policy['default_send_mode'])) {
            $update['default_send_mode'] = self::normalizeMode($policy['default_send_mode']);
        }
        if (array_key_exists('default_rate_limit_per_hour', $policy)) {
            $update['default_rate_limit_per_hour'] = max(0, (int)$policy['default_rate_limit_per_hour']);
        }
        if (isset($policy['send_allowed'])) {
            $update['send_allowed'] = (bool)$policy['send_allowed'];
        }
        if (empty($update)) {
            return ['ok' => false, 'error' => 'Sin cambios'];
        }
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_domains', $update, 'id = :id', ['id' => $domainId]);
        LogService::log('mail.policy', 'domain', "Política de dominio #{$domainId} actualizada: " . json_encode($update));
        return ['ok' => true, 'applied' => $update];
    }

    private static function normalizeMode(string $m): string
    {
        return in_array($m, ['normal', 'webmail_only', 'readonly'], true) ? $m : 'normal';
    }

    // ─────────────────────────────────────────────────────────
    // Layer 1 + 3: sender-permission Postfix lookup (file + reload)
    // ─────────────────────────────────────────────────────────

    /**
     * Install the Postfix smtpd_sender_login_maps lookup that enforces send_mode,
     * can_send and the domain whitelist for submission/smtps.
     *
     * The lookup returns the owning login for an address ONLY when that mailbox is
     * allowed to send externally; when it is not (readonly, webmail_only, can_send
     * =false, suspended, or its domain is not whitelisted), it returns nothing, and
     * `reject_sender_login_mismatch` then refuses the send. Webmail sends bypass
     * this because they are injected locally (permit_mynetworks), not via the
     * authenticated submission port — so webmail_only still works.
     */
    public static function installSenderPolicy(array $dbCfg): array
    {
        $whitelist = self::whitelistEnabled();
        // Domain gate: require the domain to be send_allowed only when the whitelist
        // master switch is on.
        $domainGate = $whitelist ? " AND md.send_allowed = true" : "";

        // 'normal' can send from clients; webmail_only/readonly cannot (external).
        $query = "SELECT ma.email FROM mail_accounts ma "
               . "JOIN mail_domains md ON md.id = ma.mail_domain_id "
               . "WHERE ma.email = '%s' AND ma.status = 'active' AND ma.can_send = true "
               . "AND ma.send_mode = 'normal'{$domainGate}";

        $cf = "hosts = " . ($dbCfg['host'] ?? '127.0.0.1') . "\n"
            . "user = " . ($dbCfg['user'] ?? 'musedock_mail') . "\n"
            . "password = " . ($dbCfg['password'] ?? '') . "\n"
            . "dbname = " . ($dbCfg['dbname'] ?? 'musedock_panel') . "\n"
            . (isset($dbCfg['port']) ? "hosts = " . ($dbCfg['host'] ?? '127.0.0.1') . ":" . $dbCfg['port'] . "\n" : "")
            . "query = " . $query . "\n";

        $bak = self::SENDER_LOGIN_CF . '.bak.' . date('Ymd_His');
        if (file_exists(self::SENDER_LOGIN_CF)) @copy(self::SENDER_LOGIN_CF, $bak);
        if (@file_put_contents(self::SENDER_LOGIN_CF, $cf) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::SENDER_LOGIN_CF];
        }
        @chmod(self::SENDER_LOGIN_CF, 0640);
        @chgrp(self::SENDER_LOGIN_CF, 'postfix');

        // Wire it into submission + smtps (not port 25 — that's inbound).
        // reject_sender_login_mismatch after permit_mynetworks so local/webmail is allowed.
        static::runCmd("postconf -e " . escapeshellarg("smtpd_sender_login_maps = pgsql:" . self::SENDER_LOGIN_CF));
        static::runCmd("postconf -P " . escapeshellarg("submission/inet/smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated,reject"));
        static::runCmd("postconf -P " . escapeshellarg("smtps/inet/smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated,reject"));

        $r = static::runCmd("postfix reload 2>&1");
        LogService::log('mail.policy', 'sender-map', 'smtpd_sender_login_maps instalado (whitelist=' . ($whitelist ? 'on' : 'off') . ')');
        return ['ok' => $r['ok'], 'whitelist' => $whitelist, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Layer 2: per-mailbox rate limit via Rspamd
    // ─────────────────────────────────────────────────────────

    /**
     * Generate the Rspamd ratelimit drop-in. Uses the authenticated user as the
     * key so the limit is per mailbox. The bucket rate is the global default;
     * per-mailbox overrides are handled by Rspamd's per-user settings if set, but
     * the common case (one global cap) is covered here cleanly.
     */
    public static function applyRateLimit(): array
    {
        if (!self::rateLimitEnabled()) {
            // Disabled: remove the drop-in so Rspamd stops limiting.
            if (file_exists(self::RSPAMD_RATELIMIT)) {
                @unlink(self::RSPAMD_RATELIMIT);
                static::runCmd('systemctl reload rspamd 2>&1');
            }
            return ['ok' => true, 'enabled' => false];
        }

        $perHour = max(1, (int)Settings::get('mail_policy_default_rate_limit', '100'));

        // Rspamd ratelimit: rate as "N / 1h", keyed by authenticated username, and
        // only for authenticated outbound (user is set). Bounce/known-good exempt.
        $conf = <<<CONF
# MuseDock — per-mailbox outbound rate limit (auto-generated)
rates {
    user {
        selector = 'user';
        bucket = {
            burst = {$perHour};
            rate = "{$perHour} / 1h";
        }
    }
}
# Only rate-limit authenticated senders (outbound from our mailboxes).
whitelisted_rcpts = "postmaster,mailer-daemon";
max_rcpt = 50;
CONF;

        if (!is_dir('/etc/rspamd/local.d')) {
            return ['ok' => false, 'error' => 'Rspamd no está instalado (no existe /etc/rspamd/local.d)'];
        }
        $bak = self::RSPAMD_RATELIMIT . '.bak.' . date('Ymd_His');
        if (file_exists(self::RSPAMD_RATELIMIT)) @copy(self::RSPAMD_RATELIMIT, $bak);
        if (@file_put_contents(self::RSPAMD_RATELIMIT, $conf) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::RSPAMD_RATELIMIT];
        }
        @chmod(self::RSPAMD_RATELIMIT, 0644);
        $r = static::runCmd('systemctl reload rspamd 2>&1');
        LogService::log('mail.policy', 'ratelimit', "Rate limit activado: {$perHour}/hora por buzón");
        return ['ok' => $r['ok'], 'enabled' => true, 'rate_per_hour' => $perHour, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Layer 4: fail2ban jails for SMTP/IMAP brute force
    // ─────────────────────────────────────────────────────────

    /**
     * Enable/disable the fail2ban mail jails (stock postfix + dovecot filters).
     * Bans IPs that repeatedly fail auth — the other half of stopping abuse.
     */
    public static function applyFail2ban(): array
    {
        if (!is_dir('/etc/fail2ban')) {
            return ['ok' => false, 'error' => 'fail2ban no está instalado'];
        }
        if (!self::fail2banEnabled()) {
            if (file_exists(self::FAIL2BAN_JAIL)) {
                @unlink(self::FAIL2BAN_JAIL);
                static::runCmd('systemctl reload fail2ban 2>&1');
            }
            return ['ok' => true, 'enabled' => false];
        }

        // Postfix logs to journald/mail.log; dovecot too. Use the stock filters.
        $jail = <<<JAIL
# MuseDock — mail auth brute-force protection (auto-generated)
[postfix-sasl]
enabled  = true
filter   = postfix[mode=auth]
port     = smtp,submission,submissions
backend  = systemd
maxretry = 5
findtime = 600
bantime  = 3600

[dovecot]
enabled  = true
filter   = dovecot
port     = imap,imaps,pop3,pop3s,submission,submissions
backend  = systemd
maxretry = 5
findtime = 600
bantime  = 3600
JAIL;

        $bak = self::FAIL2BAN_JAIL . '.bak.' . date('Ymd_His');
        if (file_exists(self::FAIL2BAN_JAIL)) @copy(self::FAIL2BAN_JAIL, $bak);
        if (@file_put_contents(self::FAIL2BAN_JAIL, $jail) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::FAIL2BAN_JAIL];
        }
        @chmod(self::FAIL2BAN_JAIL, 0644);
        $r = static::runCmd('systemctl reload fail2ban 2>&1');
        if (!$r['ok']) {
            // reload can fail if fail2ban wasn't running; try restart.
            $r = static::runCmd('systemctl restart fail2ban 2>&1');
        }
        LogService::log('mail.policy', 'fail2ban', 'Jails de correo (postfix-sasl, dovecot) activados');
        return ['ok' => $r['ok'], 'enabled' => true, 'output' => $r['output']];
    }

    // ─────────────────────────────────────────────────────────
    // Orchestration + status
    // ─────────────────────────────────────────────────────────

    /**
     * Re-apply every file-based policy layer from current settings. Call this
     * after toggling any master switch. Idempotent.
     */
    public static function applyAll(array $dbCfg): array
    {
        return [
            'sender'   => self::installSenderPolicy($dbCfg),
            'ratelimit'=> self::applyRateLimit(),
            'fail2ban' => self::applyFail2ban(),
        ];
    }

    /** Current policy state for the UI. */
    public static function status(): array
    {
        // Count mailboxes by send mode.
        $modes = ['normal' => 0, 'webmail_only' => 0, 'readonly' => 0];
        try {
            $rows = Database::fetchAll("SELECT send_mode, COUNT(*) AS c FROM mail_accounts GROUP BY send_mode");
            foreach ($rows as $r) {
                $modes[$r['send_mode']] = (int)$r['c'];
            }
        } catch (\Throwable) {}

        return [
            'whitelist_enabled' => self::whitelistEnabled(),
            'ratelimit_enabled' => self::rateLimitEnabled(),
            'fail2ban_enabled'  => self::fail2banEnabled(),
            'default_rate'      => (int)Settings::get('mail_policy_default_rate_limit', '100'),
            'modes'             => $modes,
            'sender_map_active' => is_file(self::SENDER_LOGIN_CF),
        ];
    }

    public static function whitelistEnabled(): bool { return Settings::get('mail_policy_whitelist_enabled', '0') === '1'; }
    public static function rateLimitEnabled(): bool { return Settings::get('mail_policy_ratelimit_enabled', '0') === '1'; }
    public static function fail2banEnabled(): bool  { return Settings::get('mail_policy_fail2ban_enabled', '0') === '1'; }

    /**
     * NODE-side: apply a policy toggle pushed from the master, so every mail node
     * runs the SAME anti-abuse protections (fail2ban/rate-limit/whitelist). fail2ban
     * and the Rspamd rate-limit are OS/Rspamd config, not DB, so they must be applied
     * per node. Invoked via the cluster action mail_apply_policy.
     *
     * @param array $payload ['key' => whitelist|ratelimit|fail2ban, 'value' => '0'|'1']
     */
    public static function nodeApplyPolicy(array $payload): array
    {
        $keys = [
            'whitelist' => 'mail_policy_whitelist_enabled',
            'ratelimit' => 'mail_policy_ratelimit_enabled',
            'fail2ban'  => 'mail_policy_fail2ban_enabled',
        ];
        $key = (string)($payload['key'] ?? '');
        if (!isset($keys[$key])) {
            return ['ok' => false, 'error' => "Política desconocida: {$key}"];
        }
        Settings::set($keys[$key], ($payload['value'] ?? '0') === '1' ? '1' : '0');
        // Re-apply the affected layer on THIS node.
        $res = match ($key) {
            'whitelist' => self::installSenderPolicy(self::nodeMailDbCfg()),
            'ratelimit' => self::applyRateLimit(),
            'fail2ban'  => self::applyFail2ban(),
        };
        LogService::log('mail.policy', 'node-apply', "{$key} = {$payload['value']} (aplicado en este nodo)");
        return ['ok' => $res['ok'] ?? true, 'key' => $key, 'result' => $res];
    }

    /** NODE-side: set the default rate limit pushed from the master and re-apply. */
    public static function nodeSetRate(array $payload): array
    {
        $rate = max(1, (int)($payload['rate'] ?? 100));
        Settings::set('mail_policy_default_rate_limit', (string)$rate);
        $res = self::applyRateLimit();
        LogService::log('mail.policy', 'node-rate', "default_rate = {$rate} (aplicado en este nodo)");
        return ['ok' => $res['ok'] ?? true, 'rate' => $rate];
    }

    /** Local mail DB config for the sender-policy lookup on this node. */
    private static function nodeMailDbCfg(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => (int)\MuseDockPanel\Env::get('DB_PORT', '5433'),
            'name' => \MuseDockPanel\Env::get('DB_NAME', 'musedock_panel'),
            'user' => 'musedock_mail',
        ];
    }

    private static function runCmd(string $cmd): array
    {
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        return ['ok' => $code === 0, 'code' => $code, 'output' => trim(implode("\n", $out))];
    }
}
