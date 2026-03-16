<?php
/**
 * Migration: Add per-engine replication roles
 * Splits single repl_role into repl_pg_role and repl_mysql_role
 * Also adds per-engine remote IPs
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $keys = [
            ['repl_pg_role', 'standalone'],
            ['repl_mysql_role', 'standalone'],
            ['repl_pg_remote_ip', ''],
            ['repl_mysql_remote_ip', ''],
        ];

        $stmt = $pdo->prepare("INSERT INTO panel_settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING");
        foreach ($keys as [$key, $value]) {
            $stmt->execute([$key, $value]);
        }

        // Migrate existing repl_role to per-engine roles
        $existing = $pdo->query("SELECT value FROM panel_settings WHERE key = 'repl_role'")->fetchColumn();
        if ($existing && $existing !== 'standalone') {
            // If PG was enabled, set PG role
            $pgEnabled = $pdo->query("SELECT value FROM panel_settings WHERE key = 'repl_pg_enabled'")->fetchColumn();
            if ($pgEnabled === '1') {
                $pdo->exec("UPDATE panel_settings SET value = " . $pdo->quote($existing) . " WHERE key = 'repl_pg_role'");
            }
            // If MySQL was enabled, set MySQL role
            $mysqlEnabled = $pdo->query("SELECT value FROM panel_settings WHERE key = 'repl_mysql_enabled'")->fetchColumn();
            if ($mysqlEnabled === '1') {
                $pdo->exec("UPDATE panel_settings SET value = " . $pdo->quote($existing) . " WHERE key = 'repl_mysql_role'");
            }
            // Copy remote IP to per-engine IPs
            $remoteIp = $pdo->query("SELECT value FROM panel_settings WHERE key = 'repl_remote_ip'")->fetchColumn();
            if ($remoteIp) {
                $pdo->exec("UPDATE panel_settings SET value = " . $pdo->quote($remoteIp) . " WHERE key = 'repl_pg_remote_ip'");
                $pdo->exec("UPDATE panel_settings SET value = " . $pdo->quote($remoteIp) . " WHERE key = 'repl_mysql_remote_ip'");
            }
        }
    }
};
