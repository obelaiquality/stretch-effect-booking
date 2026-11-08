<?php
declare(strict_types=1);

// Minimal PSR-4 autoloader so the app runs without Composer (upload-and-run hosting).
if (!defined('SE_SERVER_DIR')) {
    define('SE_SERVER_DIR', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'StretchEffect\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Contracts declare several types per file; load them eagerly.
require_once __DIR__ . '/Contracts/DomainError.php';
require_once __DIR__ . '/Contracts/Http.php';
require_once __DIR__ . '/Contracts/RouteHandler.php';
require_once __DIR__ . '/Contracts/Services.php';
