<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Internal;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use Raphp\Error\WorkerUnavailableException;
use Raphp\Rpc\Internal\OutboundQueue;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\Recorder;

final class OutboundQueueTest extends AsyncTestCase
{
    public function testLinesComeOutInTheOrderTheyWentIn(): void
    {
        $queue = new OutboundQueue(10);
        $queue->push('a');
        $queue->push('b');
        $queue->pushNow('c');

        self::assertSame('a', $queue->pop());
        self::assertSame('b', $queue->pop());
        self::assertSame('c', $queue->pop());
    }

    public function testPopWaitsForALine(): void
    {
        $queue = new OutboundQueue(10);
        $got = new Recorder();
        async(static function () use ($queue, $got): void {
            $got->add((string) $queue->pop());
        })->ignore();
        delay(0.02);
        self::assertSame([], $got->entries());

        $queue->push('late');
        delay(0.01);

        self::assertSame(['late'], $got->entries());
    }

    public function testPushWaitsWhileTheQueueIsFullAndGoesOnWhenALineIsTaken(): void
    {
        $queue = new OutboundQueue(2);
        $queue->push('1');
        $queue->push('2');
        $done = new Recorder();
        $pusher = async(static function () use ($queue, $done): void {
            $queue->push('3');
            $done->add();
        });
        delay(0.02);
        self::assertSame(0, $done->count(), 'a full queue must make the sender wait');

        self::assertSame('1', $queue->pop());
        $pusher->await();

        self::assertSame(1, $done->count());
        self::assertSame('2', $queue->pop());
        self::assertSame('3', $queue->pop());
    }

    public function testPushNowNeverWaitsEvenWhenTheQueueIsFullButStopsAtTheReserve(): void
    {
        $queue = new OutboundQueue(1, 2);
        $queue->push('1');

        self::assertTrue($queue->pushNow('2'));
        self::assertTrue($queue->pushNow('3'));
        self::assertFalse($queue->pushNow('4'), 'beyond capacity plus reserve the line must be refused, not queued');
        self::assertSame('1', $queue->pop());
        self::assertSame('2', $queue->pop());
        self::assertSame('3', $queue->pop());
        self::assertTrue($queue->pushNow('5'), 'room that was freed can be used again');
    }

    public function testWithoutAReservePushNowStopsAtTheCapacity(): void
    {
        $queue = new OutboundQueue(2);

        self::assertTrue($queue->pushNow('1'));
        self::assertTrue($queue->pushNow('2'));
        self::assertFalse($queue->pushNow('3'));
    }

    public function testASenderThatWaitsIsHeldAtTheCapacityNotAtTheReserve(): void
    {
        $queue = new OutboundQueue(1, 5);
        $queue->push('1');
        $done = new Recorder();
        $pusher = async(static function () use ($queue, $done): void {
            $queue->push('2');
            $done->add();
        });
        delay(0.02);

        self::assertSame(0, $done->count());
        self::assertSame('1', $queue->pop());
        $pusher->await();
        self::assertSame(1, $done->count());
    }

    public function testALineWithAKeyIsDroppedWhileOneWithTheSameKeyIsStillWaiting(): void
    {
        $queue = new OutboundQueue(10, 10);

        self::assertTrue($queue->pushNow('pong-1', 'pong'));
        self::assertTrue($queue->pushNow('pong-2', 'pong'), 'a duplicate counts as accepted: its work is already queued');
        self::assertTrue($queue->pushNow('other'));
        self::assertTrue($queue->pushNow('pong-3', 'pong'));

        self::assertSame('pong-1', $queue->pop());
        self::assertSame('other', $queue->pop());
        self::assertTrue($queue->pushNow('pong-4', 'pong'), 'once the keyed line is taken, a new one is accepted');
        self::assertSame('pong-4', $queue->pop());
    }

    public function testDuplicatesDoNotUseUpTheReserve(): void
    {
        $queue = new OutboundQueue(1, 1);

        for ($ping = 0; $ping < 1000; $ping++) {
            self::assertTrue($queue->pushNow('pong', 'pong'));
        }

        self::assertTrue($queue->pushNow('next'), 'a thousand duplicates must still leave room');
    }

    public function testClosingForgetsTheKeys(): void
    {
        $queue = new OutboundQueue(10, 10);
        $queue->pushNow('pong', 'pong');

        $queue->close();

        self::assertFalse($queue->pushNow('pong', 'pong'));
        self::assertNull($queue->pop());
    }

    public function testSendersWaitingForRoomAreServedInArrivalOrder(): void
    {
        $queue = new OutboundQueue(1);
        $queue->push('0');
        foreach (['a', 'b', 'c'] as $line) {
            async(static function () use ($queue, $line): void {
                $queue->push($line);
            })->ignore();
            delay(0.005);
        }

        $order = [];
        for ($count = 0; $count < 4; $count++) {
            $order[] = $queue->pop();
            delay(0.005);
        }

        self::assertSame(['0', 'a', 'b', 'c'], $order);
    }

    public function testACancelledSenderLeavesTheLineAndTheNextOneIsStillServed(): void
    {
        $queue = new OutboundQueue(1);
        $queue->push('0');
        $cancel = new DeferredCancellation();
        $cancelled = async(static function () use ($queue, $cancel): void {
            $queue->push('cancelled', $cancel->getCancellation());
        });
        delay(0.01);
        $next = async(static function () use ($queue): void {
            $queue->push('next');
        });
        delay(0.01);

        $cancel->cancel();
        try {
            $cancelled->await();
            self::fail('Expected CancelledException.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('0', $queue->pop());
        $next->await();
        self::assertSame('next', $queue->pop());
    }

    public function testClosingDropsWhatWaitsAndRefusesSendersAndEndsTheWriter(): void
    {
        $queue = new OutboundQueue(1);
        $queue->push('dropped');
        $refused = new Recorder();
        $waiting = async(static function () use ($queue, $refused): void {
            try {
                $queue->push('waiting');
            } catch (WorkerUnavailableException) {
                $refused->add();
            }
        });
        delay(0.01);

        $queue->close();
        $waiting->await();

        self::assertSame(1, $refused->count());
        self::assertNull($queue->pop());
        self::assertFalse($queue->pushNow('after'));
        try {
            $queue->push('after');
            self::fail('Expected WorkerUnavailableException.');
        } catch (WorkerUnavailableException) {
            self::addToAssertionCount(1);
        }
    }

    public function testClosingWakesAWriterThatIsWaiting(): void
    {
        $queue = new OutboundQueue(1);
        $ended = new Recorder();
        async(static function () use ($queue, $ended): void {
            $ended->add($queue->pop() === null ? 'null' : 'line');
        })->ignore();
        delay(0.01);

        $queue->close();
        delay(0.01);

        self::assertSame(['null'], $ended->entries());
    }
}
