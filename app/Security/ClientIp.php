<?php
namespace MuseDockPanel\Security;

class ClientIp
{
    public static function resolve(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        // Trust proxy headers only when request comes from local reverse proxy.
        if (in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
            $fromXff = self::firstValidForwardedIp($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fromXff !== '') {
                return $fromXff;
            }

            $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            if (filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '';
    }

    private static function firstValidForwardedIp(string $header): string
    {
        if ($header === '') {
            return '';
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '';
    }
}
