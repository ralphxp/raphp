<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;
use function Amp\Future\await;

use Closure;
use PHPUnit\Framework\Attributes\Group;
use Raphp\Error\WorkerCrashedException;
use Raphp\Ipc\Message\Hello;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use Raphp\Tests\Support\RawConnection;
use Raphp\Tests\Support\Recorder;
use Throwable;

/**
 * Liveness (FRAMEWORK 6.8): pings after silence, a dead peer declared after too many silent
 * intervals, no false positives, and the R19 case of a large message in flight.
 */
#[Group('slow')]
final class RpcPeerHeartbeatTest extends AsyncTestCase
{
    private const float INTERVAL = 0.05;

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

    private function options(): RpcPeerOptions
    {
        return new RpcPeerOptions(pingInterval: self::INTERVAL, missedPings: 3);
    }

    // ------------------------------------------------------------ declaring a peer dead

    public function testASilentPeerIsDeclaredDeadAfterTheMissedIntervalsAndWaitingCallsFailWithWorkerCrashed(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 10.0));
        $connection->raw->receive();
        $started = hrtime(true);

        $error = $this->thrownBy(fn(): mixed => $call->await());
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertInstanceOf(WorkerCrashedException::class, $error);
        self::assertStringContainsString('3 ping intervals', $error->getMessage());
        self::assertGreaterThanOrEqual(self::INTERVAL * 3 - 0.02, $elapsed, 'it must wait the whole allowance');
        self::assertLessThan(self::INTERVAL * 6, $elapsed, 'and it must not wait much longer than the allowance either');
        self::assertTrue($connection->peer->isClosed());
        self::assertTrue($connection->logs->hasWarningThatContains('declaring it dead'));
    }

    public function testThePeerGetsPingsWhileItIsSilentAndOneAnswerPerPingKeepsItAlive(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;
        $pings = new Recorder();
        $deadline = hrtime(true) + 600_000_000; // 0.6 s: twelve intervals, far more than the three allowed

        while (hrtime(true) < $deadline) {
            $message = $connection->raw->receive(1.0);
            if (is_array($message) && ($message['kind'] ?? null) === 'ping') {
                $pings->add();
                $connection->raw->send(['kind' => 'pong']);
            }
        }

        self::assertGreaterThanOrEqual(3, $pings->count());
        self::assertFalse($connection->peer->isClosed(), 'a peer that answers is alive');
    }

    public function testPingsWaitingBehindAStuckWriterAreCoalescedSoTheyNeverFillTheQueue(): void
    {
        // A queue with room for two messages. The far end sends a little every 1.5 intervals, so it is
        // never dead but is silent for a whole interval each time, and a ping is due at every tick.
        $connection = RawConnection::open(new RpcPeerOptions(outboundBuffer: 1, pingInterval: self::INTERVAL, missedPings: 3));
        $this->connection = $connection;
        $connection->peer->notify('fill', ['padding' => str_repeat('x', 3_000_000)]);
        delay(0.05); // the writer is now stuck on the big message: the far end does not read

        for ($beat = 0; $beat < 20; $beat++) {
            $connection->raw->send(['kind' => 'evt', 'method' => 'alive']);
            delay(self::INTERVAL * 1.5);
        }

        self::assertFalse($connection->peer->isClosed(), 'twenty pings that cannot be written must cost one queue place, not twenty');
        self::assertFalse($connection->logs->hasWarningThatContains('does not read what is sent to it'));
    }

    public function testAnyTrafficProvesLifeEvenIfPingsAreNeverAnswered(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;

        for ($beat = 0; $beat < 12; $beat++) {
            $connection->raw->send(['kind' => 'evt', 'method' => 'still.here']);
            delay(self::INTERVAL * 0.6);
        }

        self::assertFalse($connection->peer->isClosed(), 'messages other than pong count as proof of life');
    }

    public function testNoPingIsSentWhileTheConnectionIsBusy(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;

        // The far end talks every 0.4 intervals for 8 beats: never silent for a whole interval.
        for ($beat = 0; $beat < 8; $beat++) {
            $connection->raw->send(['kind' => 'evt', 'method' => 'busy']);
            delay(self::INTERVAL * 0.4);
        }

        // Take what the peer sent meanwhile, without waiting (waiting would make the line silent).
        $kinds = [];
        while (true) {
            try {
                $message = $connection->raw->receive(0.005);
            } catch (CancelledException) {
                break;
            }

            $kinds[] = is_array($message) ? $message['kind'] ?? '' : '';
        }

        self::assertNotContains('ping', $kinds, 'while traffic flows no ping is needed');
    }

    public function testTheHeartbeatStopsWhenTheConnectionCloses(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;

        $connection->peer->close();
        delay(self::INTERVAL * 6);

        self::assertNull($connection->raw->receive(1.0), 'nothing but the end of the connection may arrive: no more pings');
        self::assertSame([], $this->loopErrors);
    }

    // ------------------------------------------------------------ no false positives

    public function testHeavyTrafficBothWaysWithAFastHeartbeatDeclaresNobodyDead(): void
    {
        $pair = PeerPair::open(new RpcPeerOptions(maxQueuedCalls: 4000, pingInterval: self::INTERVAL), new RpcPeerOptions(maxQueuedCalls: 4000, pingInterval: self::INTERVAL));
        $this->pair = $pair;
        $answer = str_repeat('a', 600);
        $pair->serverHandlers->register('echo', static fn(): string => $answer);
        $pair->clientHandlers->register('echo', static fn(): string => $answer);

        $futures = [];
        for ($number = 0; $number < 1000; $number++) {
            $futures[] = async(fn(): mixed => $pair->client->call('echo', timeout: 20.0));
            $futures[] = async(fn(): mixed => $pair->server->call('echo', timeout: 20.0));
        }

        $results = await($futures);

        self::assertCount(2000, $results);
        self::assertFalse($pair->client->isClosed());
        self::assertFalse($pair->server->isClosed());
    }

    public function testAnIdleConnectionWithTwoHealthyPeersStaysOpenIndefinitely(): void
    {
        $pair = PeerPair::open(new RpcPeerOptions(pingInterval: self::INTERVAL), new RpcPeerOptions(pingInterval: self::INTERVAL));
        $this->pair = $pair;
        $pair->serverHandlers->register('ok', static fn(): string => 'ok');

        delay(self::INTERVAL * 20);

        self::assertFalse($pair->client->isClosed());
        self::assertFalse($pair->server->isClosed());
        self::assertSame('ok', $pair->client->call('ok', timeout: 5.0));
    }

    public function testAProcessThatWasNotRunningForALongTimeDoesNotBlameItsPeer(): void
    {
        $pair = PeerPair::open(new RpcPeerOptions(pingInterval: self::INTERVAL), new RpcPeerOptions(pingInterval: self::INTERVAL));
        $this->pair = $pair;
        $pair->serverHandlers->register('ok', static fn(): string => 'ok');
        delay(self::INTERVAL * 2);

        usleep(500_000); // the whole process is stuck for ten intervals, like a blocking call or a stopped process
        delay(self::INTERVAL * 6);

        self::assertFalse($pair->client->isClosed(), 'the silence was this process\'s own');
        self::assertFalse($pair->server->isClosed());
        self::assertSame('ok', $pair->client->call('ok', timeout: 5.0));
    }

    public function testBothFeaturesTogetherHelloAndHeartbeat(): void
    {
        $pair = PeerPair::open(
            new RpcPeerOptions(pingInterval: self::INTERVAL),
            new RpcPeerOptions(pingInterval: self::INTERVAL),
            clientHello: new Hello(1, 'master', 'app', 1),
            serverHello: new Hello(1, 'worker', 'main', 2),
        );
        $this->pair = $pair;
        $pair->serverHandlers->register('ok', static fn(): string => 'ok');

        self::assertSame('worker', $pair->client->awaitHandshake()->role);
        delay(self::INTERVAL * 10);

        self::assertSame('ok', $pair->client->call('ok', timeout: 5.0));
    }

    // ------------------------------------------------------------ R19: a large message in flight

    public function testAPeerSlowlyDeliveringAHugeMessageIsAliveEvenThoughItsPongsWaitBehindIt(): void
    {
        $handled = new Recorder();
        $connection = RawConnection::open($this->options(), onEvent: static function (string $method, array $params) use ($handled): void {
            $handled->add($method . ':' . strlen((string) json_encode($params)));
        });
        $this->connection = $connection;
        $line = '{"kind":"evt","method":"big","params":{"padding":"' . str_repeat('x', 3_000_000) . '"}}' . "\n";
        $started = hrtime(true);

        // 3 MB in 60 chunks, one every 20 ms: about 1.2 s, eight times what the three silent
        // intervals allow (0.15 s). Not one complete message arrives in that time.
        foreach (str_split($line, 50_000) as $chunk) {
            $connection->raw->sendRaw($chunk);
            delay(0.02);
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        delay(0.05);

        self::assertGreaterThan(self::INTERVAL * 3 * 3, $elapsed, 'the transfer must really have outlasted the allowance');
        self::assertFalse($connection->peer->isClosed(), 'bytes were arriving the whole time, so the peer is alive');
        self::assertSame(1, $handled->count(), 'and the message arrived whole');
    }

    public function testAPeerThatSendsNothingAtAllDuringTheSameTimeIsDeclaredDead(): void
    {
        $connection = RawConnection::open($this->options());
        $this->connection = $connection;

        delay(self::INTERVAL * 3 * 3);

        self::assertTrue($connection->peer->isClosed(), 'the contrast to the test above: silence, not slowness, is what kills');
    }

    public function testACancelQueuedBehindAHugeMessageArrivesAfterItInOrder(): void
    {
        $events = new Recorder();
        $pair = PeerPair::open(serverOnEvent: static function (string $method) use ($events): void {
            $events->add("event {$method}");
        });
        $this->pair = $pair;
        $pair->serverHandlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation) use ($events): string {
            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $events->add('cancelled');

                throw $exception;
            }

            return 'x';
        });
        $cancel = new \Amp\DeferredCancellation();
        $call = async(fn(): mixed => $pair->client->call('wait', cancellation: $cancel->getCancellation()));
        delay(0.05);

        $pair->client->notify('big', ['padding' => str_repeat('x', 4_000_000)]);
        $cancel->cancel();
        $this->thrownBy(fn(): mixed => $call->await());
        delay(0.3);

        self::assertSame(['event big', 'cancelled'], $events->entries(), 'one ordered stream: the cancel cannot overtake the message ahead of it');
    }
}
