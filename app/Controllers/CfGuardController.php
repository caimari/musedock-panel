<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\CfGuardService as G;
use MuseDockPanel\Services\LogService;
use MuseDockPanel\Settings;
use MuseDockPanel\View;

/**
 * Ajustes → Bloqueos Cloudflare (CfGuardService) y API de las sondas.
 *   GET  /api/cf-guard/targets   texto plano, una línea por objetivo: «id ip host» (Bearer de sonda)
 *   POST /api/cf-guard/report    JSON {paths:[...], dns:[...]} (Bearer de sonda)
 * Mismo protocolo que MuseDock CMS: la misma sonda sirve para los dos, con su URL y su token.
 */
class CfGuardController
{
    private const BACK = '/settings/cf-guard';

    public function index(): void
    {
        $newToken = $_SESSION['cf_guard_new_token'] ?? null;
        unset($_SESSION['cf_guard_new_token']);
        $records = G::records();
        $states = G::edgeStates();
        $byIp = [];
        foreach ($records as $r) {
            if ((int) $r['proxied']) {
                foreach (array_filter(explode(',', (string) $r['edge_ips'])) as $ip) {
                    $byIp[$ip][] = $r['name'];
                }
            }
        }
        $changedNow = [];
        foreach (G::activeChanges() as $c) {
            if (!(int) $c['simulated']) {
                $changedNow[$c['record_id']] = $c;
                foreach (array_filter(explode(',', (string) $c['edge_ips'])) as $ip) {
                    $byIp[$ip][] = $c['name'] . ' (sin proxy)';
                }
            }
        }
        $zoneBad = [];
        $domains = [];
        foreach ($records as $r) {
            $chg = $changedNow[$r['record_id']] ?? null;
            $zoneBad[$r['zone_id']] ??= G::zoneUnhealthyIps($r['zone_id'], $states);
            $domains[] = [
                'record_id' => $r['record_id'], 'name' => $r['name'], 'type' => $r['type'], 'content' => $r['content'],
                'original' => $chg ? true : (bool) (int) $r['proxied'], 'now' => (bool) (int) $r['proxied'],
                'changed_at' => $chg['changed_at'] ?? null, 'manual' => $chg && $chg['cause'] === 'manual',
                'excluded' => G::isExcluded($r['name']), 'zone_blocked' => $zoneBad[$r['zone_id']],
            ];
        }
        $rank = fn ($s) => $s['state'] === 'blocked' ? 0 : (in_array(false, $s['paths'], true) ? 1 : 2);
        uasort($states, fn ($a, $b) => $rank($a) <=> $rank($b));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        View::render('settings/cf-guard', [
            'layout' => 'main',
            'pageTitle' => 'Bloqueos Cloudflare',
            'mode' => G::mode(),
            'isActive' => G::isActive(),
            'isSlave' => Settings::get('cluster_role', 'standalone') === 'slave',
            'restoreMinutes' => G::restoreMinutes(),
            'excluded' => G::setting('excluded', ''),
            'extraHosts' => G::setting('extra_hosts', ''),
            'cmsManaged' => G::cmsManaged(),
            'probes' => G::probes(),
            'probePaths' => G::probePaths(),
            'livePaths' => G::livePaths(),
            'states' => $states,
            'byIp' => $byIp,
            'records' => $records,
            'domains' => $domains,
            'active' => G::activeChanges(),
            'history' => G::history(),
            'events' => G::events(),
            'episodes' => G::openEpisodes(),
            'inventoryAt' => (int) G::setting('inventory_at', '0'),
            'inventoryErrors' => G::setting('inventory_errors', ''),
            'newToken' => $newToken,
            'apiBase' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-panel'),
        ]);
    }

    private function guardWrite(bool $needPassword = false): bool
    {
        View::verifyCsrf();
        if (Settings::get('cluster_role', 'standalone') === 'slave') {
            Flash::set('error', 'Este servidor es slave: los cambios se hacen en el master.');
            Router::redirect(self::BACK);

            return false;
        }
        if ($needPassword && !$this->adminPasswordOk((string) ($_POST['admin_password'] ?? ''))) {
            Flash::set('error', 'Contraseña incorrecta: no se ha cambiado nada.');
            Router::redirect(self::BACK);

            return false;
        }

        return true;
    }

    public function save(): void
    {
        if (!$this->guardWrite()) {
            return;
        }
        $mode = (string) ($_POST['mode'] ?? 'off');
        if (in_array($mode, G::MODES, true) && $mode !== G::mode()) {
            G::setSetting('mode', $mode);
            G::event('info', 'Modo cambiado a «' . ['off' => 'apagado', 'simulate' => 'simulación', 'auto' => 'automático'][$mode] . '».');
            LogService::log('cf_guard.mode', $mode, null);
        }
        G::setSetting('restore_minutes', (string) max(10, min(240, (int) ($_POST['restore_minutes'] ?? 30))));
        $ex = implode("\n", array_slice(array_filter(array_map(fn ($l) => strtolower(trim($l, " \t.")), preg_split('/[\r\n,]+/', (string) ($_POST['excluded'] ?? '')))), 0, 500));
        G::setSetting('excluded', $ex);
        G::setSetting('extra_hosts', implode("\n", G::normalizeHostList((string) ($_POST['extra_hosts'] ?? ''))));
        Flash::set('success', 'Ajustes guardados.');
        Router::redirect(self::BACK);
    }

