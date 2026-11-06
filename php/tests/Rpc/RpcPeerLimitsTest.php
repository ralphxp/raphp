<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;

use function Amp\delay;
use function Amp\Future\await;

use Closure;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use Raphp\Tests\Support\RawConnection;
use Raphp\Tests\Support\Recorder;
use Throwable;

/**
 * Limits and flow control: how many calls run at once, what waits, what fails, and that two
 * peers that send a lot cannot block each other (finding F38).
 */
final class RpcPeerLimitsTest extends AsyncTestCase
{
    private ?PeerPair $pair = null;

    private ?RawConnection $connection = null;

    protected function tearDown(): void
    {
        $this->pair?->close();
        $this->connection?->close();
        $this->pair = null;
        $this->connection = null;
        parent::tearDown();
    }

    /**
     * @param Closure(): mixed $action
     */
    private function thrownBy(Closure $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Expected an exception.');
    }

    public function testMoreCallsThanTheLimitAllFinishBecauseTheExtraOnesWait(): void
    {
        $pair = PeerPair::open(clientOptions: new RpcPeerOptions(maxInFlight: 3));
        $this->pair = $pair;
        $pair->serverHandlers->register('work', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(0.03, true, $cancellation);

            return 'done';
        });

        $futures = [];
        for ($number = 0; $number < 12; $number++) {
            $futures[] = async(fn(): mixed => $pair->client->call('work', timeout: 10.0));
        }

        self::assertSame(array_fill(0, 12, 'done'), await($futures));
    }

    public function testTheServerNeverSeesMoreCallsAtOnceThanTheClientLimit(): void
    {
        $pair = PeerPair::open(clientOptions: new RpcPeerOptions(maxInFlight: 3));
        $this->pair = $pair;
        $maximum = 0;
        $active = 0;
        $pair->serverHandlers->register('work', static function (RpcRequest $request, ?Cancellation $cancellation) use (&$active, &$maximum): string {
            ++$active;
            $maximum = max($maximum, $active);
            delay(0.03, true, $cancellation);
            --$active;

            return 'done';
        });

        $futures = [];
        for ($number = 0; $number < 12; $number++) {
            $futures[] = async(fn(): mixed => $pair->client->call('work', timeout: 10.0));
        }

        await($futures);

        self::assertGreaterThan(1, $maximum, 'calls must run concurrently');
        self::assertLessThanOrEqual(3, $maximum, 'the limit must hold');
    }

    public function testACallBeyondTheWaitingLimitFailsAtOnceWithWorkerUnavailable(): void
    {
        $pair = PeerPair::open(clientOptions: new RpcPeerOptions(maxInFlight: 1, maxQueuedCalls: 2));
        $this->pair = $pair;
        $release = new DeferredFuture();
        $pair->serverHandlers->register('hold', static function () use ($release): string {
            $release->getFuture()->await();

            return 'released';
        });

        $running = async(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        $waitingOne = async(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        $waitingTwo = async(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        delay(0.05);

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        $release->complete();

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
        self::assertSame(['released', 'released', 'released'], await([$running, $waitingOne, $waitingTwo]));
    }

    public function testACallThatTimesOutWhileWaitingForASlotSendsNothingAndLeaksNoSlot(): void
    {
        $pair = PeerPair::open(clientOptions: new RpcPeerOptions(maxInFlight: 1));
        $this->pair = $pair;
        $release = new DeferredFuture();
        $received = new Recorder();
        $pair->serverHandlers->register('hold', static function () use ($release, $received): string {
            $received->add();
            $release->getFuture()->await();

            return 'released';
        });
        $pair->serverHandlers->register('quick', static function () use ($received): string {
            $received->add();

            return 'quick';
        });

        $first = async(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        delay(0.03);

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('quick', timeout: 0.05));

        self::assertInstanceOf(RequestTimeoutException::class, $error);
        $release->complete();
        self::assertSame('released', $first->await());
        self::assertSame('quick', $pair->client->call('quick', timeout: 5.0), 'the slot must not have been lost');
        self::assertSame(2, $received->count(), 'the hold call and the last quick call: the quick call that timed out while waiting sent nothing');
    }

    public function testACallWaitingForASlotCanBeCancelled(): void
    {
        $pair = PeerPair::open(clientOptions: new RpcPeerOptions(maxInFlight: 1));
        $this->pair = $pair;
        $release = new DeferredFuture();
        $pair->serverHandlers->register('hold', static function () use ($release): string {
            $release->getFuture()->await();

            return 'released';
        });
        $first = async(fn(): mixed => $pair->client->call('hold', timeout: 10.0));
        delay(0.03);
        $cancel = new DeferredCancellation();
        $second = async(fn(): mixed => $pair->client->call('hold', cancellation: $cancel->getCancellation()));
        delay(0.03);

        $cancel->cancel();
        $error = $this->thrownBy(fn(): mixed => $second->await());
        $release->complete();

        self::assertInstanceOf(RequestCancelledException::class, $error);
        self::assertSame('released', $first->await());
    }

    // ------------------------------------------------------------ flow control

    public function testTwoPeersThatBothSendAGreatDealAtOnceNeverBlockEachOther(): void
    {
        $roomForEveryCall = new RpcPeerOptions(maxQueuedCalls: 4000);
        $pair = PeerPair::open($roomForEveryCall, $roomForEveryCall);
        $this->pair = $pair;
        $answer = str_repeat('a', 600);
        $pair->serverHandlers->register('echo', static fn(): string => $answer);
        $pair->clientHandlers->register('echo', static fn(): string => $answer);

        $futures = [];
        for ($number = 0; $number < 1500; $number++) {
            $futures[] = async(fn(): mixed => $pair->client->call('echo', ['n' => $number], timeout: 20.0));
            $futures[] = async(fn(): mixed => $pair->server->call('echo', ['n' => $number], timeout: 20.0));
        }

        $results = await($futures);

        self::assertSame(array_fill(0, 3000, $answer), array_values($results));
        self::assertSame([], $this->loopErrors);
    }

    public function testWhileTheFarEndStopsReadingTheReaderStillHandlesRequestsAndSendersWait(): void
    {
        $connection = RawConnection::open(new RpcPeerOptions(outboundBuffer: 8));
        $this->connection = $connection;
        $handled = new Recorder();
        $connection->handlers->register('mark', static function () use ($handled): string {
            $handled->add();

            return 'marked';
        });
        $sent = new Recorder();
        $notifier = async(function () use ($connection, $sent): void {
            for ($number = 0; $number < 3000; $number++) {
                $connection->peer->notify('tick', ['n' => $number, 'padding' => str_repeat('p', 100)]);
                $sent->add();
            }
        });
        delay(0.2);

        self::assertLessThan(3000, $sent->count(), 'the sender must wait while nobody reads');
        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'mark']);
        delay(0.1);
        self::assertSame(1, $handled->count(), 'the read loop must stay responsive while writes are backed up');

        $events = 0;
        $answered = false;
        while ($events < 3000 || !$answered) {
            $message = $connection->raw->receive(10.0);
            self::assertIsArray($message);
            if (($message['kind'] ?? null) === 'evt') {
                ++$events;
            } else {
                $answered = true;
                self::assertSame(['id' => 1, 'kind' => 'res', 'result' => 'marked'], $message);
            }
        }

        $notifier->await();
        self::assertSame(3000, $sent->count());
    }
}
