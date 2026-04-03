<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Database;
use MuseDockPanel\Services\FederationService;
use MuseDockPanel\Services\FederationMigrationService;
use MuseDockPanel\Services\LogService;

/**
 * FederationController — Settings > Federation UI.
 *
 * Manages federation peers and shows migration history.
 */
class FederationController
{
    /**
     * GET /settings/federation
     */
    public function index(): void
    {
        $peers = FederationService::getPeers();
        $migrations = FederationMigrationService::list();

        View::render('settings.federation', [
            'layout' => 'main',
            'pageTitle' => 'Federation',
            'peers' => $peers,
            'migrations' => $migrations,
        ]);
    }

    /**
     * POST /settings/federation/add-peer
     */
    public function addPeer(): void
    {
        $name      = trim($_POST['name'] ?? '');
        $apiUrl    = trim($_POST['api_url'] ?? '');
        $authToken = trim($_POST['auth_token'] ?? '');
        $sshHost   = trim($_POST['ssh_host'] ?? '');
        $sshPort   = (int)($_POST['ssh_port'] ?? 22);
        $sshUser   = trim($_POST['ssh_user'] ?? 'root');
        $sshKeyPath = trim($_POST['ssh_key_path'] ?? '/root/.ssh/id_ed25519');

        if (empty($name) || empty($apiUrl) || empty($authToken)) {
            Flash::error('Nombre, URL y token son obligatorios.');
            header('Location: /settings/federation');
            return;
        }

        $result = FederationService::addPeer($name, $apiUrl, $authToken, [
            'host'     => $sshHost,
            'port'     => $sshPort,
            'user'     => $sshUser,
            'key_path' => $sshKeyPath,
        ]);

        if ($result['ok']) {
            Flash::success("Peer '{$name}' agregado correctamente.");
        } else {
            Flash::error('Error: ' . ($result['error'] ?? 'Unknown'));
        }

        header('Location: /settings/federation');
    }

    /**
     * POST /settings/federation/update-peer
     */
    public function updatePeer(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            Flash::error('ID invalido.');
            header('Location: /settings/federation');
            return;
        }

        $result = FederationService::updatePeer($id, $_POST);

        if ($result['ok']) {
            Flash::success('Peer actualizado.');
        } else {
            Flash::error('Error: ' . ($result['error'] ?? 'Unknown'));
        }