    public function createProbe(): void
    {
        if (!$this->guardWrite()) {
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Pon un nombre a la sonda (p. ej. b250).');
            Router::redirect(self::BACK);

            return;
        }
        $_SESSION['cf_guard_new_token'] = ['name' => $name, 'token' => G::createProbe($name)];
        LogService::log('cf_guard.probe_create', $name, null);
        Router::redirect(self::BACK);
    }

    public function revokeProbe(): void
    {
        if (!$this->guardWrite()) {
            return;
        }
        G::revokeProbe((string) ($_POST['name'] ?? ''));
        LogService::log('cf_guard.probe_revoke', (string) ($_POST['name'] ?? ''), null);
        Flash::set('success', 'Sonda revocada: su token deja de valer.');
        Router::redirect(self::BACK);
    }

    public function inventory(): void
    {
        if (!$this->guardWrite()) {
            return;
        }
        try {
            $r = G::refreshInventory();
            Flash::set($r['errors'] ? 'error' : 'success', "Leídos {$r['records']} registros de Cloudflare ({$r['proxied']} con proxy)." . ($r['errors'] ? ' Errores: ' . implode('; ', $r['errors']) : ''));
        } catch (\Throwable $e) {
            Flash::set('error', 'No se pudo leer Cloudflare: ' . $e->getMessage());
        }
        Router::redirect(self::BACK);
    }

    public function clearLog(): void
    {
        if (!$this->guardWrite()) {
            return;
        }
        G::clearLog();
        Flash::set('success', 'Registro vaciado. Los cambios activos se conservan para poder deshacerlos.');
        Router::redirect(self::BACK);
    }

    public function setOriginal(): void
    {
        if (!$this->guardWrite(true)) {
            return;
        }
        $who = (string) ($_SESSION['panel_user']['username'] ?? $_SESSION['panel_user']['email'] ?? 'admin');
        try {
            $msg = G::setOriginal((string) ($_POST['record_id'] ?? ''), ($_POST['proxied'] ?? '') === '1', $who);
            LogService::log('cf_guard.set_original', (string) ($_POST['record_id'] ?? ''), $msg);
            Flash::set('success', $msg);
        } catch (\Throwable $e) {
            Flash::set('error', 'No se pudo cambiar: ' . $e->getMessage());
        }
        Router::redirect(self::BACK);
    }

    /** Descarga del cliente de sonda (el mismo que usa MuseDock CMS). */
    public function downloadProbe(): void
    {
        header('Content-Type: text/x-python; charset=utf-8');
        header('Content-Disposition: attachment; filename="musedock-edge-probe.py"');
        header('Cache-Control: no-store');
        readfile(PANEL_ROOT . '/bin/edge-guard-probe.py');
        exit;
    }

    // ------------------------------------------------------------------ API de sondas

    public function apiTargets(): void
    {
        $probe = $this->probeAuth();
        if ($probe === null) {
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        foreach (G::targets() as $t) {
            echo $t['id'], ' ', $t['ip'], ' ', $t['host'], "\n";
        }
    }

    public function apiReport(): void
    {
        $probe = $this->probeAuth();
        if ($probe === null) {
            return;
        }
        $data = json_decode((string) file_get_contents('php://input', false, null, 0, 512 * 1024), true);
        if (!is_array($data) || !is_array($data['paths'] ?? null)) {
            $this->json(400, ['ok' => false, 'error' => 'JSON no válido']);

            return;
        }
        if (time() - (int) G::setting('last_report_' . $probe, '0') < 20) {
            $this->json(429, ['ok' => false, 'error' => 'Demasiado seguido']);

            return;
        }
        G::setSetting('last_report_' . $probe, (string) time());
        $this->json(200, ['ok' => true, 'stored' => G::ingest($probe, $data['paths'], is_array($data['dns'] ?? null) ? $data['dns'] : [])]);
    }

    private function probeAuth(): ?string
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/^Bearer\s+(egp_[a-f0-9]{48})$/i', trim($auth), $m) ? $m[1] : '';
        $probe = $token !== '' ? G::probeForToken($token) : null;
        if ($probe === null) {
            header('WWW-Authenticate: Bearer');
            $this->json(401, ['ok' => false, 'error' => 'Token de sonda no válido']);
        }

        return $probe;
    }

    private function json(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function adminPasswordOk(string $password): bool
    {
        $id = (int) ($_SESSION['panel_user']['id'] ?? 0);
        $a = $id > 0 ? Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $id]) : null;

        return $a && password_verify($password, (string) $a['password_hash']);
    }
}
