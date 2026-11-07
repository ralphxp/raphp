<?php

declare(strict_types=1);

namespace Raphp\Tests\Filesystem\Internal;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Raphp\Filesystem\Internal\TimeSlice;
use Raphp\Tests\Support\AsyncTestCase;
use Revolt\EventLoop;

final class TimeSliceTest extends AsyncTestCase
{
    public function testAZeroSliceGivesTheLoopATurnAtEveryCheckpoint(): void
    {
        $turns = 0;
        $timer = EventLoop::repeat(0.0, static function () use (&$turns): void {
            ++$turns;
        });
        $slice = new TimeSlice(0.0);

        for ($i = 0; $i < 5; $i++) {
            $slice->checkpoint();
        }

        EventLoop::cancel($timer);

        self::assertSame(5, $turns);
    }

    public function testALongSliceDoesNotYield(): void
    {
        $turns = 0;
        $timer = EventLoop::repeat(0.0, static function () use (&$turns): void {
            ++$turns;
        });
        $slice = new TimeSlice(60.0);

        for ($i = 0; $i < 100; $i++) {
            $slice->checkpoint();
        }

        EventLoop::cancel($timer);

        self::assertSame(0, $turns);
    }

    public function testASliceThatRunsOutYieldsOnceAndStartsANewOne(): void
    {
        $turns = 0;
        $timer = EventLoop::repeat(0.0, static function () use (&$turns): void {
            ++$turns;
        });
        $slice = new TimeSlice(0.02);

        usleep(30_000);
        $slice->checkpoint();
        $afterFirst = $turns;
        $slice->checkpoint();
        EventLoop::cancel($timer);

        self::assertSame(1, $afterFirst, 'the used-up slice gave the loop a turn');
        self::assertSame(1, $turns, 'the new slice has just started, so the next checkpoint does not yield');
    }

    public function testACancelledOperationStopsAtTheNextCheckpointEvenWithinTheSlice(): void
    {
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        $this->expectException(CancelledException::class);

        (new TimeSlice(60.0))->checkpoint($cancel->getCancellation());
    }
}
