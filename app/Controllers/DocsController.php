<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;

class DocsController
{
    public function mailModes(): void
    {
        View::render('help/mail-modes', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Mail Modes',
        ]);
    }
}
