<?php

declare(strict_types=1);

namespace Raphp\Core\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The PSR-20 clock: wall-clock time in UTC.
 *
 * Use it for timestamps and for logic that tests need to control. Never use it to measure
 * elapsed time, because wall-clock time can step: use `hrtime(true)` or `Amp\now()` for that
 * (FRAMEWORK 4.9).
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
