<?php
declare(strict_types=1);

define('SE_SERVER_DIR', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php'; // Contracts (several types per file) + no-op autoloader duplicate
StretchEffect\Support\Log::$path = sys_get_temp_dir() . '/stretch-effect-test-error.log';
