<?php
namespace MuseDockPanel\Controllers;

use MuseDockPanel\View;
use MuseDockPanel\Flash;
use MuseDockPanel\Settings;
use MuseDockPanel\Services\NotificationService;
use MuseDockPanel\Services\ReplicationService;
use MuseDockPanel\Services\LogService;

class NotificationController
{
    /**
     * GET /settings/notifications
     */
    public function index(): void
    {
        // Auto-migrate old cluster_* keys on first visit
        NotificationService::migrateOldKeys();

        $settings = Settings::getAll();
        $recipientEmail = NotificationService::getRecipientEmail();

        // Decrypt sensitive fields for display
        if (!empty($settings['notify_telegram_token'])) {
            $settings['notify_telegram_token'] = ReplicationService::decryptPassword($settings['notify_telegram_token']);
        }
        if (!empty($settings['notify_smtp_pass'])) {
            $settings['notify_smtp_pass'] = ReplicationService::decryptPassword($settings['notify_smtp_pass']);
        }

        View::render('settings/notifications', [
            'layout'         => 'main',
            'pageTitle'      => 'Notificaciones',
            'settings'       => $settings,
            'recipientEmail' => $recipientEmail,
        ]);
    }

    /**
     * POST /settings/notifications/save
     */
    public function save(): void
    {
        View::verifyCsrf();

        // Email method
        $method = in_array($_POST['notify_email_method'] ?? '', ['smtp', 'php']) ? $_POST['notify_email_method'] : 'smtp';
        Settings::set('notify_email_method', $method);

        // SMTP settings
        Settings::set('notify_smtp_host', trim($_POST['notify_smtp_host'] ?? ''));
        Settings::set('notify_smtp_port', (string)(int)($_POST['notify_smtp_port'] ?? 587));
        Settings::set('notify_smtp_user', trim($_POST['notify_smtp_user'] ?? ''));
        Settings::set('notify_smtp_from', trim($_POST['notify_smtp_from'] ?? ''));
        Settings::set('notify_smtp_from_name', trim($_POST['notify_smtp_from_name'] ?? ''));

        $encryption = in_array($_POST['notify_smtp_encryption'] ?? '', ['tls', 'ssl', 'none']) ? $_POST['notify_smtp_encryption'] : 'tls';
        Settings::set('notify_smtp_encryption', $encryption);

        // SMTP password (only update if provided)
        $smtpPass = $_POST['notify_smtp_pass'] ?? '';
        if ($smtpPass !== '') {
            Settings::set('notify_smtp_pass', ReplicationService::encryptPassword($smtpPass));
        }

        // Recipient email (manual override)
        Settings::set('notify_email_to', trim($_POST['notify_email_to'] ?? ''));

        // Telegram (encrypt token)
        $telegramToken = trim($_POST['notify_telegram_token'] ?? '');
        if ($telegramToken !== '') {
            Settings::set('notify_telegram_token', ReplicationService::encryptPassword($telegramToken));
        }
        Settings::set('notify_telegram_chat_id', trim($_POST['notify_telegram_chat_id'] ?? ''));

        // Also update old cluster_* keys for backwards compatibility
        Settings::set('cluster_smtp_host', trim($_POST['notify_smtp_host'] ?? ''));
        Settings::set('cluster_smtp_port', (string)(int)($_POST['notify_smtp_port'] ?? 587));
        Settings::set('cluster_smtp_user', trim($_POST['notify_smtp_user'] ?? ''));
        Settings::set('cluster_smtp_from', trim($_POST['notify_smtp_from'] ?? ''));
        Settings::set('cluster_smtp_to', trim($_POST['notify_email_to'] ?? ''));
        if ($smtpPass !== '') {
            Settings::set('cluster_smtp_pass', ReplicationService::encryptPassword($smtpPass));
        }
        if ($telegramToken !== '') {
            Settings::set('cluster_telegram_token', ReplicationService::encryptPassword($telegramToken));
        }
        Settings::set('cluster_telegram_chat_id', trim($_POST['notify_telegram_chat_id'] ?? ''));

        LogService::log('notifications.save', 'settings', 'Configuracion de notificaciones guardada');
        Flash::set('success', 'Configuracion de notificaciones guardada.');
        header('Location: /settings/notifications');
        exit;
    }

    /**
     * POST /settings/notifications/test-email (JSON)
     */
    public function testEmail(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        // Check if PHP mail() method is selected but sendmail is not installed
        $method = Settings::get('notify_email_method', 'smtp');
        if ($method === 'php' && !file_exists('/usr/sbin/sendmail')) {
            echo json_encode([
                'ok' => false,
                'message' => 'PHP mail() requiere sendmail o postfix instalado. Instala con: apt install postfix, o usa SMTP.',
            ]);
            exit;
        }

        $result = NotificationService::sendEmail(
            'Test - MuseDock Panel',
            'Este es un email de prueba enviado desde MuseDock Panel. Si recibes este mensaje, la configuracion de email funciona correctamente.'
        );

        $errorMsg = 'Error al enviar email.';
        if (!$result && $method === 'smtp') {
            $errorMsg .= ' Revisa la configuracion SMTP (host, puerto, credenciales).';
        } elseif (!$result && $method === 'php') {
            $errorMsg .= ' PHP mail() fallo. Revisa los logs del sistema.';
        }

        echo json_encode([
            'ok' => $result,
            'message' => $result ? 'Email de prueba enviado correctamente' : $errorMsg,
        ]);
        exit;
    }

    /**
     * POST /settings/notifications/test-telegram (JSON)
     */
    public function testTelegram(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        $result = NotificationService::sendTelegram(
            "Test - MuseDock Panel\n\nEste es un mensaje de prueba. Si recibes esto, la configuracion de Telegram funciona correctamente."
        );

        echo json_encode([
            'ok' => $result,
            'message' => $result
                ? 'Mensaje de Telegram enviado correctamente'
                : 'Error al enviar mensaje. Revisa el Bot Token y el Chat ID.',
        ]);
        exit;
    }
}
