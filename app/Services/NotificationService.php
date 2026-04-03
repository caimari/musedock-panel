<?php
namespace MuseDockPanel\Services;

use MuseDockPanel\Auth;
use MuseDockPanel\Database;
use MuseDockPanel\Settings;

/**
 * Unified notification service: Email (SMTP / PHP mail) + Telegram
 */
class NotificationService
{
    // ─── Public API ─────────────────────────────────────────

    /**
     * Send notification via all configured channels
     */
    public static function send(string $subject, string $message): void
    {
        if (Settings::get('monitor_notify_email', '0') === '1') {
            self::sendEmail($subject, $message);
        }
        if (Settings::get('monitor_notify_telegram', '0') === '1') {
            self::sendTelegram("{$subject}\n\n{$message}");
        }
    }

    // ─── Email ──────────────────────────────────────────────

    public static function sendEmail(string $subject, string $body): bool
    {
        $method = Settings::get('notify_email_method', 'smtp');
        $to = self::getRecipientEmail();

        if (!$to) return false;

        $from = Settings::get('notify_smtp_from', '');
        if (!$from) $from = self::getAdminEmail();
        $fromName = Settings::get('notify_smtp_from_name', '');

        if ($method === 'php') {
            return self::sendViaPhpMail($to, $from, $subject, $body, $fromName);
        }

        return self::sendViaSmtp($to, $from, $subject, $body, $fromName);
    }

    /**
     * Get the recipient email: manual override or admin's profile email
     */
    public static function getRecipientEmail(): string
    {
        $manual = Settings::get('notify_email_to', '');
        if ($manual !== '') return $manual;

        return self::getAdminEmail();
    }

    /**
     * Get the first admin's email from the database
     */
    public static function getAdminEmail(): string
    {
        try {
            // Try current logged-in admin first
            if (!empty($_SESSION['panel_user']['id'])) {
                $admin = Database::fetchOne(
                    "SELECT email FROM panel_admins WHERE id = :id",
                    ['id' => $_SESSION['panel_user']['id']]
                );
                if (!empty($admin['email'])) return $admin['email'];
            }

            // Fallback: first admin with an email (any role)
            $admin = Database::fetchOne(
                "SELECT email FROM panel_admins WHERE email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1"
            );
            return $admin['email'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function sendViaSmtp(string $to, string $from, string $subject, string $body, string $fromName = ''): bool
    {
        $host = Settings::get('notify_smtp_host', '');
        $port = (int)Settings::get('notify_smtp_port', '587');
        $user = Settings::get('notify_smtp_user', '');
        $pass = Settings::get('notify_smtp_pass', '');
        $encryption = Settings::get('notify_smtp_encryption', 'tls');

        if (!$host) return false;

        if ($pass) {
            $pass = ReplicationService::decryptPassword($pass);
        }

        try {
            // Determine connection prefix
            $prefix = '';
            if ($encryption === 'ssl') {
                $prefix = 'ssl://';
            }

            $socket = @fsockopen("{$prefix}{$host}", $port, $errno, $errstr, 10);
            if (!$socket) return false;

            $response = fgets($socket); // Greeting

            fwrite($socket, "EHLO musedock-panel\r\n");
            $response = self::readSmtpResponse($socket);

            // STARTTLS for TLS mode
            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $response = fgets($socket);
                if (!str_starts_with(trim($response), '220')) {
                    fclose($socket);
                    return false;
                }
                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if (!$crypto) {
                    fclose($socket);
                    return false;
                }
                fwrite($socket, "EHLO musedock-panel\r\n");
                $response = self::readSmtpResponse($socket);
            }

            // Auth
            if ($user) {
                fwrite($socket, "AUTH LOGIN\r\n"); fgets($socket);
                fwrite($socket, base64_encode($user) . "\r\n"); fgets($socket);
                fwrite($socket, base64_encode($pass) . "\r\n");
                $authResponse = trim(fgets($socket));
                if (!str_starts_with($authResponse, '235')) {
                    fclose($socket);
                    return false;
                }
            }

            fwrite($socket, "MAIL FROM:<{$from}>\r\n"); fgets($socket);
            fwrite($socket, "RCPT TO:<{$to}>\r\n"); fgets($socket);
            fwrite($socket, "DATA\r\n"); fgets($socket);

            $date = date('r');
            $fromHeader = $fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>" : $from;
            $headers = "From: {$fromHeader}\r\nTo: {$to}\r\nSubject: {$subject}\r\nDate: {$date}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            fwrite($socket, "{$headers}\r\n{$body}\r\n.\r\n");
            $dataResponse = trim(fgets($socket));
            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return str_starts_with($dataResponse, '250');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function readSmtpResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // Multi-line response: continues if 4th char is '-'
            if (isset($line[3]) && $line[3] !== '-') break;
        }
        return $response;
    }

    private static function sendViaPhpMail(string $to, string $from, string $subject, string $body, string $fromName = ''): bool
    {
        $fromHeader = $fromName ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>" : $from;
        $headers = "From: {$fromHeader}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, $subject, $body, $headers);
    }

    // ─── Telegram ───────────────────────────────────────────

    public static function sendTelegram(string $message): bool
    {
        $botTokenEnc = Settings::get('notify_telegram_token', '');
        $chatId      = Settings::get('notify_telegram_chat_id', '');

        if (!$botTokenEnc || !$chatId) return false;

        $botToken = ReplicationService::decryptPassword($botTokenEnc);
        if (!$botToken) return false;

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id' => $chatId,
                'text'    => $message,
            ]),
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    // ─── Migration Helper ───────────────────────────────────

    /**
     * Migrate old cluster_* notification keys to new notify_* keys
     */
    public static function migrateOldKeys(): void
    {
        $mapping = [
            'cluster_smtp_host'        => 'notify_smtp_host',
            'cluster_smtp_port'        => 'notify_smtp_port',
            'cluster_smtp_user'        => 'notify_smtp_user',
            'cluster_smtp_pass'        => 'notify_smtp_pass',
            'cluster_smtp_from'        => 'notify_smtp_from',
            'cluster_smtp_to'          => 'notify_email_to',
            'cluster_telegram_token'   => 'notify_telegram_token',
            'cluster_telegram_chat_id' => 'notify_telegram_chat_id',
        ];

        foreach ($mapping as $old => $new) {
            $val = Settings::get($old, '');
            if ($val !== '' && Settings::get($new, '') === '') {
                Settings::set($new, $val);
            }
        }
    }
}
