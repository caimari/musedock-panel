<?php
/**
 * Create file_audit_logs table for RGPD-compliant file access auditing.
 * Records every admin operation on hosting account files.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS file_audit_logs (
            id BIGSERIAL PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_username VARCHAR(100) NOT NULL,
            admin_ip INET NOT NULL,
            account_id INT NOT NULL,
            account_username VARCHAR(100) NOT NULL,
            account_domain VARCHAR(255),
            action VARCHAR(20) NOT NULL,
            path VARCHAR(1024) NOT NULL,
            details JSONB,
            legal_basis VARCHAR(50) DEFAULT 'contract_execution',
            created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_audit_account ON file_audit_logs(account_id, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_audit_admin ON file_audit_logs(admin_id, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_audit_action ON file_audit_logs(action, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_audit_date ON file_audit_logs(created_at DESC)");
};
