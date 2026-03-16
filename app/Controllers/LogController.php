<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\View;
use MuseDockPanel\Services\LogService;

class LogController
{
    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Filters
        $filterAction = trim($_GET['action'] ?? '');
        $filterAdmin = trim($_GET['admin'] ?? '');
        $filterSearch = trim($_GET['q'] ?? '');

        $where = [];
        $params = [];

        if ($filterAction !== '') {
            $where[] = "l.action LIKE :action";
            $params['action'] = "%{$filterAction}%";
        }
        if ($filterAdmin !== '') {
            $where[] = "a.username = :admin";
            $params['admin'] = $filterAdmin;
        }
        if ($filterSearch !== '') {
            $where[] = "(l.target ILIKE :q OR l.details ILIKE :q2 OR l.action ILIKE :q3)";
            $params['q'] = "%{$filterSearch}%";
            $params['q2'] = "%{$filterSearch}%";
            $params['q3'] = "%{$filterSearch}%";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countRow = Database::fetchOne(
            "SELECT COUNT(*) AS total FROM panel_log l LEFT JOIN panel_admins a ON a.id = l.admin_id {$whereClause}",
            $params
        );
        $total = (int)($countRow['total'] ?? 0);
        $totalPages = max(1, ceil($total / $perPage));

        // Fetch logs
        $logs = Database::fetchAll(
            "SELECT l.*, a.username as admin_name
             FROM panel_log l
             LEFT JOIN panel_admins a ON a.id = l.admin_id
             {$whereClause}
             ORDER BY l.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // Unique action types for filter dropdown
        $actionTypes = Database::fetchAll(
            "SELECT DISTINCT action FROM panel_log ORDER BY action"
        );

        // Unique admins
        $admins = Database::fetchAll(
            "SELECT DISTINCT a.username FROM panel_log l JOIN panel_admins a ON a.id = l.admin_id ORDER BY a.username"
        );

        View::render('logs/index', [
            'layout'       => 'main',
            'pageTitle'    => 'Activity Log',
            'logs'         => $logs,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'total'        => $total,
            'perPage'      => $perPage,
            'filterAction' => $filterAction,
            'filterAdmin'  => $filterAdmin,
            'filterSearch' => $filterSearch,
            'actionTypes'  => $actionTypes,
            'admins'       => $admins,
        ]);
    }

    /**
     * POST /logs/clear
     */
    public function clear(): void
    {
        View::verifyCsrf();

        $days = (int)($_POST['days'] ?? 30);
        if ($days < 1) $days = 30;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $result = Database::delete('panel_log', 'created_at < :cutoff', ['cutoff' => $cutoff]);

        LogService::log('logs.clear', (string)$result, "Eliminados {$result} registros anteriores a {$cutoff}");
        Flash::set('success', "Se eliminaron {$result} registros de hace mas de {$days} dias.");
        header('Location: /logs');
        exit;
    }

    /**
     * POST /logs/clear-all
     */
    public function clearAll(): void
    {
        View::verifyCsrf();

        // Verify admin password
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            Flash::set('error', 'Debes ingresar tu contrasena para confirmar.');
            header('Location: /logs');
            exit;
        }

        $admin = Database::fetchOne(
            "SELECT password_hash FROM panel_admins WHERE id = :id",
            ['id' => $_SESSION['panel_user']['id'] ?? 0]
        );
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            Flash::set('error', 'Contrasena incorrecta.');
            header('Location: /logs');
            exit;
        }

        $count = Database::fetchOne("SELECT COUNT(*) AS total FROM panel_log");
        Database::query("DELETE FROM panel_log");

        LogService::log('logs.clear_all', (string)($count['total'] ?? 0), 'Todos los registros eliminados');
        Flash::set('success', 'Todos los registros del Activity Log han sido eliminados.');
        header('Location: /logs');
        exit;
    }
}
