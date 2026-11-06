<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Raphp\Core\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testItIsAPsr20Clock(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock());
    }

    public function testNowIsUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
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
