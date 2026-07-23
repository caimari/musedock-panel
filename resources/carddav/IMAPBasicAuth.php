<?php

namespace Baikal\Core;

/**
 * MuseDock — CardDAV auth backend that validates against Dovecot via IMAP.
 *
 * Rationale (single source of truth): mailbox passwords live in Dovecot
 * ({BLF-CRYPT} bcrypt in mail_accounts). Rather than duplicate/convert those
 * hashes into Baïkal's `users.digesta1` (md5 digest — incompatible format),
 * we authenticate every CardDAV request by opening an IMAP session against the
 * LOCAL Dovecot (127.0.0.1). If IMAP accepts the credentials, the user is valid.
 *
 * Consequences handled here:
 *  - Baïkal/SabreDAV still needs a `principals` row + a `users` row for the
 *    logged-in user so DAVACL can resolve the principal and expose its address
 *    book. Since we bypass the `users` table for password checks, we
 *    AUTO-PROVISION the principal + a default address book on first successful
 *    login (idempotent). Without this, a freshly-authenticated user would have
 *    no addressbook and clients would see an empty/broken account.
 *  - We NEVER trust the username blindly: the principal we provision is keyed
 *    to the exact username IMAP authenticated, so a user can only ever reach
 *    their own address book (principals/<username>).
 *
 * This file is copied into Baïkal's Core by the CardDAV installer and selected
 * via authType = 'IMAP' in Server.php.
 */
class IMAPBasicAuth extends \Sabre\DAV\Auth\Backend\AbstractBasic {
    /** @var \PDO */
    protected $pdo;

    /** @var string */
    protected $authRealm;

    /** IMAP host used for validation (local Dovecot). */
    protected $imapHost;

    /** IMAP port (143 STARTTLS is fine locally; we force TLS). */
    protected $imapPort;

    /** @var string */
    private $currentUser;

    function __construct(\PDO $pdo, $authRealm, $imapHost = '127.0.0.1', $imapPort = 143) {
        $this->pdo = $pdo;
        $this->authRealm = $authRealm;
        $this->imapHost = $imapHost;
        $this->imapPort = (int) $imapPort;
    }

    /**
     * Validate username/password against Dovecot via IMAP.
     *
     * @param string $username full email address (e.g. hello@musedock.com)
     * @param string $password plaintext password from HTTP Basic
     * @return bool
     */
    function validateUserPass($username, $password) {
        // Reject empty/obviously malformed usernames up front. CardDAV usernames
        // are full email addresses; anything else can't be a real mailbox.
        $username = trim((string) $username);
        if ($username === '' || $password === '' || strpos($username, '@') === false) {
            return false;
        }
        if (!function_exists('imap_open')) {
            error_log('MuseDock CardDAV: php-imap not available, cannot authenticate');
            return false;
        }

        // PERFORMANCE: a CardDAV/CalDAV client fires many requests in a burst,
        // and validating each one against Dovecot (TLS handshake + Dovecot's
        // anti-bruteforce auth_failure_delay) is slow. Cache the RESULT of a
        // validation for a short TTL, keyed by a salted hash of user+password
        // (never the plaintext). A positive result is cached 30s; we do NOT
        // cache negatives (so a password change / typo retries immediately).
        $cacheKey = 'mdcarddav_' . hash('sha256', $username . "\0" . $password . "\0" . $this->authRealm);
        $ttl = 30;
        if (function_exists('apcu_fetch')) {
            $hit = @apcu_fetch($cacheKey, $ok);
            if ($ok && $hit === 1) { $this->currentUser = $username; return true; }
        } else {
            $cf = sys_get_temp_dir() . '/' . $cacheKey;
            if (@is_file($cf) && (time() - (int) @filemtime($cf)) < $ttl) {
                $this->currentUser = $username; return true;
            }
        }

        // Validate against the LOCAL Dovecot. We try connection modes in order
        // of preference and stop at the first that AUTHENTICATES (or cleanly
        // rejects the password). Traffic never leaves the machine (127.0.0.1),
        // so cert validation is disabled on every mode.
        //
        // NOTE: PHP's "/imap/tls" (STARTTLS on 143) fails "SSL negotiation
        // failed" against this Dovecot build, so we prefer implicit SSL on 993,
        // then fall back to plain 143 (notls) — both loopback-only.
        $host = $this->imapHost;
        $modes = [
            '{' . $host . ':993/imap/ssl/novalidate-cert}INBOX',   // implicit TLS, works
            '{' . $host . ':' . $this->imapPort . '/imap/notls}INBOX', // plain loopback fallback
        ];

        $authed = false;
        foreach ($modes as $mailbox) {
            $imap = @imap_open($mailbox, $username, $password, OP_HALFOPEN, 1);
            // Clear the error/alert stacks so nothing leaks into later requests.
            if (function_exists('imap_errors')) { @imap_errors(); }
            if (function_exists('imap_alerts')) { @imap_alerts(); }
            if ($imap !== false) {
                @imap_close($imap);
                if (function_exists('imap_errors')) { @imap_errors(); }
                $authed = true;
                break;
            }
            // If the connection reached Dovecot but the PASSWORD was wrong, the
            // error is AUTHENTICATIONFAILED — that's a definitive "no", don't
            // try the next mode (it would give the same answer). Only fall
            // through on transport/negotiation errors.
            $last = function_exists('imap_last_error') ? (string) @imap_last_error() : '';
            if (stripos($last, 'AUTHENTICATIONFAILED') !== false || stripos($last, 'authenticate') !== false) {
                if (function_exists('imap_errors')) { @imap_errors(); }
                return false;
            }
            if (function_exists('imap_errors')) { @imap_errors(); }
        }
        if (!$authed) {
            return false;
        }

        // Cache the positive result for the short TTL (see top of method).
        if (function_exists('apcu_store')) {
            @apcu_store($cacheKey, 1, $ttl);
        } else {
            @touch(sys_get_temp_dir() . '/' . $cacheKey);
        }

        // Auth OK — make sure the principal + default address book + calendar
        // exist so the client actually sees an account. Idempotent.
        try {
            $this->ensureProvisioned($username);
        } catch (\Throwable $e) {
            error_log('MuseDock CardDAV: ensurePrincipal failed for ' . $username . ': ' . $e->getMessage());
            // Auth still succeeded; provisioning failure shouldn't grant/deny wrongly.
            // Returning true lets a subsequent request retry provisioning.
        }

        $this->currentUser = $username;
        return true;
    }

