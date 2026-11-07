<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Closure;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\Hello;
use Raphp\Rpc\Middleware\TraceIdMiddleware;
use Raphp\Rpc\MiddlewareStack;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\RawConnection;
use Raphp\Tests\Support\Recorder;
use Throwable;

/**
 * The peer against a far end played by hand: exact messages in, exact messages out, and what it
 * does with garbage.
 */
final class RpcPeerProtocolTest extends AsyncTestCase
{
    private ?RawConnection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->connection = null;
        parent::tearDown();
    }

    /**
     * @param (Closure(string, array<array-key, mixed>): void)|null $onEvent
     * @param Hello|null $hello what the peer announces first; null means no handshake
     */
    private function connect(
        RpcPeerOptions $options = new RpcPeerOptions(),
        JsonLineCodec $codec = new JsonLineCodec(),
        MiddlewareStack $outbound = new MiddlewareStack(),
        ?Closure $onEvent = null,
        ?Hello $hello = null,
    ): RawConnection {
        $this->connection = RawConnection::open($options, $codec, $outbound, $onEvent, $hello);

        return $this->connection;
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

    private function assertStillWorks(RawConnection $connection): void
    {
        $connection->raw->send(['kind' => 'ping']);

        self::assertSame(['kind' => 'pong'], $connection->raw->receive());
    }

    // ------------------------------------------------------------ answering requests

    public function testAPingIsAnsweredWithAPong(): void
    {
        $connection = $this->connect();

        $this->assertStillWorks($connection);
    }

    public function testARequestIsAnsweredWithTheExactResponseMessage(): void
    {
        $connection = $this->connect();
        $connection->handlers->register('echo', static fn(RpcRequest $request): mixed => $request->params);

        $connection->raw->send(['id' => 5, 'kind' => 'req', 'method' => 'echo', 'params' => ['a' => 1]]);

        self::assertSame(['id' => 5, 'kind' => 'res', 'result' => ['a' => 1]], $connection->raw->receive());
    }

    public function testProgressComesBeforeTheResponseAndCarriesTheRequestsIdAsRef(): void
    {
        $connection = $this->connect();
        $connection->handlers->register('scan', static function (RpcRequest $request): string {
            $request->progress->report(['done' => 1]);
            $request->progress->report(['done' => 2]);

            return 'ok';
        });

        $connection->raw->send(['id' => 9, 'kind' => 'req', 'method' => 'scan']);

        self::assertSame(['kind' => 'evt', 'ref' => 9, 'method' => 'progress', 'params' => ['done' => 1]], $connection->raw->receive());
        self::assertSame(['kind' => 'evt', 'ref' => 9, 'method' => 'progress', 'params' => ['done' => 2]], $connection->raw->receive());
        self::assertSame(['id' => 9, 'kind' => 'res', 'result' => 'ok'], $connection->raw->receive());
    }

    public function testAnUnknownMethodAndAFailingHandlerGiveTheDocumentedErrorObjects(): void
    {
        $connection = $this->connect();
        $connection->handlers->register('boom', static function (): never {
            throw new \RuntimeException('hidden');
        });

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'nope']);
        $connection->raw->send(['id' => 2, 'kind' => 'req', 'method' => 'boom']);
        $answers = [$connection->raw->receive(), $connection->raw->receive()];

        self::assertContains(['id' => 1, 'kind' => 'res', 'error' => ['code' => 'E_METHOD_NOT_FOUND', 'message' => 'there is no method with that name', 'data' => ['method' => 'nope']]], $answers);
        self::assertContains(['id' => 2, 'kind' => 'res', 'error' => ['code' => 'E_INTERNAL', 'message' => 'internal error', 'data' => null]], $answers);
    }

    public function testTheTraceIdOfARequestReachesTheHandler(): void
    {
        $connection = $this->connect();
        $seen = new Recorder();
        $connection->handlers->register('x', static function (RpcRequest $request) use ($seen): string {
            $seen->add((string) $request->trace);

            return 'ok';
        });

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'x', 'trace' => 'abc-123']);
        $connection->raw->receive();

        self::assertSame(['abc-123'], $seen->entries());
    }

    public function testRequestsWithTheSameIdAfterTheFirstWasAnsweredAreFine(): void
    {
        $connection = $this->connect();
        $connection->handlers->register('x', static fn(): string => 'ok');

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'x']);
        self::assertSame(['id' => 1, 'kind' => 'res', 'result' => 'ok'], $connection->raw->receive());
        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'x']);
        self::assertSame(['id' => 1, 'kind' => 'res', 'result' => 'ok'], $connection->raw->receive());
    }

    public function testACancelStopsTheMatchingRequestOnly(): void
    {
        $connection = $this->connect();
        $cancelled = new Recorder();
        $connection->handlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation) use ($cancelled): string {
            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $name = $request->params['name'] ?? '';
                $cancelled->add(is_string($name) ? $name : '');

                throw $exception;
            }

            return 'done';
        });

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'wait', 'params' => ['name' => 'one']]);
        $connection->raw->send(['id' => 2, 'kind' => 'req', 'method' => 'wait', 'params' => ['name' => 'two']]);
        delay(0.05);
        $connection->raw->send(['kind' => 'cancel', 'ref' => 1]);

        self::assertSame(['id' => 1, 'kind' => 'res', 'error' => ['code' => 'E_CANCELLED', 'message' => 'the request was cancelled', 'data' => null]], $connection->raw->receive());
        self::assertSame(['one'], $cancelled->entries());
    }

    public function testACancelForARequestThatIsUnknownOrFinishedIsIgnored(): void
    {
        $connection = $this->connect();

        $connection->raw->send(['kind' => 'cancel', 'ref' => 999]);

        $this->assertStillWorks($connection);
    }

    public function testMoreRequestsInFlightThanTheLimitAreAnsweredWithWorkerUnavailable(): void
    {
        $connection = $this->connect(new RpcPeerOptions(maxInbound: 1));
        $connection->handlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(10, true, $cancellation);

            return 'done';
        });

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'wait']);
        delay(0.02);
        $connection->raw->send(['id' => 2, 'kind' => 'req', 'method' => 'wait']);

        self::assertSame(['id' => 2, 'kind' => 'res', 'error' => ['code' => 'E_WORKER_UNAVAILABLE', 'message' => 'too many requests are being handled', 'data' => null]], $connection->raw->receive());
    }

    // ------------------------------------------------------------ what the peer sends when calling

    public function testACallIsSentAsTheDocumentedRequestAndIdsCountUpFromOne(): void
    {
        $connection = $this->connect();
        $first = async(fn(): mixed => $connection->peer->call('files.scan', ['path' => '/x'], timeout: 5.0));

        self::assertSame(['id' => 1, 'kind' => 'req', 'method' => 'files.scan', 'params' => ['path' => '/x']], $connection->raw->receive());
        $connection->raw->send(['id' => 1, 'kind' => 'res', 'result' => ['count' => 3]]);
        self::assertSame(['count' => 3], $first->await());

        $second = async(fn(): mixed => $connection->peer->call('again', timeout: 5.0));

        self::assertSame(['id' => 2, 'kind' => 'req', 'method' => 'again', 'params' => []], $connection->raw->receive());
        $connection->raw->send(['id' => 2, 'kind' => 'res', 'result' => null]);
        self::assertNull($second->await());
    }

    public function testAnErrorResponseFailsTheCallWithTheRightException(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->send(['id' => 1, 'kind' => 'res', 'error' => ['code' => 'E_TIMEOUT', 'message' => 'too slow']]);

        self::assertInstanceOf(RequestTimeoutException::class, $this->thrownBy(fn(): mixed => $call->await()));
    }

    public function testATimedOutCallTellsThePeerToCancelIt(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 0.05));

        $request = $connection->raw->receive();
        self::assertIsArray($request);
        self::assertSame('req', $request['kind'] ?? null);
        self::assertSame(['kind' => 'cancel', 'ref' => 1], $connection->raw->receive());
        self::assertInstanceOf(RequestTimeoutException::class, $this->thrownBy(fn(): mixed => $call->await()));
    }

    public function testAGivenUpCallKeepsItsSlotUntilTheAnswerArrives(): void
    {
        $connection = $this->connect(new RpcPeerOptions(maxInFlight: 1));
        $first = async(fn(): mixed => $connection->peer->call('first', timeout: 0.05));
        $connection->raw->receive();
        $connection->raw->receive();
        $this->thrownBy(fn(): mixed => $first->await());

        $second = async(fn(): mixed => $connection->peer->call('second', timeout: 5.0));

        self::assertInstanceOf(CancelledException::class, $this->thrownBy(fn() => $connection->raw->receive(0.1)), 'the second call must wait: the first is still being worked on');

        $connection->raw->send(['id' => 1, 'kind' => 'res', 'error' => ['code' => 'E_CANCELLED', 'message' => 'stopped']]);

        self::assertSame(['id' => 2, 'kind' => 'req', 'method' => 'second', 'params' => []], $connection->raw->receive());
        $connection->raw->send(['id' => 2, 'kind' => 'res', 'result' => 'ok']);
        self::assertSame('ok', $second->await());
    }

    public function testTheTraceMiddlewareAddsATraceIdToTheWire(): void
    {
        $connection = $this->connect(outbound: new MiddlewareStack([new TraceIdMiddleware()]));
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));

        $request = $connection->raw->receive();

        self::assertIsArray($request);
        self::assertIsString($request['trace'] ?? null);
        self::assertSame(1, preg_match('/\A[0-9a-f]{16}\z/', $request['trace']));
        $connection->raw->send(['id' => 1, 'kind' => 'res', 'result' => null]);
        $call->await();
    }

    public function testNotifyIsSentAsAnEventWithoutIdOrRef(): void
    {
        $connection = $this->connect();

        $connection->peer->notify('app.theme_changed', ['dark' => true]);

        self::assertSame(['kind' => 'evt', 'method' => 'app.theme_changed', 'params' => ['dark' => true]], $connection->raw->receive());
    }

    // ------------------------------------------------------------ messages that are not requests

    public function testAnEventWithoutARefGoesToTheEventHandler(): void
    {
        $events = new Recorder();
        $connection = $this->connect(onEvent: static function (string $method, array $params) use ($events): void {
            $events->add($method . ':' . json_encode($params));
        });

        $connection->raw->send(['kind' => 'evt', 'method' => 'app.theme_changed', 'params' => ['dark' => true]]);
        $this->assertStillWorks($connection);

        self::assertSame(['app.theme_changed:{"dark":true}'], $events->entries());
    }

    public function testAProgressEventForACallNobodyWaitsForIsIgnored(): void
    {
        $connection = $this->connect();

        $connection->raw->send(['kind' => 'evt', 'ref' => 42, 'method' => 'progress', 'params' => ['done' => 1]]);

        $this->assertStillWorks($connection);
        self::assertFalse($connection->logs->hasWarningRecords());
    }

    public function testAHelloWithoutAHandshakeAndAPongAreIgnored(): void
    {
        $connection = $this->connect();

        $connection->raw->send(['kind' => 'hello', 'proto' => 1, 'role' => 'worker', 'name' => 'main', 'pid' => 5]);
        $connection->raw->send(['kind' => 'pong']);
        $this->assertStillWorks($connection);

        self::assertFalse($connection->logs->hasWarningRecords());
        self::assertTrue($connection->logs->hasDebugThatContains('does not use a handshake'));
    }

    public function testAResponseForACallNobodyWaitsForIsLoggedAndTheConnectionContinues(): void
    {
        $connection = $this->connect();

        $connection->raw->send(['id' => 999, 'kind' => 'res', 'result' => 1]);
        $this->assertStillWorks($connection);

        self::assertTrue($connection->logs->hasWarningThatContains('nobody is waiting for'));
    }

    // ------------------------------------------------------------ the peer breaks the protocol

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenInput(): iterable
    {
        yield 'not JSON' => ["this is not json\n"];
        yield 'a list' => ["[1,2]\n"];
        yield 'invalid UTF-8' => ["{\"kind\":\"ping\",\"x\":\"\xff\"}\n"];
        yield 'an unknown kind' => ["{\"kind\":\"teleport\"}\n"];
        yield 'a request without an id' => ["{\"kind\":\"req\",\"method\":\"x\"}\n"];
        yield 'a request id of the wrong type' => ["{\"kind\":\"req\",\"id\":\"1\",\"method\":\"x\"}\n"];
        yield 'a blank line' => ["\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenInput')]
    public function testBrokenInputClosesTheConnectionAndFailsWaitingCallsWithAProtocolError(string $bytes): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->sendRaw($bytes);
        $error = $this->thrownBy(fn(): mixed => $call->await());

        self::assertInstanceOf(ProtocolException::class, $error);
        self::assertTrue($connection->peer->isClosed());
        $farewell = $connection->raw->receive();
        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null, 'the far end is told why before the connection closes');
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringStartsWith('E_', $farewell['reason']);
        self::assertNull($connection->raw->receive(), 'the far end must then see the connection close');
        self::assertTrue($connection->logs->hasWarningThatContains('broke the protocol'));
    }

    public function testALineLargerThanTheLimitClosesTheConnectionWithPayloadTooLarge(): void
    {
        $connection = $this->connect(codec: new JsonLineCodec(maxLineBytes: 100));
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->sendRaw('{"kind":"evt","method":"' . str_repeat('x', 300) . "\"}\n");

        self::assertInstanceOf(PayloadTooLargeException::class, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertTrue($connection->peer->isClosed());
    }

    public function testALimitlessLineWithoutANewlineCannotMakeThePeerBufferWithoutEnd(): void
    {
        $connection = $this->connect(codec: new JsonLineCodec(maxLineBytes: 1000));
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $sent = 0;
        while ($sent < 50 && !$connection->peer->isClosed()) {
            $connection->raw->sendRaw(str_repeat('x', 500));
            ++$sent;
            delay(0.001);
        }

        self::assertInstanceOf(PayloadTooLargeException::class, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertLessThan(10, $sent, 'the peer must give up soon after the limit');
    }

    public function testTwoRequestsWithTheSameIdAtOnceAreAProtocolViolation(): void
    {
        $connection = $this->connect();
        $cancelled = new Recorder();
        $connection->handlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation) use ($cancelled): string {
            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $cancelled->add();

                throw $exception;
            }

            return 'x';
        });

        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'wait']);
        delay(0.02);
        $connection->raw->send(['id' => 1, 'kind' => 'req', 'method' => 'wait']);

        $farewell = $connection->raw->receive();
        self::assertIsArray($farewell);
        self::assertSame('bye', $farewell['kind'] ?? null);
        self::assertIsString($farewell['reason'] ?? null);
        self::assertStringContainsString('already in use', $farewell['reason']);
        self::assertNull($connection->raw->receive());
        self::assertTrue($connection->peer->isClosed());
        $connection->peer->awaitClosed();
        self::assertSame(1, $cancelled->count(), 'the running request is cancelled when the connection is dropped');
    }

    // ------------------------------------------------------------ the peer ends the connection

    public function testEndOfInputWithoutByeFailsWaitingCallsWithWorkerCrashed(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->close();

        self::assertInstanceOf(WorkerCrashedException::class, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertTrue($connection->peer->isClosed());
    }

    public function testByeFailsWaitingCallsWithWorkerUnavailableAndSaysWhy(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->send(['kind' => 'bye', 'reason' => 'shutdown']);
        $error = $this->thrownBy(fn(): mixed => $call->await());

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
        self::assertStringContainsString('shutdown', $error->getMessage());
        self::assertTrue($connection->peer->isClosed());
    }

    public function testAHalfWrittenMessageAtEndOfInputIsLoggedAndStillACrash(): void
    {
        $connection = $this->connect();
        $call = async(fn(): mixed => $connection->peer->call('x', timeout: 5.0));
        $connection->raw->receive();

        $connection->raw->sendRaw('{"id":1,"kind":"res","res');
        $connection->raw->close();

        self::assertInstanceOf(WorkerCrashedException::class, $this->thrownBy(fn(): mixed => $call->await()));
        self::assertTrue($connection->logs->hasWarningThatContains('in the middle of a message'));
    }
}
