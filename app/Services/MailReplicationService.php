<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Settings;
use MuseDockPanel\Database;

/**
 * MailReplicationService — real mailbox replication between two mail nodes.
 *
 * The rest of the mail system replicates only CONFIGURATION (accounts, passwords,
 * quotas, domains, DKIM) because that lives in the replicated PostgreSQL DB. The
 * actual messages under /var/mail/vhosts are NOT replicated: lsyncd only mirrors
 * /var/www/vhosts, and there is no dsync. So on a mail-node failover a user could
 * authenticate but see an EMPTY mailbox — their mail is stranded on the dead node.
 *
 * This service adds Dovecot's native replication (replicator + dsync). We use
 * dsync deliberately, NOT rsync: rsync over a live Maildir corrupts mailboxes when
 * deliveries happen concurrently, while dsync understands Maildir/IMAP semantics
 * (UIDs, flags, expunges) and merges both directions safely.
 *
 * Topology on this fleet: mortadelo <-> Filemon, replicating over WireGuard
 * (10.10.70.x), so mail traffic never leaves the private network.
 *
 * IMPORTANT: this configures replication; it never deletes mail. The generated
 * config is written to a dedicated drop-in and every change backs up first.
 */
class MailReplicationService
{
    private const DROPIN = '/etc/dovecot/conf.d/95-musedock-replication.conf';
    private const REPL_PORT = 12345; // doveadm replication (dsync) TCP port, WG-only

    /**
     * Build the Dovecot replication drop-in for THIS node, pointed at its partner.
     *
     * @param string $partnerIp WireGuard IP of the other mail node
     * @param bool   $dryRun    return the config without writing it
     */
    public static function configureNode(string $partnerIp, bool $dryRun = false, string $sharedSecret = ''): array
    {
        if (!filter_var($partnerIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP del nodo pareja no valida'];
        }

        $localIp = static::wireguardIp();
        $port = self::REPL_PORT;

        // Notify plugin fires dsync on every change; replicator manages the queue.
        // mail_replica points at the partner over doveadm TCP on the WG network.
        $conf = <<<CONF
# MuseDock Panel — Dovecot replication (auto-generated; do not edit)
# Replicates mailboxes with the partner mail node over WireGuard using dsync.
mail_plugins = \$mail_plugins notify replication

service replicator {
    process_min_avail = 1
    unix_listener replicator-doveadm {
        mode = 0666
    }
}

service aggregator {
    fifo_listener replication-notify-fifo {
        user = vmail
    }
    unix_listener replication-notify {
        user = vmail
    }
}

service doveadm {
    inet_listener {
        port = {$port}
    }
}

plugin {
    mail_replica = tcp:{$partnerIp}:{$port}
}

# doveadm must authenticate to the partner with a shared secret.
doveadm_port = {$port}
CONF;

        if ($dryRun) {
            return [
                'ok' => true, 'dry_run' => true, 'config' => $conf,
                'plan' => [
                    "escribir " . self::DROPIN . " (backup si existe)",
                    "asegurar doveadm_password compartido entre {$localIp} y {$partnerIp}",
                    "abrir puerto " . self::REPL_PORT . " SOLO en WireGuard hacia {$partnerIp}",
                    "reiniciar Dovecot y verificar el servicio replicator",
                    "sincronizacion inicial: doveadm sync -A hacia {$partnerIp}",
                ],
            ];
        }

        if (!is_dir('/etc/dovecot')) {
            return ['ok' => false, 'error' => 'Dovecot no esta instalado en este nodo (configure primero el servidor de correo).'];
        }

        // Back up any previous drop-in, then write.
        if (file_exists(self::DROPIN)) {
            @copy(self::DROPIN, self::DROPIN . '.bak.' . date('Ymd_His'));
        }
        $written = @file_put_contents(self::DROPIN, $conf . "\n");
        if ($written === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::DROPIN];
        }
        @chmod(self::DROPIN, 0644);

        // Shared doveadm secret so both nodes trust each other for dsync. When the
        // master pushes one (setupPair), use it verbatim so both ends match.
        static::ensureSharedSecret($sharedSecret);

        // Restart Dovecot to load the replicator/aggregator services.
        $out = shell_exec('systemctl restart dovecot 2>&1');
        sleep(2);
        $running = trim((string)shell_exec('systemctl is-active dovecot 2>/dev/null')) === 'active';
        if (!$running) {
            // Roll back the drop-in so a bad config never leaves mail down.
            @unlink(self::DROPIN);
            shell_exec('systemctl restart dovecot 2>&1');
            return ['ok' => false, 'error' => 'Dovecot no arranco con la config de replicacion; se revirtio. Salida: ' . trim((string)$out)];
        }

        Settings::set('mail_replication_partner', $partnerIp);
        Settings::set('mail_replication_configured_at', date('Y-m-d H:i:s'));
        LogService::log('mail.replication', 'configure', "Replicacion Dovecot configurada con {$partnerIp}");

        return ['ok' => true, 'partner' => $partnerIp, 'port' => self::REPL_PORT];
    }

