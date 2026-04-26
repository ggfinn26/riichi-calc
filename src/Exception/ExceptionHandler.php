<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Exception;

use Dewa\Mahjong\Http\Response;
use Dewa\Mahjong\Infrastructure\Logger;

/**
 * ExceptionHandler
 * -----------------------------------------------------------------------
 * Global handler that intercepts all uncaught Throwables and PHP errors,
 * converts them to JSON API responses, and logs unexpected errors.
 *
 * Registration:
 *   (new ExceptionHandler())->register();
 *
 * Exception → HTTP status mapping:
 *   \InvalidArgumentException → 422 Unprocessable Entity
 *   \RuntimeException         → 400 Bad Request
 *   \LogicException           → 400 Bad Request
 *   \Throwable (all others)   → 500 Internal Server Error + logged
 */
final class ExceptionHandler
{
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleException(\Throwable $e): void
    {
        $appEnv  = $_ENV['APP_ENV'] ?? 'production';
        $isDev   = $appEnv === 'development' || $appEnv === 'local';

        if ($e instanceof \InvalidArgumentException) {
            Response::error($e->getMessage(), 422, terminate: false);
            return;
        }

        if ($e instanceof \RuntimeException || $e instanceof \LogicException) {
            Response::error($e->getMessage(), 400, terminate: false);
            return;
        }

        // Unexpected error: log full details, hide internals from response.
        $this->logError($e);

        $message = $isDev
            ? sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())
            : 'Internal server error. Please try again later.';

        Response::error($message, 500, terminate: false);
    }

    /**
     * Converts PHP errors to ErrorException so they flow through handleException().
     *
     * @throws \ErrorException
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false; // Error suppressed by @
        }
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Catches fatal errors (E_ERROR, E_PARSE) that set_error_handler misses.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->logRaw($error['message'], $error['file'], $error['line']);
            Response::error('Fatal error. Please try again later.', 500, terminate: false);
        }
    }

    // -----------------------------------------------------------------------

    private function logError(\Throwable $e): void
    {
        try {
            Logger::get()->error(
                sprintf('[%s] %s', get_class($e), $e->getMessage()),
                [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
        } catch (\Throwable) {
            // If logger itself fails, fall back to error_log().
            error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function logRaw(string $message, string $file, int $line): void
    {
        try {
            Logger::get()->critical("Fatal PHP error: {$message}", ['file' => $file, 'line' => $line]);
        } catch (\Throwable) {
            error_log("Fatal: {$message} in {$file}:{$line}");
        }
    }
}
