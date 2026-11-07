<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Middleware;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredCancellation;

use function Amp\delay;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Raphp\Error\ApplicationException;
use Raphp\Error\ErrorCode;
use Raphp\Error\InternalErrorException;
use Raphp\Error\InvalidParamsException;
use Raphp\Error\MethodNotFoundException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\RpcException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Rpc\Internal\ClosureHandler;
use Raphp\Rpc\MethodCatalog;
use Raphp\Rpc\Middleware\RetryMiddleware;
use Raphp\Rpc\MiddlewareStack;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;
use Raphp\Tests\Rpc\Fixture\FileService;
use Raphp\Tests\Rpc\Fixture\OtherService;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use Raphp\Tests\Support\Recorder;
use Throwable;

final class RetryMiddlewareTest extends AsyncTestCase
{
    private function catalog(): MethodCatalog
    {
        return MethodCatalog::fromClasses([FileService::class, OtherService::class]);
    }

    /**
     * A handler that answers with the given responses in turn (the last one repeats) and counts
     * how often it was asked.
     *
     * @param list<RpcResponse> $responses
     */
    private function scripted(array $responses, Recorder $attempts): RpcHandler
    {
        $next = 0;

        return new ClosureHandler(static function () use ($responses, $attempts, &$next): RpcResponse {
            $attempts->add();
            $response = $responses[min($next, count($responses) - 1)];
            ++$next;

            return $response;
        });
    }

    private function through(RetryMiddleware $retry, string $method, RpcHandler $final, ?Cancellation $cancellation = null): RpcResponse
    {
        return (new MiddlewareStack([$retry]))->wrap($final)->handle(new RpcRequest($method), $cancellation);
    }

    private function transient(): RpcResponse
    {
        return RpcResponse::failure(new WorkerCrashedException('gone'));
    }

    // ------------------------------------------------------------ what is retried

    public function testAnIdempotentMethodIsRetriedAfterATransientFailureAndTheSuccessIsReturned(): void
    {
        $attempts = new Recorder();
        $handler = $this->scripted([$this->transient(), $this->transient(), RpcResponse::success('finally')], $attempts);

        $response = $this->through(new RetryMiddleware($this->catalog(), 3, 0.001), 'files.scan', $handler);

        self::assertSame('finally', $response->result);
        self::assertSame(3, $attempts->count());
    }

    public function testAMethodNotMarkedIdempotentIsTriedExactlyOnce(): void
    {
        $attempts = new Recorder();

        $response = $this->through(new RetryMiddleware($this->catalog(), 5, 0.001), 'files.delete', $this->scripted([$this->transient()], $attempts));

        self::assertTrue($response->isFailure());
        self::assertSame(1, $attempts->count(), 'only #[Idempotent] methods may be repeated');
    }

    public function testAMethodTheCatalogDoesNotKnowIsTriedOnce(): void
    {
        $attempts = new Recorder();

        $this->through(new RetryMiddleware($this->catalog(), 5, 0.001), 'no.such.method', $this->scripted([$this->transient()], $attempts));

        self::assertSame(1, $attempts->count());
    }

    public function testEveryMethodTheCatalogMarksIdempotentIsRetriedAndNoOtherIs(): void
    {
        $catalog = $this->catalog();

        foreach ($catalog->all() as $descriptor) {
            $attempts = new Recorder();

            $this->through(new RetryMiddleware($catalog, 2, 0.001), $descriptor->name, $this->scripted([$this->transient()], $attempts));

            self::assertSame($descriptor->idempotent ? 2 : 1, $attempts->count(), $descriptor->name);
        }
    }

    public function testItGivesUpAfterTheMaximumNumberOfAttemptsAndReturnsTheLastFailure(): void
    {
        $attempts = new Recorder();
        $last = new WorkerUnavailableException('still down');
        $handler = $this->scripted([$this->transient(), $this->transient(), RpcResponse::failure($last)], $attempts);

        $response = $this->through(new RetryMiddleware($this->catalog(), 3, 0.001), 'files.scan', $handler);

        self::assertSame($last, $response->error);
        self::assertSame(3, $attempts->count());
    }

    public function testASuccessOnTheFirstAttemptIsNotRepeated(): void
    {
        $attempts = new Recorder();

        $response = $this->through(new RetryMiddleware($this->catalog(), 3, 0.001), 'files.scan', $this->scripted([RpcResponse::success('ok')], $attempts));

        self::assertSame('ok', $response->result);
        self::assertSame(1, $attempts->count());
    }

    public function testOneAttemptMeansNoRetryEvenForAnIdempotentMethod(): void
    {
        $attempts = new Recorder();

        $this->through(new RetryMiddleware($this->catalog(), 1, 0.001), 'files.scan', $this->scripted([$this->transient()], $attempts));

        self::assertSame(1, $attempts->count());
    }

    /**
     * @return iterable<string, array{Closure(): RpcException, bool}>
     */
    public static function failures(): iterable
    {
        yield 'the worker crashed' => [static fn(): RpcException => new WorkerCrashedException('x'), true];
        yield 'the worker is unavailable' => [static fn(): RpcException => new WorkerUnavailableException('x'), true];
        yield 'the call timed out' => [static fn(): RpcException => new RequestTimeoutException('x'), true];
        yield 'the params were wrong' => [static fn(): RpcException => new InvalidParamsException('x'), false];
        yield 'the method does not exist' => [static fn(): RpcException => new MethodNotFoundException('x'), false];
        yield 'the handler failed internally' => [static fn(): RpcException => new InternalErrorException('x'), false];
        yield 'the call was cancelled' => [static fn(): RpcException => new RequestCancelledException('x'), false];
        yield 'the payload was too large' => [static fn(): RpcException => new PayloadTooLargeException('x'), false];
        yield 'the protocol was broken' => [static fn(): RpcException => new ProtocolException('x'), false];
        yield 'the application said no' => [static fn(): RpcException => new ApplicationException('E_APP_DISK_FULL', 'x'), false];
    }

