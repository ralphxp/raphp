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

    public function testPushNowNeverWaitsEvenWhenTheQueueIsFull(): void
    {
        $queue = new OutboundQueue(1);
        $queue->push('1');

        self::assertTrue($queue->pushNow('2'));
        self::assertTrue($queue->pushNow('3'));
        self::assertSame('1', $queue->pop());
        self::assertSame('2', $queue->pop());
        self::assertSame('3', $queue->pop());
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
