<?php
/**
 * Migration: mail send policies (anti-abuse).
 *
 * Adds combinable send-policy controls to mailboxes and domains so a compromised
 * account can't be used to blast spam:
 *   - send_mode: how a mailbox may send (normal | webmail_only | readonly)
 *   - rate_limit_per_hour: max messages/hour per mailbox (0 = domain/global default)
 *   - can_send: hard on/off switch, enforced DB-live like status='active' is today
 *
 * Domains carry DEFAULTS that new mailboxes inherit, plus a global fallback in
 * panel_settings. A domain-level send whitelist decides which domains may send at
 * all. All additive and idempotent; existing installs (Nitro) untouched.
 *
 * send_mode meanings:
 *   normal        → can send via 587/465 (IMAP/SMTP clients) and webmail
 *   webmail_only  → can ONLY send from the panel webmail; external SMTP submission
 *                   is refused (blocks a stolen password used by a spam bot)
 *   readonly      → cannot send at all; receives and reads only (e.g. info@ inboxes)
 */
return function (PDO $pdo): void {
    // ── Per-mailbox policy ──────────────────────────────────────
    $mailboxCols = [
        "ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS send_mode VARCHAR(20) DEFAULT 'normal'",
        "ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS rate_limit_per_hour INTEGER DEFAULT 0",
        "ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS can_send BOOLEAN DEFAULT true",
    ];
    foreach ($mailboxCols as $sql) {
        $pdo->exec($sql);
    }

    // ── Per-domain defaults (inherited by new mailboxes) ────────
    $domainCols = [
        "ALTER TABLE mail_domains ADD COLUMN IF NOT EXISTS default_send_mode VARCHAR(20) DEFAULT 'normal'",
        "ALTER TABLE mail_domains ADD COLUMN IF NOT EXISTS default_rate_limit_per_hour INTEGER DEFAULT 0",
        // Whitelist gate: may any mailbox of this domain send at all?
        "ALTER TABLE mail_domains ADD COLUMN IF NOT EXISTS send_allowed BOOLEAN DEFAULT true",
    ];
    foreach ($domainCols as $sql) {
        $pdo->exec($sql);
    }

    // ── Global defaults / master switches ───────────────────────
    $defaults = [
        // Fallback rate limit when neither mailbox nor domain sets one.
        'mail_policy_default_rate_limit' => '100',
        // Master switch for the domain send-whitelist. When '1', only domains with
        // send_allowed=true may send; when '0', the whitelist is ignored (all send).
        'mail_policy_whitelist_enabled'  => '0',
        // Enable the fail2ban mail jails (SMTP/IMAP brute force).
        'mail_policy_fail2ban_enabled'   => '0',
        // Enable per-mailbox rate limiting via Rspamd.
        'mail_policy_ratelimit_enabled'  => '0',
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO panel_settings (key, value, updated_at) VALUES (:key, :value, NOW()) ON CONFLICT (key) DO NOTHING'
    );
    foreach ($defaults as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    // Helpful index for the sender-permission lookup Postfix will run.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mail_accounts_send ON mail_accounts(email, can_send, send_mode)");
};
