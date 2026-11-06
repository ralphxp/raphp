<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A PSR-20 clock that always returns the same instant, for tests.
 */
final readonly class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $instant) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
