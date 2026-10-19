<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Database;
use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\WebMailAuditService as A;
use MuseDockPanel\Services\WebSmtpService;
use MuseDockPanel\View;

/** Informe del correo de las webs (qué mandan por el Postfix local y cómo pasarlas a SMTP autenticado). */
class WebMailAuditController
{
    public function index(): void
    {
        View::render('mail/web-audit', [
            'layout' => 'main',
            'pageTitle' => 'Correo de las webs: auditoría',
            'report' => A::cached(!empty($_GET['refresh'])),
        ]);
    }

    /** Configura (o quita) el SMTP autenticado de una web. */
    public function smtp(): void
    {
        $acc = Database::fetchOne('SELECT * FROM hosting_accounts WHERE id = :id', ['id' => (int)($_POST['id'] ?? 0)]);
        if (!$acc) {
            Flash::set('error', 'Cuenta no encontrada.');
            Router::redirect('/mail/web-audit');
            return;
        }
        if (($_POST['do'] ?? '') === 'remove') {
            $r = WebSmtpService::remove($acc);
            Flash::set('success', "SMTP de la web quitado ({$r['removed']} ficheros). " . $r['note']);
        } else {
            $then = in_array($_POST['then'] ?? 'capture', ['capture', 'block', 'keep'], true) ? (string)$_POST['then'] : 'capture';
            $r = WebSmtpService::provision($acc, true, $then);
            Flash::set($r['ok'] ? 'success' : 'error', $r['ok']
                ? 'SMTP autenticado configurado para ' . $acc['domain'] . ' (cuenta ' . $r['account'] . '). ' . implode(' · ', $r['steps'])
                : ($r['error'] ?? 'No se pudo.'));
        }
        A::cached(true);
        Router::redirect('/mail/web-audit');
    }
}
