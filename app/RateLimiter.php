<?php
namespace MuseDockPanel;

class RateLimiter
{
    private static string $dir = '/tmp/musedock-panel-ratelimit';

    public static function check(string $ip, string $action, int $maxPerMinute = 20): bool
    {
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0700, true);
        }

        $action = preg_replace('/[^a-z0-9:_-]/i', '', $action) ?: 'default';
        $key = hash('sha256', $ip . ':' . $action);
        $file = self::$dir . '/' . $key;
        $windowStart = time() - 60;

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Fail-open to avoid hard lockouts if filesystem is unavailable.
            return true;
        }

        $allowed = true;

        if (@flock($fh, LOCK_EX)) {
            rewind($fh);
            $raw = stream_get_contents($fh);
            $timestamps = [];

            if ($raw !== false && $raw !== '') {
                foreach (explode("\n", trim($raw)) as $ts) {
                    $value = (int)$ts;
                    if ($value > $windowStart) {
                        $timestamps[] = $value;
                    }
                }
            }

            if (count($timestamps) >= $maxPerMinute) {
                $allowed = false;
            } else {
                $timestamps[] = time();
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, implode("\n", $timestamps));
            fflush($fh);
            flock($fh, LOCK_UN);
        }

        fclose($fh);
        return $allowed;
    }
}
