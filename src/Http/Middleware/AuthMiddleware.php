<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http\Middleware;

use Dewa\Mahjong\Http\Request;
use Dewa\Mahjong\Http\Response;

/**
 * AuthMiddleware
 * -----------------------------------------------------------------------
 * Validates a Bearer token in the Authorization header.
 *
 * Disabled by default — activate via .env:
 *   AUTH_ENABLED = true
 *
 * Token validation strategy (configurable):
 *   AUTH_STRATEGY = "static"  → compare against AUTH_STATIC_TOKEN
 *   AUTH_STRATEGY = "db"      → look up in users.api_token column (future)
 *
 * Routes that should skip auth can be listed in:
 *   AUTH_PUBLIC_ROUTES = "/api/health,/api/docs" (comma-separated)
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): void
    {
        // Guard: skip entirely when auth is disabled.
        if (($_ENV['AUTH_ENABLED'] ?? 'false') !== 'true') {
            return;
        }

        // Skip public routes.
        $publicRoutes = array_map(
            'trim',
            explode(',', $_ENV['AUTH_PUBLIC_ROUTES'] ?? '')
        );
        if (in_array($request->getPath(), $publicRoutes, true)) {
            return;
        }

        $authHeader = $request->getHeader('Authorization') ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Unauthorized: missing or invalid Authorization header.', 401);
        }

        $token = substr($authHeader, 7);

        // Static token strategy (suitable for internal / CLI clients).
        $strategy = $_ENV['AUTH_STRATEGY'] ?? 'static';
        if ($strategy === 'static') {
            $expected = $_ENV['AUTH_STATIC_TOKEN'] ?? '';
            if ($token !== $expected || $expected === '') {
                Response::error('Unauthorized: invalid token.', 401);
            }
        }

        // DB strategy placeholder — extend this in a future iteration.
        // if ($strategy === 'db') { ... }
    }
}
