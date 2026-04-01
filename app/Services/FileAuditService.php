<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;

/**
 * RGPD-compliant file access audit logging.
 *
 * Records every admin operation on hosting account files.
 * Retention: 2 years (purged by cron).
 * Immutable: no delete/update endpoints exposed.
 */
class FileAuditService
{
    /**
     * Log a file operation.
     */
    public static function log(
        array $account,
        string $action,
        string $path,
        ?array $details = null,
        ?string $legalBasis = null
    ): void {
        $admin = Auth::user();
        if (!$admin) return;

        Database::insert('file_audit_logs', [
            'admin_id'         => $admin['id'],
            'admin_username'   => $admin['username'],
            'admin_ip'         => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'account_id'       => (int)$account['id'],
            'account_username' => $account['username'],
            'account_domain'   => $account['domain'] ?? null,
            'action'           => $action,
            'path'             => $path,
            'details'          => $details ? json_encode($details) : null,
            'legal_basis'      => $legalBasis ?? ($_SESSION['fm_legal_basis'] ?? 'contract_execution'),
        ]);
    }

    /**
     * Get audit logs for a specific account.
     */
    public static function forAccount(int $accountId, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where = 'account_id = :account_id';
        $params = ['account_id' => $accountId];

        if (!empty($filters['action'])) {
            $where .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['admin_id'])) {
            $where .= ' AND admin_id = :admin_id';
            $params['admin_id'] = (int)$filters['admin_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return Database::fetchAll(
            "SELECT * FROM file_audit_logs WHERE {$where} ORDER BY created_at DESC LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );
    }

    /**
     * Count audit logs for a specific account (for pagination).
     */
    public static function countForAccount(int $accountId, array $filters = []): int
    {
        $where = 'account_id = :account_id';
        $params = ['account_id' => $accountId];

        if (!empty($filters['action'])) {
            $where .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['admin_id'])) {
            $where .= ' AND admin_id = :admin_id';
            $params['admin_id'] = (int)$filters['admin_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $row = Database::fetchOne("SELECT COUNT(*) as cnt FROM file_audit_logs WHERE {$where}", $params);
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get all audit logs (global view).
     */
    public static function all(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['account_id'])) {
            $where .= ' AND account_id = :account_id';
            $params['account_id'] = (int)$filters['account_id'];
        }
        if (!empty($filters['action'])) {
            $where .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['admin_id'])) {
            $where .= ' AND admin_id = :admin_id';
            $params['admin_id'] = (int)$filters['admin_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return Database::fetchAll(
            "SELECT * FROM file_audit_logs WHERE {$where} ORDER BY created_at DESC LIMIT :lim OFFSET :off",
            array_merge($params, ['lim' => $limit, 'off' => $offset])
        );
    }

    /**
     * Count all audit logs (for pagination).
     */
    public static function countAll(array $filters = []): int
    {
        $where = '1=1';
        $params = [];

        if (!empty($filters['account_id'])) {
            $where .= ' AND account_id = :account_id';
            $params['account_id'] = (int)$filters['account_id'];
        }
        if (!empty($filters['action'])) {
            $where .= ' AND action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['admin_id'])) {
            $where .= ' AND admin_id = :admin_id';
            $params['admin_id'] = (int)$filters['admin_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $row = Database::fetchOne("SELECT COUNT(*) as cnt FROM file_audit_logs WHERE {$where}", $params);
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Export logs for a specific account as CSV string (RGPD Art. 15).
     */
    public static function exportCsv(int $accountId): string
    {
        $logs = Database::fetchAll(
            "SELECT created_at, admin_username, admin_ip, account_username, account_domain, action, path, details, legal_basis
             FROM file_audit_logs WHERE account_id = :id ORDER BY created_at DESC",
            ['id' => $accountId]
        );

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Fecha', 'Admin', 'IP', 'Usuario', 'Dominio', 'Accion', 'Path', 'Detalles', 'Base legal']);

        foreach ($logs as $row) {
            fputcsv($output, [
                $row['created_at'],
                $row['admin_username'],
                $row['admin_ip'],
                $row['account_username'],
                $row['account_domain'],
                $row['action'],
                $row['path'],
                $row['details'],
                $row['legal_basis'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Purge logs older than N days (default 730 = 2 years).
     * Returns number of deleted rows.
     */
    public static function purgeOlderThan(int $days = 730): int
    {
        $stmt = Database::query(
            "DELETE FROM file_audit_logs WHERE created_at < NOW() - CAST(:days || ' days' AS INTERVAL)",
            ['days' => $days]
        );
        return $stmt->rowCount();
    }
}
