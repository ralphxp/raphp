<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Internal;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use Raphp\Error\WorkerUnavailableException;
use Raphp\Rpc\Internal\Slots;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\Recorder;
use Throwable;

final class SlotsTest extends AsyncTestCase
{
    private function taker(Slots $slots, Recorder $got, string $name): void
    {
        async(static function () use ($slots, $got, $name): void {
            try {
                $slots->acquire();
                $got->add($name);
            } catch (WorkerUnavailableException) {
                $got->add("{$name} refused");
            }
        })->ignore();
    }

    public function testSlotsUpToTheCapacityAreGivenAtOnce(): void
    {
        $slots = new Slots(2, 10);

        $slots->acquire();
        $slots->acquire();

        self::addToAssertionCount(1);
    }

    public function testTheNextTakerWaitsUntilASlotIsReleased(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $got = new Recorder();
        $this->taker($slots, $got, 'waiter');

        delay(0.02);
        self::assertSame([], $got->entries());

        $slots->release();
        delay(0.01);

        self::assertSame(['waiter'], $got->entries());
    }

    public function testReleasedSlotsGoToWaitersInArrivalOrder(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $got = new Recorder();
        foreach (['a', 'b', 'c'] as $name) {
            $this->taker($slots, $got, $name);
            delay(0.005);
        }

        foreach ([1, 2, 3] as $ignored) {
            $slots->release();
            delay(0.01);
        }

        self::assertSame(['a', 'b', 'c'], $got->entries());
    }

    public function testASlotHandedToAWaiterIsNotAlsoLeftFree(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $got = new Recorder();
        $this->taker($slots, $got, 'waiter');
        delay(0.01);

        $slots->release();
        delay(0.01);
        $this->taker($slots, $got, 'third');
        delay(0.02);

        self::assertSame(['waiter'], $got->entries(), 'only one slot exists: the third taker must wait');
    }

    public function testAReleasedSlotIsKeptWhenNobodyIsWaiting(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $slots->release();

        $slots->acquire();

        self::addToAssertionCount(1);
    }

    public function testNoMoreWaitersThanTheLimitAreAccepted(): void
    {
        $slots = new Slots(1, 1);
        $slots->acquire();
        $got = new Recorder();
        $this->taker($slots, $got, 'waits');
        delay(0.01);
        $this->taker($slots, $got, 'too many');
        delay(0.01);

        self::assertSame(['too many refused'], $got->entries());
    }

    public function testACancelledWaiterDoesNotCostASlot(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $cancel = new DeferredCancellation();
        $cancelled = async(fn() => $slots->acquire($cancel->getCancellation()));
        delay(0.01);
        $got = new Recorder();
        $this->taker($slots, $got, 'next');
        delay(0.01);

        $cancel->cancel();
        try {
            $cancelled->await();
            self::fail('Expected CancelledException.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        $slots->release();
        delay(0.01);

        self::assertSame(['next'], $got->entries(), 'the slot must reach the next waiter');
    }

    public function testClosingRefusesEveryoneWaitingAndEveryoneAfter(): void
    {
        $slots = new Slots(1, 10);
        $slots->acquire();
        $got = new Recorder();
        $this->taker($slots, $got, 'waiting');
        delay(0.01);

        $slots->close();
        delay(0.01);

        self::assertSame(['waiting refused'], $got->entries());
        try {
            $slots->acquire();
            self::fail('Expected WorkerUnavailableException.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(WorkerUnavailableException::class, $exception);
        }
    }
}
