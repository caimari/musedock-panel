<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\View;
use MuseDockPanel\Services\UpdateService;
use MuseDockPanel\Services\LogService;

class UpdateController
{
    /**
     * GET /settings/updates — Update management page
     */
    public function index(): void
    {
        $updateInfo = UpdateService::checkForUpdate();
        $changelog = $updateInfo['has_update'] ? UpdateService::fetchRemoteChangelog() : [];
        $updateStatus = UpdateService::getUpdateStatus();

        View::render('settings/updates', [
            'layout'       => 'main',
            'pageTitle'    => 'Updates',
            'updateInfo'   => $updateInfo,
            'changelog'    => $changelog,
            'updateStatus' => $updateStatus,
        ]);
    }

    /**
     * POST /settings/updates/check — Force check for updates
     */
    public function check(): void
    {
        View::verifyCsrf();
        $info = UpdateService::checkForUpdate(force: true);

        if ($info['has_update']) {
            Flash::set('success', "Nueva version disponible: v{$info['remote']}");
        } else {
            Flash::set('success', 'El panel esta actualizado (v' . PANEL_VERSION . ')');
        }

        Router::redirect('/settings/updates');
    }

    /**
     * POST /settings/updates/run — Execute update
     */
    public function run(): void
    {
        View::verifyCsrf();

        LogService::log('panel.update.start', null, 'Update initiated from v' . PANEL_VERSION);

        $result = UpdateService::runUpdate();

        if ($result['success']) {
            Flash::set('success', 'Actualizacion iniciada. El panel se reiniciara automaticamente en unos segundos.');
        } else {
            Flash::set('error', 'Error al iniciar la actualizacion: ' . ($result['message'] ?? 'unknown'));
        }

        Router::redirect('/settings/updates');
    }

    /**
     * GET /settings/updates/api/status — AJAX endpoint for banner + progress
     */
    public function apiStatus(): void
    {
        header('Content-Type: application/json');

        $cached = UpdateService::getCachedUpdateInfo();
        $status = UpdateService::getUpdateStatus();

        echo json_encode([
            'has_update'   => $cached['has_update'] ?? false,
            'current'      => PANEL_VERSION,
            'remote'       => $cached['remote'] ?? '',
            'checked_at'   => $cached['checked_at'] ?? null,
            'in_progress'  => $status['in_progress'],
            'output'       => $status['output'],
        ]);
        exit;
    }
}
