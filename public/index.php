<?php
declare(strict_types=1);

/**
 * public/index.php
 * -----------------------------------------------------------------------
 * HTTP front controller — single entry point.
 *
 * Request lifecycle:
 *   1. Autoload + Exception Handler
 *   2. Build Request object
 *   3. Run Middleware stack (CORS → RateLimit → Auth)
 *   4. Dispatch via FastRoute
 *   5. Resolve Controller from PHP-DI Container
 *   6. Call Controller method (passes Request + route vars)
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dewa\Mahjong\Exception\ExceptionHandler;
use Dewa\Mahjong\Http\Middleware\AuthMiddleware;
use Dewa\Mahjong\Http\Middleware\CorsMiddleware;
use Dewa\Mahjong\Http\Middleware\RateLimitMiddleware;
use Dewa\Mahjong\Http\Request;
use Dewa\Mahjong\Http\Response;

// ── 1. Global exception & error handler ─────────────────────────────────────
(new ExceptionHandler())->register();

// ── 2. Build Request ─────────────────────────────────────────────────────────
$request = new Request();

// ── 3. Middleware stack ──────────────────────────────────────────────────────
(new CorsMiddleware())->handle($request);
(new RateLimitMiddleware())->handle($request);
(new AuthMiddleware())->handle($request);

// ── 4. DI Container ──────────────────────────────────────────────────────────
$container = require dirname(__DIR__) . '/config/container.php';

// ── 5. Router ────────────────────────────────────────────────────────────────
/** @var FastRoute\Dispatcher $dispatcher */
$dispatcher = require dirname(__DIR__) . '/config/routes.php';

$routeInfo = $dispatcher->dispatch(
    $request->getMethod(),
    $request->getPath()
);

switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        Response::error('Route not found.', 404);
        break;

    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowed = implode(', ', $routeInfo[1]);
        header("Allow: {$allowed}");
        Response::error("Method not allowed. Allowed: {$allowed}", 405);
        break;

    case FastRoute\Dispatcher::FOUND:
        [$controllerClass, $method] = $routeInfo[1];
        $vars = $routeInfo[2]; // e.g. ['id' => '5']

        // ── 6. Resolve controller from container & invoke ─────────────────
        $controller = $container->get($controllerClass);
        $controller->$method($request, ...array_values($vars));
        break;
}
