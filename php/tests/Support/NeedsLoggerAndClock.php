<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A service with constructor dependencies, used to prove that the container autowires.
 */
final readonly class NeedsLoggerAndClock
{
    public function __construct(
        public LoggerInterface $logger,
        public ClockInterface $clock,
    ) {}
}
