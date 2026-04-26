<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\Auth;
use MuseDockPanel\Flash;
use MuseDockPanel\RateLimiter;
use MuseDockPanel\Router;
use MuseDockPanel\Security\ClientIp;
use MuseDockPanel\Services\MfaService;
use MuseDockPanel\Services\SecurityService;
use MuseDockPanel\Settings;
use MuseDockPanel\View;

class AuthController
{
    public function loginForm(): void
    {
        if (!empty($_SESSION['mfa_pending'])) {
            unset($_SESSION['mfa_pending']);
        }
        if (Auth::check()) {
            Router::redirect('/');
            return;
        }
        View::render('auth/login');
    }

    public function login(): void
    {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = ClientIp::resolve() ?: 'unknown';
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        if (!View::verifyCsrf()) {
            Flash::set('error', 'Sesion expirada. Intenta de nuevo.');
            Router::redirect('/login');
            return;
        }

        if (!RateLimiter::check($ip, 'panel-login', 20)) {
            self::writeAuthLog($ip, $username !== '' ? $username : '-', false);
            Flash::set('error', 'Demasiados intentos. Espera un minuto.');
            Router::redirect('/login');
            return;
        }

        if (empty($username) || empty($password)) {
            Flash::set('error', 'Usuario y contraseña son obligatorios.');
            Router::redirect('/login');
            return;
        }

        $user = Auth::verifyCredentials($username, $password);
        if (!$user) {
            SecurityService::recordAdminLoginEvent(null, $username, $ip, false, [], false, '', $userAgent);
            self::writeAuthLog($ip, $username, false);
            Flash::set('error', 'Credenciales incorrectas.');
            Router::redirect('/login');
            return;
        }

        $mfaRequired = $this->isMfaRequiredForUser($user);
        if ($mfaRequired) {
            $secret = trim((string)($user['mfa_secret'] ?? ''));
            if ($secret === '') {
                self::writeAuthLog($ip, $username, false);
                Flash::set('error', 'MFA obligatorio: tu cuenta aun no tiene MFA configurado. Activalo desde tu perfil con otro admin o desactiva la obligatoriedad temporalmente.');
                Router::redirect('/login');
                return;
            }

            $_SESSION['mfa_pending'] = [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'ip' => $ip,
                'ua' => $userAgent,
                'ts' => time(),
            ];
            self::writeAuthLog($ip, $username, false, 'MFA_REQUIRED');
            Router::redirect('/login/mfa');
            return;
        }

        Auth::loginUser($user);
        self::writeAuthLog($ip, $username, true);
        $this->handleSuccessfulLoginSecurity((int)$user['id'], (string)$user['username'], $ip, $userAgent);
        Flash::set('success', 'Bienvenido al panel.');
        Router::redirect('/');
    }

    public function mfaForm(): void
    {
        if (Auth::check()) {
            Router::redirect('/');
            return;
        }

        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id']) || empty($pending['username'])) {
            Flash::set('error', 'Sesion MFA no valida. Inicia login de nuevo.');
            Router::redirect('/login');
            return;
        }

        if ((time() - (int)($pending['ts'] ?? 0)) > 600) {
            unset($_SESSION['mfa_pending']);
            Flash::set('error', 'Sesion MFA expirada. Inicia login de nuevo.');
            Router::redirect('/login');
            return;
        }

        View::render('auth/login-mfa', [
            'username' => (string)$pending['username'],
        ]);
    }

    public function mfaVerify(): void
    {
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id']) || empty($pending['username'])) {
            Flash::set('error', 'Sesion MFA no valida. Inicia login de nuevo.');
            Router::redirect('/login');
            return;
        }

        if (!View::verifyCsrf()) {
            Flash::set('error', 'Sesion expirada. Intenta de nuevo.');
            Router::redirect('/login/mfa');
            return;
        }

        $ip = ClientIp::resolve() ?: ((string)($pending['ip'] ?? 'unknown'));
        if (!RateLimiter::check($ip, 'panel-login-mfa', 20)) {
            Flash::set('error', 'Demasiados intentos MFA. Espera un minuto.');
            Router::redirect('/login/mfa');
            return;
        }

        if ((time() - (int)($pending['ts'] ?? 0)) > 600) {
            unset($_SESSION['mfa_pending']);
            Flash::set('error', 'Sesion MFA expirada. Inicia login de nuevo.');
            Router::redirect('/login');
            return;
        }

        $user = Auth::findActiveUser((string)$pending['username']);
        if (!$user || (int)$user['id'] !== (int)$pending['id']) {
            unset($_SESSION['mfa_pending']);
            Flash::set('error', 'Usuario MFA no valido. Inicia login de nuevo.');
            Router::redirect('/login');
            return;
        }

        $secret = trim((string)($user['mfa_secret'] ?? ''));
        if ($secret === '') {
            unset($_SESSION['mfa_pending']);
            Flash::set('error', 'MFA no configurado para este usuario.');
            Router::redirect('/login');
            return;
        }

        $secret = \MuseDockPanel\Services\ReplicationService::decryptPassword($secret);
        $code = trim((string)($_POST['mfa_code'] ?? ''));
        if (!MfaService::verifyCode($secret, $code, 1)) {
            self::writeAuthLog($ip, (string)$pending['username'], false, 'MFA_FAIL');
            Flash::set('error', 'Codigo MFA incorrecto.');
            Router::redirect('/login/mfa');
            return;
        }

        Auth::loginUser($user);
        unset($_SESSION['mfa_pending']);
        self::writeAuthLog($ip, (string)$pending['username'], true, 'MFA_OK');
        $this->handleSuccessfulLoginSecurity((int)$user['id'], (string)$user['username'], $ip, (string)($pending['ua'] ?? ''));
        Flash::set('success', 'Bienvenido al panel.');
        Router::redirect('/');
    }

    private function isMfaRequiredForUser(array $user): bool
    {
        $globalRequired = Settings::get('security_mfa_required', '0') === '1';
        $userEnabled = (string)($user['mfa_enabled'] ?? '') === '1'
            || (bool)($user['mfa_enabled'] ?? false);
        return $globalRequired || $userEnabled;
    }

    private function handleSuccessfulLoginSecurity(int $adminId, string $username, string $ip, string $userAgent): void
    {
        $host = gethostname() ?: 'localhost';
        $ctx = SecurityService::lookupIpContext($ip);
        $analysis = SecurityService::analyzeLoginAnomaly($adminId, $ip, $ctx);
        $anomaly = !empty($analysis['anomaly']);
        $reason = (string)($analysis['reason'] ?? '');

        SecurityService::recordAdminLoginEvent($adminId, $username, $ip, true, $ctx, $anomaly, $reason, $userAgent);

        if ($anomaly) {
            SecurityService::notifyLoginAnomaly($host, $username, $ip, $ctx, $reason, $userAgent);
        }
    }

    private static function writeAuthLog(string $ip, string $username, bool $success, string $tag = ''): void
    {
        $status = $success ? 'OK' : 'FAIL';
        if ($tag !== '') {
            $status .= ':' . $tag;
        }
        $line = date('Y-m-d H:i:s') . " {$status} login from {$ip} user {$username}\n";
        @file_put_contents('/var/log/musedock-panel-auth.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function logout(): void
    {
        Auth::logout();
        Router::redirect('/login');
    }
}
