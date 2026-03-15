<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\View;

class LogController
{
    public function index(): void
    {
        $logs = Database::fetchAll(
            "SELECT l.*, a.username as admin_name FROM panel_log l LEFT JOIN panel_admins a ON a.id = l.admin_id ORDER BY l.created_at DESC LIMIT 100"
        );

        View::render('logs/index', [
            'layout' => 'main',
            'pageTitle' => 'Activity Log',
            'logs' => $logs,
        ]);
    }
}
