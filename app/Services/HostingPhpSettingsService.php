<?php
namespace MuseDockPanel\Services;

/**
 * Límites de PHP de una cuenta de hosting (su pool de PHP-FPM): memory_limit,
 * upload_max_filesize, post_max_size, max_execution_time, max_input_vars.
 * Lo usan la web (Cuentas → Editar → PHP) y la herramienta MCP hosting_php_settings.
 *
 * Antes de recargar PHP-FPM se comprueba la configuración (php-fpmX.Y -t); si
 * falla, el pool vuelve a su contenido anterior y no se recarga nada.
 */
final class HostingPhpSettingsService
{
    public const ALLOWED = [
        'memory_limit' => '/^\d+[MmGgKk]?$/',
        'upload_max_filesize' => '/^\d+[MmGgKk]?$/',
        'post_max_size' => '/^\d+[MmGgKk]?$/',
        'max_execution_time' => '/^\d+$/',
        'max_input_vars' => '/^\d+$/',
    ];

    /**
     * Pool de la cuenta: normalmente pool.d/{usuario}.conf, pero hay cuentas cuyo
     * pool tiene otro nombre (p. ej. musedock.com → musedock.conf); en ese caso se
     * busca en pool.d el pool cuyo "user =" es el de la cuenta.
     */
    public static function poolFile(array $account): string
    {
        $ver = preg_replace('/[^0-9.]/', '', (string)($account['php_version'] ?? ''));
        $user = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($account['username'] ?? ''));
        $default = "/etc/php/{$ver}/fpm/pool.d/{$user}.conf";
        if ($user === '' || file_exists($default)) {
            return $default;
        }
        foreach (glob("/etc/php/{$ver}/fpm/pool.d/*.conf") ?: [] as $f) {
            if (preg_match('/^\s*user\s*=\s*' . preg_quote($user, '/') . '\s*$/m', (string)@file_get_contents($f))) {
                return $f;
            }
        }
        return $default;
    }

    /** Valores actuales en el pool (php_admin_value / php_value). */
    public static function current(array $account): array
    {
        $content = (string)@file_get_contents(self::poolFile($account));
        $out = [];
        foreach (array_keys(self::ALLOWED) as $key) {
            $out[$key] = preg_match('/^php_(?:admin_)?value\[' . preg_quote($key, '/') . '\]\s*=\s*(.+)$/m', $content, $m) ? trim($m[1]) : null;
        }
        return $out;
    }

    /**
     * Valida $changes (clave => valor). Devuelve [cambios válidos, errores].
     * Los valores vacíos se ignoran.
     */
    public static function validate(array $changes): array
    {
        $ok = [];
        $errors = [];
        foreach ($changes as $key => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (!isset(self::ALLOWED[$key])) {
                $errors[] = "Ajuste no permitido: {$key}";
            } elseif (!preg_match(self::ALLOWED[$key], $value)) {
                $errors[] = "Valor no válido para {$key}: {$value}";
            } else {
                $ok[$key] = $value;
            }
        }
        return [$ok, $errors];
    }

    /** Aplica los cambios al pool y recarga PHP-FPM. @return array{ok:bool, error?:string, before?:array, after?:array} */
    public static function apply(array $account, array $changes): array
    {
        [$changes, $errors] = self::validate($changes);
        if ($errors) {
            return ['ok' => false, 'error' => implode('; ', $errors)];
        }
        if (!$changes) {
            return ['ok' => false, 'error' => 'No se especificaron valores para actualizar.'];
        }
        $poolFile = self::poolFile($account);
        if (!file_exists($poolFile)) {
            return ['ok' => false, 'error' => 'No se encontró el archivo de pool FPM.'];
        }
        $original = file_get_contents($poolFile);
        if ($original === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo de pool FPM.'];
        }
        $before = self::current($account);
        $poolContent = $original;

        foreach ($changes as $key => $value) {
            // Sustituye php_admin_value[key] o php_value[key]; si no existe, lo añade
            // antes de security.limit_extensions o al final.
            $replaced = false;
            foreach (['php_admin_value', 'php_value'] as $kind) {
                $pattern = '/^(' . $kind . '\[' . preg_quote($key, '/') . '\])\s*=\s*.+$/m';
                if (!$replaced && preg_match($pattern, $poolContent)) {
                    $poolContent = preg_replace($pattern, "{$kind}[{$key}] = {$value}", $poolContent);
                    $replaced = true;
                }
            }
            if (!$replaced) {
                $newLine = "php_admin_value[{$key}] = {$value}";
                if (strpos($poolContent, 'security.limit_extensions') !== false) {
                    $poolContent = preg_replace('/^(security\.limit_extensions\s*=)/m', $newLine . "\n\n$1", $poolContent);
                } else {
                    $poolContent = rtrim($poolContent) . "\n{$newLine}\n";
                }
            }
        }

        if (file_put_contents($poolFile, $poolContent) === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir el archivo de pool FPM.'];
        }
        $ver = preg_replace('/[^0-9.]/', '', (string)$account['php_version']);
        $test = [];
        exec("php-fpm{$ver} -t 2>&1", $test, $rc);
        if ($rc !== 0) {
            file_put_contents($poolFile, $original);
            return ['ok' => false, 'error' => 'PHP-FPM rechaza la configuración; se deja como estaba: ' . trim(implode(' ', array_slice($test, -2)))];
        }
        shell_exec("systemctl reload php{$ver}-fpm 2>&1");

        $changesLog = implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($changes), array_values($changes)));
        LogService::log('account.php_settings', $account['domain'], "PHP settings updated: {$changesLog}");
        return ['ok' => true, 'before' => $before, 'after' => self::current($account)];
    }
}
