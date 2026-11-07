<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;
use function Amp\delay;

use Closure;
use LogicException;
use Raphp\Error\ProtocolException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Message\Hello;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use Raphp\Tests\Support\RawConnection;
use Throwable;

/**
 * The hello exchange, the protocol version check and the orderly goodbye (FRAMEWORK 6.5).
 */
final class RpcPeerHandshakeTest extends AsyncTestCase
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

    private function connect(RpcPeerOptions $options = new RpcPeerOptions()): RawConnection
    {
        $this->connection = RawConnection::open($options, hello: new Hello(1, 'worker', 'main', 4242, ['shm']));

        return $this->connection;
    }

    /**
     * Reads the peer's own hello, which is always the first thing it sends.
     */
    private function receiveHello(RawConnection $connection): void
    {
        self::assertSame('hello', $this->receiveMessage($connection)['kind'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function receiveMessage(RawConnection $connection): array
    {
        $message = $connection->raw->receive();
        self::assertIsArray($message);

        return $message;
    }

    // ------------------------------------------------------------ the exchange

    public function testBothSidesLearnWhoTheOtherIs(): void
    {
        $pair = PeerPair::open(clientHello: new Hello(1, 'master', 'app', 100, ['shm']), serverHello: new Hello(1, 'worker', 'main', 200));
        $this->pair = $pair;

        $fromServer = $pair->client->awaitHandshake();
        $fromClient = $pair->server->awaitHandshake();

        self::assertEquals(new Hello(1, 'worker', 'main', 200, []), $fromServer);
        self::assertEquals(new Hello(1, 'master', 'app', 100, ['shm']), $fromClient);
        self::assertSame($fromServer, $pair->client->remote());
    }

    public function testNothingIsKnownAboutThePeerBeforeItsHelloArrives(): void
    {
        $connection = $this->connect();

        self::assertNull($connection->peer->remote());
    }

    public function testTheFirstThingSentIsTheHelloEvenIfACallIsMadeAtOnce(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));

        $first = $this->receiveMessage($connection);
        $second = $this->receiveMessage($connection);

        self::assertSame(['kind' => 'hello', 'proto' => 1, 'role' => 'worker', 'name' => 'main', 'pid' => 4242, 'caps' => ['shm']], $first);
        self::assertSame('req', $second['kind'] ?? null);

        // answer as a well-behaved far end: its own hello first, then the response
        $connection->raw->send(['kind' => 'hello', 'proto' => 1, 'role' => 'master', 'name' => 'app', 'pid' => 9, 'caps' => []]);
        $connection->raw->send(['id' => $second['id'] ?? 0, 'kind' => 'res', 'result' => null]);
        $call->await();
    }

    public function testAPeerBuiltWithoutAHelloHasNoHandshakeToWaitFor(): void
    {
        $pair = PeerPair::open();
        $this->pair = $pair;

        $this->expectException(LogicException::class);

        $pair->client->awaitHandshake();
    }

    public function testWaitingForTheHandshakeCanBeCancelled(): void
    {
        $connection = $this->connect();
        $cancel = new \Amp\DeferredCancellation();
        $wait = async(fn() => $connection->peer->awaitHandshake($cancel->getCancellation()));
        delay(0.02);

        $cancel->cancel();

        self::assertInstanceOf(\Amp\CancelledException::class, $this->thrownBy(fn() => $wait->await()));
    }

    public function testPingPongAndByeMayComeBeforeTheHello(): void
    {
        $connection = $this->connect();
        $this->receiveHello($connection);

        $connection->raw->send(['kind' => 'ping']);

        self::assertSame(['kind' => 'pong'], $connection->raw->receive());
        self::assertFalse($connection->peer->isClosed());
    }

    // ------------------------------------------------------------ what goes wrong

    public function testADifferentProtocolVersionIsAnsweredWithByeAndEProtocolAndTheConnectionCloses(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $wait = async(fn() => $connection->peer->awaitHandshake());
        $this->receiveHello($connection);
        $connection->raw->receive();

        $connection->raw->send(['kind' => 'hello', 'proto' => 2, 'role' => 'master', 'name' => 'app', 'pid' => 9, 'caps' => []]);
        $farewell = $connection->raw->receive();

        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringStartsWith('E_PROTOCOL: unsupported protocol version 2, this side speaks 1', $farewell['reason']);
        $closing = hrtime(true);
        self::assertNull($connection->raw->receive(), 'the connection must close after the bye');
        self::assertLessThan(1.0, (hrtime(true) - $closing) / 1e9, 'and at once: the flush timeout is only a safety net');
        self::assertInstanceOf(ProtocolException::class, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertInstanceOf(ProtocolException::class, $this->thrownBy(fn() => $wait->await()));
        self::assertTrue($connection->peer->isClosed());
        self::assertNull($connection->peer->remote(), 'a refused hello must not be taken as the peer');
    }

    public function testAnyMessageBeforeTheHelloIsAProtocolViolation(): void
    {
        $connection = $this->connect();
        $this->receiveHello($connection);

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'x']);
        $farewell = $connection->raw->receive();

        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringContainsString('expected hello first, got req', $farewell['reason']);
        self::assertNull($connection->raw->receive());
    }

    public function testASecondHelloIsAProtocolViolation(): void
    {
        $connection = $this->connect();
        $this->receiveHello($connection);
        $hello = ['kind' => 'hello', 'proto' => 1, 'role' => 'master', 'name' => 'app', 'pid' => 9, 'caps' => []];

        $connection->raw->send($hello);
        $connection->raw->send($hello);
        $farewell = $connection->raw->receive();

        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringContainsString('a second hello arrived', $farewell['reason']);
    }

    public function testNoHelloInTimeClosesTheConnectionWithAProtocolError(): void
    {
        $connection = $this->connect(new RpcPeerOptions(handshakeTimeout: 0.1));
        $wait = async(fn() => $connection->peer->awaitHandshake());
        $this->receiveHello($connection);

        $farewell = $connection->raw->receive();

        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringContainsString('no hello arrived within 0.1 seconds', $farewell['reason']);
        self::assertInstanceOf(ProtocolException::class, $this->thrownBy(fn() => $wait->await()));
    }

    public function testAHelloThatArrivesInTimeCancelsTheTimeout(): void
    {
        $connection = $this->connect(new RpcPeerOptions(handshakeTimeout: 0.15));
        $this->receiveHello($connection);
        delay(0.05);

        $connection->raw->send(['kind' => 'hello', 'proto' => 1, 'role' => 'master', 'name' => 'app', 'pid' => 9, 'caps' => []]);
        delay(0.3);

        self::assertFalse($connection->peer->isClosed(), 'the timeout must not fire once the hello is there');
        self::assertSame('app', $connection->peer->remote()?->name);
    }

    // ------------------------------------------------------------ saying bye

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function byeReasons(): iterable
    {
        yield 'an error code and a text' => ['E_PROTOCOL: you spoke wrongly', ProtocolException::class];
        yield 'a plain goodbye' => ['shutdown', WorkerUnavailableException::class];
        yield 'an empty reason' => ['', WorkerUnavailableException::class];
        yield 'an unknown code' => ['E_MADE_UP: whatever', WorkerUnavailableException::class];
        yield 'a code without a text' => ['E_PROTOCOL', WorkerUnavailableException::class];
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('byeReasons')]
    public function testWhatAWaitingCallFailsWithDependsOnTheReasonInTheBye(string $reason, string $expected): void
    {
        $connection = RawConnection::open();
        $this->connection = $connection;
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->send(['kind' => 'bye', 'reason' => $reason]);

        self::assertInstanceOf($expected, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertTrue($connection->peer->isClosed());
    }

    public function testShutdownSendsAByeWithTheReasonAndThenClosesTheConnection(): void
    {
        $connection = RawConnection::open();
        $this->connection = $connection;
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $started = hrtime(true);
        $connection->peer->shutdown('maintenance');

        self::assertSame(['kind' => 'bye', 'reason' => 'maintenance'], $connection->raw->receive());
        self::assertNull($connection->raw->receive(), 'the connection must close after the bye');
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'it must close as soon as the bye is out, not wait for the 2 s flush timeout');
        self::assertInstanceOf(WorkerUnavailableException::class, $this->thrownBy(fn(): mixed => $call->await()));
        $connection->peer->awaitClosed();
        self::assertTrue($connection->peer->isClosed());
        self::assertInstanceOf(WorkerUnavailableException::class, $this->thrownBy(fn(): mixed => $connection->peer->call('again')));
    }

    public function testAReasonLongerThanTheLimitIsCutNotRefused(): void
    {
        $connection = RawConnection::open();
        $this->connection = $connection;

        $connection->peer->shutdown(str_repeat('r', 1000));
        $farewell = $connection->raw->receive();

        self::assertIsArray($farewell);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertSame(256, strlen($farewell['reason']));
        self::assertStringEndsWith('...', $farewell['reason']);
    }

    public function testTheByeIsTheLastThingWrittenAfterWhatWasAlreadyQueued(): void
    {
        $connection = RawConnection::open();
        $this->connection = $connection;

        $connection->peer->notify('first');
        $connection->peer->notify('second');
        $connection->peer->shutdown('done');

        $first = $this->receiveMessage($connection);
        $second = $this->receiveMessage($connection);

        self::assertSame('first', $first['method'] ?? null);
        self::assertSame('second', $second['method'] ?? null);
        self::assertSame(['kind' => 'bye', 'reason' => 'done'], $connection->raw->receive());
        self::assertNull($connection->raw->receive());
    }

    public function testAFarEndThatDoesNotReadCannotHoldTheConnectionOpenPastTheFlushTimeout(): void
    {
        $connection = RawConnection::open(new RpcPeerOptions(outboundBuffer: 2, flushTimeout: 0.15));
        $this->connection = $connection;
        $connection->peer->notify('fill', ['padding' => str_repeat('x', 3_000_000)]);
        delay(0.05);
        $started = hrtime(true);

        $connection->peer->shutdown('bye');
        $connection->peer->awaitClosed();
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertGreaterThanOrEqual(0.1, $elapsed, 'the bye is given its chance to leave first');
        self::assertLessThan(2.0, $elapsed, 'but only for the flush timeout');
        self::assertTrue($connection->peer->isClosed());
    }

    public function testClosingLeavesNoTimerOrHeartbeatBehindSoTheProcessCanExit(): void
    {
        $referenced = static fn(): int => count(array_filter(
            \Revolt\EventLoop::getIdentifiers(),
            static fn(string $id): bool => \Revolt\EventLoop::isReferenced($id) && \Revolt\EventLoop::isEnabled($id),
        ));
        $before = $referenced();

        // Long timers, so anything left running is still there when we look.
        $connection = RawConnection::open(new RpcPeerOptions(pingInterval: 60.0, handshakeTimeout: 60.0, flushTimeout: 60.0), hello: new Hello(1, 'worker', 'main', 1));
        $this->connection = $connection;
        delay(0.02);
        self::assertGreaterThan($before, $referenced(), 'while the connection lives its timers are running');

        $connection->peer->shutdown('done');
        $connection->peer->awaitClosed();
        delay(0.05);

        self::assertSame($before, $referenced(), 'after the close nothing may keep the event loop (and so the process) alive');
    }

    public function testShutdownTwiceOrAfterCloseIsHarmless(): void
    {
        $connection = RawConnection::open();
        $this->connection = $connection;

        $connection->peer->shutdown('once');
        $connection->peer->shutdown('twice');
        $connection->peer->close();

        self::assertSame(['kind' => 'bye', 'reason' => 'once'], $connection->raw->receive());
        self::assertNull($connection->raw->receive());
    }
}
