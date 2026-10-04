<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\WitnessService;
use MuseDockPanel\View;

/**
 * Ajustes → Testigos: testigos externos "solo ojos" de este panel (WitnessService).
 * Ver su estado, generar el script de instalación de uno nuevo (sin secretos: la clave
 * y el certificado se crean en el testigo) y registrarlo / quitarlo con la contraseña
 * de administrador.
 */
class WitnessController
{
    public function index(): void
    {
        View::render('settings/witnesses', [
            'layout' => 'main',
            'pageTitle' => 'Testigos',
            'witnesses' => array_map(static fn($w) => ['name' => $w['name'], 'url' => $w['url'], 'fingerprint' => $w['fingerprint']], WitnessService::all()),
            'defaultTargets' => WitnessService::defaultTargets(),
            'defaultAllowed' => WitnessService::defaultAllowed(),
        ]);
    }

    /** GET: estado en vivo de cada testigo (JSON). */
    public function status(): void
    {
        $out = [];
        foreach (WitnessService::all() as $w) {
            $a = WitnessService::query($w);
            $out[] = ['name' => $w['name'], 'ok' => !empty($a['ok']), 'error' => $a['error'] ?? '', 'version' => $a['version'] ?? '',
                      'targets' => $a['targets'] ?? []];
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'witnesses' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** POST: descarga el script de instalación (sin secretos). */
    public function script(): void
    {
        View::verifyCsrf();
        $targets = json_decode((string)($_POST['targets'] ?? ''), true);
        if (!is_array($targets)) {
            Flash::set('error', 'Las comprobaciones no son un JSON válido.');
            Router::redirect('/settings/witnesses');
            return;
        }
        $allowed = preg_split('/[\s,]+/', trim((string)($_POST['allowed'] ?? ''))) ?: [];
        $r = WitnessService::installScript(trim((string)($_POST['listen'] ?? '')), (int)($_POST['port'] ?? 8447), $targets, $allowed, (int)($_POST['interval'] ?? 15));
        if (empty($r['ok'])) {
            Flash::set('error', 'No se pudo generar: ' . ($r['error'] ?? '?'));
            Router::redirect('/settings/witnesses');
            return;
        }
        header('Content-Type: text/x-shellscript; charset=utf-8');
        header('Content-Disposition: attachment; filename="musedock-witness-install.sh"');
        header('Cache-Control: no-store');
        echo $r['script'];
        exit;
    }

    /** POST: registrar un testigo (contraseña de administrador). */
    public function add(): void
    {
        View::verifyCsrf();
        if (!$this->adminPasswordOk((string)($_POST['admin_password'] ?? ''))) {
            Flash::set('error', 'Contraseña de administrador incorrecta.');
            Router::redirect('/settings/witnesses');
            return;
        }
        $r = WitnessService::add((string)($_POST['name'] ?? ''), trim((string)($_POST['url'] ?? '')), (string)($_POST['fingerprint'] ?? ''), trim((string)($_POST['key'] ?? '')));
        Flash::set(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
            ? 'Testigo registrado: responde con ' . count($r['targets'] ?? []) . ' comprobaciones.'
            : 'No se registró: ' . ($r['error'] ?? '?'));
        Router::redirect('/settings/witnesses');
    }

    /** POST: quitar un testigo (contraseña de administrador). */
    public function remove(): void
    {
        View::verifyCsrf();
        if (!$this->adminPasswordOk((string)($_POST['admin_password'] ?? ''))) {
            Flash::set('error', 'Contraseña de administrador incorrecta.');
            Router::redirect('/settings/witnesses');
            return;
        }
        $name = (string)($_POST['name'] ?? '');
        Flash::set('success', WitnessService::remove($name) ? "Testigo {$name} quitado de este panel (el servidor testigo no se toca)." : 'No estaba registrado.');
        Router::redirect('/settings/witnesses');
    }

    private function adminPasswordOk(string $password): bool
    {
        $id = (int)($_SESSION['panel_user']['id'] ?? 0);
        $a = $id > 0 ? Database::fetchOne('SELECT password_hash FROM panel_admins WHERE id = :id', ['id' => $id]) : null;
        return $a && password_verify($password, (string)$a['password_hash']);
    }
}
