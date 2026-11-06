<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Raphp\Core\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testNowIsUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testNowIsUtcWhateverTheProcessTimezoneIs(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            self::assertSame('+00:00', (new SystemClock())->now()->format('P'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testNowIsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable('now');
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable('now');

        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    public function testEachCallReturnsAFreshInstant(): void
    {
        $clock = new SystemClock();

        self::assertNotSame($clock->now(), $clock->now());
    }
}