    /**
     * Ensure principals/<username>, a `users` shadow row, a default address book
     * and a default calendar exist for this user. All writes are guarded (SELECT
     * then guarded INSERT) so concurrent first-logins can't create duplicates.
     *
     * We reuse SabreDAV's own backends for the address book / calendar so the
     * rows (including the calendars/calendarinstances split) are created exactly
     * the way sabre expects — no hand-rolled multi-table SQL to drift from.
     */
    private function ensureProvisioned($username) {
        $principalUri = 'principals/' . $username;

        // 1) principals row (DAVACL needs this to resolve the identity).
        $stmt = $this->pdo->prepare('SELECT id FROM principals WHERE uri = ?');
        $stmt->execute([$principalUri]);
        if ($stmt->fetchColumn() === false) {
            $ins = $this->pdo->prepare(
                'INSERT INTO principals (uri, email, displayname) VALUES (?, ?, ?)
                 ON CONFLICT (uri) DO NOTHING'
            );
            $ins->execute([$principalUri, $username, $username]);
        }

        // 2) users shadow row. Baïkal's admin UI + some ACL paths expect a
        //    matching `users` row. digesta1 is set to a random value we never
        //    check (auth goes through IMAP), so it can't be used to log in.
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() === false) {
            $digest = md5($username . ':' . $this->authRealm . ':' . bin2hex(random_bytes(16)));
            $ins = $this->pdo->prepare(
                'INSERT INTO users (username, digesta1) VALUES (?, ?)
                 ON CONFLICT (username) DO NOTHING'
            );
            $ins->execute([$username, $digest]);
        }

        // 3) default address book — via sabre's CardDAV PDO backend if the class
        //    is available (it is, under Baïkal), else a guarded direct insert.
        $stmt = $this->pdo->prepare('SELECT id FROM addressbooks WHERE principaluri = ? AND uri = ?');
        $stmt->execute([$principalUri, 'default']);
        if ($stmt->fetchColumn() === false) {
            if (class_exists('\Sabre\CardDAV\Backend\PDO')) {
                try {
                    $ab = new \Sabre\CardDAV\Backend\PDO($this->pdo);
                    $ab->createAddressBook($principalUri, 'default', [
                        '{DAV:}displayname' => 'Contactos',
                        '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'MuseDock',
                    ]);
                } catch (\Throwable $e) {
                    // Lost a race or minor mismatch: ignore, the row likely exists now.
                    error_log('MuseDock CardDAV: addressbook provision: ' . $e->getMessage());
                }
            } else {
                $ins = $this->pdo->prepare(
                    'INSERT INTO addressbooks (principaluri, displayname, uri, description, synctoken)
                     VALUES (?, ?, ?, ?, 1) ON CONFLICT (principaluri, uri) DO NOTHING'
                );
                $ins->execute([$principalUri, 'Contactos', 'default', 'MuseDock']);
            }
        }

        // 4) default calendar — via sabre's CalDAV PDO backend (handles the
        //    calendars + calendarinstances split correctly). Guarded by a check
        //    on calendarinstances for this principal.
        if (class_exists('\Sabre\CalDAV\Backend\PDO')) {
            $stmt = $this->pdo->prepare('SELECT id FROM calendarinstances WHERE principaluri = ? AND uri = ?');
            $stmt->execute([$principalUri, 'default']);
            if ($stmt->fetchColumn() === false) {
                try {
                    $cal = new \Sabre\CalDAV\Backend\PDO($this->pdo);
                    $cal->createCalendar($principalUri, 'default', [
                        '{DAV:}displayname' => 'Calendario',
                    ]);
                } catch (\Throwable $e) {
                    error_log('MuseDock CardDAV: calendar provision: ' . $e->getMessage());
                }
            }
        }
    }

    function getCurrentUser() {
        return $this->currentUser;
    }
}
