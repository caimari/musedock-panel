<?php
/**
 * PHP Built-in Server Router
 * Used with: php -S 0.0.0.0:8444 -t public router.php
 */

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
