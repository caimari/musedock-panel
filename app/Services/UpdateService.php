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
        $current = self::currentVersion();
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
        $current = self::currentVersion();
        $hasUpdate = $remote && version_compare($current, $remote, '<');

        // Auto-clear stale flag if we're already up to date
        if (!$hasUpdate && Settings::get('update_has_update', '0') === '1') {
            Settings::set('update_has_update', '0');
        }

        return [
            'current'          => $current,
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
        $unitName = 'musedock-panel-update-' . time();

        @file_put_contents($logFile, '');

        // Mark progress before launching the child process so the UI can start
        // polling immediately. The update process must run outside the panel
        // service cgroup; otherwise systemctl restart musedock-panel kills it
        // before it can clear flags or print the final completion line.
        Settings::set('update_in_progress', '1');
        Settings::set('update_started_at', (string) time());
        Settings::set('update_unit', $unitName);

        if (self::commandExists('systemd-run')) {
            $inner = sprintf(
                'exec bash %s/bin/update.sh --auto > %s 2>&1',
                escapeshellarg($panelDir),
                escapeshellarg($logFile)
            );
            $cmd = sprintf(
                'systemd-run --unit=%s --collect --property=WorkingDirectory=%s /bin/bash -lc %s',
                escapeshellarg($unitName),
                escapeshellarg($panelDir),
                escapeshellarg($inner)
            );

            $output = [];
            $rc = 0;
            exec($cmd . ' 2>&1', $output, $rc);
            if ($rc !== 0) {
                Settings::set('update_in_progress', '0');
                @file_put_contents($logFile, implode("\n", $output) . "\n", FILE_APPEND);
                return [
                    'success' => false,
                    'message' => 'Could not start update unit: ' . trim(implode("\n", $output)),
                    'log_file' => $logFile,
                ];
            }
        } else {
            $cmd = sprintf(
                'nohup setsid bash %s/bin/update.sh --auto > %s 2>&1 < /dev/null &',
                escapeshellarg($panelDir),
                escapeshellarg($logFile)
            );
            shell_exec($cmd);
        }

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
        // Strip ANSI escape codes (color, bold, etc.) for web display
        $output = preg_replace('/\033\[[0-9;]*m/', '', $output);
        $completed = false;
        $remote = Settings::get('update_remote_version', '');
        $unitName = Settings::get('update_unit', '');

        $currentAtOrPastRemote = $remote !== '' && version_compare(self::currentVersion(), $remote, '>=');
        $unitStillRunning = $unitName !== '' && self::systemdUnitActive($unitName);

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
            $completed = true;
        }

        // Robust completion detection for updates launched from the web UI.
        // Older updater runs could be killed during service restart before
        // writing "Update complete" or clearing DB flags.
        if ($inProgress && $currentAtOrPastRemote && !$unitStillRunning) {
            Settings::set('update_in_progress', '0');
            Settings::set('update_has_update', '0');
            Settings::set('update_last_check', (string) time());
            $inProgress = false;
            $completed = true;
        }

        if (!$inProgress && $currentAtOrPastRemote) {
            Settings::set('update_has_update', '0');
            $completed = true;
        }

        return [
            'in_progress' => $inProgress,
            'started_at'  => $startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : null,
            'output'      => $output,
            'elapsed'     => $startedAt > 0 ? time() - $startedAt : 0,
            'completed'   => $completed,
        ];
    }

    /**
     * Fetch remote version from GitHub raw content.
     */
    private static function fetchRemoteVersion(): ?string
    {
        $rawBaseUrl = self::remoteRawBaseUrl();

        // Primary: read version from config/panel.php
        $content = self::httpGet($rawBaseUrl . '/config/panel.php');
        if ($content !== null && preg_match("/'version'\s*=>\s*'([^']+)'/", $content, $m)) {
            return $m[1];
        }

        // Fallback: legacy index.php with define('PANEL_VERSION', ...)
        $content = self::httpGet($rawBaseUrl . '/public/index.php');
        if ($content !== null && preg_match("/define\(\s*'PANEL_VERSION'\s*,\s*'([^']+)'\s*\)/", $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Fetch remote changelog entries newer than current version.
     */
    public static function fetchRemoteChangelog(): array
    {
        $url = self::remoteRawBaseUrl() . '/app/Controllers/ChangelogController.php';
        $content = self::httpGet($url);
        if ($content === null) return [];

        // Extract version entries using regex
        $entries = [];
        $current = self::currentVersion();

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

    /**
     * HTTP GET helper with curl-first strategy.
     * Why: on some nodes file_get_contents(https://...) times out while curl works.
     */
    private static function httpGet(string $url, int $timeout = 10): ?string
    {
        $userAgent = 'MuseDockPanel/' . self::currentVersion();

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_errno($ch);
            curl_close($ch);

            if ($curlErr === 0 && $httpCode >= 200 && $httpCode < 400 && is_string($body)) {
                return $body;
            }
        }

        // Fallback for minimal environments without curl extension.
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => $userAgent,
            ],
        ]);
        $content = @file_get_contents($url, false, $ctx);
        return $content !== false ? $content : null;
    }

    /**
     * Resolve the immutable raw URL for origin/main when possible.
     *
     * GitHub's raw branch URL can lag behind after a push. Reading by commit
     * SHA avoids stale "latest version" checks on slave nodes.
     */
    private static function remoteRawBaseUrl(): string
    {
        if (!self::commandExists('git') || !is_dir(PANEL_ROOT . '/.git')) {
            return self::REPO_RAW_URL;
        }

        $cmd = sprintf(
            'git -C %s ls-remote origin refs/heads/main 2>/dev/null',
            escapeshellarg(PANEL_ROOT)
        );
        $output = trim((string) shell_exec($cmd));

        if (preg_match('/^([a-f0-9]{40})\s+refs\/heads\/main$/', $output, $m)) {
            return 'https://raw.githubusercontent.com/caimari/musedock-panel/' . $m[1];
        }

        return self::REPO_RAW_URL;
    }

    private static function commandExists(string $command): bool
    {
        $result = trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        return $result !== '';
    }

    private static function systemdUnitActive(string $unitName): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_.@-]+$/', $unitName)) {
            return false;
        }

        exec('systemctl is-active --quiet ' . escapeshellarg($unitName), $out, $rc);
        return $rc === 0;
    }

    private static function currentVersion(): string
    {
        if (defined('PANEL_VERSION')) {
            return (string) constant('PANEL_VERSION');
        }

        $configFile = PANEL_ROOT . '/config/panel.php';
        if (is_file($configFile)) {
            $config = require $configFile;
            if (is_array($config) && !empty($config['version'])) {
                return (string) $config['version'];
            }
        }

        return '0.0.0';
    }
}
