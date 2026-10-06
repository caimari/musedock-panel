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
    /**
     * "[servidor] asunto": con varios paneles avisando al mismo buzón (master, slaves,
     * nodos) hay que saber de un vistazo QUIÉN avisa. Se usa el hostname del servidor
     * (nada fijo en el código); si el asunto ya lo lleva, no se repite.
     */
    public static function tagSubject(string $subject): string
    {
        $host = (string)(Settings::get('panel_hostname', '') ?: gethostname() ?: 'servidor');
        $short = explode('.', $host)[0];
        // Ya lo lleva (p. ej. "[mortadelo.musedock.com] …"): no repetir.
        if (stripos($subject, $host) !== false || stripos($subject, "[{$short}]") !== false) {
            return $subject;
        }
        return "[{$host}] {$subject}";
    }

    /**
     * $type: tipo del aviso (AlertPolicyService::TYPES) para poder silenciarlo. Sin tipo,
     * o con 'warning'/'critical'/'info' (llamadas antiguas), no se puede silenciar.
     */
    public static function send(string $subject, string $message, string $type = ''): void
    {
        if (AlertPolicyService::muted($type)) {
            return;
        }
        // Mantenimiento programado: los avisos de "algo no responde" se apuntan, no se envían.
        if (in_array($type, AlertPolicyService::MAINTENANCE_TYPES, true) && AlertPolicyService::inMaintenance()) {
            LogService::log('notify.maintenance', $type, 'No enviado (mantenimiento programado): ' . $subject);
            return;
        }
        $message .= AlertPolicyService::emailFooter($type);
        $subject = self::tagSubject($subject);
        if (Settings::get('monitor_notify_email', '0') === '1') {
            self::sendEmail($subject, $message);
        }
        if (Settings::get('monitor_notify_telegram', '0') === '1') {
            self::sendTelegram("{$subject}\n\n{$message}");
        }
    }

    /**
     * True when email notifications are configured from Settings > Notifications.
     * This checks channel config only (not monitor channel toggles).
     */
    /** Claves de avisos que se copian del master a sus nodos. */
    private const SHARED_KEYS = [
        'notify_email_method', 'notify_email_to', 'notify_smtp_host', 'notify_smtp_port', 'notify_smtp_user',
        'notify_smtp_from', 'notify_smtp_encryption', 'notify_telegram_chat_id',
        'monitor_notify_email', 'monitor_notify_telegram',
        'notify_smtp2_host', 'notify_smtp2_port', 'notify_smtp2_user', 'notify_smtp2_from', 'notify_smtp2_encryption',
        'notify_email_daily_cap', 'notify_brand',
    ];

    /**
     * Configuración de avisos para enviar a un nodo. Los secretos van en claro
     * (por el canal autenticado del cluster): cada panel cifra con SU clave
     * (derivada de su DB_PASS), así que el valor cifrado no sirve en otro nodo.
     */
    public static function exportConfig(): array
    {
        $out = [];
        foreach (self::SHARED_KEYS as $k) {
            $out[$k] = Settings::get($k, '');
        }
        $out['smtp_pass'] = ReplicationService::decryptPassword(Settings::get('notify_smtp_pass', ''));
        $out['smtp2_pass'] = ReplicationService::decryptPassword(Settings::get('notify_smtp2_pass', ''));
        $out['telegram_token'] = ReplicationService::decryptPassword(Settings::get('notify_telegram_token', ''));
        return $out;
    }

    /** Acción de cluster set-notify-config (en el nodo). */
    public static function importConfig(array $p): array
    {
        $n = 0;
        foreach (self::SHARED_KEYS as $k) {
            if (array_key_exists($k, $p)) {
                Settings::set($k, (string)$p[$k]);
                $n++;
            }
        }
        if (($p['smtp_pass'] ?? '') !== '') {
            Settings::set('notify_smtp_pass', ReplicationService::encryptPassword((string)$p['smtp_pass']));
        }
        if (($p['smtp2_pass'] ?? '') !== '') {
            Settings::set('notify_smtp2_pass', ReplicationService::encryptPassword((string)$p['smtp2_pass']));
        }
        if (($p['telegram_token'] ?? '') !== '') {
            Settings::set('notify_telegram_token', ReplicationService::encryptPassword((string)$p['telegram_token']));
        }
        // El nombre de remitente es de cada nodo: si no, los avisos de Nitro llegaban
        // como "Mortadelo Master".
        Settings::set('notify_smtp_from_name', 'MuseDock ' . (gethostname() ?: 'nodo'));
        LogService::log('cluster.notify', 'import', "Configuración de avisos recibida del master ({$n} ajustes)");
        return ['ok' => true, 'imported' => $n, 'email_ready' => self::isEmailConfigured()];
    }

    public static function isEmailConfigured(): bool
    {
        $to = self::getRecipientEmail();
        if ($to === '') {
            return false;
        }

        $method = Settings::get('notify_email_method', 'smtp');
        if ($method === 'php') {
            return true;
        }

        return Settings::get('notify_smtp_host', '') !== '';
    }

    /**
     * Event-driven email with anti-spam cooldown.
     * Returns true only when an email was actually sent.
     */
    public static function sendEventEmail(
        string $eventKey,
        string $subject,
        string $body,
        int $cooldownSeconds = 1800
    ): bool {
        $eventKey = strtolower(trim($eventKey));
        if ($eventKey === '') {
            $eventKey = 'generic';
        }
        $eventKey = preg_replace('/[^a-z0-9_.-]+/', '_', $eventKey) ?: 'generic';

        if (!self::isEmailConfigured() || AlertPolicyService::muted($eventKey)) {
            return false; // sin correo configurado, o tipo silenciado en Ajustes → Avisos
        }
        if (in_array($eventKey, AlertPolicyService::MAINTENANCE_TYPES, true) && AlertPolicyService::inMaintenance()) {
            LogService::log('notify.maintenance', $eventKey, 'No enviado (mantenimiento programado): ' . $subject);
            return false;
        }

        $cooldownSeconds = max(60, min(86400, $cooldownSeconds));
        $settingKey = 'notify_event_email_last_' . $eventKey;
        $now = time();
        $last = (int)Settings::get($settingKey, '0');

        if ($last > 0 && ($now - $last) < $cooldownSeconds) {
            return false;
        }

        // Mark timestamp before send to avoid bursts on concurrent runs.
        Settings::set($settingKey, (string)$now);
        return self::sendEmail($subject, $body . AlertPolicyService::emailFooter($eventKey));
    }

    // ─── Email ──────────────────────────────────────────────

    public static function sendEmail(string $subject, string $body): bool
    {
        $subject = self::tagSubject($subject);
        $method = Settings::get('notify_email_method', 'smtp');
        $to = self::getRecipientEmail();

        if (!$to) return false;

        // Tope diario por panel: un aviso que se repite no debe agotar el cupo del
        // proveedor (Sweego gratis = 100/día para TODOS los paneles) y dejar sin
        // correo el aviso que de verdad importa. Al llegar al tope se manda uno
        // último diciéndolo y se calla hasta mañana (lo demás queda en el log).
        $cap = max(5, (int)Settings::get('notify_email_daily_cap', '25'));
        $day = date('Y-m-d');
        [$capDay, $sent] = array_pad(explode('|', Settings::get('notify_email_daily_count', '')), 2, '0');
        $sent = $capDay === $day ? (int)$sent : 0;
        if ($sent >= $cap) {
            LogService::log('notify.capped', null, "Correo no enviado (tope diario {$cap}): {$subject}");
            return false;
        }
        Settings::set('notify_email_daily_count', $day . '|' . ($sent + 1));
        if ($sent + 1 === $cap) {
            $subject .= ' [último aviso por correo de hoy]';
            $body .= "\n\n---\nEste panel ha llegado a su tope de {$cap} correos de aviso hoy (notify_email_daily_cap). "
                . 'Los siguientes avisos de hoy quedan solo en el registro del panel.';
        }

        $from = Settings::get('notify_smtp_from', '');
        if (!$from) $from = self::getAdminEmail();
        $fromName = Settings::get('notify_smtp_from_name', '');
        // La configuración de avisos se copia entre nodos: sin esto, los correos de TODOS
        // salían con el nombre del master ("Mortadelo Master" en los de nitro). El
        // remitente lleva el nombre de quien envía de verdad.
        $short = explode('.', (string)(Settings::get('panel_hostname', '') ?: gethostname() ?: 'servidor'))[0];
        $fromName = ucfirst($short) . ' · MuseDock Panel';

        if ($method === 'php') {
            return self::sendViaPhpMail($to, $from, $subject, $body, $fromName);
        }

        return self::sendViaSmtp($to, $from, $subject, $body, $fromName);
    }

    /**
     * Correo a una dirección concreta que no es la del administrador (p. ej. avisar a un
     * cliente del portal de que su ticket tiene respuesta). Misma configuración de envío
     * que los avisos, sin etiqueta de servidor en el asunto y con su propio tope diario
     * (notify_customer_email_daily_cap, 50) para no agotar el cupo de los avisos.
     */
    public static function sendToAddress(string $to, string $subject, string $body, string $fromName = '', string $html = ''): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject . $fromName)) {
            return false;
        }
        if (!self::isEmailConfigured()) {
            return false;
        }
        $cap = max(5, (int)Settings::get('notify_customer_email_daily_cap', '50'));
        $day = date('Y-m-d');
        [$capDay, $sent] = array_pad(explode('|', Settings::get('notify_customer_email_daily_count', '')), 2, '0');
        $sent = $capDay === $day ? (int)$sent : 0;
        if ($sent >= $cap) {
            LogService::log('notify.capped', null, "Correo a cliente no enviado (tope diario {$cap}): {$subject}");
            return false;
        }
        Settings::set('notify_customer_email_daily_count', $day . '|' . ($sent + 1));
        $from = Settings::get('notify_smtp_from', '') ?: self::getAdminEmail();
        if (Settings::get('notify_email_method', 'smtp') === 'php') {
            return self::sendViaPhpMail($to, $from, $subject, $body, $fromName);
        }
        return self::sendViaSmtp($to, $from, $subject, $body, $fromName, $html);
    }

    /**
     * HTML sencillo para correos a clientes (invitación, cambio de contraseña, tickets):
     * título, texto, un botón y un pie con quién envía. Sin imágenes externas ni scripts
     * (lo que más penaliza en los filtros). La marca sale del dominio del remitente.
     */
    public static function customerHtml(string $greeting, array $paragraphs, string $buttonText = '', string $buttonUrl = '', string $note = ''): string
    {
        $e = static fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $from = Settings::get('notify_smtp_from', '') ?: self::getAdminEmail();
        $domain = strtolower(substr(strrchr($from, '@') ?: '@', 1));
        // Marca en los correos a clientes (Ajustes → Notificaciones); si no, la del dominio.
        $brand = trim((string)Settings::get('notify_brand', '')) ?: ucfirst(explode('.', $domain)[0] ?? '');
        $p = '';
        foreach ($paragraphs as $t) {
            $p .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#334155;">' . $e($t) . '</p>';
        }
        $btn = $buttonUrl !== ''
            ? '<p style="margin:22px 0;"><a href="' . $e($buttonUrl) . '" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;'
              . 'padding:12px 22px;border-radius:8px;font-weight:600;font-size:15px;">' . $e($buttonText) . '</a></p>'
              . '<p style="margin:0 0 14px;font-size:12px;line-height:1.5;color:#64748b;">Si el botón no funciona, copia este enlace en el navegador:<br>'
              . '<span style="word-break:break-all;color:#4f46e5;">' . $e($buttonUrl) . '</span></p>'
            : '';
        $noteHtml = $note !== '' ? '<p style="margin:14px 0 0;font-size:13px;line-height:1.5;color:#64748b;">' . $e($note) . '</p>' : '';
        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:28px;">'
            . '<tr><td>'
            . ($brand !== '' ? '<p style="margin:0 0 18px;font-size:18px;font-weight:700;color:#0f172a;">' . $e($brand) . '</p>' : '')
            . '<p style="margin:0 0 14px;font-size:15px;color:#0f172a;">' . $e($greeting) . '</p>'
            . $p . $btn . $noteHtml
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94a3b8;">' . $e($brand !== '' ? "{$brand} · {$domain}" : '') . '</p>'
            . '</td></tr></table></body></html>';
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

    /**
     * Envío SMTP con servidor principal (notify_smtp_*) y, si falla o rechaza,
     * servidor secundario (notify_smtp2_*). Antes no se miraba la respuesta a
     * MAIL FROM/RCPT TO (donde un proveedor rechaza por cupo: Sweego gratis = 100
     * al día) ni se guardaba el motivo: el aviso se perdía sin rastro.
     * El último error y el último envío correcto quedan en notify_email_last_*.
     */
    private static function sendViaSmtp(string $to, string $from, string $subject, string $body, string $fromName = '', string $html = ''): bool
    {
        $errors = [];
        foreach (['notify_smtp_' => 'principal', 'notify_smtp2_' => 'secundario'] as $prefix => $label) {
            $cfg = self::smtpConfig($prefix);
            if ($cfg === null) {
                continue;
            }
            // El secundario usa su propio remitente si lo tiene (otro proveedor puede
            // no aceptar el dominio del principal).
            $sender = $prefix === 'notify_smtp2_' && $cfg['from'] !== '' ? $cfg['from'] : $from;
            $r = self::smtpSendOnce($cfg, $to, $sender, $subject, $body, $fromName, $html);
            if ($r['ok']) {
                Settings::set('notify_email_last_ok', json_encode(['at' => date('Y-m-d H:i:s'), 'server' => $label . ' ' . $cfg['host']], JSON_UNESCAPED_UNICODE));
                if ($errors) {
                    LogService::log('notify.fallback', null, 'Aviso enviado por el SMTP ' . $label . ' tras fallar: ' . implode(' | ', $errors));
                }
                return true;
            }
            $errors[] = "{$label} {$cfg['host']}: {$r['error']}";
        }
        if ($errors) {
            Settings::set('notify_email_last_error', json_encode(['at' => date('Y-m-d H:i:s'), 'errors' => $errors, 'subject' => mb_substr($subject, 0, 120)], JSON_UNESCAPED_UNICODE));
            LogService::log('notify.failed', null, 'Aviso por correo NO enviado: ' . implode(' | ', $errors));
        }
        return false;
    }

    /** Prueba un servidor SMTP con una configuración dada (la del formulario, sin guardar). */
    public static function testSmtp(array $cfg, string $to, string $from, string $fromName = ''): array
    {
        return self::smtpSendOnce($cfg, $to, $from, 'Test - MuseDock Panel',
            'Este es un email de prueba enviado desde MuseDock Panel. Si recibes este mensaje, este servidor de envío funciona correctamente.', $fromName);
    }

    /** Configuración de un servidor SMTP de avisos, o null si no está configurado. */
    public static function smtpConfig(string $prefix): ?array
    {
        $host = trim(Settings::get($prefix . 'host', ''));
        if ($host === '') {
            return null;
        }
        $pass = Settings::get($prefix . 'pass', '');
        return [
            'host' => $host,
            'port' => (int)(Settings::get($prefix . 'port', '587') ?: 587),
            'user' => Settings::get($prefix . 'user', ''),
            'pass' => $pass !== '' ? ReplicationService::decryptPassword($pass) : '',
            'encryption' => Settings::get($prefix . 'encryption', 'tls'),
            'from' => Settings::get($prefix . 'from', ''),
        ];
    }

    /** Una conversación SMTP comprobando el código de cada paso. */
    private static function smtpSendOnce(array $c, string $to, string $from, string $subject, string $body, string $fromName, string $html = ''): array
    {
        $socket = null;
        $step = static function (string $cmd, array $okCodes, string $what) use (&$socket): ?string {
            if ($cmd !== '') {
                fwrite($socket, $cmd . "\r\n");
            }
            $resp = self::readSmtpResponse($socket);
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $okCodes, true)) {
                return "{$what}: " . ($resp === '' ? 'sin respuesta' : trim(preg_replace('/\s+/', ' ', $resp)));
            }
            return null;
        };
        // Nombre con el que se presenta al servidor de envío: uno real del propio servidor
        // (nombre de envío/DNS inverso, nombre del panel o de la máquina). Antes era
        // "musedock-panel", que no es un nombre válido y queda en las cabeceras (Received)
        // como señal de spam.
        $ehlo = MailHeloService::name() ?: (string)Settings::get('panel_hostname', '');
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $ehlo)) {
            $ehlo = (string)gethostname();
            $ehlo = str_contains($ehlo, '.') ? $ehlo : 'localhost.localdomain';
        }
        try {
            $prefix = $c['encryption'] === 'ssl' ? 'ssl://' : '';
            $socket = @fsockopen($prefix . $c['host'], $c['port'], $errno, $errstr, 10);
            if (!$socket) {
                return ['ok' => false, 'error' => "no conecta ({$errstr})"];
            }
            stream_set_timeout($socket, 15);
            $fail = $step('', [220], 'saludo')
                ?? $step('EHLO ' . $ehlo, [250], 'EHLO');
            if ($fail === null && $c['encryption'] === 'tls') {
                $fail = $step('STARTTLS', [220], 'STARTTLS');
                if ($fail === null && !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    $fail = 'TLS: no se pudo negociar';
                }
                $fail = $fail ?? $step('EHLO ' . $ehlo, [250], 'EHLO tras TLS');
            }
            if ($fail === null && $c['user'] !== '') {
                $fail = $step('AUTH LOGIN', [334], 'AUTH')
                    ?? $step(base64_encode($c['user']), [334], 'AUTH usuario')
                    ?? $step(base64_encode($c['pass']), [235], 'AUTH contraseña');
            }
            $fail = $fail
                ?? $step("MAIL FROM:<{$from}>", [250], 'MAIL FROM')
                ?? $step("RCPT TO:<{$to}>", [250, 251], 'RCPT TO')
                ?? $step('DATA', [354], 'DATA');
            if ($fail === null) {
                $enc = static fn(string $t) => preg_match('/[^\x20-\x7E]/', $t) ? '=?UTF-8?B?' . base64_encode($t) . '?=' : $t;
                $fromHeader = $fromName !== '' ? $enc($fromName) . " <{$from}>" : $from;
                $headers = "From: {$fromHeader}\r\nTo: {$to}\r\nSubject: " . $enc($subject) . "\r\nDate: " . date('r')
                    . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@') ?: '@localhost', 1)) . ">\r\nMIME-Version: 1.0\r\n";
                if ($html !== '') {
                    // Texto + HTML (multipart/alternative), cada parte en base64 (sin líneas largas).
                    $b = 'b' . bin2hex(random_bytes(12));
                    $headers .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
                    $part = static fn(string $type, string $content) => "--{$b}\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                        . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";
                    $text = $part('text/plain', $body) . $part('text/html', $html) . "--{$b}--";
                } else {
                    $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
                    // CRLF y "dot-stuffing": una línea que empieza por "." no debe cortar el mensaje.
                    $text = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body));
                }
                fwrite($socket, $headers . "\r\n" . $text . "\r\n.\r\n");
                $fail = $step('', [250], 'envío del mensaje');
            }
            @fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return $fail === null ? ['ok' => true] : ['ok' => false, 'error' => $fail];
        } catch (\Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
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

    /** $token/$chatId: para probar lo escrito en el formulario antes de guardarlo. */
    public static function sendTelegram(string $message, ?string $token = null, ?string $chatId = null, ?string &$error = null): bool
    {
        $chatId = $chatId ?? Settings::get('notify_telegram_chat_id', '');
        if ($token !== null) {
            $botToken = $token;
        } else {
            $botTokenEnc = Settings::get('notify_telegram_token', '');
            $botToken = $botTokenEnc ? ReplicationService::decryptPassword($botTokenEnc) : '';
        }
        if (!$botToken || !$chatId) {
            $error = 'falta el Bot Token o el Chat ID';
            return false;
        }

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
        if ($httpCode !== 200) {
            // Motivo que da Telegram (p. ej. "chat not found": el bot aún no tiene permiso para
            // escribirte; "Unauthorized": token incorrecto). Nunca incluye el token.
            $desc = (string)(json_decode((string)$result, true)['description'] ?? '');
            $error = $desc !== '' ? $desc : ($httpCode ? "HTTP {$httpCode}" : 'sin conexión con Telegram');
        }

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
