<?php

declare(strict_types=1);

namespace Raphp\Core\Logging;

use InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Builds the PSR-3 logger (Monolog, FRAMEWORK 4.9).
 *
 * It writes one line per record to stderr by default. A worker's stderr is captured by
 * the master, which aggregates it with the worker's name (FRAMEWORK 14).
 */
final class LoggerFactory
{
    private const array LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * @param resource|string $stream a stream resource or a stream URL such as "php://stderr"
     *
     * @throws InvalidArgumentException when the level is not a PSR-3 level name
     */
    public function create(string $level, mixed $stream = 'php://stderr', string $channel = 'raphp'): LoggerInterface
    {
        $normalised = strtolower($level);
        if (!in_array($normalised, self::LEVELS, true)) {
            throw new InvalidArgumentException("'{$level}' is not a PSR-3 log level; use one of: " . implode(', ', self::LEVELS));
        }

        $handler = new StreamHandler($stream, Level::fromName($normalised));
        $handler->setFormatter(new LineFormatter(
            format: "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            dateFormat: 'Y-m-d\TH:i:s.vP',
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        ));

        return new Logger($channel, [$handler]);
    }
}
