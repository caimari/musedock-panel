<?php
/**
 * PHP Built-in Server Router
 * Used with: php -S 0.0.0.0:8444 -t public router.php
 */

require_once dirname(__DIR__) . '/app/Env.php';
\MuseDockPanel\Env::load(dirname(__DIR__) . '/.env');

// Enforce ALLOWED_IPS for all requests, including static files.
$allowedRaw = trim((string)\MuseDockPanel\Env::get('ALLOWED_IPS', ''));
if ($allowedRaw !== '') {
    $allowedIps = array_filter(array_map('trim', explode(',', $allowedRaw)));
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $clientIp = '';

    if (in_array($remoteAddr, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $clientIp = $candidate;
                break;
            }
        }
    }
    if ($clientIp === '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        $clientIp = $remoteAddr;
    }

    $cidrMatch = static function (string $ip, string $rule): bool {
        if (!str_contains($rule, '/')) {
            return false;
        }
        [$subnet, $prefix] = explode('/', $rule, 2);
        if (!is_numeric($prefix)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $prefix = (int)$prefix;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    };

    $allowed = false;
    foreach ($allowedIps as $rule) {
        if ($clientIp !== '' && ($rule === $clientIp || $cidrMatch($clientIp, $rule))) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access denied by IP allowlist.';
        exit;
    }
}

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Serve static files directly
if (preg_match('/\.(css|js|png|jpg|gif|svg|ico|woff2?|ttf|eot)$/', $path)) {
    $file = __DIR__ . $path;
    if (file_exists($file)) {
        return false; // Let PHP built-in server handle it
    }
}

// Route everything else through index.php
require __DIR__ . '/index.php';