        header('Location: /settings/federation');
    }

    /**
     * POST /settings/federation/remove-peer/{id}
     */
    public function removePeer(int $id): void
    {
        $result = FederationService::removePeer($id);

        if ($result['ok']) {
            Flash::success('Peer eliminado.');
        } else {
            Flash::error('Error: ' . ($result['error'] ?? 'Unknown'));
        }

        header('Location: /settings/federation');
    }

    /**
     * POST /settings/federation/test-peer
     */
    public function testPeer(): void
    {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        echo json_encode(FederationService::testPeer($id));
    }

    /**
     * POST /settings/federation/exchange-keys
     */
    public function exchangeKeys(): void
    {
        header('Content-Type: application/json');
        $id = (int)($_POST['peer_id'] ?? 0);
        echo json_encode(FederationService::exchangeSshKeys($id));
    }

    // ═══════════════════════════════════════════════════════════════
    // Account migration actions (from Accounts > Migrate to...)
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /accounts/{id}/federation-migrate
     * Show migration form for an account.
     */
    public function migrateForm(int $accountId): void
    {
        $account = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => $accountId]);
        if (!$account) {
            http_response_code(404);
            echo 'Account not found';
            return;
        }

        $peers = FederationService::getPeers();

        // Check if there's an active migration
        $activeMigration = Database::fetchOne(
            "SELECT * FROM hosting_migrations WHERE account_id = :aid AND status IN ('pending', 'running', 'paused') LIMIT 1",
            ['aid' => $accountId]
        );

        View::render('accounts.federation-migrate', [
            'layout' => 'main',
            'pageTitle' => 'Migrar hosting — ' . $account['domain'],
            'account' => $account,
            'peers' => $peers,
            'activeMigration' => $activeMigration,
        ]);
    }

    /**
     * POST /accounts/{id}/federation-migrate/start
     * Start a new migration.
     */
    public function migrateStart(int $accountId): void
    {
        header('Content-Type: application/json');

        $peerId  = (int)($_POST['peer_id'] ?? 0);
        $mode    = $_POST['mode'] ?? FederationMigrationService::MODE_MIGRATE;
        $dryRun  = !empty($_POST['dry_run']);
        $gracePeriod = (int)($_POST['grace_period'] ?? 60);
        $dnsMode = $_POST['dns_mode'] ?? 'auto'; // 'auto' or 'manual'

        if ($peerId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Selecciona un peer de destino']);
            return;
        }

        $result = FederationMigrationService::create($accountId, $peerId, $mode, $dryRun, $gracePeriod, $dnsMode);

        if ($result['ok']) {
            LogService::log('federation.migration.start', null, "Migration started: {$result['migration_id']}");
        }

        echo json_encode($result);
    }

    /**
     * POST /accounts/{id}/federation-migrate/execute
     * Execute the next step (or run all).
     */
    public function migrateExecute(int $accountId): void
    {
        header('Content-Type: application/json');

        $migrationId = $_POST['migration_id'] ?? '';
        $runAll      = !empty($_POST['run_all']);

        if (empty($migrationId)) {
            echo json_encode(['ok' => false, 'error' => 'migration_id required']);
            return;
        }

        if ($runAll) {
            echo json_encode(FederationMigrationService::runAll($migrationId));
        } else {
            echo json_encode(FederationMigrationService::executeNextStep($migrationId));
        }
    }

    /**
     * GET /accounts/{id}/federation-migrate/progress
     * SSE or JSON progress for real-time UI.
     */
    public function migrateProgress(int $accountId): void
    {
        $migrationId = $_GET['migration_id'] ?? '';
        $format = $_GET['format'] ?? 'json';

        if ($format === 'sse') {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            $maxIterations = 600; // 10 minutes at 1s intervals
            for ($i = 0; $i < $maxIterations; $i++) {
                $progress = FederationMigrationService::getProgress($migrationId);
                echo "data: " . json_encode($progress) . "\n\n";
                ob_flush();
                flush();

                // Stop if final state
                if (in_array($progress['status'] ?? '', ['completed', 'failed', 'rolled_back', 'cancelled'])) {
                    break;
                }

                sleep(1);
            }
            return;
        }

        header('Content-Type: application/json');
        echo json_encode(FederationMigrationService::getProgress($migrationId));
    }

    /**
     * POST /accounts/{id}/federation-migrate/pause
     */
    public function migratePause(int $accountId): void
    {
        header('Content-Type: application/json');
        $migrationId = $_POST['migration_id'] ?? '';
        echo json_encode(FederationMigrationService::pause($migrationId));
    }

    /**
     * POST /accounts/{id}/federation-migrate/resume
     */
    public function migrateResume(int $accountId): void
    {
        header('Content-Type: application/json');
        $migrationId = $_POST['migration_id'] ?? '';
        echo json_encode(FederationMigrationService::resume($migrationId));
    }

    /**
     * POST /accounts/{id}/federation-migrate/cancel
     */
    public function migrateCancel(int $accountId): void
    {
        header('Content-Type: application/json');
        $migrationId = $_POST['migration_id'] ?? '';
        $result = FederationMigrationService::cancel($migrationId);

        if ($result['ok']) {
            LogService::log('federation.migration.cancel', null, "Migration cancelled: {$migrationId}");
        }

        echo json_encode($result);
    }

    /**
     * GET /accounts/{id}/federation-migrate/logs
     * Get migration logs.
     */
    public function migrateLogs(int $accountId): void
    {
        header('Content-Type: application/json');
        $migrationId = $_GET['migration_id'] ?? '';
        $step = $_GET['step'] ?? null;
        echo json_encode(FederationMigrationService::getLogs($migrationId, $step));
    }
}
