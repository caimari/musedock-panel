<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Flash;
use MuseDockPanel\Router;
use MuseDockPanel\Services\MailCaptureService as C;
use MuseDockPanel\View;

/** Correo que las webs intentaron mandar por el sendmail local y que el panel ha capturado o bloqueado. */
class WebMailCaptureController
{
    public function index(): void
    {
        View::render('mail/web-capture', [
            'layout' => 'main',
            'pageTitle' => 'Correo de las webs capturado',
            'rows' => C::list(300),
            'spoolOk' => is_dir(C::SPOOL),
        ]);
    }

    public function show(): void
    {
        $name = (string)($_GET['f'] ?? '');
        $raw = C::read($name);
        if ($raw === null) {
            Flash::set('error', 'Mensaje no encontrado.');
            Router::redirect('/mail/web-capture');
            return;
        }
        View::render('mail/web-capture-show', [
            'layout' => 'main',
            'pageTitle' => 'Correo capturado',
            'name' => $name,
            'raw' => $raw,
        ]);
    }

    public function delete(): void
    {
        $name = (string)($_POST['f'] ?? '');
        $ok = C::delete($name);
        Flash::set($ok ? 'success' : 'error', $ok ? 'Mensaje borrado.' : 'No se pudo borrar.');
        Router::redirect('/mail/web-capture');
    }

    public function purge(): void
    {
        $n = C::prune();
        Flash::set('success', "Limpieza hecha: {$n} mensajes antiguos borrados (más de " . C::MAX_KEEP_DAYS . ' días).');
        Router::redirect('/mail/web-capture');
    }
}
