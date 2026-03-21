<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Database;
use MuseDockPanel\Settings;

class UpdateService
{
    private const REPO_RAW_URL = 'https://raw.githubusercontent.com/caimari/musedock-panel/main';
    private const CHECK_INTERVAL = 21600; // 6 hours

    /**
     * Check for available update. Uses cache unless forced.
     */
    public static function checkForUpdate(bool $force = false): array
    {
        $current = PANEL_VERSION;
        $cached = self::getCachedUpdateInfo();

        if (!$force && $cached && (time() - ($cached['checked_at_epoch'] ?? 0)) < self::CHECK_INTERVAL) {
            return $cached;
        }

        // Fetch remote version from GitHub
        $remote = self::fetchRemoteVersion();

        $info = [
            'current'          => $current,
            'remote'           => $remote,
            'has_update'       => $remote && version_compare($current, $remote, '<'),
            'checked_at'       => date('Y-m-d H:i:s'),
            'checked_at_epoch' => time(),
        ];

        // Cache in panel_settings
        Settings::set('update_remote_version', $remote ?: '');
        Settings::set('update_last_check', (string) time());
        Settings::set('update_has_update', $info['has_update'] ? '1' : '0');

        return $info;
    }

    /**
     * Get cached update info (lightweight, no HTTP calls).
     */
    public static function getCachedUpdateInfo(): ?array
    {
        $remote = Settings::get('update_remote_version', '');
        $lastCheck = (int) Settings::get('update_last_check', '0');

        if ($lastCheck === 0) return null;

        // Re-evaluate against actual running version — the DB flag may be stale
        // after an update that didn't clear it properly.
        $hasUpdate = $remote && version_compare(PANEL_VERSION, $remote, '<');

        // Auto-clear stale flag if we're already up to date
        if (!$hasUpdate && Settings::get('update_has_update', '0') === '1') {
            Settings::set('update_has_update', '0');
        }

        return [
            'current'          => PANEL_VERSION,
            'remote'           => $remote,
            'has_update'       => $hasUpdate,
            'checked_at'       => date('Y-m-d H:i:s', $lastCheck),
            'checked_at_epoch' => $lastCheck,
        ];
    }

    /**
     * Execute the update process.
     */
    public static function runUpdate(): array
    {
        $panelDir = PANEL_ROOT;
        $logFile = $panelDir . '/storage/logs/update.log';

        // Run update.sh in background with --auto flag
        // We use nohup so the service restart doesn't kill our response
        $cmd = sprintf(
            'nohup bash %s/bin/update.sh --auto > %s 2>&1 &',
            escapeshellarg($panelDir),
            escapeshellarg($logFile)
        );

        shell_exec($cmd);

        // Mark that update is in progress
        Settings::set('update_in_progress', '1');
        Settings::set('update_started_at', (string) time());

        return [
            'success'  => true,
            'message'  => 'Update started in background. The panel will restart automatically.',
            'log_file' => $logFile,
        ];
    }

    /**
     * Get the status of a running or completed update.
     */
    public static function getUpdateStatus(): array
    {
        $inProgress = Settings::get('update_in_progress', '0') === '1';
        $startedAt = (int) Settings::get('update_started_at', '0');
        $logFile = PANEL_ROOT . '/storage/logs/update.log';
        $output = file_exists($logFile) ? file_get_contents($logFile) : '';

        // If started more than 2 minutes ago, assume it finished
        if ($inProgress && (time() - $startedAt) > 120) {
            Settings::set('update_in_progress', '0');
            $inProgress = false;
        }

        // Check if output indicates completion
        if ($inProgress && str_contains($output, 'Update complete')) {
            Settings::set('update_in_progress', '0');
            Settings::set('update_has_update', '0');
            $inProgress = false;
        }

        return [
            'in_progress' => $inProgress,
            'started_at'  => $startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : null,
            'output'      => $output,
            'elapsed'     => $startedAt > 0 ? time() - $startedAt : 0,
        ];
    }

    /**
     * Fetch remote version from GitHub raw content.
     */
    private static function fetchRemoteVersion(): ?string
    {
        $url = self::REPO_RAW_URL . '/public/index.php';

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'MuseDockPanel/' . PANEL_VERSION,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) return null;

        // Parse: define('PANEL_VERSION', '0.7.0');
        if (preg_match("/define\(\s*'PANEL_VERSION'\s*,\s*'([^']+)'\s*\)/", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Fetch remote changelog entries newer than current version.
     */
    public static function fetchRemoteChangelog(): array
    {
        $url = self::REPO_RAW_URL . '/app/Controllers/ChangelogController.php';

        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'MuseDockPanel/' . PANEL_VERSION,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);
        if ($content === false) return [];

        // Extract version entries using regex
        $entries = [];
        $current = PANEL_VERSION;

        // Match version strings in the changelog
        preg_match_all("/'version'\s*=>\s*'([^']+)'/", $content, $versions);
        preg_match_all("/'date'\s*=>\s*'([^']+)'/", $content, $dates);

        if (empty($versions[1])) return [];

        foreach ($versions[1] as $i => $ver) {
            if (version_compare($ver, $current, '>')) {
                $entries[] = [
                    'version' => $ver,
                    'date'    => $dates[1][$i] ?? '',
                ];
            }
        }

        return $entries;
    }
}