    /**
     * @param Closure(): RpcException $failure
     */
    #[DataProvider('failures')]
    public function testOnlyFailuresThatMayGoAwayByThemselvesAreRetried(Closure $failure, bool $retried): void
    {
        $attempts = new Recorder();

        $this->through(new RetryMiddleware($this->catalog(), 3, 0.001), 'files.scan', $this->scripted([RpcResponse::failure($failure())], $attempts));

        self::assertSame($retried ? 3 : 1, $attempts->count());
    }

    public function testTheFailuresThatAreRetriedCanBeChosen(): void
    {
        $attempts = new Recorder();
        $retry = new RetryMiddleware($this->catalog(), 3, 0.001, [ErrorCode::Internal]);

        $this->through($retry, 'files.scan', $this->scripted([RpcResponse::failure(new InternalErrorException('x'))], $attempts));
        $crashAttempts = new Recorder();
        $this->through($retry, 'files.scan', $this->scripted([$this->transient()], $crashAttempts));

        self::assertSame(3, $attempts->count());
        self::assertSame(1, $crashAttempts->count(), 'a failure that was not chosen is not retried');
    }

    // ------------------------------------------------------------ waiting between attempts

    public function testTheWaitDoublesBetweenAttempts(): void
    {
        $times = [];
        $handler = new ClosureHandler(function () use (&$times): RpcResponse {
            $times[] = hrtime(true) / 1e9;

            return $this->transient();
        });

        $this->through(new RetryMiddleware($this->catalog(), 4, 0.04), 'files.scan', $handler);

        self::assertCount(4, $times);
        $gaps = [$times[1] - $times[0], $times[2] - $times[1], $times[3] - $times[2]];
        self::assertGreaterThanOrEqual(0.035, $gaps[0]);
        self::assertGreaterThanOrEqual(0.075, $gaps[1], 'the second wait is about twice the first');
        self::assertGreaterThanOrEqual(0.155, $gaps[2], 'and the third about four times');
        self::assertGreaterThan($gaps[0], $gaps[2]);
    }

    public function testCancellingWhileWaitingToTryAgainStopsAtOnceWithACancelledFailure(): void
    {
        $attempts = new Recorder();
        $cancel = new DeferredCancellation();
        $retry = new RetryMiddleware($this->catalog(), 5, 10.0);
        $call = async(fn(): RpcResponse => $this->through($retry, 'files.scan', $this->scripted([$this->transient()], $attempts), $cancel->getCancellation()));
        delay(0.05);

        $cancel->cancel();
        $response = $call->await();

        self::assertInstanceOf(RpcResponse::class, $response);
        self::assertInstanceOf(RequestCancelledException::class, $response->error);
        self::assertSame(1, $attempts->count(), 'no further attempt after the cancel');
    }

    public function testTheRequestAndTheCancellationReachTheHandlerOnEveryAttempt(): void
    {
        $seen = [];
        $cancel = new DeferredCancellation();
        $handler = new ClosureHandler(function (RpcRequest $request, ?Cancellation $cancellation) use (&$seen, $cancel): RpcResponse {
            $seen[] = [$request->method, $cancellation === $cancel->getCancellation()];

            return $this->transient();
        });

        $this->through(new RetryMiddleware($this->catalog(), 2, 0.001), 'files.scan', $handler, $cancel->getCancellation());

        self::assertSame([['files.scan', true], ['files.scan', true]], $seen);
    }

    // ------------------------------------------------------------ construction

    public function testNoAttemptsOrANegativeDelayIsRefused(): void
    {
        foreach ([[0, 0.1], [-1, 0.1], [3, -0.1]] as [$attempts, $delay]) {
            try {
                new RetryMiddleware($this->catalog(), $attempts, $delay);
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------ through a live peer

    public function testOverARealConnectionOnlyTheIdempotentMethodIsRepeated(): void
    {
        $pair = PeerPair::open(clientOutbound: new MiddlewareStack([new RetryMiddleware($this->catalog(), 3, 0.001)]));
        $calls = new Recorder();
        $failTwice = static function (string $name) use ($calls): Closure {
            $failures = 0;

            return static function () use ($name, $calls, &$failures): string {
                $calls->add($name);
                if (++$failures <= 2) {
                    throw new WorkerUnavailableException('warming up');
                }

                return 'done';
            };
        };
        $pair->serverHandlers->register('files.scan', $failTwice('scan'));
        $pair->serverHandlers->register('files.delete', $failTwice('delete'));

        try {
            self::assertSame('done', $pair->client->call('files.scan', timeout: 5.0), 'the idempotent method recovers');
            $error = $this->thrownBy(fn(): mixed => $pair->client->call('files.delete', timeout: 5.0));

            self::assertInstanceOf(WorkerUnavailableException::class, $error);
            self::assertSame(['scan', 'scan', 'scan', 'delete'], $calls->entries(), 'the method that is not idempotent was tried once');
        } finally {
            $pair->close();
        }
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
}
