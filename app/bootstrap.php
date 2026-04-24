<?php
/**
 * Minimal CLI bootstrap for background scripts.
 */
if (!defined('PANEL_ROOT')) {
    define('PANEL_ROOT', dirname(__DIR__));
}

spl_autoload_register(function ($class) {
    $prefix = 'MuseDockPanel\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = PANEL_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\MuseDockPanel\Env::load(PANEL_ROOT . '/.env');
