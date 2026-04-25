<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;

class DocsController
{
    private function topics(): array
    {
        return [
            [
                'title' => 'Modos de correo',
                'description' => 'Como elegir entre Satellite, Relay Privado, Correo Completo y SMTP Externo.',
                'url' => '/docs/mail-modes',
                'category' => 'Mail',
                'icon' => 'bi-envelope',
                'keywords' => 'mail correo smtp satellite relay wireguard postfix dkim roundcube sieve externo',
            ],
        ];
    }

    public function index(): void
    {
        $query = trim((string)($_GET['q'] ?? ''));
        $topics = $this->topics();

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $topics = array_values(array_filter($topics, static function (array $topic) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $topic['title'] ?? '',
                    $topic['description'] ?? '',
                    $topic['category'] ?? '',
                    $topic['keywords'] ?? '',
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        View::render('help/index', [
            'layout' => 'main',
            'pageTitle' => 'Docs',
            'topics' => $topics,
            'query' => $query,
        ]);
    }

    public function mailModes(): void
    {
        View::render('help/mail-modes', [
            'layout' => 'main',
            'pageTitle' => 'Docs - Mail Modes',
        ]);
    }
}
