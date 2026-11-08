<?php
declare(strict_types=1);

namespace StretchEffect\Http;

use StretchEffect\Api\Router;
use StretchEffect\App;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Contracts\RouteHandler;
use StretchEffect\Support\Log;

/** Routes a request to a handler, converts errors to JSON and adds security headers. */
final class Kernel
{
    public function __construct(private readonly App $app)
    {
    }

    public function handle(HttpRequest $req): HttpResponse
    {
        try {
            $handler = $this->route($req->path);
            $res = $handler?->handle($req, $this->app);
            $res ??= HttpResponse::json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        } catch (DomainError $e) {
            $res = HttpResponse::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $res = HttpResponse::json(['error' => ['code' => 'server', 'message' => 'Something went wrong. Please try again.']], 500);
        }
        return $this->withSecurityHeaders($req, $res);
    }

    private function route(string $path): ?RouteHandler
    {
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return new Router($this->app);
        }
        $oauth = '\\StretchEffect\\OAuth\\OAuthRoutes';
        if ((str_starts_with($path, '/.well-known/oauth-') || $path === '/oauth' || str_starts_with($path, '/oauth/')) && class_exists($oauth)) {
            return new $oauth();
        }
        $mcp = '\\StretchEffect\\Mcp\\McpEndpoint';
        if (($path === '/mcp' || $path === '/mcp/') && class_exists($mcp)) {
            return new $mcp();
        }
        return null;
    }

    private function withSecurityHeaders(HttpRequest $req, HttpResponse $res): HttpResponse
    {
        $h = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($this->app->config()->isLive()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        $lower = array_change_key_case($res->headers, CASE_LOWER);
        if (!isset($lower['cache-control'])) {
            $h['Cache-Control'] = 'no-store';
        }
        // Handler-set headers win.
        foreach ($h as $k => $v) {
            if (!isset($lower[strtolower($k)])) {
                $res->headers[$k] = $v;
            }
        }
        return $res;
    }
}
