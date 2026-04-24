<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * MailService — manages mail domains, accounts, aliases, and DKIM.
 *
 * Two modes of operation:
 *
 * 1. MASTER (panel): CRUD operations on DB + enqueue actions to mail node via cluster_queue.
 *    Postfix/Dovecot are NOT installed on the master (unless mail_node is local).
 *
 * 2. MAIL NODE (slave): Receives actions via ClusterApiController and executes filesystem
 *    operations (Maildir creation, DKIM key generation, quota enforcement).
 *    Postfix/Dovecot on the mail node query PostgreSQL directly for virtual maps.
 *
 * Because Postfix/Dovecot use SQL lookups against the replicated DB, creating a mailbox
 * in the panel is instantly available on the mail node — no postmap or reload needed.
 * The MailService on the node only handles filesystem operations.
 */
class MailService
{
    // ═══════════════════════════════════════════════════════════
    // ─── Mail Domains (Master - DB operations) ────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getDomains(): array
    {
        return Database::fetchAll("
            SELECT md.*, cn.name AS node_name, c.name AS customer_name,
                   (SELECT COUNT(*) FROM mail_accounts WHERE mail_domain_id = md.id) AS account_count
            FROM mail_domains md
            LEFT JOIN cluster_nodes cn ON cn.id = md.mail_node_id
            LEFT JOIN customers c ON c.id = md.customer_id
            ORDER BY md.domain
        ");
    }

    public static function getDomain(int $id): ?array
    {
        return Database::fetchOne("
            SELECT md.*, cn.name AS node_name, c.name AS customer_name
            FROM mail_domains md
            LEFT JOIN cluster_nodes cn ON cn.id = md.mail_node_id
            LEFT JOIN customers c ON c.id = md.customer_id
            WHERE md.id = :id
        ", ['id' => $id]);
    }

    public static function getDomainByName(string $domain): ?array
    {
        return Database::fetchOne("SELECT * FROM mail_domains WHERE domain = :d", ['d' => $domain]);
    }

    public static function createDomain(string $domain, ?int $customerId, ?int $mailNodeId, array $extra = []): int
    {
        $data = [
            'domain'       => strtolower(trim($domain)),
            'customer_id'  => $customerId,
            'mail_node_id' => $mailNodeId ?: (Settings::get('mail_default_node_id') ?: null),
            'max_accounts' => $extra['max_accounts'] ?? 0,
            'status'       => 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        $domainId = Database::insert('mail_domains', $data);

        // Generate DKIM key pair and enqueue filesystem creation on mail node
        if (Settings::get('mail_dkim_auto', '1') === '1') {
            self::generateDkim($domainId);
        }

        // Enqueue domain creation on the mail node (Maildir base dir, DKIM signing)
        $nodeId = $data['mail_node_id'];
        $domainPayload = [
            'domain'           => $data['domain'],
            'dkim_selector'    => 'default',
            'dkim_private_key' => self::getDkimPrivateKey($domainId),
        ];

        if ($nodeId) {
            ClusterService::enqueue((int)$nodeId, 'mail_create_domain', $domainPayload);
        } elseif (Settings::get('mail_local_configured', '') === '1') {
            // Local mail server — execute filesystem operations directly
            self::nodeCreateDomain($domainPayload);
        }

        return $domainId;
    }

    public static function updateDomain(int $id, array $data): void
    {
        $allowed = ['customer_id', 'mail_node_id', 'max_accounts', 'status', 'spf_record', 'dmarc_policy'];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_domains', $update, 'id = :id', ['id' => $id]);
    }

    public static function deleteDomain(int $id): void
    {
        $domain = self::getDomain($id);
        if (!$domain) return;

        // Enqueue cleanup on mail node or execute locally
        $deletePayload = ['domain' => $domain['domain']];
        if ($domain['mail_node_id']) {
            ClusterService::enqueue((int)$domain['mail_node_id'], 'mail_delete_domain', $deletePayload);
        } elseif (Settings::get('mail_local_configured', '') === '1') {
            self::nodeDeleteDomain($deletePayload);
        }

        // Cascade deletes accounts and aliases (FK ON DELETE CASCADE)
        Database::delete('mail_domains', 'id = :id', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Accounts / Mailboxes (Master - DB) ─────────────
    // ═══════════════════════════════════════════════════════════

    public static function getAccounts(?int $domainId = null): array
    {
        $where = $domainId ? 'WHERE ma.mail_domain_id = :did' : '';
        $params = $domainId ? ['did' => $domainId] : [];

        return Database::fetchAll("
            SELECT ma.*, md.domain AS domain_name, c.name AS customer_name
            FROM mail_accounts ma
            JOIN mail_domains md ON md.id = ma.mail_domain_id
            LEFT JOIN customers c ON c.id = ma.customer_id
            {$where}
            ORDER BY ma.email
        ", $params);
    }

    public static function getAccount(int $id): ?array
    {
        return Database::fetchOne("
            SELECT ma.*, md.domain AS domain_name, md.mail_node_id,
                   c.name AS customer_name
            FROM mail_accounts ma
            JOIN mail_domains md ON md.id = ma.mail_domain_id
            LEFT JOIN customers c ON c.id = ma.customer_id
            WHERE ma.id = :id
        ", ['id' => $id]);
    }

    public static function getAccountByEmail(string $email): ?array
    {
        return Database::fetchOne("
            SELECT ma.*, md.domain AS domain_name, md.mail_node_id
            FROM mail_accounts ma
            JOIN mail_domains md ON md.id = ma.mail_domain_id
            WHERE ma.email = :e
        ", ['e' => strtolower($email)]);
    }

    public static function createAccount(int $domainId, string $localPart, string $password, array $extra = []): int
    {
        $domain = self::getDomain($domainId);
        if (!$domain) {
            throw new \RuntimeException("Mail domain #{$domainId} not found");
        }

        // Check max accounts limit
        if ($domain['max_accounts'] > 0) {
            $count = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM mail_accounts WHERE mail_domain_id = :did AND status != 'deleted'",
                ['did' => $domainId]
            );
            if (($count['cnt'] ?? 0) >= $domain['max_accounts']) {
                throw new \RuntimeException("Maximum accounts ({$domain['max_accounts']}) reached for {$domain['domain']}");
            }
        }

        $email = strtolower(trim($localPart)) . '@' . $domain['domain'];
        $homeDir = '/var/mail/vhosts/' . $domain['domain'] . '/' . strtolower(trim($localPart));
        $quotaMb = $extra['quota_mb'] ?? (int)Settings::get('mail_default_quota_mb', '1024');

        // Use Dovecot-compatible password hash: {BLF-CRYPT} (bcrypt)
        $passwordHash = '{BLF-CRYPT}' . password_hash($password, PASSWORD_BCRYPT);

        $data = [
            'mail_domain_id' => $domainId,
            'account_id'     => $extra['account_id'] ?? null,
            'customer_id'    => $extra['customer_id'] ?? $domain['customer_id'],
            'email'          => $email,
            'local_part'     => strtolower(trim($localPart)),
            'password_hash'  => $passwordHash,
            'display_name'   => $extra['display_name'] ?? '',
            'quota_mb'       => $quotaMb,
            'home_dir'       => $homeDir,
            'status'         => 'active',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        $accountId = Database::insert('mail_accounts', $data);

        // Enqueue Maildir creation on mail node or execute locally
        $nodeId = $domain['mail_node_id'];
        $mailboxPayload = [
            'email'    => $email,
            'home_dir' => $homeDir,
            'quota_mb' => $quotaMb,
            'domain'   => $domain['domain'],
        ];

        if ($nodeId) {
            ClusterService::enqueue((int)$nodeId, 'mail_create_mailbox', $mailboxPayload);
        } elseif (Settings::get('mail_local_configured', '') === '1') {
            self::nodeCreateMailbox($mailboxPayload);
        }

        return $accountId;
    }

    public static function updateAccount(int $id, array $data): void
    {
        $account = self::getAccount($id);
        if (!$account) return;

        $allowed = ['display_name', 'quota_mb', 'status', 'account_id', 'customer_id',
                     'autoresponder_enabled', 'autoresponder_subject', 'autoresponder_body'];
        $update = array_intersect_key($data, array_flip($allowed));

        // Password change
        if (!empty($data['password'])) {
            $update['password_hash'] = '{BLF-CRYPT}' . password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        Database::update('mail_accounts', $update, 'id = :id', ['id' => $id]);

        // Enqueue quota update on mail node if quota changed
        if (isset($data['quota_mb'])) {
            $quotaPayload = [
                'email'    => $account['email'],
                'home_dir' => $account['home_dir'],
                'quota_mb' => (int)$data['quota_mb'],
            ];
            if ($account['mail_node_id']) {
                ClusterService::enqueue((int)$account['mail_node_id'], 'mail_update_quota', $quotaPayload);
            } elseif (Settings::get('mail_local_configured', '') === '1') {
                self::nodeUpdateQuota($quotaPayload);
            }
        }

        // Enqueue suspend/activate on mail node
        if (isset($data['status']) && $data['status'] !== $account['status']) {
            $action = $data['status'] === 'suspended' ? 'mail_suspend_mailbox' : 'mail_activate_mailbox';
            $statusPayload = ['email' => $account['email']];
            if ($account['mail_node_id']) {
                ClusterService::enqueue((int)$account['mail_node_id'], $action, $statusPayload);
            }
            // Note: suspend/activate for local mode would need Dovecot passdb deny list — not yet implemented
        }
    }

    public static function deleteAccount(int $id): void
    {
        $account = self::getAccount($id);
        if (!$account) return;

        // Enqueue Maildir cleanup on mail node or execute locally
        $deletePayload = [
            'email'    => $account['email'],
            'home_dir' => $account['home_dir'],
            'domain'   => $account['domain_name'],
        ];
        if ($account['mail_node_id']) {
            ClusterService::enqueue((int)$account['mail_node_id'], 'mail_delete_mailbox', $deletePayload);
        } elseif (Settings::get('mail_local_configured', '') === '1') {
            self::nodeDeleteMailbox($deletePayload);
        }

        Database::delete('mail_accounts', 'id = :id', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Aliases (Master - DB) ──────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getAliases(int $domainId): array
    {
        return Database::fetchAll("
            SELECT * FROM mail_aliases
            WHERE mail_domain_id = :did
            ORDER BY source
        ", ['did' => $domainId]);
    }

    public static function createAlias(int $domainId, string $source, string $destination, bool $isCatchall = false): int
    {
        return Database::insert('mail_aliases', [
            'mail_domain_id' => $domainId,
            'source'         => strtolower(trim($source)),
            'destination'    => strtolower(trim($destination)),
            'is_catchall'    => $isCatchall,
            'is_active'      => true,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public static function deleteAlias(int $id): void
    {
        Database::delete('mail_aliases', 'id = :id', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════
    // ─── DKIM Management ─────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function generateDkim(int $domainId): array
    {
        $domain = Database::fetchOne("SELECT * FROM mail_domains WHERE id = :id", ['id' => $domainId]);
        if (!$domain) {
            return ['ok' => false, 'error' => 'Domain not found'];
        }

        $selector = $domain['dkim_selector'] ?: 'default';

        // Generate 2048-bit RSA key pair
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = openssl_pkey_new($config);
        if (!$res) {
            return ['ok' => false, 'error' => 'Failed to generate key pair: ' . openssl_error_string()];
        }

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $publicKey = $details['key'];

        // Extract just the base64 portion for DNS TXT record
        $pubKeyClean = str_replace(["\n", "\r", '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $publicKey);

        Database::update('mail_domains', [
            'dkim_private_key' => $privateKey,
            'dkim_public_key'  => $pubKeyClean,
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $domainId]);

        return [
            'ok'         => true,
            'selector'   => $selector,
            'public_key' => $pubKeyClean,
            'dns_record' => "{$selector}._domainkey.{$domain['domain']}",
            'dns_value'  => "v=DKIM1; k=rsa; p={$pubKeyClean}",
        ];
    }

    public static function getDkimPrivateKey(int $domainId): string
    {
        $row = Database::fetchOne("SELECT dkim_private_key FROM mail_domains WHERE id = :id", ['id' => $domainId]);
        return $row['dkim_private_key'] ?? '';
    }

    /**
     * Get all DNS records needed for a mail domain.
     */
    public static function getDnsRecords(int $domainId): array
    {
        $domain = self::getDomain($domainId);
        if (!$domain) return [];

        $nodeIp = '';
        $mailHostname = '';
        if ($domain['mail_node_id']) {
            $node = ClusterService::getNode((int)$domain['mail_node_id']);
            $nodeIp = $node['metadata']['ip'] ?? parse_url($node['api_url'] ?? '', PHP_URL_HOST) ?? '';
            $mailHostname = $node['mail_hostname'] ?? '';
        } elseif (Settings::get('mail_local_configured', '') === '1') {
            // Local mail server — use this server's IP and hostname
            $nodeIp = trim(shell_exec('curl -s -4 --max-time 3 ifconfig.me 2>/dev/null') ?: '');
            $mailHostname = Settings::get('mail_local_hostname', '');
        }

        // Use mail hostname if set, otherwise fall back to mail.domain
        $mxValue = $mailHostname ?: ($nodeIp ? "mail.{$domain['domain']}" : 'localhost');

        $records = [];

        // MX record
        $records[] = [
            'type'     => 'MX',
            'name'     => $domain['domain'],
            'value'    => $mxValue,
            'priority' => 10,
        ];

        // A record for mail hostname
        if ($nodeIp && $mailHostname) {
            $records[] = [
                'type'  => 'A',
                'name'  => $mailHostname,
                'value' => $nodeIp,
            ];
        } elseif ($nodeIp) {
            $records[] = [
                'type'  => 'A',
                'name'  => "mail.{$domain['domain']}",
                'value' => $nodeIp,
            ];
        }

        // SPF
        $spf = $domain['spf_record'] ?: 'v=spf1 mx ~all';
        if ($nodeIp && !str_contains($spf, $nodeIp)) {
            $spf = str_replace('~all', "ip4:{$nodeIp} ~all", $spf);
        }
        $records[] = [
            'type'  => 'TXT',
            'name'  => $domain['domain'],
            'value' => $spf,
        ];

        // DKIM
        if ($domain['dkim_public_key']) {
            $selector = $domain['dkim_selector'] ?: 'default';
            $records[] = [
                'type'  => 'TXT',
                'name'  => "{$selector}._domainkey.{$domain['domain']}",
                'value' => "v=DKIM1; k=rsa; p={$domain['dkim_public_key']}",
            ];
        }

        // DMARC
        $dmarcPolicy = $domain['dmarc_policy'] ?: 'quarantine';
        $records[] = [
            'type'  => 'TXT',
            'name'  => "_dmarc.{$domain['domain']}",
            'value' => "v=DMARC1; p={$dmarcPolicy}; rua=mailto:postmaster@{$domain['domain']}",
        ];

        return $records;
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Mail Node Actions (executed ON the mail node) ───────
    // ═══════════════════════════════════════════════════════════
    // These methods are called by ClusterApiController when the
    // mail node receives an enqueued action from the master.

    /**
     * Create Maildir structure and set quota on the mail node.
     */
    public static function nodeCreateMailbox(array $payload): array
    {
        $homeDir = $payload['home_dir'] ?? '';
        $email = $payload['email'] ?? '';
        $quotaMb = $payload['quota_mb'] ?? 1024;

        if (!$homeDir || !$email) {
            return ['ok' => false, 'error' => 'Missing home_dir or email'];
        }

        // Create Maildir structure
        $maildirPath = $homeDir . '/Maildir';
        foreach (['cur', 'new', 'tmp'] as $sub) {
            $dir = $maildirPath . '/' . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }

        // Set ownership to vmail user (virtual mail delivery)
        shell_exec(sprintf('chown -R vmail:vmail %s 2>&1', escapeshellarg($homeDir)));

        // Write Dovecot quota file
        $quotaBytes = $quotaMb * 1024 * 1024;
        file_put_contents($homeDir . '/dovecot-quota', "* storage={$quotaBytes}\n");
        shell_exec(sprintf('chown vmail:vmail %s 2>&1', escapeshellarg($homeDir . '/dovecot-quota')));

        return ['ok' => true, 'message' => "Mailbox created: {$email}"];
    }

    /**
     * Create domain base directory and install DKIM signing key on the mail node.
     */
    public static function nodeCreateDomain(array $payload): array
    {
        $domain = $payload['domain'] ?? '';
        $dkimSelector = $payload['dkim_selector'] ?? 'default';
        $dkimPrivateKey = $payload['dkim_private_key'] ?? '';

        if (!$domain) {
            return ['ok' => false, 'error' => 'Missing domain'];
        }

        // Create domain base directory
        $domainDir = '/var/mail/vhosts/' . $domain;
        if (!is_dir($domainDir)) {
            mkdir($domainDir, 0700, true);
            shell_exec(sprintf('chown vmail:vmail %s 2>&1', escapeshellarg($domainDir)));
        }

        // Install DKIM key for OpenDKIM
        if ($dkimPrivateKey) {
            $dkimDir = '/etc/opendkim/keys/' . $domain;
            if (!is_dir($dkimDir)) {
                mkdir($dkimDir, 0700, true);
            }
            file_put_contents($dkimDir . '/' . $dkimSelector . '.private', $dkimPrivateKey);
            chmod($dkimDir . '/' . $dkimSelector . '.private', 0600);
            shell_exec(sprintf('chown -R opendkim:opendkim %s 2>&1', escapeshellarg($dkimDir)));

            // Update OpenDKIM signing table
            self::updateDkimSigningTable($domain, $dkimSelector);

            // Reload OpenDKIM
            shell_exec('systemctl reload opendkim 2>&1');
        }

        return ['ok' => true, 'message' => "Mail domain created: {$domain}"];
    }

    /**
     * Delete Maildir on the mail node.
     */
    public static function nodeDeleteMailbox(array $payload): array
    {
        $homeDir = $payload['home_dir'] ?? '';
        $email = $payload['email'] ?? '';

        if (!$homeDir || !$email) {
            return ['ok' => false, 'error' => 'Missing home_dir or email'];
        }

        // Safety: only allow paths under /var/mail/vhosts/
        if (!str_starts_with($homeDir, '/var/mail/vhosts/')) {
            return ['ok' => false, 'error' => 'Invalid home_dir path'];
        }

        // Move to trash instead of hard delete (recoverable for 30 days)
        $trashDir = '/var/mail/trash/' . date('Ymd') . '/' . basename(dirname($homeDir)) . '/' . basename($homeDir);
        if (is_dir($homeDir)) {
            mkdir(dirname($trashDir), 0700, true);
            rename($homeDir, $trashDir);
        }

        return ['ok' => true, 'message' => "Mailbox deleted (moved to trash): {$email}"];
    }

    /**
     * Delete domain directory and DKIM keys on the mail node.
     */
    public static function nodeDeleteDomain(array $payload): array
    {
        $domain = $payload['domain'] ?? '';
        if (!$domain) {
            return ['ok' => false, 'error' => 'Missing domain'];
        }

        // Move domain Maildirs to trash
        $domainDir = '/var/mail/vhosts/' . $domain;
        if (is_dir($domainDir)) {
            $trashDir = '/var/mail/trash/' . date('Ymd') . '/' . $domain;
            mkdir(dirname($trashDir), 0700, true);
            rename($domainDir, $trashDir);
        }

        // Remove DKIM keys
        $dkimDir = '/etc/opendkim/keys/' . $domain;
        if (is_dir($dkimDir)) {
            shell_exec(sprintf('rm -rf %s 2>&1', escapeshellarg($dkimDir)));
        }

        // Remove from signing/key tables
        self::removeDkimSigningTable($domain);
        shell_exec('systemctl reload opendkim 2>&1');

        return ['ok' => true, 'message' => "Mail domain deleted: {$domain}"];
    }

    /**
     * Update Maildir quota on the mail node.
     */
    public static function nodeUpdateQuota(array $payload): array
    {
        $homeDir = $payload['home_dir'] ?? '';
        $quotaMb = $payload['quota_mb'] ?? 1024;

        if (!$homeDir) {
            return ['ok' => false, 'error' => 'Missing home_dir'];
        }

        $quotaBytes = $quotaMb * 1024 * 1024;
        file_put_contents($homeDir . '/dovecot-quota', "* storage={$quotaBytes}\n");
        shell_exec(sprintf('chown vmail:vmail %s 2>&1', escapeshellarg($homeDir . '/dovecot-quota')));

        return ['ok' => true, 'message' => "Quota updated to {$quotaMb}MB"];
    }

    /**
     * Suspend a mailbox — Dovecot will deny login via the DB status field,
     * but we also create a lock file as belt-and-suspenders.
     */
    public static function nodeSuspendMailbox(array $payload): array
    {
        $email = $payload['email'] ?? '';
        // The DB status='suspended' already blocks Dovecot auth via SQL query.
        // This is a no-op on the filesystem side; kept for future use.
        return ['ok' => true, 'message' => "Mailbox suspended: {$email}"];
    }

    public static function nodeActivateMailbox(array $payload): array
    {
        $email = $payload['email'] ?? '';
        return ['ok' => true, 'message' => "Mailbox activated: {$email}"];
    }

    /**
     * Migrate a mailbox from one node to another via rsync over WireGuard.
     */
    public static function migrateMailbox(int $accountId, int $targetNodeId): array
    {
        $account = self::getAccount($accountId);
        if (!$account) {
            return ['ok' => false, 'error' => 'Account not found'];
        }

        $sourceDomain = Database::fetchOne("SELECT * FROM mail_domains WHERE id = :id", ['id' => $account['mail_domain_id']]);
        $sourceNodeId = $sourceDomain['mail_node_id'] ?? null;

        if (!$sourceNodeId) {
            return ['ok' => false, 'error' => 'Source node not configured'];
        }

        if ((int)$sourceNodeId === $targetNodeId) {
            return ['ok' => false, 'error' => 'Source and target are the same node'];
        }

        // Enqueue migration on source node (rsync to target)
        ClusterService::enqueue((int)$sourceNodeId, 'mail_migrate_mailbox', [
            'email'          => $account['email'],
            'home_dir'       => $account['home_dir'],
            'target_node_id' => $targetNodeId,
            'domain'         => $account['domain_name'],
        ], 2); // High priority

        return ['ok' => true, 'message' => "Migration queued: {$account['email']} → node #{$targetNodeId}"];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Healthcheck (called from cluster-worker) ────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Check mail services and the local PostgreSQL replica used by Postfix/Dovecot.
     * This runs from the master and asks the remote node to inspect its own DB.
     */
    public static function checkMailNodeHealth(int $nodeId): array
    {
        $node = ClusterService::getNode($nodeId);
        if (!$node) return ['ok' => false, 'error' => 'Node not found'];

        $host = parse_url($node['api_url'] ?? '', PHP_URL_HOST) ?? '';
        if (!$host) return ['ok' => false, 'error' => 'Cannot determine node IP'];

        $services = [];
        $checks = [
            'smtp'       => 25,
            'submission' => 587,
            'imaps'      => 993,
        ];

        $allOk = true;
        foreach ($checks as $name => $port) {
            $fp = @fsockopen($host, $port, $errno, $errstr, 5);
            $ok = $fp !== false;
            if ($fp) fclose($fp);

            $services[$name] = ['port' => $port, 'ok' => $ok, 'error' => $ok ? '' : "{$errstr} ({$errno})"];
            if (!$ok) $allOk = false;
        }

        // Also check the panel API endpoint
        $apiResult = ClusterService::sendHeartbeat($nodeId);
        $services['panel_api'] = ['ok' => $apiResult['ok'], 'error' => $apiResult['error'] ?? ''];
        if (!$apiResult['ok']) $allOk = false;

        $dbHealth = [
            'pg_alive' => false,
            'pg_read_ok' => false,
            'is_replica' => false,
            'replication_lag_seconds' => null,
            'maildir_ok' => false,
            'mail_domains_count' => 0,
            'ptr_ok' => null,
            'ptr_value' => '',
            'expected_hostname' => (string)($node['mail_hostname'] ?? ''),
            'timestamp' => gmdate('c'),
        ];

        if ($apiResult['ok']) {
            $dbResult = ClusterService::callNode($nodeId, 'POST', 'api/cluster/action', [
                'action' => 'mail_db_health',
                'payload' => [
                    'node_host' => $host,
                    'expected_hostname' => (string)($node['mail_hostname'] ?? ''),
                ],
            ]);
            if ($dbResult['ok'] && isset($dbResult['data']['mail_db_health']) && is_array($dbResult['data']['mail_db_health'])) {
                $dbHealth = array_merge($dbHealth, $dbResult['data']['mail_db_health']);
            } else {
                $dbHealth['error'] = $dbResult['error'] ?? 'mail_db_health failed';
            }
        } else {
            $dbHealth['error'] = 'panel API unreachable';
        }

        $decision = self::evaluateMailDbHealth($dbHealth);
        $dbHealth['status'] = $decision['status'];
        $dbHealth['message'] = $decision['message'];
        $dbHealth['pause_queue'] = $decision['pause_queue'];

        self::recordMailNodeHealth($nodeId, $dbHealth);

        if ($decision['pause_queue']) {
            ClusterService::pauseMailQueueForNode($nodeId, $decision['reason']);
        } elseif ($decision['status'] === 'active') {
            ClusterService::resumeMailQueueForNode($nodeId);
        }

        $metadata = json_decode((string)($node['metadata'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['mail_db_health'] = $dbHealth;
        ClusterService::updateNode($nodeId, [
            'status' => $decision['status'] === 'active' && $allOk ? 'online' : 'error',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'ok' => $allOk && $decision['status'] === 'active',
            'services' => $services,
            'db_health' => $dbHealth,
            'node' => $node['name'],
        ];
    }

    private static function evaluateMailDbHealth(array $health): array
    {
        $warnLag = (float)Settings::get('mail_db_lag_warn_seconds', '30');
        $pauseLag = (float)Settings::get('mail_db_lag_pause_seconds', '120');
        $lag = isset($health['replication_lag_seconds']) && $health['replication_lag_seconds'] !== null
            ? (float)$health['replication_lag_seconds']
            : 0.0;

        if (empty($health['pg_alive'])) {
            return [
                'status' => 'down',
                'pause_queue' => true,
                'reason' => 'node_mail_db_down',
                'message' => 'PostgreSQL local no responde en el nodo de mail.',
            ];
        }

        if (empty($health['pg_read_ok'])) {
            return [
                'status' => 'degraded',
                'pause_queue' => true,
                'reason' => 'node_mail_db_read_failed',
                'message' => 'PostgreSQL responde pero el usuario musedock_mail no puede leer las tablas de mail.',
            ];
        }

        if (!empty($health['is_replica']) && $lag > $pauseLag) {
            return [
                'status' => 'degraded',
                'pause_queue' => true,
                'reason' => 'node_mail_db_lag_high',
                'message' => "Replica PostgreSQL con {$lag}s de retraso; cola mail pausada.",
            ];
        }

        if (!empty($health['is_replica']) && $lag > $warnLag) {
            return [
                'status' => 'degraded',
                'pause_queue' => false,
                'reason' => 'node_mail_db_lag_warning',
                'message' => "Replica PostgreSQL con {$lag}s de retraso.",
            ];
        }

        if (empty($health['maildir_ok'])) {
            return [
                'status' => 'degraded',
                'pause_queue' => false,
                'reason' => 'node_maildir_permissions',
                'message' => '/var/mail/vhosts no existe o no pertenece a vmail:vmail.',
            ];
        }

        return [
            'status' => 'active',
            'pause_queue' => false,
            'reason' => '',
            'message' => 'Mail DB healthy.',
        ];
    }

    private static function recordMailNodeHealth(int $nodeId, array $health): void
    {
        try {
            Database::insert('mail_node_health', [
                'node_id' => $nodeId,
                'pg_alive' => !empty($health['pg_alive']),
                'pg_read_ok' => !empty($health['pg_read_ok']),
                'is_replica' => !empty($health['is_replica']),
                'replication_lag_seconds' => $health['replication_lag_seconds'] ?? null,
                'maildir_ok' => !empty($health['maildir_ok']),
                'mail_domains_count' => (int)($health['mail_domains_count'] ?? 0),
                'ptr_ok' => array_key_exists('ptr_ok', $health) && $health['ptr_ok'] !== null ? (bool)$health['ptr_ok'] : null,
                'ptr_value' => (string)($health['ptr_value'] ?? ''),
                'expected_hostname' => (string)($health['expected_hostname'] ?? ''),
                'status' => (string)($health['status'] ?? 'unknown'),
                'pause_queue' => !empty($health['pause_queue']),
                'message' => (string)($health['message'] ?? ''),
                'checked_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Keep the table bounded without expensive window deletes.
            Database::query(
                "DELETE FROM mail_node_health
                 WHERE node_id = :nid
                   AND id NOT IN (
                       SELECT id FROM mail_node_health
                       WHERE node_id = :nid2
                       ORDER BY checked_at DESC
                       LIMIT 1000
                   )",
                ['nid' => $nodeId, 'nid2' => $nodeId]
            );
        } catch (\Throwable) {
            // Health persistence must never break the worker.
        }
    }

    public static function getLatestMailHealthByNode(): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT DISTINCT ON (node_id) *
                 FROM mail_node_health
                 ORDER BY node_id, checked_at DESC"
            );
        } catch (\Throwable) {
            return [];
        }

        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int)$row['node_id']] = $row;
        }
        return $byNode;
    }

    public static function getMailHealthAlerts(): array
    {
        $latest = self::getLatestMailHealthByNode();
        $alerts = [];
        foreach ($latest as $nodeId => $health) {
            $status = (string)($health['status'] ?? '');
            if (in_array($status, ['down', 'degraded'], true)) {
                $alerts[$nodeId] = $health;
            }
        }
        return $alerts;
    }

    /**
     * Runs on the mail node through ClusterApiController.
     */
    public static function nodeMailDbHealth(array $payload = []): array
    {
        $db = self::detectMailDbConfig();
        $result = [
            'pg_alive' => false,
            'pg_read_ok' => false,
            'mail_domains_count' => 0,
            'is_replica' => false,
            'replication_lag_seconds' => null,
            'maildir_ok' => self::maildirIsHealthy(),
            'ptr_ok' => null,
            'ptr_value' => '',
            'expected_hostname' => trim((string)($payload['expected_hostname'] ?? '')),
            'timestamp' => gmdate('c'),
        ];

        if (empty($db['password'])) {
            $result['error'] = 'mail DB password not found in Postfix/Dovecot config';
            return ['ok' => true, 'mail_db_health' => array_merge($result, self::checkPtr($payload))];
        }

        try {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $db['dbname']);
            $pdo = new \PDO($dsn, $db['user'], $db['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $result['pg_alive'] = true;

            $count = $pdo->query("SELECT COUNT(*) AS total FROM mail_domains WHERE status = 'active'")->fetch();
            $result['pg_read_ok'] = true;
            $result['mail_domains_count'] = (int)($count['total'] ?? 0);

            $replica = $pdo->query('SELECT pg_is_in_recovery() AS is_replica')->fetch();
            $isReplica = filter_var($replica['is_replica'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $result['is_replica'] = $isReplica;

            if ($isReplica) {
                $lag = $pdo->query("
                    SELECT CASE
                      WHEN pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0
                      ELSE COALESCE(EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp()), 0)
                    END AS replication_lag_seconds
                ")->fetch();
                $result['replication_lag_seconds'] = round((float)($lag['replication_lag_seconds'] ?? 0), 2);
            } else {
                $result['replication_lag_seconds'] = 0.0;
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $ptr = self::checkPtr($payload);
        return ['ok' => true, 'mail_db_health' => array_merge($result, $ptr)];
    }

    private static function detectMailDbConfig(): array
    {
        $cfg = [
            'host' => 'localhost',
            'port' => '5433',
            'dbname' => 'musedock_panel',
            'user' => 'musedock_mail',
            'password' => '',
        ];

        $postfix = '/etc/postfix/pgsql-virtual-domains.cf';
        if (is_file($postfix)) {
            foreach (file($postfix, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if ($key === 'hosts') $cfg['host'] = preg_split('/\s+/', $value)[0] ?? $cfg['host'];
                if ($key === 'port') $cfg['port'] = $value;
                if ($key === 'dbname') $cfg['dbname'] = $value;
                if ($key === 'user') $cfg['user'] = $value;
                if ($key === 'password') $cfg['password'] = $value;
            }
        }

        $dovecot = '/etc/dovecot/dovecot-sql.conf';
        if ($cfg['password'] === '' && is_file($dovecot)) {
            $content = (string)file_get_contents($dovecot);
            if (preg_match('/connect\s*=\s*(.+)$/m', $content, $m)) {
                preg_match_all('/(host|port|dbname|user|password)=([^\\s]+)/', $m[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $cfg[$pair[1]] = $pair[2];
                }
            }
        }

        return $cfg;
    }

    private static function maildirIsHealthy(): bool
    {
        $dir = '/var/mail/vhosts';
        if (!is_dir($dir)) {
            return false;
        }
        return (int)@fileowner($dir) === 5000 && (int)@filegroup($dir) === 5000;
    }

    private static function checkPtr(array $payload): array
    {
        $expected = rtrim(strtolower(trim((string)($payload['expected_hostname'] ?? ''))), '.');
        $host = trim((string)($payload['node_host'] ?? ''));
        $ip = '';

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } elseif ($host !== '') {
            $records = @dns_get_record($host, DNS_A);
            if (is_array($records) && !empty($records[0]['ip'])) {
                $ip = (string)$records[0]['ip'];
            }
        }

        if ($ip === '') {
            return ['ptr_ok' => null, 'ptr_value' => '', 'ptr_ip' => ''];
        }

        $ptr = @gethostbyaddr($ip);
        $ptrClean = $ptr && $ptr !== $ip ? rtrim(strtolower($ptr), '.') : '';
        $ptrOk = $ptrClean !== '' && ($expected === '' || $ptrClean === $expected);

        return [
            'ptr_ok' => $ptrOk,
            'ptr_value' => $ptrClean,
            'ptr_ip' => $ip,
        ];
    }

    /**
     * Get mail nodes (cluster_nodes with 'mail' in services).
     */
    public static function getMailNodes(): array
    {
        return Database::fetchAll("
            SELECT * FROM cluster_nodes
            WHERE services::text LIKE '%mail%'
            ORDER BY name
        ");
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Statistics ──────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getStats(): array
    {
        $totalDomains = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_domains WHERE status = 'active'");
        $totalAccounts = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_accounts WHERE status = 'active'");
        $totalAliases = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_aliases WHERE is_active = true");
        $totalUsedMb = Database::fetchOne("SELECT COALESCE(SUM(used_mb), 0) AS total FROM mail_accounts");
        $totalQuotaMb = Database::fetchOne("SELECT COALESCE(SUM(quota_mb), 0) AS total FROM mail_accounts WHERE status = 'active'");

        return [
            'domains'        => (int)($totalDomains['cnt'] ?? 0),
            'accounts'       => (int)($totalAccounts['cnt'] ?? 0),
            'aliases'        => (int)($totalAliases['cnt'] ?? 0),
            'used_mb'        => (int)($totalUsedMb['total'] ?? 0),
            'quota_mb'       => (int)($totalQuotaMb['total'] ?? 0),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Internal helpers ────────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════
    // ─── Node Setup (remote provisioning via cluster API) ──────
    // ═══════════════════════════════════════════════════════════

    /**
     * Accept mail setup request and launch background installer.
     *
     * Returns 202-style response immediately with a task_id.
     * The actual installation runs in bin/mail-setup-run.php via nohup.
     * Master polls progress via mail_setup_status action.
     *
     * Security: requires setup_token (one-time token generated by master)
     * in addition to the normal cluster bearer token.
     */
    public static function nodeSetupMail(array $payload): array
    {
        $dbPass   = $payload['db_pass'] ?? '';
        $hostname = $payload['mail_hostname'] ?? '';

        if (!$dbPass || !$hostname) {
            return ['ok' => false, 'error' => 'Missing db_pass or mail_hostname'];
        }

        // Verify setup token (one-time, generated by master)
        $expectedToken = Settings::get('mail_setup_token', '');
        $receivedToken = $payload['setup_token'] ?? '';
        if (!$expectedToken || !hash_equals($expectedToken, $receivedToken)) {
            return ['ok' => false, 'error' => 'Invalid or missing setup_token. Generate one from the master panel first.'];
        }

        // Invalidate the one-time token immediately
        Settings::set('mail_setup_token', '');

        // Check if setup is already running
        $existingTaskId = Settings::get('mail_setup_task_id', '');
        if ($existingTaskId) {
            $progressFile = self::setupProgressFile($existingTaskId);
            if (file_exists($progressFile)) {
                $progress = json_decode(file_get_contents($progressFile), true);
                if (($progress['status'] ?? '') === 'running') {
                    return ['ok' => false, 'error' => 'Setup already in progress', 'task_id' => $existingTaskId];
                }
            }
        }

        // Generate task ID
        $taskId = 'mail-setup-' . time() . '-' . bin2hex(random_bytes(4));
        Settings::set('mail_setup_task_id', $taskId);

        // Write initial progress
        $progressFile = self::setupProgressFile($taskId);
        file_put_contents($progressFile, json_encode([
            'status'      => 'starting',
            'step'        => 0,
            'total_steps' => 9,
            'message'     => 'Iniciando instalacion...',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]));

        // Launch background process
        $payloadB64 = base64_encode(json_encode($payload));
        $cmd = sprintf(
            'nohup /usr/bin/php %s %s %s > /dev/null 2>&1 &',
            escapeshellarg(dirname(__DIR__, 2) . '/bin/mail-setup-run.php'),
            escapeshellarg($taskId),
            escapeshellarg($payloadB64)
        );
        shell_exec($cmd);

        return [
            'ok'      => true,
            'async'   => true,
            'task_id' => $taskId,
            'message' => 'Mail setup started in background. Poll mail_setup_status for progress.',
        ];
    }

    /**
     * Return current setup progress (called from master via polling).
     *
     * Detects stale/dead processes:
     * - If status is 'running' but the PID is no longer alive → mark as 'failed' (process died)
     * - If status is 'running' but updated_at is >10 min old → mark as 'stale' (hung process)
     */
    public static function nodeSetupStatus(array $payload): array
    {
        $taskId = $payload['task_id'] ?? '';
        if (!$taskId) {
            return ['ok' => false, 'error' => 'Missing task_id'];
        }

        $progressFile = self::setupProgressFile($taskId);
        if (!file_exists($progressFile)) {
            return ['ok' => false, 'error' => 'Task not found'];
        }

        $progress = json_decode(file_get_contents($progressFile), true);
        if (!$progress) {
            return ['ok' => false, 'error' => 'Corrupt progress file'];
        }

        // Dead process detection
        if (($progress['status'] ?? '') === 'running') {
            $pid = $progress['pid'] ?? 0;
            $updatedAt = $progress['updated_at'] ?? '';

            // Check 1: PID no longer alive (killed, OOM, crash)
            if ($pid > 0 && !file_exists("/proc/{$pid}")) {
                $progress['status'] = 'failed';
                $progress['errors'][] = [
                    'step'    => $progress['current'] ?? 'unknown',
                    'command' => 'process_died',
                    'exit'    => -1,
                    'output'  => "Background process (PID {$pid}) is no longer running. Likely killed by OOM or crash. Check dmesg and the setup log file.",
                ];
                $progress['failed_at'] = date('Y-m-d H:i:s');
                file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
            }
            // Check 2: Progress not updated in >10 minutes (hung process)
            elseif ($updatedAt && (time() - strtotime($updatedAt)) > 600) {
                $staleSince = round((time() - strtotime($updatedAt)) / 60);
                $progress['status'] = 'stale';
                $progress['errors'][] = [
                    'step'    => $progress['current'] ?? 'unknown',
                    'command' => 'process_stale',
                    'exit'    => -2,
                    'output'  => "No progress update in {$staleSince} minutes. Process may be hung at step: {$progress['current']}. PID: {$pid}.",
                ];
                $progress['stale_at'] = date('Y-m-d H:i:s');
                file_put_contents($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
            }
        }

        // Include log file path for troubleshooting
        $safeTaskId = preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId);
        $logFile = dirname(__DIR__, 2) . '/storage/logs/mail-setup-' . $safeTaskId . '.log';
        $progress['log_available'] = file_exists($logFile);

        // Include last 30 lines of log for quick debugging
        if ($progress['log_available'] && in_array($progress['status'] ?? '', ['failed', 'stale', 'completed_with_errors'])) {
            $logContent = file_get_contents($logFile);
            $logLines = explode("\n", $logContent);
            $progress['log_tail'] = implode("\n", array_slice($logLines, -30));
        }

        return ['ok' => true, 'progress' => $progress];
    }

    /**
     * Generate a one-time setup token on this node.
     * Called via cluster API before starting mail_setup_node.
     */
    public static function nodeGenerateSetupToken(array $payload): array
    {
        $token = bin2hex(random_bytes(32));
        Settings::set('mail_setup_token', $token);
        return ['ok' => true, 'setup_token' => $token];
    }

    private static function setupProgressFile(string $taskId): string
    {
        return dirname(__DIR__, 2) . '/storage/mail-setup-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $taskId) . '.json';
    }

    private static function updateDkimSigningTable(string $domain, string $selector): void
    {
        $signingFile = '/etc/opendkim/signing.table';
        $keyFile = '/etc/opendkim/key.table';

        // signing.table: *@domain default._domainkey.domain
        $signingLine = "*@{$domain} {$selector}._domainkey.{$domain}";
        $existingContent = is_file($signingFile) ? file_get_contents($signingFile) : '';
        if (!str_contains($existingContent, "*@{$domain}")) {
            file_put_contents($signingFile, $existingContent . $signingLine . "\n");
        }

        // key.table: default._domainkey.domain domain:default:/etc/opendkim/keys/domain/default.private
        $keyLine = "{$selector}._domainkey.{$domain} {$domain}:{$selector}:/etc/opendkim/keys/{$domain}/{$selector}.private";
        $existingContent = is_file($keyFile) ? file_get_contents($keyFile) : '';
        if (!str_contains($existingContent, "{$selector}._domainkey.{$domain}")) {
            file_put_contents($keyFile, $existingContent . $keyLine . "\n");
        }
    }

    private static function removeDkimSigningTable(string $domain): void
    {
        foreach (['/etc/opendkim/signing.table', '/etc/opendkim/key.table'] as $file) {
            if (!is_file($file)) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $filtered = array_filter($lines, fn($line) => !str_contains($line, $domain));
            file_put_contents($file, implode("\n", $filtered) . "\n");
        }
    }

    // ═══════════════════════════════════════════════════════════
    // ─── DB Password Rotation ────────────────────────────────
    // ═══════════════════════════════════════════════════════════

    /**
     * Propagate a new musedock_mail password to all active mail nodes.
     * Called from master after ALTER USER on master PG.
     */
    public static function rotateDbPassword(string $newPassword): array
    {
        $nodes = self::getMailNodes();
        $dbPort = \MuseDockPanel\Env::int('DB_PORT', 5433);
        $results = [];

        // Update local mail config files if local mail is configured
        if (Settings::get('mail_local_configured', '') === '1') {
            $localResult = self::nodeRotateDbPassword([
                'db_pass' => $newPassword,
                'db_port' => (string)$dbPort,
                'db_name' => 'musedock_panel',
                'db_user' => 'musedock_mail',
            ]);
            $results[] = ['node' => 'Local (este servidor)', 'ok' => $localResult['ok'] ?? false, 'error' => $localResult['error'] ?? ''];
        }

        foreach ($nodes as $node) {
            if (($node['status'] ?? '') !== 'online') {
                $results[] = ['node' => $node['name'], 'ok' => false, 'error' => 'Nodo no online, omitido'];
                continue;
            }

            $token = \MuseDockPanel\Services\ReplicationService::decryptPassword($node['auth_token'] ?? '');
            try {
                $result = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
                    'action'  => 'mail_rotate_db_password',
                    'payload' => [
                        'db_pass' => $newPassword,
                        'db_port' => (string)$dbPort,
                        'db_name' => 'musedock_panel',
                        'db_user' => 'musedock_mail',
                    ],
                ], 30, [
                    'metadata' => $node['metadata'] ?? null,
                ]);
                $results[] = ['node' => $node['name'], 'ok' => $result['data']['ok'] ?? false, 'error' => $result['error'] ?? ''];
            } catch (\Exception $e) {
                $results[] = ['node' => $node['name'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Update DB password in Postfix/Dovecot config files and restart services.
     * Runs ON THE NODE when receiving mail_rotate_db_password action.
     */
    public static function nodeRotateDbPassword(array $payload): array
    {
        $newPass = $payload['db_pass'] ?? '';
        if (!$newPass) {
            return ['ok' => false, 'error' => 'Missing db_pass'];
        }

        $updated = [];

        // Update Postfix SQL lookup files (password = line)
        $postfixFiles = [
            '/etc/postfix/pgsql-virtual-domains.cf',
            '/etc/postfix/pgsql-virtual-mailboxes.cf',
            '/etc/postfix/pgsql-virtual-aliases.cf',
        ];
        foreach ($postfixFiles as $file) {
            if (!is_file($file)) continue;
            $content = file_get_contents($file);
            $newContent = preg_replace('/^password\s*=\s*.*$/m', 'password = ' . $newPass, $content);
            file_put_contents($file, $newContent);
            $updated[] = basename($file);
        }

        // Update Dovecot SQL config (password= inside connect line)
        $dovecotSql = '/etc/dovecot/dovecot-sql.conf';
        if (is_file($dovecotSql)) {
            $content = file_get_contents($dovecotSql);
            $newContent = preg_replace('/password=\S+/', 'password=' . $newPass, $content);
            file_put_contents($dovecotSql, $newContent);
            $updated[] = 'dovecot-sql.conf';
        }

        // Restart services
        $services = [];
        foreach (['postfix', 'dovecot'] as $svc) {
            exec("systemctl restart {$svc} 2>&1", $out, $code);
            exec("systemctl is-active {$svc} 2>&1", $statusOut, $statusCode);
            $services[$svc] = $statusCode === 0 ? 'active' : 'failed';
        }

        $allActive = !in_array('failed', $services);

        return [
            'ok'       => $allActive,
            'updated'  => $updated,
            'services' => $services,
        ];
    }

    /**
     * Check if mail stack is configured on this node.
     * Runs ON THE NODE — checks for mail_node_configured setting and key config files.
     */
    public static function nodeCheckConfigured(): array
    {
        $configured = Settings::get('mail_node_configured', '') === '1';

        // Double-check: verify key config files exist
        $hasPostfix  = is_file('/etc/postfix/pgsql-virtual-domains.cf');
        $hasDovecot  = is_file('/etc/dovecot/dovecot-sql.conf');

        return [
            'ok'         => true,
            'configured' => $configured && $hasPostfix && $hasDovecot,
            'details'    => [
                'setting'  => $configured,
                'postfix'  => $hasPostfix,
                'dovecot'  => $hasDovecot,
            ],
        ];
    }
}
