<?php
namespace MuseDockPanel\Services;

/** Public resolver check, independent of this machine's negative DNS cache. */
final class DnsPropagationService
{
    public static function parseAnswers(array $answer, string $type): array
    {
        $codes = ['A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16];
        $records = [];
        foreach ($answer as $r) {
            if (($r['type'] ?? 0) !== ($codes[$type] ?? -1)) continue;
            $content = (string)($r['data'] ?? ''); $priority = 0;
            if ($type === 'MX') {
                if (!preg_match('/^(\d+)\s+(.+)$/', $content, $m)) continue;
                $priority = (int)$m[1]; $content = $m[2];
            } elseif ($type === 'TXT') {
                if (preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $content, $parts)) {
                    $content = implode('', array_map('stripcslashes', $parts[1]));
                }
            }
            $records[] = ['type' => $type, 'name' => DomainDnsSyncService::normalizeHost($r['name'] ?? ''),
                'content' => $content, 'priority' => $priority];
        }
        return $records;
    }

    /** null means resolver unavailable; [] is a valid negative answer. */
    public static function records(string $name, string $type): ?array
    {
        static $cache = [];
        $key = $type . ' ' . $name;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $url = 'https://cloudflare-dns.com/dns-query?' . http_build_query(['name' => $name, 'type' => $type]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = json_decode((string)$body, true);
        if ($code !== 200 || !is_array($data) || !in_array($data['Status'] ?? -1, [0, 3], true) || !empty($data['TC'])) return $cache[$key] = null;
        return $cache[$key] = self::parseAnswers($data['Answer'] ?? [], $type);
    }
}
