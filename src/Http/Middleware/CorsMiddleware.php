<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http\Middleware;

use Dewa\Mahjong\Http\Request;

/**
 * CorsMiddleware
 * -----------------------------------------------------------------------
 * Adds Cross-Origin Resource Sharing headers to every response.
 *
 * Configuration via .env:
 *   CORS_ALLOW_ORIGIN  = *                (default)
 *   CORS_ALLOW_METHODS = GET,POST,OPTIONS (default)
 *   CORS_ALLOW_HEADERS = Content-Type,Authorization (default)
 *   CORS_MAX_AGE       = 3600            (default)
 *
 * For preflight OPTIONS requests the middleware sends headers immediately
 * and exits with 204 No Content.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): void
    {
        $origin  = $_ENV['CORS_ALLOW_ORIGIN']  ?? '*';
        $methods = $_ENV['CORS_ALLOW_METHODS'] ?? 'GET, POST, OPTIONS';
        $headers = $_ENV['CORS_ALLOW_HEADERS'] ?? 'Content-Type, Authorization, X-Requested-With';
        $maxAge  = $_ENV['CORS_MAX_AGE']        ?? '3600';

        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: {$methods}");
        header("Access-Control-Allow-Headers: {$headers}");
        header("Access-Control-Max-Age: {$maxAge}");

        // Preflight: browser sends OPTIONS first; reply immediately.
        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
