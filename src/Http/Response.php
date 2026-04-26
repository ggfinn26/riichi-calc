<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http;

/**
 * Static JSON response helper.
 *
 * Calling any method terminates the script by default. Pass $terminate = false
 * in tests to prevent exit().
 */
final class Response
{
    /**
     * Send a successful JSON response.
     *
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200, bool $terminate = true): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($terminate) {
            exit;
        }
    }

    /**
     * Send a JSON error response.
     */
    public static function error(string $message, int $status = 400, bool $terminate = true): void
    {
        self::json(['error' => $message], $status, $terminate);
    }

    /**
     * Send a 422 Unprocessable Entity with validation details.
     */
    public static function validationError(string $message, bool $terminate = true): void
    {
        self::json(['error' => $message, 'type' => 'validation_error'], 422, $terminate);
    }
}
