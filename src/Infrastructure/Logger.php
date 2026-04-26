<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Infrastructure;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\WebProcessor;

/**
 * Logger
 * -----------------------------------------------------------------------
 * Singleton factory for the application's Monolog instance.
 *
 * Configuration via .env:
 *   LOG_LEVEL   = debug | info | notice | warning | error | critical (default: debug)
 *   LOG_PATH    = absolute path to log file (default: storage/logs/app.log)
 *
 * Usage:
 *   Logger::get()->info('Score calculated', ['han' => 3, 'fu' => 40]);
 *   Logger::get()->error('Unexpected exception', ['exception' => $e]);
 */
final class Logger
{
    private static ?MonologLogger $instance = null;

    public static function get(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = self::build();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton (useful in tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function build(): MonologLogger
    {
        $logPath = $_ENV['LOG_PATH']
            ?? dirname(__DIR__, 2) . '/storage/logs/app.log';

        // Resolve log level from env (default: debug).
        $levelName = strtolower($_ENV['LOG_LEVEL'] ?? 'debug');
        $level = match ($levelName) {
            'emergency' => Level::Emergency,
            'alert'     => Level::Alert,
            'critical'  => Level::Critical,
            'error'     => Level::Error,
            'warning'   => Level::Warning,
            'notice'    => Level::Notice,
            'info'      => Level::Info,
            default     => Level::Debug,
        };

        // Ensure log directory exists.
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logger = new MonologLogger('riichi-calc');

        // Rotate daily: use RotatingFileHandler if available, else StreamHandler.
        if (class_exists(\Monolog\Handler\RotatingFileHandler::class)) {
            $handler = new \Monolog\Handler\RotatingFileHandler($logPath, 30, $level);
        } else {
            $handler = new StreamHandler($logPath, $level);
        }

        $logger->pushHandler($handler);

        // Add context processors.
        $logger->pushProcessor(new WebProcessor());          // IP, method, URI
        $logger->pushProcessor(new IntrospectionProcessor()); // class/function/line

        return $logger;
    }
}
