<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;
use function Amp\Future\await;

use Closure;
use InvalidArgumentException;
use Monolog\Level;
use Raphp\Error\ApplicationException;
use Raphp\Error\InternalErrorException;
use Raphp\Error\InvalidParamsException;
use Raphp\Error\MethodNotFoundException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\RpcException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use Raphp\Tests\Support\Recorder;
use RuntimeException;
use Throwable;

final class RpcPeerTest extends AsyncTestCase
{
    private ?PeerPair $pair = null;

    protected function tearDown(): void
    {
        $this->pair?->close();
        $this->pair = null;
        parent::tearDown();
    }

    private function open(
        RpcPeerOptions $clientOptions = new RpcPeerOptions(),
        RpcPeerOptions $serverOptions = new RpcPeerOptions(),
    ): PeerPair {
        $this->pair = PeerPair::open($clientOptions, $serverOptions);

        return $this->pair;
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

    private static function echoParams(RpcRequest $request): mixed
    {
        return $request->params;
    }

    // ------------------------------------------------------------ calling

    public function testACallReturnsTheResultOfTheHandler(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('math.sum', static fn(RpcRequest $request): int|float => array_sum($request->params));

        self::assertSame(12, $pair->client->call('math.sum', ['a' => 5, 'b' => 7]));
    }

    public function testBothSidesCanCallTheOther(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('whoami', static fn(): string => 'server');
        $pair->clientHandlers->register('whoami', static fn(): string => 'client');

        self::assertSame('server', $pair->client->call('whoami'));
        self::assertSame('client', $pair->server->call('whoami'));
    }

    public function testAnyJsonValueCanBeTheResult(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('value', static fn(RpcRequest $request): mixed => $request->params['v'] ?? null);

        foreach ([null, true, 0, 1.5, 'caf' . "\u{e9}", [1, 2, 3], ['a' => ['b' => null]]] as $value) {
            self::assertSame($value, $pair->client->call('value', ['v' => $value]));
        }
    }

    public function testManyCallsAtOnceEachGetTheirOwnAnswer(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('echo', self::echoParams(...));
        $futures = [];
        for ($number = 0; $number < 40; $number++) {
            $futures[$number] = async(fn(): mixed => $pair->client->call('echo', ['n' => $number]));
        }

        $results = await($futures);

        foreach ($results as $number => $result) {
            self::assertSame(['n' => $number], $result);
        }
    }

    public function testAnswersMayComeBackInADifferentOrderThanTheCalls(): void
    {
        $pair = $this->open();
        $order = new Recorder();
        $pair->serverHandlers->register('slow', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(0.15, true, $cancellation);

            return 'slow answer';
        });
        $pair->serverHandlers->register('fast', static fn(): string => 'fast answer');

        $slow = async(function () use ($pair, $order): mixed {
            $result = $pair->client->call('slow');
            $order->add('slow');

            return $result;
        });
        delay(0.02);
        $fast = async(function () use ($pair, $order): mixed {
            $result = $pair->client->call('fast');
            $order->add('fast');

            return $result;
        });

        self::assertSame('fast answer', $fast->await());
        self::assertSame('slow answer', $slow->await());
        self::assertSame(['fast', 'slow'], $order->entries());
    }

    public function testProgressEventsArriveInOrderBeforeTheResult(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('scan', static function (RpcRequest $request): string {
            foreach ([25, 50, 100] as $done) {
                $request->progress->report(['done' => $done]);
            }

            return 'finished';
        });
        $seen = new Recorder();

        $result = $pair->client->call('scan', onProgress: static function (array $params, string $method) use ($seen): void {
            $seen->add($method . ':' . json_encode($params));
        });

        self::assertSame('finished', $result);
        self::assertSame(['progress:{"done":25}', 'progress:{"done":50}', 'progress:{"done":100}'], $seen->entries());
    }

