<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http;

/**
 * Thin wrapper around the current HTTP request.
 *
 * Parsed JSON body is cached after the first call to getBody().
 */
final class Request
{
    private ?array $parsedBody = null;

    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Returns the URI path, stripped of the query string.
     */
    public function getPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return '/' . ltrim(rawurldecode($uri), '/');
    }

    /**
     * Returns a single HTTP header value, or null if absent.
     * Header name is case-insensitive (e.g. 'Content-Type').
     */
    public function getHeader(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$serverKey] ?? null;
    }

    /**
     * Returns the client's IP address.
     */
    public function getIp(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                // X-Forwarded-For can be a comma-separated list; take the first one.
                return explode(',', $_SERVER[$key])[0];
            }
        }
        return '0.0.0.0';
    }

    /**
     * Parses and caches the JSON request body.
     * Returns an empty array for non-JSON or empty bodies.
     *
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        if ($this->parsedBody === null) {
            $raw = (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $this->parsedBody = is_array($decoded) ? $decoded : [];
        }
        return $this->parsedBody;
    }

    /**
     * Returns a single field from the JSON body, or $default if absent.
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->getBody()[$key] ?? $default;
    }
}
