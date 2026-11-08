<?php
declare(strict_types=1);

// Minimal PSR-4 autoloader so these tests run without a vendor/ directory (PHPUnit itself is loaded by composer).
// The contracts hold several types per file, so they cannot be found by name.
foreach (['DomainError', 'Http', 'RouteHandler', 'Services'] as $f) {
    require_once __DIR__ . '/../../src/Contracts/' . $f . '.php';
}

spl_autoload_register(static function (string $class): void {
    $maps = ['StretchEffect\\Tests\\' => __DIR__ . '/../', 'StretchEffect\\' => __DIR__ . '/../../src/'];
    foreach ($maps as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
require_once __DIR__ . '/FakeServices.php';
