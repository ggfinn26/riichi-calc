<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Http\Middleware;

use Dewa\Mahjong\Http\Request;
use Dewa\Mahjong\Http\Response;

/**
 * RateLimitMiddleware
 * -----------------------------------------------------------------------
 * Simple file-based per-IP rate limiter.
 *
 * Configuration via .env:
 *   RATE_LIMIT_PER_MINUTE = 60  (default)
 *   RATE_LIMIT_STORAGE    = /path/to/storage/rate_limit  (default: storage/rate_limit)
 *
 * Each IP gets a JSON file tracking request count + window start time.
 * The window resets every 60 seconds.
 *
 * For production use Redis or APCu instead.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): void
    {
        $limit   = (int) ($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 60);
        $storage = $_ENV['RATE_LIMIT_STORAGE']
            ?? dirname(__DIR__, 4) . '/storage/rate_limit';

        // Ensure storage directory exists.
        if (!is_dir($storage)) {
            @mkdir($storage, 0755, true);
        }

        $ip       = preg_replace('/[^a-fA-F0-9:._-]/', '_', $request->getIp());
        $file     = $storage . '/' . md5($ip) . '.json';
        $now      = time();
        $window   = 60; // seconds

        // Read existing counter.
        $data = ['count' => 0, 'window_start' => $now];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?: $data;
            }
        }

        // Reset window if expired.
        if (($now - (int) $data['window_start']) >= $window) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        $data['count']++;

        // Persist.
        @file_put_contents($file, json_encode($data), LOCK_EX);

        if ((int) $data['count'] > $limit) {
            header('Retry-After: ' . ($window - ($now - (int) $data['window_start'])));
            Response::error(
                "Rate limit exceeded. Maximum {$limit} requests per minute.",
                429
            );
        }
    }
}
