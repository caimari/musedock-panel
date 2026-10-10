<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Settings;
use MuseDockPanel\View;
use MuseDockPanel\Services\DomainDnsSyncService;

class DomainDnsSyncController
{
    public function index(): void
    {
        View::render('domains/dns-sync', ['layout' => 'main', 'pageTitle' => 'Revisar DNS del dominio',
            'domain' => DomainDnsSyncService::normalizeHost((string)($_GET['domain'] ?? '')),
            'scope' => ($_GET['scope'] ?? '') === 'mail' ? 'mail' : 'hosting',
            'mailExists' => (bool)\MuseDockPanel\Services\MailService::getDomainByName((string)($_GET['domain'] ?? '')),
            'readOnly' => Settings::get('cluster_role', 'standalone') === 'slave']);
    }

    public function plan(): void
    {
        $this->json(function () {
            $plan = DomainDnsSyncService::plan((string)($_POST['domain'] ?? ''), (string)($_POST['scope'] ?? ''));
            $token = bin2hex(random_bytes(24));
            // One review per session: old confirmations cannot be reused after a new review.
            $_SESSION['domain_dns_review'] = ['token' => $token, 'expires' => time() + 600, 'plan' => $plan];
            return ['ok' => true, 'token' => $token, 'plan' => $plan];
        });
    }

    public function apply(): void
    {
        $this->json(function () {
            if (Settings::get('cluster_role', 'standalone') === 'slave') throw new \RuntimeException('Publica DNS desde el master.');
            $review = $_SESSION['domain_dns_review'] ?? null;
            if (!$review || $review['expires'] < time() || !hash_equals($review['token'], (string)($_POST['token'] ?? ''))
                || ($_POST['confirmed'] ?? '') !== '1') throw new \RuntimeException('La revisión ha caducado o no se ha confirmado. Obtén un plan nuevo.');
            unset($_SESSION['domain_dns_review']);
            return DomainDnsSyncService::apply($review['plan']);
        });
    }

    public function verify(): void
    {
        $this->json(fn() => DomainDnsSyncService::verify((string)($_POST['domain'] ?? ''), (string)($_POST['scope'] ?? '')));
    }

    private function json(callable $fn): void
    {
        header('Content-Type: application/json');
        try { echo json_encode($fn(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
        catch (\Throwable $e) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
