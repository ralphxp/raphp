<?php

declare(strict_types=1);

namespace Raphp\Core\Logging;

/**
 * The eight PSR-3 severity levels, most severe first.
 *
 * Config holds one of these instead of a string, so an invalid level cannot exist once a
 * Config has been built. The backing values are the PSR-3 level names.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * Looks a level up by name, ignoring case. Returns null for anything that is not a level.
     */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}
