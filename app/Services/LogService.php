<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;

class LogService
{
    public static function log(string $action, ?string $target = null, ?string $details = null): void
    {
        $user = Auth::user();
        Database::insert('panel_log', [
            'admin_id' => $user['id'] ?? null,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
}
