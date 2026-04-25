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

        if (array_key_exists('autoresponder_enabled', $data)
            || array_key_exists('autoresponder_subject', $data)
            || array_key_exists('autoresponder_body', $data)) {
            $autoPayload = [
                'email' => $account['email'],
                'home_dir' => $account['home_dir'],
                'enabled' => !empty($data['autoresponder_enabled']),
                'subject' => (string)($data['autoresponder_subject'] ?? ''),
                'body' => (string)($data['autoresponder_body'] ?? ''),
            ];
            if ($account['mail_node_id']) {
                ClusterService::enqueue((int)$account['mail_node_id'], 'mail_update_autoresponder', $autoPayload);
            } elseif (Settings::get('mail_local_configured', '') === '1') {
                self::nodeUpdateAutoresponder($autoPayload);
            }
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

    public static function getCurrentMailMode(): string
    {
        $mode = Settings::get('mail_mode', 'full');
        return in_array($mode, ['satellite', 'relay', 'full', 'external'], true) ? $mode : 'full';
    }

    public static function getSmtpConfig(bool $includeSecret = false): array
    {
        $mode = self::getCurrentMailMode();
        $password = '';
        $enc = Settings::get('mail_smtp_password_enc', '');
        if ($includeSecret && $enc !== '') {
            try {
                $password = ReplicationService::decryptPassword($enc);
            } catch (\Throwable) {
                $password = '';
            }
        }

        if ($mode === 'external') {
            return [
                'mode' => 'external',
                'host' => Settings::get('mail_smtp_host', ''),
                'port' => (int)Settings::get('mail_smtp_port', '587'),
                'encryption' => Settings::get('mail_smtp_encryption', 'tls') ?: null,
                'username' => Settings::get('mail_smtp_user', ''),
                'password' => $includeSecret ? $password : ($enc !== '' ? '***' : ''),
                'from_address' => Settings::get('mail_from_address', ''),
                'from_name' => Settings::get('mail_from_name', ''),
            ];
        }

        $relayUserCountRow = $mode === 'relay' ? Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_relay_users") : ['cnt' => 0];

        return [
            'mode' => $mode,
            'host' => '127.0.0.1',
            'port' => 25,
            'encryption' => null,
            'username' => null,
            'password' => null,
            'from_address' => Settings::get('mail_from_address', '') ?: ('noreply@' . (Settings::get('mail_outbound_domain', '') ?: gethostname())),
            'from_name' => Settings::get('mail_from_name', ''),
            'dkim_configured' => $mode === 'relay' ? !empty(self::getRelayDomains()) : self::satelliteDkimConfigured(),
            'deliverability_score' => 'unknown',
        ];
    }

    public static function getInternalSmtpToken(bool $create = true): string
    {
        $token = Settings::get('internal_smtp_token', '');
        if ($token === '' && $create) {
            $token = bin2hex(random_bytes(32));
            Settings::set('internal_smtp_token', $token);
        }
        return $token;
    }

    private static function satelliteDkimConfigured(): bool
    {
        return Settings::get('mail_satellite_dkim_txt', '') !== ''
            || (bool)Database::fetchOne("SELECT id FROM mail_domains WHERE dkim_public_key IS NOT NULL AND dkim_public_key != '' LIMIT 1");
    }

    public static function getDeliverabilityRows(): array
    {
        $mode = self::getCurrentMailMode();
        $ip = $mode === 'relay' && Settings::get('mail_relay_public_ip', '') !== ''
            ? Settings::get('mail_relay_public_ip', '')
            : self::detectPublicIp();
        $rows = [];

        $domains = self::getDomains();
        if ($mode === 'relay') {
            foreach (self::getRelayDomains() as $relayDomain) {
                $domains[] = [
                    'id' => 0,
                    'domain' => $relayDomain['domain'],
                    'mail_node_id' => null,
                    'dkim_selector' => $relayDomain['dkim_selector'] ?: 'default',
                    'dkim_public_key' => $relayDomain['dkim_public_key'] ?? '',
                    'spf_record' => '',
                    'dmarc_policy' => 'quarantine',
                    'mail_mode' => 'relay',
                ];
            }
        }
        if (empty($domains) && in_array($mode, ['satellite', 'external'], true)) {
            $fallbackDomain = Settings::get('mail_outbound_domain', '');
            if ($fallbackDomain === '' && Settings::get('mail_from_address', '') && str_contains(Settings::get('mail_from_address', ''), '@')) {
                $fallbackDomain = substr(strrchr(Settings::get('mail_from_address', ''), '@'), 1);
            }
            if ($fallbackDomain !== '') {
                $domains[] = [
                    'id' => 0,
                    'domain' => $fallbackDomain,
                    'mail_node_id' => null,
                    'dkim_selector' => 'default',
                    'dkim_public_key' => '',
                    'spf_record' => '',
                    'dmarc_policy' => 'quarantine',
                    'mail_mode' => $mode,
                ];
            }
        }

        foreach ($domains as $domain) {
            $domainName = (string)$domain['domain'];
            $rowMode = $domain['mail_mode'] ?? $mode;
            $mailHostname = self::resolveMailHostnameForDomain($domain);
            $recommended = self::recommendedDeliverabilityRecords($domain, $ip, $mailHostname, $rowMode);
            $checks = self::runDeliverabilityChecks($domainName, $mailHostname, $ip, $recommended);
            $score = self::deliverabilityScore($checks);
            $rows[] = [
                'domain' => $domainName,
                'mode' => $rowMode,
                'mail_hostname' => $mailHostname,
                'ip' => $ip,
                'checks' => $checks,
                'recommended' => $recommended,
                'score' => $score['ok'],
                'score_total' => $score['total'],
            ];
        }

        return $rows;
    }

    private static function resolveMailHostnameForDomain(array $domain): string
    {
        if (($domain['mail_mode'] ?? '') === 'relay') {
            return Settings::get('mail_outbound_hostname', '')
                ?: Settings::get('mail_relay_host', '')
                ?: ('relay.' . (string)$domain['domain']);
        }
        if (!empty($domain['mail_node_id'])) {
            $node = ClusterService::getNode((int)$domain['mail_node_id']);
            if (!empty($node['mail_hostname'])) return (string)$node['mail_hostname'];
        }
        return Settings::get('mail_outbound_hostname', '')
            ?: Settings::get('mail_local_hostname', '')
            ?: ('mail.' . (string)$domain['domain']);
    }

    private static function recommendedDeliverabilityRecords(array $domain, string $ip, string $mailHostname, string $mode): array
    {
        $domainName = (string)$domain['domain'];
        $selector = $domain['dkim_selector'] ?: 'default';
        $dkimValue = '';
        if (!empty($domain['dkim_public_key'])) {
            $dkimValue = "v=DKIM1; k=rsa; p={$domain['dkim_public_key']}";
        } elseif (Settings::get('mail_satellite_dkim_txt', '') !== '' && Settings::get('mail_satellite_dkim_domain', '') === $domainName) {
            $txt = Settings::get('mail_satellite_dkim_txt', '');
            if (preg_match('/\\((.*)\\)/s', $txt, $m)) {
                $dkimValue = trim(str_replace(['"', "\n", "\t"], '', $m[1]));
            } else {
                $dkimValue = trim($txt);
            }
        }

        $spf = $ip !== '' ? "v=spf1 ip4:{$ip} -all" : 'v=spf1 -all';
        if ($mode === 'full') {
            $spf = $ip !== '' ? "v=spf1 mx ip4:{$ip} ~all" : 'v=spf1 mx ~all';
        }

        $records = [
            ['type' => 'TXT', 'name' => $domainName, 'value' => $spf],
            ['type' => 'TXT', 'name' => "_dmarc.{$domainName}", 'value' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domainName}"],
        ];
        if ($dkimValue !== '') {
            $records[] = ['type' => 'TXT', 'name' => "{$selector}._domainkey.{$domainName}", 'value' => $dkimValue];
        }
        if ($mailHostname !== '' && $ip !== '') {
            $records[] = ['type' => 'A', 'name' => $mailHostname, 'value' => $ip];
            $records[] = ['type' => 'PTR', 'name' => $ip, 'value' => $mailHostname];
        }
        if ($mode === 'full') {
            $records[] = ['type' => 'MX', 'name' => $domainName, 'value' => $mailHostname, 'priority' => 10];
        }
        return $records;
    }

    private static function runDeliverabilityChecks(string $domain, string $mailHostname, string $ip, array $recommended): array
    {
        $txt = self::dnsTxtValues($domain);
        $spf = '';
        foreach ($txt as $value) {
            if (str_starts_with(strtolower($value), 'v=spf1')) {
                $spf = $value;
                break;
            }
        }

        $dkimName = 'default._domainkey.' . $domain;
        $dkim = implode(' ', self::dnsTxtValues($dkimName));
        $dmarc = implode(' ', self::dnsTxtValues('_dmarc.' . $domain));
        $a = $mailHostname !== '' ? @dns_get_record($mailHostname, DNS_A) : [];
        $aIps = is_array($a) ? array_values(array_filter(array_map(fn($r) => $r['ip'] ?? '', $a))) : [];
        $ptr = $ip !== '' ? @gethostbyaddr($ip) : '';
        $ptrClean = $ptr && $ptr !== $ip ? rtrim(strtolower($ptr), '.') : '';

        return [
            'spf' => [
                'ok' => $spf !== '' && ($ip === '' || str_contains($spf, $ip) || str_contains($spf, ' mx ') || str_contains($spf, 'mx')),
                'value' => $spf,
                'message' => $spf === '' ? 'Falta SPF' : 'SPF encontrado',
            ],
            'dkim' => [
                'ok' => str_contains(strtolower($dkim), 'v=dkim1'),
                'value' => $dkim,
                'message' => $dkim === '' ? 'Falta DKIM default' : 'DKIM encontrado',
            ],
            'dmarc' => [
                'ok' => str_contains(strtolower($dmarc), 'v=dmarc1'),
                'value' => $dmarc,
                'message' => $dmarc === '' ? 'Falta DMARC' : 'DMARC encontrado',
            ],
            'a' => [
                'ok' => $ip !== '' && in_array($ip, $aIps, true),
                'value' => implode(', ', $aIps),
                'message' => $mailHostname === '' ? 'Sin hostname' : 'A record',
            ],
            'ptr' => [
                'ok' => $ptrClean !== '' && ($mailHostname === '' || $ptrClean === rtrim(strtolower($mailHostname), '.')),
                'value' => $ptrClean,
                'message' => $ptrClean === '' ? 'PTR no configurado' : 'PTR detectado',
            ],
            'blacklists' => self::checkBlacklists($ip),
        ];
    }

    private static function dnsTxtValues(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) return [];
        $values = [];
        foreach ($records as $record) {
            if (!empty($record['txt'])) $values[] = (string)$record['txt'];
            elseif (!empty($record['entries']) && is_array($record['entries'])) $values[] = implode('', $record['entries']);
        }
        return $values;
    }

    private static function detectPublicIp(): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $ip = trim((string)@file_get_contents('https://api.ipify.org', false, $ctx));
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }

    private static function checkBlacklists(string $ip): array
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => null, 'listed' => [], 'message' => 'IP no disponible'];
        }
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $lists = ['zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org', 'dnsbl.sorbs.net', 'psbl.surriel.com'];
        $listed = [];
        foreach ($lists as $list) {
            $records = @dns_get_record($reversed . '.' . $list, DNS_A);
            if (is_array($records) && !empty($records)) {
                $listed[] = $list;
            }
        }
        return ['ok' => empty($listed), 'listed' => $listed, 'message' => empty($listed) ? 'Limpio' : ('Listado en: ' . implode(', ', $listed))];
    }

    private static function deliverabilityScore(array $checks): array
    {
        $keys = ['spf', 'dkim', 'dmarc', 'ptr', 'blacklists'];
        $ok = 0;
        foreach ($keys as $key) {
            if (($checks[$key]['ok'] ?? false) === true) {
                $ok++;
            }
        }
        return ['ok' => $ok, 'total' => count($keys)];
    }

    // ═══════════════════════════════════════════════════════════
    // ─── Private Relay Domains / Users ───────────────────────
    // ═══════════════════════════════════════════════════════════

    public static function getRelayDomains(): array
    {
        try {
            return Database::fetchAll("SELECT * FROM mail_relay_domains ORDER BY domain");
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getRelayUsers(): array
    {
        try {
            return Database::fetchAll("SELECT id, username, description, enabled, rate_limit_per_hour, allowed_from_domains,
                created_at, updated_at, (COALESCE(password_encrypted, '') <> '') AS has_recoverable_password
                FROM mail_relay_users ORDER BY username");
        } catch (\Throwable) {
            return [];
        }
    }

    public static function createRelayDomain(string $domain): array
    {
        try {
            $domain = self::normalizeDomain($domain);
            if ($domain === '') return ['ok' => false, 'error' => 'Dominio no valido'];
            if (Database::fetchOne("SELECT id FROM mail_relay_domains WHERE domain = :d", ['d' => $domain])) {
                return ['ok' => false, 'error' => 'El dominio ya existe en el relay'];
            }

            $nodeResult = self::runRelayNodeAction('mail_relay_create_domain', ['domain' => $domain]);
            if (!($nodeResult['ok'] ?? false)) return $nodeResult;

            $data = $nodeResult['data'] ?? $nodeResult;
            $publicKey = (string)($data['dkim_public_key'] ?? '');
            $privateKey = (string)($data['dkim_private_key'] ?? '');
            $selector = (string)($data['dkim_selector'] ?? 'default');
            if ($publicKey === '' || $privateKey === '') {
                return ['ok' => false, 'error' => 'OpenDKIM creo el dominio, pero no devolvio clave DKIM completa. Revisa permisos de /etc/opendkim/keys.'];
            }

            $checks = self::checkRelayDomainDns($domain, $publicKey);
            $active = ($checks['spf']['ok'] ?? false) && ($checks['dkim']['ok'] ?? false) && ($checks['dmarc']['ok'] ?? false);

            Database::insert('mail_relay_domains', [
                'domain' => $domain,
                'dkim_selector' => $selector,
                'dkim_private_key' => ReplicationService::encryptPassword($privateKey),
                'dkim_public_key' => $publicKey,
                'spf_verified' => self::pgBool($checks['spf']['ok'] ?? false),
                'dkim_verified' => self::pgBool($checks['dkim']['ok'] ?? false),
                'dmarc_verified' => self::pgBool($checks['dmarc']['ok'] ?? false),
                'status' => $active ? 'active' : 'pending',
                'last_dns_check_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return ['ok' => true, 'domain' => $domain, 'dkim_public_key' => $publicKey, 'dkim_txt' => self::dkimTxtValue($publicKey)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error creando dominio relay: ' . $e->getMessage()];
        }
    }

    public static function refreshRelayDomain(int $id): array
    {
        $row = Database::fetchOne("SELECT * FROM mail_relay_domains WHERE id = :id", ['id' => $id]);
        if (!$row) return ['ok' => false, 'error' => 'Dominio no encontrado'];

        $checks = self::checkRelayDomainDns((string)$row['domain'], (string)($row['dkim_public_key'] ?? ''));
        $active = ($checks['spf']['ok'] ?? false) && ($checks['dkim']['ok'] ?? false) && ($checks['dmarc']['ok'] ?? false);
        Database::update('mail_relay_domains', [
            'spf_verified' => self::pgBool($checks['spf']['ok'] ?? false),
            'dkim_verified' => self::pgBool($checks['dkim']['ok'] ?? false),
            'dmarc_verified' => self::pgBool($checks['dmarc']['ok'] ?? false),
            'status' => $active ? 'active' : 'pending',
            'last_dns_check_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        return ['ok' => true, 'checks' => $checks, 'status' => $active ? 'active' : 'pending'];
    }

    public static function deleteRelayDomain(int $id): array
    {
        $row = Database::fetchOne("SELECT * FROM mail_relay_domains WHERE id = :id", ['id' => $id]);
        if (!$row) return ['ok' => false, 'error' => 'Dominio no encontrado'];

        $nodeResult = self::runRelayNodeAction('mail_relay_delete_domain', ['domain' => $row['domain']]);
        if (!($nodeResult['ok'] ?? false)) return $nodeResult;
        Database::delete('mail_relay_domains', 'id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    public static function createRelayUser(string $username, string $description, int $limit, string $domains = ''): array
    {
        try {
            $username = strtolower(trim($username));
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,120}$/', $username)) {
                return ['ok' => false, 'error' => 'Usuario no valido. Usa letras, numeros, punto, guion o guion bajo.'];
            }
            if (Database::fetchOne("SELECT id FROM mail_relay_users WHERE username = :u", ['u' => $username])) {
                return ['ok' => false, 'error' => 'El usuario ya existe'];
            }

            $password = bin2hex(random_bytes(24));
            $nodeResult = self::runRelayNodeAction('mail_relay_create_user', ['username' => $username, 'password' => $password]);
            if (!($nodeResult['ok'] ?? false)) return $nodeResult;

            Database::insert('mail_relay_users', [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'password_encrypted' => ReplicationService::encryptPassword($password),
                'description' => trim($description),
                'enabled' => true,
                'rate_limit_per_hour' => max(1, $limit),
                'allowed_from_domains' => trim($domains),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return ['ok' => true, 'username' => $username, 'password' => $password];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error creando usuario relay: ' . $e->getMessage()];
        }
    }

    public static function getMailMigrations(int $limit = 10): array
    {
        try {
            $limit = max(1, min(50, $limit));
            return Database::fetchAll("SELECT mm.*, src.name AS source_node_name, dst.name AS target_node_name
                FROM mail_migrations mm
                LEFT JOIN cluster_nodes src ON src.id = mm.source_node_id
                LEFT JOIN cluster_nodes dst ON dst.id = mm.target_node_id
                ORDER BY mm.created_at DESC
                LIMIT {$limit}");
        } catch (\Throwable) {
            return [];
        }
    }

    public static function createMailMigrationPreflight(string $mode, int $targetNodeId, ?int $createdBy = null): array
    {
        $mode = self::normalizeMailMigrationMode($mode);
        if ($mode === '') return ['ok' => false, 'error' => 'Modo de migracion no valido'];

        $target = ClusterService::getNode($targetNodeId);
        if (!$target) return ['ok' => false, 'error' => 'Nodo destino no encontrado'];

        $progress = self::buildMailMigrationPreflight($mode, $target);
        $status = empty($progress['blocking']) ? 'preflight_ok' : 'blocked';
        $id = self::insertMailMigration([
            'mode' => $mode,
            'source_node_id' => self::currentMailSourceNodeId($mode),
            'target_node_id' => $targetNodeId,
            'status' => $status,
            'stage' => 'preflight',
            'dry_run' => true,
            'switch_routing' => false,
            'domains_json' => json_encode($progress['domains'] ?? []),
            'progress_json' => json_encode($progress),
            'error_message' => $progress['summary'] ?? null,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'id' => $id, 'status' => $status, 'progress' => $progress];
    }

    public static function migrateRelayToNode(int $targetNodeId, bool $switchRouting, ?int $createdBy = null): array
    {
        $target = ClusterService::getNode($targetNodeId);
        if (!$target) return ['ok' => false, 'error' => 'Nodo destino no encontrado'];

        $preflight = self::buildMailMigrationPreflight('relay', $target);
        if (!empty($preflight['blocking'])) {
            $id = self::insertMailMigration([
                'mode' => 'relay',
                'source_node_id' => self::currentMailSourceNodeId('relay'),
                'target_node_id' => $targetNodeId,
                'status' => 'blocked',
                'stage' => 'preflight',
                'dry_run' => false,
                'switch_routing' => $switchRouting,
                'domains_json' => json_encode($preflight['domains'] ?? []),
                'progress_json' => json_encode($preflight),
                'error_message' => $preflight['summary'] ?? 'Preflight bloqueado',
                'created_by' => $createdBy,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => false, 'id' => $id, 'error' => $preflight['summary'] ?? 'Preflight bloqueado', 'progress' => $preflight];
        }

        $id = self::insertMailMigration([
            'mode' => 'relay',
            'source_node_id' => self::currentMailSourceNodeId('relay'),
            'target_node_id' => $targetNodeId,
            'status' => 'running',
            'stage' => 'importing_relay',
            'dry_run' => false,
            'switch_routing' => $switchRouting,
            'domains_json' => json_encode($preflight['domains'] ?? []),
            'progress_json' => json_encode($preflight),
            'created_by' => $createdBy,
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $progress = $preflight;
        $progress['imported_domains'] = [];
        $progress['imported_users'] = [];

        try {
            foreach (Database::fetchAll("SELECT * FROM mail_relay_domains ORDER BY domain") as $domain) {
                $privateKey = self::decryptOrPlainPem((string)($domain['dkim_private_key'] ?? ''));
                if ($privateKey === '') {
                    throw new \RuntimeException('No se puede migrar DKIM de ' . $domain['domain'] . ': clave privada no recuperable');
                }
                $result = self::callMailNodeAction($target, 'mail_relay_import_domain', [
                    'domain' => (string)$domain['domain'],
                    'selector' => (string)($domain['dkim_selector'] ?? 'default'),
                    'dkim_private_key' => $privateKey,
                    'dkim_public_key' => (string)($domain['dkim_public_key'] ?? ''),
                ], 120);
                if (!($result['ok'] ?? false)) {
                    throw new \RuntimeException('Error importando dominio relay ' . $domain['domain'] . ': ' . ($result['error'] ?? 'error remoto'));
                }
                $progress['imported_domains'][] = (string)$domain['domain'];
            }

            foreach (Database::fetchAll("SELECT * FROM mail_relay_users ORDER BY username") as $user) {
                $password = ReplicationService::decryptPassword((string)($user['password_encrypted'] ?? ''));
                if ($password === '') {
                    throw new \RuntimeException('Usuario relay legacy sin password recuperable: ' . $user['username'] . '. Regenera ese usuario antes de migrar.');
                }
                $result = self::callMailNodeAction($target, 'mail_relay_import_user', [
                    'username' => (string)$user['username'],
                    'password' => $password,
                ], 120);
                if (!($result['ok'] ?? false)) {
                    throw new \RuntimeException('Error importando usuario relay ' . $user['username'] . ': ' . ($result['error'] ?? 'error remoto'));
                }
                $progress['imported_users'][] = (string)$user['username'];
            }

            if ($switchRouting) {
                Settings::set('mail_setup_node_id', (string)$targetNodeId);
                $progress['routing_switched'] = true;
            }

            Database::update('mail_migrations', [
                'status' => 'completed',
                'stage' => 'completed',
                'progress_json' => json_encode($progress),
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);

            return ['ok' => true, 'id' => $id, 'progress' => $progress];
        } catch (\Throwable $e) {
            $progress['error'] = $e->getMessage();
            Database::update('mail_migrations', [
                'status' => 'failed',
                'stage' => 'failed',
                'progress_json' => json_encode($progress),
                'error_message' => $e->getMessage(),
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage(), 'progress' => $progress];
        }
    }

    public static function deleteRelayUser(int $id): array
    {
        $row = Database::fetchOne("SELECT * FROM mail_relay_users WHERE id = :id", ['id' => $id]);
        if (!$row) return ['ok' => false, 'error' => 'Usuario no encontrado'];

        $nodeResult = self::runRelayNodeAction('mail_relay_delete_user', ['username' => $row['username']]);
        if (!($nodeResult['ok'] ?? false)) return $nodeResult;
        Database::delete('mail_relay_users', 'id = :id', ['id' => $id]);
        return ['ok' => true];
    }

    public static function getRelayLogEntries(int $limit = 30, int $offset = 0): array
    {
        $page = self::getRelayLogPage(max(1, intdiv(max(0, $offset), max(1, $limit)) + 1), $limit);
        return $page['entries'];
    }

    public static function getRelayLogPage(int $page = 1, int $perPage = 25): array
    {
        $file = is_readable('/var/log/mail.log') ? '/var/log/mail.log' : (is_readable('/var/log/maillog') ? '/var/log/maillog' : '');
        if ($file === '') {
            return ['entries' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1];
        }

        $page = max(1, $page);
        $perPage = max(5, min(1000, $perPage));
        $scanLines = (int)min(60000, max(2500, ($perPage * 30), ($page * $perPage * 3)));
        $lines = explode("\n", trim((string)shell_exec('tail -n ' . $scanLines . ' ' . escapeshellarg($file) . ' 2>/dev/null')));
        $entries = [];
        foreach (array_reverse($lines) as $line) {
            if (!str_contains($line, 'postfix/') || !preg_match('/status=(sent|deferred|bounced)/', $line, $sm)) continue;
            preg_match('/from=<([^>]*)>/', $line, $fm);
            preg_match('/to=<([^>]*)>/', $line, $tm);
            preg_match('/relay=([^, ]+)/', $line, $rm);
            preg_match('/dsn=([^, ]+)/', $line, $dm);
            $from = $fm[1] ?? '';
            $domain = str_contains($from, '@') ? substr(strrchr($from, '@'), 1) : '';
            $detail = '';
            if (preg_match('/status=(?:sent|deferred|bounced)\s+\((.*)\)\s*$/', $line, $detailMatch)) {
                $detail = $detailMatch[1];
            }
            $entries[] = [
                'timestamp' => substr($line, 0, 15),
                'domain' => $domain,
                'from' => $from,
                'to' => $tm[1] ?? '',
                'status' => $sm[1],
                'relay' => $rm[1] ?? '',
                'dsn' => $dm[1] ?? '',
                'detail' => $detail,
                'line' => $line,
            ];
        }

        $total = count($entries);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        return [
            'entries' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    public static function clearRelayLog(): array
    {
        $candidates = ['/var/log/mail.log', '/var/log/maillog'];
        $targets = [];
        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $real = realpath($candidate) ?: $candidate;
            $targets[$real] = $real;
        }

        if (empty($targets)) {
            return ['ok' => false, 'error' => 'No se encontro mail.log/maillog en este nodo.'];
        }

        $errors = [];
        $cleared = [];
        foreach ($targets as $path) {
            $ok = @file_put_contents($path, '') !== false;
            if (!$ok) {
                $output = trim((string)shell_exec('truncate -s 0 ' . escapeshellarg($path) . ' 2>&1'));
                $ok = $output === '';
                if (!$ok) {
                    $errors[] = basename($path) . ': ' . $output;
                }
            }
            if ($ok) {
                $cleared[] = $path;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'error' => implode(' | ', $errors)];
        }

        return ['ok' => true, 'files' => $cleared];
    }

    public static function getMailQueueEntries(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $out = [];
        $code = 0;
        exec('postqueue -j 2>/dev/null', $out, $code);
        if ($code !== 0 || empty($out)) {
            return [];
        }

        $entries = [];
        foreach ($out as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }

            $recipients = [];
            foreach (($row['recipients'] ?? []) as $recipient) {
                if (is_array($recipient)) {
                    $recipients[] = (string)($recipient['address'] ?? $recipient['recipient'] ?? '');
                } else {
                    $recipients[] = (string)$recipient;
                }
            }
            $recipients = array_values(array_filter($recipients, static fn($v) => $v !== ''));
            $arrival = (int)($row['arrival_time'] ?? 0);

            $entries[] = [
                'queue_id' => (string)($row['queue_id'] ?? ''),
                'queue_name' => (string)($row['queue_name'] ?? ''),
                'arrival_time' => $arrival > 0 ? date('M d H:i:s', $arrival) : '-',
                'size' => (int)($row['message_size'] ?? 0),
                'sender' => (string)($row['sender'] ?? ''),
                'recipients' => $recipients,
                'reason' => (string)($row['reason'] ?? ''),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    public static function flushMailQueue(): array
    {
        return self::runPostfixQueueCommand('postqueue -f 2>&1');
    }

    public static function deleteMailQueue(string $scope): array
    {
        $target = $scope === 'deferred' ? 'ALL deferred' : 'ALL';
        return self::runPostfixQueueCommand('postsuper -d ' . $target . ' 2>&1');
    }

    public static function deleteMailQueueMessage(string $queueId): array
    {
        $queueId = strtoupper(trim($queueId));
        if (!preg_match('/^[A-F0-9]{5,32}[*!]?$/', $queueId)) {
            return ['ok' => false, 'error' => 'Queue ID no valido'];
        }

        return self::runPostfixQueueCommand('postsuper -d ' . escapeshellarg(rtrim($queueId, '*!')) . ' 2>&1');
    }

    private static function runPostfixQueueCommand(string $cmd): array
    {
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $output = trim(implode("\n", $out));
        return [
            'ok' => $code === 0,
            'output' => $output,
            'error' => $code === 0 ? null : ($output !== '' ? $output : "exit {$code}"),
        ];
    }

    private static function normalizeMailMigrationMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['satellite', 'relay', 'full'], true) ? $mode : '';
    }

    private static function decryptOrPlainPem(string $stored): string
    {
        $decrypted = ReplicationService::decryptPassword($stored);
        if ($decrypted !== '') return $decrypted;
        return str_contains($stored, 'BEGIN') ? $stored : '';
    }

    private static function pgBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 't' : 'f';
    }

    private static function currentMailSourceNodeId(string $mode): ?int
    {
        $setting = Settings::get('mail_setup_node_id', 'local');
        if ($mode === 'relay' && ctype_digit((string)$setting)) {
            return (int)$setting;
        }
        return null;
    }

    private static function insertMailMigration(array $data): int
    {
        $columns = array_keys($data);
        $quoted = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn($k) => ':' . $k, $columns));
        $stmt = Database::query("INSERT INTO mail_migrations ({$quoted}) VALUES ({$placeholders}) RETURNING id", $data);
        return (int)$stmt->fetchColumn();
    }

    private static function callMailNodeAction(array $node, string $action, array $payload, int $timeout = 60): array
    {
        $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
        $result = ClusterService::callNodeDirect((string)$node['api_url'], $token, 'POST', 'api/cluster/action', [
            'action' => $action,
            'payload' => $payload,
        ], $timeout, [
            'metadata' => $node['metadata'] ?? null,
            'node_id' => (int)($node['id'] ?? 0),
        ]);
        return $result['data'] ?? ['ok' => false, 'error' => $result['error'] ?? 'Remote action failed'];
    }

    private static function buildMailMigrationPreflight(string $mode, array $target): array
    {
        $blocking = [];
        $warnings = [];
        $domains = [];
        $sourceNodeId = self::currentMailSourceNodeId($mode);

        if ($sourceNodeId !== null && $sourceNodeId === (int)$target['id']) {
            $blocking[] = 'El nodo destino ya es el nodo activo para este modo.';
        }

        if ($mode === 'relay') {
            $domains = array_map(static fn($r) => $r['domain'], Database::fetchAll("SELECT domain FROM mail_relay_domains ORDER BY domain"));
            $legacy = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_relay_users WHERE COALESCE(password_encrypted, '') = ''");
            if ((int)($legacy['cnt'] ?? 0) > 0) {
                $blocking[] = (int)$legacy['cnt'] . ' usuario(s) relay legacy no tienen password recuperable. Regeneralos antes de migrar.';
            }
            $missingDkim = Database::fetchOne("SELECT COUNT(*) AS cnt FROM mail_relay_domains WHERE COALESCE(dkim_private_key, '') = ''");
            if ((int)($missingDkim['cnt'] ?? 0) > 0) {
                $blocking[] = (int)$missingDkim['cnt'] . ' dominio(s) relay no tienen clave DKIM privada recuperable.';
            }
        } elseif ($mode === 'full') {
            $domains = array_map(static fn($r) => $r['domain'], Database::fetchAll("SELECT domain FROM mail_domains ORDER BY domain"));
            $blocking[] = 'La migracion full mail requiere rsync de Maildirs, pausa de cola y corte controlado. En esta version solo se ejecuta preflight.';
        } elseif ($mode === 'satellite') {
            $warnings[] = 'Satellite no tiene buzones. La migracion copia/recrea Postfix + OpenDKIM; confirma DKIM antes de cambiar el emisor.';
        }

        $remote = self::callMailNodeAction($target, 'mail_migration_preflight', ['mode' => $mode], 30);
        if (!($remote['ok'] ?? false)) {
            $blocking[] = 'No se pudo consultar el nodo destino: ' . ($remote['error'] ?? 'error remoto');
        } else {
            $remoteData = $remote['data'] ?? $remote;
            foreach (($remoteData['blocking'] ?? []) as $issue) {
                $blocking[] = (string)$issue;
            }
            foreach (($remoteData['warnings'] ?? []) as $warning) {
                $warnings[] = (string)$warning;
            }
        }

        return [
            'summary' => empty($blocking) ? 'Preflight correcto' : implode(' ', $blocking),
            'mode' => $mode,
            'source_node_id' => $sourceNodeId,
            'target_node_id' => (int)$target['id'],
            'target_node' => $target['name'] ?? ('#' . (int)$target['id']),
            'domains' => $domains,
            'domain_count' => count($domains),
            'relay_user_count' => (int)($relayUserCountRow['cnt'] ?? 0),
            'blocking' => $blocking,
            'warnings' => $warnings,
            'remote' => $remote['data'] ?? $remote,
            'checked_at' => gmdate('c'),
        ];
    }

    private static function runRelayNodeAction(string $action, array $payload): array
    {
        $target = Settings::get('mail_setup_node_id', 'local');
        if ($target !== '' && $target !== 'local' && ctype_digit($target)) {
            $node = ClusterService::getNode((int)$target);
            if ($node) {
                $token = ReplicationService::decryptPassword($node['auth_token'] ?? '');
                $result = ClusterService::callNodeDirect($node['api_url'], $token, 'POST', 'api/cluster/action', [
                    'action' => $action,
                    'payload' => $payload,
                ], 60, ['metadata' => $node['metadata'] ?? null]);
                return $result['data'] ?? ['ok' => false, 'error' => $result['error'] ?? 'Remote action failed'];
            }
        }

        return match ($action) {
            'mail_relay_create_domain' => self::nodeRelayCreateDomain($payload),
            'mail_relay_delete_domain' => self::nodeRelayDeleteDomain($payload),
            'mail_relay_create_user' => self::nodeRelayCreateUser($payload),
            'mail_relay_delete_user' => self::nodeRelayDeleteUser($payload),
            'mail_relay_import_domain' => self::nodeRelayImportDomain($payload),
            'mail_relay_import_user' => self::nodeRelayImportUser($payload),
            'mail_migration_preflight' => self::nodeMailMigrationPreflight($payload),
            default => ['ok' => false, 'error' => 'Unknown relay action'],
        };
    }

    public static function nodeRelayCreateDomain(array $payload): array
    {
        $domain = self::normalizeDomain((string)($payload['domain'] ?? ''));
        if ($domain === '') return ['ok' => false, 'error' => 'Dominio no valido'];

        $selector = 'default';
        $dkimDir = "/etc/opendkim/keys/{$domain}";
        @mkdir($dkimDir, 0700, true);
        if (!is_file("{$dkimDir}/{$selector}.private")) {
            exec('opendkim-genkey -b 2048 -D ' . escapeshellarg($dkimDir) . ' -d ' . escapeshellarg($domain) . ' -s ' . escapeshellarg($selector) . ' 2>&1', $out, $code);
            if ($code !== 0) return ['ok' => false, 'error' => implode("\n", $out)];
        }
        shell_exec('chown -R opendkim:opendkim ' . escapeshellarg($dkimDir) . ' 2>&1');

        self::ensureRelayOpenDkimFiles(Settings::get('mail_relay_wireguard_cidr', '10.10.70.0/24'));
        self::upsertLine('/etc/opendkim/signing.table', "*@{$domain} {$selector}._domainkey.{$domain}", "*@{$domain}");
        self::upsertLine('/etc/opendkim/key.table', "{$selector}._domainkey.{$domain} {$domain}:{$selector}:{$dkimDir}/{$selector}.private", "{$selector}._domainkey.{$domain}");
        shell_exec('systemctl reload opendkim 2>&1 || systemctl restart opendkim 2>&1');

        $private = is_file("{$dkimDir}/{$selector}.private") ? (string)file_get_contents("{$dkimDir}/{$selector}.private") : '';
        $public = self::parseDkimPublicKey(is_file("{$dkimDir}/{$selector}.txt") ? (string)file_get_contents("{$dkimDir}/{$selector}.txt") : '');
        return ['ok' => true, 'domain' => $domain, 'dkim_selector' => $selector, 'dkim_private_key' => $private, 'dkim_public_key' => $public];
    }

    public static function nodeRelayDeleteDomain(array $payload): array
    {
        $domain = self::normalizeDomain((string)($payload['domain'] ?? ''));
        if ($domain === '') return ['ok' => false, 'error' => 'Dominio no valido'];
        self::removeMatchingLines('/etc/opendkim/signing.table', $domain);
        self::removeMatchingLines('/etc/opendkim/key.table', $domain);
        shell_exec('systemctl reload opendkim 2>&1 || true');
        return ['ok' => true];
    }

    public static function nodeMailMigrationPreflight(array $payload): array
    {
        $mode = self::normalizeMailMigrationMode((string)($payload['mode'] ?? ''));
        if ($mode === '') return ['ok' => false, 'error' => 'Modo de migracion no valido'];

        $blocking = [];
        $warnings = [];
        $cmdOk = static fn(string $cmd): bool => trim((string)shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null')) !== '';
        $checks = [
            'postfix' => $cmdOk('postfix') || is_file('/usr/sbin/postfix'),
            'opendkim' => $cmdOk('opendkim') || is_file('/usr/sbin/opendkim'),
            'saslpasswd2' => $cmdOk('saslpasswd2'),
            'rsync' => $cmdOk('rsync'),
            'maildirs' => is_dir('/var/mail/vhosts'),
            'sasldb2' => is_file('/etc/sasldb2'),
        ];

        if (in_array($mode, ['relay', 'satellite', 'full'], true)) {
            if (!$checks['postfix']) $blocking[] = 'Postfix no esta instalado en el nodo destino.';
            if (!$checks['opendkim']) $blocking[] = 'OpenDKIM no esta instalado en el nodo destino.';
        }
        if ($mode === 'relay' && !$checks['saslpasswd2']) {
            $blocking[] = 'saslpasswd2 no esta disponible; no se pueden importar usuarios SMTP.';
        }
        if ($mode === 'full') {
            if (!$checks['rsync']) $blocking[] = 'rsync no esta instalado; no se pueden copiar Maildirs.';
            if (!$checks['maildirs']) $warnings[] = '/var/mail/vhosts no existe todavia; se creara al preparar el nodo.';
        }

        $df = trim((string)shell_exec("df -Pm /var/mail 2>/dev/null | awk 'NR==2{print $4}'"));
        $freeMb = is_numeric($df) ? (int)$df : null;

        return [
            'ok' => empty($blocking),
            'mode' => $mode,
            'hostname' => gethostname() ?: php_uname('n'),
            'mail_mode_setting' => Settings::get('mail_mode', ''),
            'mail_setup_node_id' => Settings::get('mail_setup_node_id', 'local'),
            'checks' => $checks,
            'free_mb' => $freeMb,
            'blocking' => $blocking,
            'warnings' => $warnings,
        ];
    }

    public static function nodeRelayImportDomain(array $payload): array
    {
        $domain = self::normalizeDomain((string)($payload['domain'] ?? ''));
        $selector = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($payload['selector'] ?? 'default')) ?: 'default';
        $private = (string)($payload['dkim_private_key'] ?? '');
        $public = preg_replace('/\s+/', '', (string)($payload['dkim_public_key'] ?? ''));
        if ($domain === '' || $private === '') {
            return ['ok' => false, 'error' => 'Dominio o clave DKIM no validos'];
        }

        $dkimDir = "/etc/opendkim/keys/{$domain}";
        @mkdir($dkimDir, 0700, true);
        file_put_contents("{$dkimDir}/{$selector}.private", $private);
        chmod("{$dkimDir}/{$selector}.private", 0600);
        if ($public !== '') {
            file_put_contents("{$dkimDir}/{$selector}.txt", "{$selector}._domainkey IN TXT \"v=DKIM1; k=rsa; p={$public}\"\n");
        }
        shell_exec('chown -R opendkim:opendkim ' . escapeshellarg($dkimDir) . ' 2>&1');

        self::ensureRelayOpenDkimFiles(Settings::get('mail_relay_wireguard_cidr', '10.10.70.0/24'));
        self::upsertLine('/etc/opendkim/signing.table', "*@{$domain} {$selector}._domainkey.{$domain}", "*@{$domain}");
        self::upsertLine('/etc/opendkim/key.table', "{$selector}._domainkey.{$domain} {$domain}:{$selector}:{$dkimDir}/{$selector}.private", "{$selector}._domainkey.{$domain}");
        shell_exec('systemctl reload opendkim 2>&1 || systemctl restart opendkim 2>&1 || true');

        return ['ok' => true, 'domain' => $domain, 'selector' => $selector];
    }

    public static function nodeRelayCreateUser(array $payload): array
    {
        $username = strtolower(trim((string)($payload['username'] ?? '')));
        $password = (string)($payload['password'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,120}$/', $username) || $password === '') {
            return ['ok' => false, 'error' => 'Usuario/password no validos'];
        }
        $realm = self::relaySaslRealm();
        @mkdir('/etc/postfix/sasl', 0755, true);
        file_put_contents('/etc/postfix/sasl/smtpd.conf', "pwcheck_method: auxprop\nauxprop_plugin: sasldb\nmech_list: PLAIN LOGIN\n");
        $cmd = 'printf %s\\\\n ' . escapeshellarg($password) . ' | saslpasswd2 -p -c -u ' . escapeshellarg($realm) . ' ' . escapeshellarg($username) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0) return ['ok' => false, 'error' => implode("\n", $out)];
        shell_exec('usermod -aG sasl postfix 2>/dev/null || true');
        shell_exec('chown root:postfix /etc/sasldb2 2>/dev/null || chgrp postfix /etc/sasldb2 2>/dev/null || true; chmod 0640 /etc/sasldb2 2>/dev/null || true');
        shell_exec('systemctl restart postfix 2>&1 || true');
        return ['ok' => true, 'username' => $username, 'realm' => $realm];
    }

    public static function nodeRelayImportUser(array $payload): array
    {
        return self::nodeRelayCreateUser($payload);
    }

    public static function nodeRelayDeleteUser(array $payload): array
    {
        $username = strtolower(trim((string)($payload['username'] ?? '')));
        if ($username === '') return ['ok' => false, 'error' => 'Usuario no valido'];
        $realm = self::relaySaslRealm();
        exec('saslpasswd2 -d -u ' . escapeshellarg($realm) . ' ' . escapeshellarg($username) . ' 2>&1', $out, $code);
        return ['ok' => $code === 0, 'error' => $code === 0 ? '' : implode("\n", $out)];
    }

    private static function relaySaslRealm(): string
    {
        $realm = Settings::get('mail_outbound_domain', '') ?: Settings::get('mail_relay_domain', '');
        if ($realm === '') {
            $realm = trim((string)shell_exec('postconf -h mydomain 2>/dev/null'));
        }
        if ($realm === '') {
            $host = Settings::get('mail_outbound_hostname', '') ?: Settings::get('mail_relay_host', '') ?: (gethostname() ?: 'relay');
            $parts = explode('.', $host);
            $realm = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
        }
        return strtolower($realm);
    }

    private static function checkRelayDomainDns(string $domain, string $publicKey = ''): array
    {
        $ip = Settings::get('mail_relay_public_ip', '') ?: self::detectPublicIp();
        $host = Settings::get('mail_outbound_hostname', '') ?: Settings::get('mail_relay_host', '');
        $checks = self::runDeliverabilityChecks($domain, $host, $ip, []);
        if ($publicKey !== '') {
            $dkimTxt = strtolower((string)($checks['dkim']['value'] ?? ''));
            $checks['dkim']['ok'] = str_contains($dkimTxt, strtolower(substr($publicKey, 0, 32)));
        }
        return $checks;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) ? $domain : '';
    }

    private static function parseDkimPublicKey(string $txt): string
    {
        $txt = trim($txt);
        if (preg_match('/\\((.*)\\)/s', $txt, $m)) $txt = $m[1];
        $txt = str_replace(['"', "\n", "\r", "\t", ' '], '', $txt);
        return preg_match('/p=([^;]+)/', $txt, $m) ? $m[1] : '';
    }

    private static function dkimTxtValue(string $publicKey): string
    {
        return $publicKey !== '' ? "v=DKIM1; k=rsa; p={$publicKey}" : '';
    }

    private static function ensureRelayOpenDkimFiles(string $wgCidr): void
    {
        @mkdir('/etc/opendkim/keys', 0700, true);
        foreach (['/etc/opendkim/key.table', '/etc/opendkim/signing.table'] as $file) {
            if (!is_file($file)) @touch($file);
        }
        file_put_contents('/etc/opendkim/trusted.hosts', "127.0.0.1\n::1\nlocalhost\n{$wgCidr}\n");
    }

    private static function upsertLine(string $file, string $line, string $needle): void
    {
        $content = is_file($file) ? (string)file_get_contents($file) : '';
        $lines = array_filter(explode("\n", $content), static fn($l) => trim($l) !== '' && !str_contains($l, $needle));
        $lines[] = $line;
        file_put_contents($file, implode("\n", $lines) . "\n");
    }

    private static function removeMatchingLines(string $file, string $needle): void
    {
        if (!is_file($file)) return;
        $lines = array_filter(explode("\n", (string)file_get_contents($file)), static fn($l) => trim($l) !== '' && !str_contains($l, $needle));
        file_put_contents($file, implode("\n", $lines) . "\n");
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

    public static function nodeEnableSieve(array $payload = []): array
    {
        $commands = [
            'apt-get update -qq',
            'DEBIAN_FRONTEND=noninteractive apt-get install -yqq dovecot-sieve dovecot-managesieved',
        ];
        $errors = [];
        foreach ($commands as $cmd) {
            exec($cmd . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $errors[] = $cmd . ': ' . implode("\n", array_slice($out, -8));
            }
        }

        @mkdir('/etc/dovecot/sieve', 0755, true);
        file_put_contents('/etc/dovecot/sieve/default.sieve', "require [\"fileinto\"];\n");
        shell_exec('sievec /etc/dovecot/sieve/default.sieve 2>/dev/null || true');

        $conf = "# MuseDock Sieve/ManageSieve configuration\n"
            . "protocols = \$protocols sieve\n\n"
            . "mail_plugins = \$mail_plugins sieve\n\n"
            . "protocol lmtp {\n"
            . "  mail_plugins = \$mail_plugins sieve\n"
            . "}\n\n"
            . "plugin {\n"
            . "  sieve = file:~/sieve;active=~/.dovecot.sieve\n"
            . "  sieve_default = /etc/dovecot/sieve/default.sieve\n"
            . "  sieve_global_extensions = +vacation +copy +include\n"
            . "}\n\n"
            . "service managesieve-login {\n"
            . "  inet_listener sieve {\n"
            . "    port = 4190\n"
            . "  }\n"
            . "}\n";
        file_put_contents('/etc/dovecot/conf.d/90-musedock-sieve.conf', $conf);
        exec('systemctl restart dovecot 2>&1', $restartOut, $restartCode);
        if ($restartCode !== 0) {
            $errors[] = 'restart dovecot: ' . implode("\n", array_slice($restartOut, -8));
        }
        return empty($errors)
            ? ['ok' => true, 'message' => 'Sieve/ManageSieve enabled']
            : ['ok' => false, 'error' => implode('; ', $errors)];
    }

    public static function nodeUpdateAutoresponder(array $payload): array
    {
        $email = (string)($payload['email'] ?? '');
        $homeDir = (string)($payload['home_dir'] ?? '');
        $enabled = !empty($payload['enabled']);
        $subject = trim((string)($payload['subject'] ?? ''));
        $body = trim((string)($payload['body'] ?? ''));

        if ($email === '' || $homeDir === '') {
            return ['ok' => false, 'error' => 'Missing email or home_dir'];
        }
        if (!str_starts_with($homeDir, '/var/mail/vhosts/')) {
            return ['ok' => false, 'error' => 'Invalid home_dir path'];
        }

        $sieveDir = $homeDir . '/sieve';
        $scriptFile = $sieveDir . '/musedock-autoresponder.sieve';
        $activeFile = $homeDir . '/.dovecot.sieve';
        if (!$enabled) {
            @unlink($scriptFile);
            if (is_link($activeFile) && readlink($activeFile) === $scriptFile) {
                @unlink($activeFile);
            }
            shell_exec(sprintf('chown -R vmail:vmail %s 2>&1', escapeshellarg($homeDir)));
            return ['ok' => true, 'message' => "Autoresponder disabled: {$email}"];
        }

        if ($subject === '') $subject = 'Auto-reply';
        if ($body === '') $body = "I am currently unavailable and will reply as soon as possible.";
        if (!is_dir($sieveDir)) {
            mkdir($sieveDir, 0700, true);
        }
        $escapeSieve = static function (string $value): string {
            return str_replace(['\\', '"', "\r"], ['\\\\', '\"', ''], $value);
        };
        $script = "# MuseDock autoresponder - managed by panel\n"
            . "require [\"vacation\"];\n\n"
            . "vacation\n"
            . "  :days 1\n"
            . "  :subject \"" . $escapeSieve($subject) . "\"\n"
            . "  \"" . $escapeSieve($body) . "\";\n";
        file_put_contents($scriptFile, $script);
        @unlink($activeFile);
        symlink($scriptFile, $activeFile);
        shell_exec(sprintf('sievec %s 2>&1', escapeshellarg($scriptFile)));
        shell_exec(sprintf('chown -R vmail:vmail %s 2>&1', escapeshellarg($homeDir)));
        return ['ok' => true, 'message' => "Autoresponder enabled: {$email}"];
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

        $mailMode = in_array(($node['mail_mode'] ?? 'full'), ['satellite', 'relay', 'full', 'external'], true)
            ? (string)$node['mail_mode']
            : 'full';
        $services = [];
        $checks = $mailMode === 'full'
            ? [
                'smtp'       => 25,
                'submission' => 587,
                'imaps'      => 993,
            ]
            : [];

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
            'mode' => $mailMode,
            'pg_alive' => $mailMode !== 'full',
            'pg_read_ok' => $mailMode !== 'full',
            'is_replica' => false,
            'replication_lag_seconds' => null,
            'maildir_ok' => true,
            'mail_domains_count' => 0,
            'ptr_ok' => null,
            'ptr_value' => '',
            'expected_hostname' => (string)($node['mail_hostname'] ?? ''),
            'timestamp' => gmdate('c'),
        ];

        if ($mailMode !== 'full') {
            $dbHealth['message'] = match ($mailMode) {
                'satellite' => 'Modo Solo Envio: no usa PostgreSQL/Dovecot para buzones y no abre puertos de entrada.',
                'relay' => 'Modo Relay Privado: no usa DB de buzones; debe escuchar 587 solo en WireGuard.',
                default => 'Modo SMTP Externo: no hay servidor de correo local que comprobar.',
            };
            $dbHealth = array_merge($dbHealth, self::checkPtr([
                'node_host' => $host,
                'expected_hostname' => (string)($node['mail_hostname'] ?? ''),
            ]));
        } elseif ($apiResult['ok']) {
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
        $mode = (string)($health['mode'] ?? 'full');
        if (in_array($mode, ['satellite', 'relay', 'external'], true)) {
            return [
                'status' => 'active',
                'pause_queue' => false,
                'reason' => '',
                'message' => match ($mode) {
                    'satellite' => 'Modo Solo Envio activo: no requiere DB de buzones ni puertos entrantes.',
                    'relay' => 'Modo Relay Privado activo: no requiere DB de buzones; se controla por WireGuard/SASL.',
                    default => 'Modo SMTP Externo activo: no hay servicios mail locales que pausar.',
                },
            ];
        }

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
        $mailMode = in_array(($payload['mail_mode'] ?? 'full'), ['satellite', 'relay', 'full', 'external'], true) ? $payload['mail_mode'] : 'full';

        if ($mailMode === 'full' && (!$dbPass || !$hostname)) {
            return ['ok' => false, 'error' => 'Missing db_pass or mail_hostname'];
        }
        if ($mailMode === 'satellite' && !$hostname) {
            return ['ok' => false, 'error' => 'Missing mail_hostname'];
        }
        if ($mailMode === 'relay' && (!$hostname || empty($payload['wireguard_ip']))) {
            return ['ok' => false, 'error' => 'Missing mail_hostname or wireguard_ip'];
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
        $taskId = 'mail-setup-' . $mailMode . '-' . time() . '-' . bin2hex(random_bytes(4));
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
        $mode = self::getCurrentMailMode();

        if ($mode === 'external') {
            return [
                'ok' => true,
                'configured' => Settings::get('mail_smtp_host', '') !== '',
                'details' => [
                    'mode' => 'external',
                    'smtp_host' => Settings::get('mail_smtp_host', '') !== '',
                ],
            ];
        }

        if ($mode === 'satellite') {
            $hasPostfix = is_file('/etc/postfix/main.cf');
            $hasDkim = is_file('/etc/opendkim.conf');
            return [
                'ok' => true,
                'configured' => $configured && $hasPostfix && $hasDkim,
                'details' => [
                    'mode' => 'satellite',
                    'setting' => $configured,
                    'postfix' => $hasPostfix,
                    'opendkim' => $hasDkim,
                ],
            ];
        }

        if ($mode === 'relay') {
            $hasPostfix = is_file('/etc/postfix/main.cf');
            $hasDkim = is_file('/etc/opendkim/key.table') && is_file('/etc/opendkim/signing.table');
            $hasSasl = is_file('/etc/sasldb2') || trim((string)shell_exec('command -v saslpasswd2 2>/dev/null')) !== '';
            return [
                'ok' => true,
                'configured' => $configured && $hasPostfix && $hasDkim && $hasSasl,
                'details' => [
                    'mode' => 'relay',
                    'setting' => $configured,
                    'postfix' => $hasPostfix,
                    'opendkim_multi_domain' => $hasDkim,
                    'sasl' => $hasSasl,
                ],
            ];
        }

        // Full mode: verify key config files exist
        $hasPostfix  = is_file('/etc/postfix/pgsql-virtual-domains.cf');
        $hasDovecot  = is_file('/etc/dovecot/dovecot-sql.conf');

        return [
            'ok'         => true,
            'configured' => $configured && $hasPostfix && $hasDovecot,
            'details'    => [
                'mode' => 'full',
                'setting'  => $configured,
                'postfix'  => $hasPostfix,
                'dovecot'  => $hasDovecot,
            ],
        ];
    }
}
