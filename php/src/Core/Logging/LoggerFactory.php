<?php

declare(strict_types=1);

namespace Raphp\Core\Logging;

use DateTimeZone;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the PSR-3 logger (Monolog, FRAMEWORK 4.9).
 *
 * It writes one line per record to stderr by default. A worker's stderr is captured by
 * the master, which aggregates it with the worker's name (FRAMEWORK 14). Timestamps are in
 * UTC, and when a PSR-20 clock is given it supplies the time, so a test that controls the
 * clock also controls the log.
 */
final class LoggerFactory
{
    /**
     * @param resource|string $stream a stream resource or a stream URL such as "php://stderr"
     */
    public function create(
        LogLevel $level,
        mixed $stream = 'php://stderr',
        string $channel = 'raphp',
        ?ClockInterface $clock = null,
    ): LoggerInterface {
        $handler = new StreamHandler($stream, $this->monologLevel($level));
        $handler->setFormatter(new LineLogFormatter());

        return new Logger($channel, [$handler], [], new DateTimeZone('UTC'), $clock);
    }

    private function monologLevel(LogLevel $level): Level
    {
        return match ($level) {
            LogLevel::Emergency => Level::Emergency,
            LogLevel::Alert => Level::Alert,
            LogLevel::Critical => Level::Critical,
            LogLevel::Error => Level::Error,
            LogLevel::Warning => Level::Warning,
            LogLevel::Notice => Level::Notice,
            LogLevel::Info => Level::Info,
            LogLevel::Debug => Level::Debug,
        };
    }
}
