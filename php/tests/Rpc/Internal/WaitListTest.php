<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Internal;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use Raphp\Rpc\Internal\WaitList;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\Recorder;

final class WaitListTest extends AsyncTestCase
{
    private function waiter(WaitList $list, Recorder $woken, string $name): void
    {
        async(static function () use ($list, $woken, $name): void {
            $list->wait();
            $woken->add($name);
        })->ignore();
    }

    public function testWaitersAreWokenOneAtATimeInArrivalOrder(): void
    {
        $list = new WaitList();
        $woken = new Recorder();
        foreach (['a', 'b', 'c'] as $name) {
            $this->waiter($list, $woken, $name);
        }

        delay(0.01);
        self::assertSame(3, $list->count());

        self::assertTrue($list->wakeOne());
        delay(0.01);
        self::assertSame(['a'], $woken->entries());

        $list->wakeOne();
        $list->wakeOne();
        delay(0.01);
        self::assertSame(['a', 'b', 'c'], $woken->entries());
        self::assertSame(0, $list->count());
    }

    public function testWakingWhenNobodyWaitsReportsItAndKeepsNothing(): void
    {
        $list = new WaitList();

        self::assertFalse($list->wakeOne());

        $woken = new Recorder();
        $this->waiter($list, $woken, 'later');
        delay(0.01);
        self::assertSame([], $woken->entries(), 'an earlier wake-up must not be remembered');
        $list->wakeAll();
    }

    public function testWakeAllWakesEveryone(): void
    {
        $list = new WaitList();
        $woken = new Recorder();
        foreach (['a', 'b', 'c'] as $name) {
            $this->waiter($list, $woken, $name);
        }

        delay(0.01);
        $list->wakeAll();
        delay(0.01);

        self::assertSame(['a', 'b', 'c'], $woken->entries());
    }

    public function testACancelledWaiterLeavesTheListSoTheNextWakeUpReachesTheNextWaiter(): void
    {
        $list = new WaitList();
        $woken = new Recorder();
        $cancel = new DeferredCancellation();
        $cancelled = async(function () use ($list, $cancel): void {
            $list->wait($cancel->getCancellation());
        });
        delay(0.01);
        $this->waiter($list, $woken, 'second');
        delay(0.01);

        $cancel->cancel();
        try {
            $cancelled->await();
            self::fail('Expected CancelledException.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(1, $list->count());
        self::assertTrue($list->wakeOne());
        delay(0.01);
        self::assertSame(['second'], $woken->entries(), 'the wake-up must not be wasted on the cancelled waiter');
    }

    public function testAWaiterThatWasWokenBeforeItWasCancelledIsWoken(): void
    {
        $list = new WaitList();
        $cancel = new DeferredCancellation();
        $outcome = new Recorder();
        $waiter = async(static function () use ($list, $cancel, $outcome): void {
            try {
                $list->wait($cancel->getCancellation());
                $outcome->add('woken');
            } catch (CancelledException) {
                $outcome->add('cancelled');
            }
        });
        delay(0.01);

        $list->wakeOne();
        $cancel->cancel();
        $waiter->await();

        self::assertSame(['woken'], $outcome->entries());
    }

    public function testACancellationThatIsAlreadyRequestedStillLeavesTheListEmpty(): void
    {
        $list = new WaitList();
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        try {
            $list->wait($cancel->getCancellation());
            self::fail('Expected CancelledException.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $list->count());
    }
}
