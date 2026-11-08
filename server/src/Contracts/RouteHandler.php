<?php
declare(strict_types=1);

namespace StretchEffect\Contracts;

/**
 * public/index.php (server-core) routes:
 *   /api/*                          -> StretchEffect\Api\Router
 *   /.well-known/oauth-*, /oauth/*  -> StretchEffect\OAuth\OAuthRoutes   (server-mcp)
 *   /mcp                            -> StretchEffect\Mcp\McpEndpoint     (server-mcp)
 * Each implements this interface. Return null if the path is not yours (index.php then 404s).
 * index.php adds the security headers to every response.
 */
interface RouteHandler
{
    public function handle(HttpRequest $request, Services $services): ?HttpResponse;
}