    /**
     * Orchestrate replication between the LOCAL mail node and a REMOTE one from
     * the master: configure both ends to point at each other, then trigger the
     * initial sync. Bidirectional — either node can serve mail after a failover.
     *
     * @param int  $remoteNodeId cluster node that is (or will be) the mail partner
     * @param bool $dryRun
     */
    public static function setupPair(int $remoteNodeId, bool $dryRun = false): array
    {
        $steps = [];
        $remote = ClusterService::getNode($remoteNodeId);
        if (!$remote) {
            return ['ok' => false, 'error' => 'Nodo remoto no encontrado'];
        }
        $remoteIp = parse_url($remote['api_url'] ?? '', PHP_URL_HOST) ?: '';
        $localIp = static::wireguardIp();
        if ($localIp === '' || $remoteIp === '') {
            return ['ok' => false, 'error' => "No se pudo determinar IPs WireGuard (local={$localIp}, remoto={$remoteIp})"];
        }

        if ($dryRun) {
            return [
                'ok' => true, 'dry_run' => true,
                'plan' => [
                    "Nodo LOCAL ({$localIp}): configurar Dovecot replication apuntando a {$remoteIp}",
                    "Nodo REMOTO {$remote['name']} ({$remoteIp}): configurar apuntando a {$localIp}",
                    "Compartir el mismo doveadm secret en ambos",
                    "Sincronizacion inicial bidireccional (doveadm sync -A)",
                    "Los correos existentes se copiaran; a partir de ahi, cada cambio se replica al instante",
                ],
            ];
        }

        // 1. Configure the local node.
        $local = static::configureNode($remoteIp, false);
        $steps[] = ['name' => "Local ({$localIp})", 'ok' => $local['ok'], 'output' => $local['error'] ?? 'configurado'];
        if (!$local['ok']) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'Fallo al configurar el nodo local: ' . ($local['error'] ?? '')];
        }

        // 2. Configure the remote node via the cluster API, sharing the secret.
        $secret = static::currentSecret();
        $remoteResp = ClusterService::callNode($remoteNodeId, 'POST', 'api/cluster/action', [
            'action'  => 'mail_setup_replication',
            'payload' => ['partner_ip' => $localIp, 'shared_secret' => $secret],
        ]);
        $remoteOk = !empty($remoteResp['ok']);
        $steps[] = ['name' => "Remoto {$remote['name']} ({$remoteIp})", 'ok' => $remoteOk,
                    'output' => $remoteOk ? 'configurado' : ($remoteResp['error'] ?? 'sin respuesta')];
        if (!$remoteOk) {
            return ['ok' => false, 'steps' => $steps, 'error' => 'Fallo al configurar el nodo remoto'];
        }

        // 3. Initial bidirectional sync.
        $sync = static::initialSync($remoteIp);
        $steps[] = ['name' => 'Sync inicial', 'ok' => $sync['ok'], 'output' => $sync['output'] ?? ''];

        Settings::set('mail_replication_pair_node_id', (string)$remoteNodeId);
        LogService::log('mail.replication', 'setup-pair', "Pareja de replicacion de correo: {$localIp} <-> {$remoteIp}");

        return ['ok' => true, 'steps' => $steps, 'partner' => $remoteIp];
    }

    /** Return the shared doveadm secret in plaintext (creating it if needed). */
    public static function currentSecret(): string
    {
        return static::ensureSharedSecret();
    }

    /**
     * Kick off a full initial sync so the partner catches up on existing mail.
     * Safe to run repeatedly: dsync only transfers differences.
     */
    public static function initialSync(string $partnerIp): array
    {
        if (!filter_var($partnerIp, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'IP no valida'];
        }
        // -A = all users; dsync merges both directions, never deletes blindly.
        $cmd = 'doveadm sync -A tcp:' . escapeshellarg($partnerIp) . ':' . self::REPL_PORT . ' 2>&1';
        $out = trim((string)shell_exec($cmd));
        $ok = !preg_match('/error|failed|fatal/i', $out) || $out === '';
        LogService::log('mail.replication', 'initial-sync', "Sync inicial con {$partnerIp}: " . ($ok ? 'OK' : $out));
        return ['ok' => $ok, 'output' => $out ?: 'OK'];
    }

    /**
     * Replication health on this node: is the replicator running and how far
     * behind is the queue.
     */
    public static function status(): array
    {
        $partner = Settings::get('mail_replication_partner', '');
        if ($partner === '') {
            return ['ok' => true, 'configured' => false, 'message' => 'Replicacion de correo no configurada en este nodo'];
        }

        $dovecotUp = trim((string)shell_exec('systemctl is-active dovecot 2>/dev/null')) === 'active';
        $replStatus = trim((string)shell_exec('doveadm replicator status 2>&1'));
        // "doveadm replicator dsync-status" lists users still pending.
        $pending = trim((string)shell_exec("doveadm replicator status '*' 2>/dev/null | grep -c -v '^username' 2>/dev/null"));

        return [
            'ok'          => $dovecotUp,
            'configured'  => true,
            'partner'     => $partner,
            'dovecot_up'  => $dovecotUp,
            'replicator'  => $replStatus,
            'pending_users' => is_numeric($pending) ? (int)$pending : null,
            'configured_at' => Settings::get('mail_replication_configured_at', ''),
        ];
    }

    /**
     * On a mail-node failover, mail domains still point at the dead node via
     * mail_node_id. Repoint every domain/account served by the old node to the
     * surviving one so Postfix/Dovecot lookups resolve locally and new mail is
     * delivered here. The messages themselves are already present because dsync
     * kept the partner in sync.
     */
    public static function reassignMailNode(int $oldNodeId, int $newNodeId): array
    {
        if ($oldNodeId < 1 || $newNodeId < 1) {
            return ['ok' => false, 'error' => 'node ids invalidos'];
        }
        try {
            $d = Database::execute(
                'UPDATE mail_domains SET mail_node_id = :new WHERE mail_node_id = :old',
                ['new' => $newNodeId, 'old' => $oldNodeId]
            );
            $a = 0;
            // mail_accounts may carry their own node reference in some schemas.
            if (static::columnExists('mail_accounts', 'mail_node_id')) {
                $a = Database::execute(
                    'UPDATE mail_accounts SET mail_node_id = :new WHERE mail_node_id = :old',
                    ['new' => $newNodeId, 'old' => $oldNodeId]
                );
            }
            Settings::set('mail_default_node_id', (string)$newNodeId);
            LogService::log('mail.replication', 'failover-reassign',
                "Dominios de correo reasignados del nodo #{$oldNodeId} al #{$newNodeId} ({$d} dominios, {$a} cuentas)");
            return ['ok' => true, 'domains' => $d, 'accounts' => $a];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────

    /**
     * Ensure both nodes share a doveadm secret so dsync-over-TCP is authenticated.
     * Stored in panel settings (encrypted) and written to a root-only file the
     * partner also gets via the cluster action.
     */
    private static function ensureSharedSecret(string $provided = ''): string
    {
        if ($provided !== '') {
            // The master supplied the partner's secret; adopt it so both match.
            Settings::set('mail_replication_secret', ReplicationService::encryptPassword($provided));
            $secret = $provided;
        } else {
            $secret = Settings::get('mail_replication_secret', '');
            if ($secret === '') {
                $secret = bin2hex(random_bytes(24));
                Settings::set('mail_replication_secret', ReplicationService::encryptPassword($secret));
            } else {
                $dec = ReplicationService::decryptPassword($secret);
                $secret = $dec !== '' ? $dec : $secret;
            }
        }
        $file = '/etc/dovecot/musedock-repl.secret';
        @file_put_contents($file, "doveadm_password = {$secret}\n");
        @chmod($file, 0600);
        return $secret;
    }

    public static function wireguardIp(): string
    {
        $out = trim((string)shell_exec("ip -o -4 addr show 2>/dev/null | awk '{print \$4}' | cut -d/ -f1"));
        foreach (preg_split('/\s+/', $out) as $ip) {
            if (str_starts_with($ip, '10.10.70.')) return $ip;
        }
        return '';
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            $r = Database::fetchOne(
                "SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c",
                ['t' => $table, 'c' => $column]
            );
            return (bool)$r;
        } catch (\Throwable) {
            return false;
        }
    }
}
