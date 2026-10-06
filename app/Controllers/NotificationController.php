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
        Settings::set('notify_brand', mb_substr(trim((string)($_POST['notify_brand'] ?? '')), 0, 60));

        $encryption = in_array($_POST['notify_smtp_encryption'] ?? '', ['tls', 'ssl', 'none']) ? $_POST['notify_smtp_encryption'] : 'tls';
        Settings::set('notify_smtp_encryption', $encryption);

        // SMTP password (only update if provided)
        $smtpPass = $_POST['notify_smtp_pass'] ?? '';
        if ($smtpPass !== '') {
            Settings::set('notify_smtp_pass', ReplicationService::encryptPassword($smtpPass));
        }

        // SMTP secundario (opcional): host vacío = sin reserva.
        Settings::set('notify_smtp2_host', trim($_POST['notify_smtp2_host'] ?? ''));
        Settings::set('notify_smtp2_port', (string)max(1, (int)($_POST['notify_smtp2_port'] ?? 587)));
        Settings::set('notify_smtp2_user', trim($_POST['notify_smtp2_user'] ?? ''));
        Settings::set('notify_smtp2_from', trim($_POST['notify_smtp2_from'] ?? ''));
        Settings::set('notify_smtp2_encryption', in_array($_POST['notify_smtp2_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['notify_smtp2_encryption'] : 'tls');
        if (($_POST['notify_smtp2_pass'] ?? '') !== '') {
            Settings::set('notify_smtp2_pass', ReplicationService::encryptPassword((string)$_POST['notify_smtp2_pass']));
        }

        // Recipient email (manual override)
        Settings::set('notify_email_to', trim($_POST['notify_email_to'] ?? ''));

        // Telegram (encrypt token)
        $telegramToken = trim($_POST['notify_telegram_token'] ?? '');
        if ($telegramToken !== '') {
            Settings::set('notify_telegram_token', ReplicationService::encryptPassword($telegramToken));
        }
        Settings::set('notify_telegram_chat_id', trim($_POST['notify_telegram_chat_id'] ?? ''));

        // Event notifications (system integrity / lifecycle)
        Settings::set('firewall_change_watch_enabled', isset($_POST['firewall_change_watch_enabled']) ? '1' : '0');
        Settings::set('server_reboot_notify_enabled', isset($_POST['server_reboot_notify_enabled']) ? '1' : '0');
        Settings::set('notify_event_collector_gap_enabled', isset($_POST['notify_event_collector_gap_enabled']) ? '1' : '0');
        Settings::set('notify_event_hardening_enabled', isset($_POST['notify_event_hardening_enabled']) ? '1' : '0');
        Settings::set('notify_event_config_drift_enabled', isset($_POST['notify_event_config_drift_enabled']) ? '1' : '0');
        Settings::set('notify_event_public_exposure_enabled', isset($_POST['notify_event_public_exposure_enabled']) ? '1' : '0');
        Settings::set('notify_login_anomaly_enabled', isset($_POST['notify_login_anomaly_enabled']) ? '1' : '0');

        $gapSeconds = (int)($_POST['notify_event_collector_gap_seconds'] ?? 300);
        $gapSeconds = max(120, min(86400, $gapSeconds));
        Settings::set('notify_event_collector_gap_seconds', (string)$gapSeconds);

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

        // SMTP: probar lo escrito en el formulario (aunque no esté guardado), principal y, si
        // tiene host, secundario, cada uno por separado y con el motivo real si falla.
        $method = in_array($_POST['notify_email_method'] ?? '', ['smtp', 'php'], true) ? $_POST['notify_email_method'] : $method;
        if ($method === 'smtp' && trim((string)($_POST['notify_smtp_host'] ?? '')) !== '') {
            $to = trim((string)($_POST['notify_email_to'] ?? '')) ?: NotificationService::getAdminEmail();
            $from = trim((string)($_POST['notify_smtp_from'] ?? '')) ?: $to;
            $fromName = trim((string)($_POST['notify_smtp_from_name'] ?? ''));
            $lines = [];
            $okAll = true;
            foreach (['notify_smtp_' => 'principal', 'notify_smtp2_' => 'secundario'] as $pfx => $label) {
                $host = trim((string)($_POST[$pfx . 'host'] ?? ''));
                if ($host === '') {
                    continue;
                }
                $saved = NotificationService::smtpConfig($pfx);
                $pass = (string)($_POST[$pfx . 'pass'] ?? '');
                $cfg = [
                    'host' => $host,
                    'port' => (int)($_POST[$pfx . 'port'] ?? 587) ?: 587,
                    'user' => trim((string)($_POST[$pfx . 'user'] ?? '')),
                    'pass' => $pass !== '' ? $pass : (string)($saved['pass'] ?? ''),
                    'encryption' => in_array($_POST[$pfx . 'encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST[$pfx . 'encryption'] : 'tls',
                ];
                $sender = $pfx === 'notify_smtp2_' && trim((string)($_POST['notify_smtp2_from'] ?? '')) !== '' ? trim((string)$_POST['notify_smtp2_from']) : $from;
                $r = NotificationService::testSmtp($cfg, $to, $sender, $fromName);
                $okAll = $okAll && !empty($r['ok']);
                $lines[] = ucfirst($label) . ' (' . $host . '): ' . (!empty($r['ok']) ? 'enviado' : 'ERROR ' . ($r['error'] ?? '?'));
            }
            echo json_encode([
                'ok' => $okAll,
                'message' => htmlspecialchars(implode(' · ', $lines) . " → {$to}", ENT_QUOTES, 'UTF-8') . ($okAll ? ' (con lo escrito; recuerda Guardar)' : ''),
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
    /**
     * POST (AJAX): una contraseña guardada (SMTP principal o secundario), para el botón
     * copiar (p. ej. ponerla en otro servidor). Solo con sesión de administrador y token
     * CSRF; queda apuntado en el registro. No está en la página: se pide al pulsar.
     */
    public function reveal(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');
        $keys = ['smtp_pass' => 'notify_smtp_pass', 'smtp2_pass' => 'notify_smtp2_pass'];
        $field = (string)($_POST['field'] ?? '');
        if (!isset($keys[$field])) {
            echo json_encode(['ok' => false, 'error' => 'campo no válido']);
            exit;
        }
        $enc = Settings::get($keys[$field], '');
        $value = $enc !== '' ? ReplicationService::decryptPassword($enc) : '';
        LogService::log('notify.reveal', $field, 'Contraseña copiada desde Notificaciones');
        echo json_encode(['ok' => $value !== '', 'value' => $value, 'error' => $value === '' ? 'no hay contraseña guardada' : null]);
        exit;
    }

    public function testTelegram(): void
    {
        View::verifyCsrf();
        header('Content-Type: application/json');

        // Prueba con lo escrito en el formulario (aunque no esté guardado); si un campo está
        // vacío, con lo guardado.
        $token = trim((string)($_POST['notify_telegram_token'] ?? ''));
        $chat = trim((string)($_POST['notify_telegram_chat_id'] ?? ''));
        $err = null;
        $result = NotificationService::sendTelegram(
            "Test - MuseDock Panel\n\nEste es un mensaje de prueba. Si recibes esto, la configuracion de Telegram funciona correctamente.",
            $token !== '' ? $token : null,
            $chat !== '' ? $chat : null,
            $err
        );

        $hint = '';
        if (!$result && $err !== null && stripos($err, 'chat not found') !== false) {
            $hint = ' Abre el bot en Telegram y pulsa Iniciar (sin eso no puede escribirte), o revisa el Chat ID.';
        } elseif (!$result && $err !== null && stripos($err, 'unauthorized') !== false) {
            $hint = ' El Bot Token no es válido.';
        }
        echo json_encode([
            'ok' => $result,
            'message' => $result
                ? 'Mensaje de Telegram enviado correctamente' . ($token !== '' ? ' (con lo escrito; recuerda Guardar)' : '')
                : 'No se pudo enviar: ' . htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8') . '.' . $hint,
        ]);
        exit;
    }
}
