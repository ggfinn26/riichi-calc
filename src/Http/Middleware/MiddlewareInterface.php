<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http\Middleware;

use Dewa\Mahjong\Http\Request;

/**
 * Every middleware must implement this interface.
 * A middleware MUST either call the next middleware / return, or
 * terminate the request itself (e.g. send 401 and exit).
 */
interface MiddlewareInterface
{
    /**
     * Handle the incoming request.
     * Return void to pass to the next handler; terminate via Response::error() + exit
     * to short-circuit.
     */
    public function handle(Request $request): void;
}
