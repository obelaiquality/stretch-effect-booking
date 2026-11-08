<?php
declare(strict_types=1);

// Front controller. Everything that is not a real file is routed here (see .htaccess).
$serverDir = dirname(__DIR__);
define('SE_SERVER_DIR', $serverDir);
require $serverDir . '/src/bootstrap.php';

use StretchEffect\App;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Http\Kernel;
use StretchEffect\Http\RequestFactory;
use StretchEffect\Support\Log;

try {
    $configFile = getenv('SE_CONFIG') ?: $serverDir . '/config.php';
    $app = App::fromFile($configFile);
    $request = RequestFactory::fromGlobals();
    $response = (new Kernel($app))->handle($request);
    RequestFactory::emit($response, $request->method);
} catch (Throwable $e) {
    Log::error('Fatal: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $res = HttpResponse::json(['error' => ['code' => 'server', 'message' => 'The server is not configured correctly.']], 500, [
        'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
    ]);
    RequestFactory::emit($res);
}
