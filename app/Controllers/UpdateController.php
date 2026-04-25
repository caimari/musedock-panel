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
        $updateError = null;
        try {
            // After an update/restart, render from local cache first. A remote
            // GitHub check here can make the just-updated page look blank/slow
            // if DNS/network is temporarily unavailable.
            if (!empty($_GET['updated'])) {
                $updateInfo = UpdateService::getCachedUpdateInfo() ?: UpdateService::localUpdateInfo();
            } else {
                $updateInfo = UpdateService::checkForUpdate();
            }
            $changelog = $updateInfo['has_update'] ? UpdateService::fetchRemoteChangelog() : [];
            $updateStatus = UpdateService::getUpdateStatus();
        } catch (\Throwable $e) {
            $updateError = $e->getMessage();
            $updateInfo = UpdateService::localUpdateInfo();
            $changelog = [];
            $updateStatus = [
                'in_progress' => false,
                'started_at' => null,
                'output' => "Updates page recovered from error:\n" . $e->getMessage(),
                'elapsed' => 0,
                'completed' => false,
            ];
        }

        View::render('settings/updates', [
            'layout'       => 'main',
            'pageTitle'    => 'Updates',
            'updateInfo'   => $updateInfo,
            'changelog'    => $changelog,
            'updateStatus' => $updateStatus,
            'updateError'  => $updateError,
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

        $wantsJson = (
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        );

        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? '',
                'current' => PANEL_VERSION,
            ]);
            exit;
        }

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
        try {

            // Auto-check if no cache or cache expired (respects 6h TTL)
            $cached = UpdateService::getCachedUpdateInfo();
            if (!$cached || (time() - ($cached['checked_at_epoch'] ?? 0)) > 21600) {
                $cached = UpdateService::checkForUpdate();
            }
            $status = UpdateService::getUpdateStatus();

            echo json_encode([
                'ok'           => true,
                'has_update'   => $cached['has_update'] ?? false,
                'current'      => PANEL_VERSION,
                'remote'       => $cached['remote'] ?? '',
                'checked_at'   => $cached['checked_at'] ?? null,
                'in_progress'  => $status['in_progress'],
                'started_at'   => $status['started_at'],
                'elapsed'      => $status['elapsed'],
                'output'       => $status['output'],
                'completed'    => (bool)($status['completed'] ?? false),
            ]);
        } catch (\Throwable $e) {
            http_response_code(200);
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'has_update' => false,
                'current' => PANEL_VERSION,
                'remote' => '',
                'checked_at' => null,
                'in_progress' => false,
                'started_at' => null,
                'elapsed' => 0,
                'output' => 'Update status recovered from error: ' . $e->getMessage(),
                'completed' => false,
            ]);
        }
        exit;
    }
}