    public function testAProgressCallbackThatThrowsIsLoggedAndTheCallStillSucceeds(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('scan', static function (RpcRequest $request): string {
            $request->progress->report(['done' => 1]);
            $request->progress->report(['done' => 2]);

            return 'finished';
        });

        $result = $pair->client->call('scan', onProgress: static function (): never {
            throw new RuntimeException('callback broke');
        });

        self::assertSame('finished', $result);
        self::assertTrue($pair->clientLogs->hasErrorThatContains('progress callback failed'));
    }

    public function testProgressForACallNobodyWaitsForAnymoreIsIgnored(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('scan', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(0.05, true, $cancellation);
            $request->progress->report(['late' => true]);

            return 'x';
        });

        $error = $this->thrownBy(fn() => $pair->client->call('scan', timeout: 0.01));
        $this->settle(0.15);

        self::assertInstanceOf(RequestTimeoutException::class, $error);
        self::assertSame([], $this->loopErrors);
        self::assertFalse($pair->clientLogs->hasWarningRecords());
    }

    public function testNotifyDeliversAnEventToTheOtherSide(): void
    {
        $events = new Recorder();
        $this->pair = PeerPair::open(serverOnEvent: static function (string $method, array $params) use ($events): void {
            $events->add($method . ':' . json_encode($params));
        });

        $this->pair->client->notify('app.theme_changed', ['dark' => true]);
        $this->settle();

        self::assertSame(['app.theme_changed:{"dark":true}'], $events->entries());
    }

    public function testAnEventHandlerThatThrowsIsLoggedAndTheConnectionKeepsWorking(): void
    {
        $this->pair = PeerPair::open(serverOnEvent: static function (): never {
            throw new RuntimeException('handler broke');
        });
        $this->pair->serverHandlers->register('ok', static fn(): string => 'fine');

        $this->pair->client->notify('anything');
        $this->settle();

        self::assertTrue($this->pair->serverLogs->hasErrorThatContains('event handler failed'));
        self::assertSame('fine', $this->pair->client->call('ok'));
    }

    // ------------------------------------------------------------ cancellation and timeouts

    public function testCancellingACallFailsItAtOnceAndStopsTheHandler(): void
    {
        $pair = $this->open();
        $handler = new Recorder();
        $pair->serverHandlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation) use ($handler): string {
            $handler->add('started');

            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $handler->add('cancelled');

                throw $exception;
            }

            return 'finished';
        });
        $cancel = new DeferredCancellation();
        $call = async(fn(): mixed => $pair->client->call('wait', cancellation: $cancel->getCancellation()));
        delay(0.05);

        $cancel->cancel();
        $error = $this->thrownBy(fn(): mixed => $call->await());
        $this->settle(0.05);

        self::assertInstanceOf(RequestCancelledException::class, $error);
        self::assertSame(['started', 'cancelled'], $handler->entries());
        self::assertSame([], $this->loopErrors);
        self::assertFalse($pair->clientLogs->hasWarningRecords(), 'the late E_CANCELLED answer is expected and must not be logged');
    }

    public function testATimeoutFailsTheCallWithETimeoutAndStopsTheHandler(): void
    {
        $pair = $this->open();
        $handler = new Recorder();
        $pair->serverHandlers->register('wait', static function (RpcRequest $request, ?Cancellation $cancellation) use ($handler): string {
            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $handler->add('cancelled');

                throw $exception;
            }

            return 'finished';
        });
        $started = hrtime(true);

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('wait', timeout: 0.1));
        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->settle(0.05);

        self::assertInstanceOf(RequestTimeoutException::class, $error);
        self::assertSame('E_TIMEOUT', $error->wireCode());
        self::assertGreaterThanOrEqual(0.09, $elapsed);
        self::assertLessThan(1.0, $elapsed);
        self::assertSame(['cancelled'], $handler->entries());
    }

    public function testACallThatFinishesBeforeItsTimeoutIsNotAffectedByIt(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('quick', static fn(): string => 'in time');

        self::assertSame('in time', $pair->client->call('quick', timeout: 5.0));
        $this->settle(0.05);
        self::assertSame([], $this->loopErrors);
    }

    public function testACancellationThatIsAlreadyRequestedFailsTheCallWithoutSendingAnything(): void
    {
        $pair = $this->open();
        $called = new Recorder();
        $pair->serverHandlers->register('x', static function () use ($called): string {
            $called->add();

            return 'x';
        });
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('x', cancellation: $cancel->getCancellation()));
        $this->settle();

        self::assertInstanceOf(RequestCancelledException::class, $error);
        self::assertSame(0, $called->count());
    }

    // ------------------------------------------------------------ errors

    public function testAnUnknownMethodIsAnsweredWithMethodNotFound(): void
    {
        $pair = $this->open();

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('no.such.method'));

        self::assertInstanceOf(MethodNotFoundException::class, $error);
        self::assertSame(['method' => 'no.such.method'], $error->data);
    }

    public function testAnRpcExceptionFromAHandlerKeepsItsCodeAndData(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('strict', static function (): never {
            throw new InvalidParamsException('"path" is required', ['field' => 'path']);
        });

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('strict'));

        self::assertInstanceOf(InvalidParamsException::class, $error);
        self::assertSame('"path" is required', $error->getMessage());
        self::assertSame(['field' => 'path'], $error->data);
    }

    public function testAnApplicationErrorCrossesTheWireWithItsOwnCode(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('save', static function (): never {
            throw new ApplicationException('E_APP_DISK_FULL', 'no space left', ['free' => 0]);
        });

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('save'));

        self::assertInstanceOf(ApplicationException::class, $error);
        self::assertSame('E_APP_DISK_FULL', $error->wireCode());
        self::assertSame(['free' => 0], $error->data);
    }

    public function testAnyOtherExceptionBecomesAnInternalErrorThatLeaksNothingAndIsLoggedOnTheServer(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('boom', static function (): never {
            throw new RuntimeException('secret detail: password=hunter2');
        });

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('boom'));

        self::assertInstanceOf(InternalErrorException::class, $error);
        self::assertSame('internal error', $error->getMessage());
        self::assertStringNotContainsString('hunter2', (string) json_encode($error->toWire()));
        self::assertTrue($pair->serverLogs->hasErrorThatContains('an rpc handler failed'));
        self::assertSame([], $this->loopErrors);
    }

    public function testAnErrorThatIsNotAnExceptionIsAlsoAnInternalError(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('bad', static fn(): int => intdiv(1, 0));

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('bad'));

        self::assertInstanceOf(InternalErrorException::class, $error);
        self::assertSame([], $this->loopErrors);
    }

    public function testAResultTooLargeToSendBecomesAPayloadTooLargeError(): void
    {
        $pair = PeerPair::open(serverCodec: new JsonLineCodec(maxLineBytes: 200));
        $this->pair = $pair;
        $pair->serverHandlers->register('big', static fn(): string => str_repeat('x', 1000));

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('big'));

        self::assertInstanceOf(PayloadTooLargeException::class, $error);
    }

    public function testAResultThatCannotBeEncodedBecomesAnInternalErrorNotAHang(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('nan', static fn(): float => NAN);

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('nan', timeout: 5.0));

        self::assertInstanceOf(InternalErrorException::class, $error);
        self::assertSame('the result could not be encoded', $error->getMessage());
    }

    public function testRequestParamsTooLargeToSendFailLocallyAndTheNextCallStillWorks(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('echo', self::echoParams(...));
        $clientCodecLimit = JsonLineCodec::DEFAULT_MAX_LINE_BYTES;

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('echo', ['x' => str_repeat('y', $clientCodecLimit + 10)]));

        self::assertInstanceOf(PayloadTooLargeException::class, $error);
        self::assertSame(['ok' => 1], $pair->client->call('echo', ['ok' => 1]));
    }

    public function testParamsThatCannotBeEncodedFailTheCallLocally(): void
    {
        $pair = $this->open();

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('echo', ['x' => NAN]));

        self::assertInstanceOf(RpcException::class, $error);
        self::assertSame('E_PROTOCOL', $error->wireCode());
    }

    public function testAListAsParamsIsRefusedBeforeAnythingIsSent(): void
    {
        $pair = $this->open();

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('echo', [1, 2, 3]));

        self::assertInstanceOf(InvalidArgumentException::class, $error);
    }

    // ------------------------------------------------------------ closing

    public function testWhenThePeerVanishesMidCallTheCallFailsWithWorkerCrashed(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('hang', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(10, true, $cancellation);

            return 'x';
        });
        $call = async(fn(): mixed => $pair->client->call('hang', timeout: 5.0));
        delay(0.05);

        $pair->server->close();
        $error = $this->thrownBy(fn(): mixed => $call->await());

        self::assertInstanceOf(WorkerCrashedException::class, $error);
        self::assertSame('E_WORKER_CRASHED', $error->wireCode());
        self::assertTrue($pair->client->isClosed());
    }

    public function testClosingHereFailsWaitingCallsWithWorkerUnavailable(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('hang', static function (RpcRequest $request, ?Cancellation $cancellation): string {
            delay(10, true, $cancellation);

            return 'x';
        });
        $call = async(fn(): mixed => $pair->client->call('hang', timeout: 5.0));
        delay(0.05);

        $pair->client->close();
        $error = $this->thrownBy(fn(): mixed => $call->await());

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
    }

    public function testACallOnAClosedPeerFailsAtOnceWithWorkerUnavailable(): void
    {
        $pair = $this->open();
        $pair->client->close();

        self::assertInstanceOf(WorkerUnavailableException::class, $this->thrownBy(fn(): mixed => $pair->client->call('x')));
        self::assertInstanceOf(WorkerUnavailableException::class, $this->thrownBy(fn() => $pair->client->notify('x')));
    }

    public function testRequestsBeingHandledAreCancelledWhenTheConnectionCloses(): void
    {
        $pair = $this->open();
        $handler = new Recorder();
        $pair->serverHandlers->register('hang', static function (RpcRequest $request, ?Cancellation $cancellation) use ($handler): string {
            try {
                delay(10, true, $cancellation);
            } catch (CancelledException $exception) {
                $handler->add('cancelled');

                throw $exception;
            }

            return 'x';
        });
        $call = async(fn(): mixed => $pair->client->call('hang', timeout: 5.0));
        delay(0.05);

        $pair->client->close();
        $pair->server->awaitClosed();
        $this->thrownBy(fn(): mixed => $call->await());

        self::assertSame(['cancelled'], $handler->entries());
        self::assertSame([], $this->loopErrors);
    }

    public function testAwaitClosedReturnsOnceTheConnectionIsClosedAndNothingIsStillRunning(): void
    {
        $pair = $this->open();

        $pair->client->close();
        $pair->client->awaitClosed();
        $pair->server->awaitClosed();

        self::assertTrue($pair->client->isClosed());
        self::assertTrue($pair->server->isClosed());
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $pair = $this->open();

        $pair->client->close();
        $pair->client->close();

        self::assertTrue($pair->client->isClosed());
    }

    public function testStartingTwiceIsAProgrammingError(): void
    {
        $pair = $this->open();

        $this->expectException(\LogicException::class);

        $pair->client->start();
    }

    public function testNothingIsLoggedAtWarningOrAboveDuringANormalConversation(): void
    {
        $pair = $this->open();
        $pair->serverHandlers->register('echo', self::echoParams(...));
        $pair->client->call('echo', ['a' => 1]);
        $pair->client->close();
        $pair->server->awaitClosed();

        self::assertFalse($pair->clientLogs->hasRecords(Level::Warning));
        self::assertFalse($pair->serverLogs->hasRecords(Level::Error));
        self::assertSame([], $this->loopErrors);
    }
}
