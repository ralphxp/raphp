<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Middleware;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Raphp\Error\ErrorCode;
use Raphp\Error\InternalErrorException;
use Raphp\Error\InvalidParamsException;
use Raphp\Rpc\Internal\ClosureHandler;
use Raphp\Rpc\Middleware\ErrorMappingMiddleware;
use Raphp\Rpc\Middleware\TimingMiddleware;
use Raphp\Rpc\Middleware\TraceIdMiddleware;
use Raphp\Rpc\MiddlewareStack;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcMiddleware;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;
use Raphp\Tests\Support\Recorder;
use RuntimeException;
use TypeError;

final class MiddlewareTest extends TestCase
{
    private function recording(string $name, Recorder $log): RpcMiddleware
    {
        return new readonly class ($name, $log) implements RpcMiddleware {
            public function __construct(private string $name, private Recorder $log) {}

            public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
            {
                $this->log->add("{$this->name} in");
                $response = $next->handle($request, $cancellation);
                $this->log->add("{$this->name} out");

                return $response;
            }
        };
    }

    private function terminal(Recorder $log, mixed $result = 'result'): RpcHandler
    {
        return new ClosureHandler(static function () use ($log, $result): RpcResponse {
            $log->add('handler');

            return RpcResponse::success($result);
        });
    }

    // ------------------------------------------------------------ the stack

    public function testMiddlewareRunsInRegistrationOrderAsAnOnion(): void
    {
        $log = new Recorder();
        $stack = new MiddlewareStack([$this->recording('a', $log), $this->recording('b', $log), $this->recording('c', $log)]);

        $stack->wrap($this->terminal($log))->handle(new RpcRequest('m'));

        self::assertSame(['a in', 'b in', 'c in', 'handler', 'c out', 'b out', 'a out'], $log->entries());
    }

    public function testAnEmptyStackIsJustTheFinalHandler(): void
    {
        $log = new Recorder();

        $response = (new MiddlewareStack())->wrap($this->terminal($log))->handle(new RpcRequest('m'));

        self::assertSame('result', $response->result);
        self::assertSame(['handler'], $log->entries());
    }

    public function testAMiddlewareCanAnswerWithoutCallingTheNextOne(): void
    {
        $log = new Recorder();
        $shortCircuit = new class implements RpcMiddleware {
            public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
            {
                return RpcResponse::success('from the middleware');
            }
        };
        $stack = new MiddlewareStack([$this->recording('outer', $log), $shortCircuit, $this->recording('inner', $log)]);

        $response = $stack->wrap($this->terminal($log))->handle(new RpcRequest('m'));

        self::assertSame('from the middleware', $response->result);
        self::assertSame(['outer in', 'outer out'], $log->entries(), 'the inner middleware and the handler must not run');
    }

    public function testAMiddlewareCanChangeTheRequestAndTheResponse(): void
    {
        $changer = new class implements RpcMiddleware {
            public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
            {
                $response = $next->handle($request->withParams(['changed' => true]), $cancellation);

                return RpcResponse::success(['wrapped' => $response->result]);
            }
        };
        $seen = new Recorder();
        $final = new ClosureHandler(static function (RpcRequest $request) use ($seen): RpcResponse {
            $seen->add((string) json_encode($request->params));

            return RpcResponse::success('inner');
        });

        $response = (new MiddlewareStack([$changer]))->wrap($final)->handle(new RpcRequest('m', ['changed' => false]));

        self::assertSame(['wrapped' => 'inner'], $response->result);
        self::assertSame(['{"changed":true}'], $seen->entries());
    }

    public function testTheCancellationReachesTheFinalHandler(): void
    {
        $cancellation = new DeferredCancellation();
        $received = new Recorder();
        $final = new ClosureHandler(static function (RpcRequest $request, ?Cancellation $given) use ($received, $cancellation): RpcResponse {
            $received->add($given === $cancellation->getCancellation() ? 'same' : 'different');

            return RpcResponse::success();
        });

        (new MiddlewareStack([$this->recording('a', new Recorder())]))->wrap($final)->handle(new RpcRequest('m'), $cancellation->getCancellation());

        self::assertSame(['same'], $received->entries());
    }

    public function testAnExceptionFromTheHandlerPassesThroughEveryLayer(): void
    {
        $log = new Recorder();
        $final = new ClosureHandler(static function (): never {
            throw new RuntimeException('deep');
        });

        try {
            (new MiddlewareStack([$this->recording('a', $log)]))->wrap($final)->handle(new RpcRequest('m'));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame('deep', $exception->getMessage());
        }

        self::assertSame(['a in'], $log->entries(), 'the layer is left without finishing, like any exception passing through');
    }

    // ------------------------------------------------------------ trace id

    public function testTheTraceMiddlewareGivesATraceIdToARequestWithoutOne(): void
    {
        $seen = new Recorder();
        $final = new ClosureHandler(static function (RpcRequest $request) use ($seen): RpcResponse {
            $seen->add((string) $request->trace);

            return RpcResponse::success();
        });
        $handler = (new MiddlewareStack([new TraceIdMiddleware()]))->wrap($final);

        $handler->handle(new RpcRequest('m'));
        $handler->handle(new RpcRequest('m'));

        $entries = $seen->entries();
        self::assertSame(1, preg_match('/\A[0-9a-f]{16}\z/', $entries[0]));
        self::assertNotSame($entries[0], $entries[1], 'every call gets its own id');
    }

    public function testTheTraceMiddlewareKeepsATraceIdThatIsAlreadyThere(): void
    {
        $seen = new Recorder();
        $final = new ClosureHandler(static function (RpcRequest $request) use ($seen): RpcResponse {
            $seen->add((string) $request->trace);

            return RpcResponse::success();
        });

        (new MiddlewareStack([new TraceIdMiddleware()]))->wrap($final)->handle(new RpcRequest('m', [], 'mine-1'));

        self::assertSame(['mine-1'], $seen->entries());
    }

    // ------------------------------------------------------------ timing

    public function testTheTimingMiddlewareLogsTheMethodTheTraceTheDurationAndAnOkOutcome(): void
    {
        $logs = new TestHandler();
        $final = new ClosureHandler(static function (): RpcResponse {
            usleep(20_000);

            return RpcResponse::success();
        });

        (new MiddlewareStack([new TimingMiddleware(new Logger('t', [$logs]))]))->wrap($final)->handle(new RpcRequest('files.scan', [], 'tr-1'));

        self::assertTrue($logs->hasInfoThatContains('rpc handled'));
        $context = $logs->getRecords()[0]->context;
        self::assertSame('files.scan', $context['method']);
        self::assertSame('tr-1', $context['trace']);
        self::assertSame('ok', $context['outcome']);
        self::assertIsFloat($context['ms']);
        self::assertGreaterThanOrEqual(15.0, $context['ms']);
        self::assertLessThan(500.0, $context['ms']);
    }

    public function testTheTimingMiddlewareLogsAnErrorCodeAsTheOutcomeOfAFailure(): void
    {
        $logs = new TestHandler();
        $final = new ClosureHandler(static fn(): RpcResponse => RpcResponse::failure(new InvalidParamsException('x')));

        (new MiddlewareStack([new TimingMiddleware(new Logger('t', [$logs]))]))->wrap($final)->handle(new RpcRequest('m'));

        self::assertSame('E_INVALID_PARAMS', $logs->getRecords()[0]->context['outcome']);
    }

    public function testTheTimingMiddlewareLogsAnExceptionAndLetsItPass(): void
    {
        $logs = new TestHandler();
        $final = new ClosureHandler(static function (): never {
            throw new RuntimeException('x');
        });

        try {
            (new MiddlewareStack([new TimingMiddleware(new Logger('t', [$logs]))]))->wrap($final)->handle(new RpcRequest('m'));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('exception', $logs->getRecords()[0]->context['outcome']);
    }

    // ------------------------------------------------------------ error mapping

    public function testAnExceptionClassInTheMapBecomesTheMappedErrorWithItsMessage(): void
    {
        $middleware = new ErrorMappingMiddleware([InvalidArgumentException::class => ErrorCode::InvalidParams]);
        $final = new ClosureHandler(static function (): never {
            throw new InvalidArgumentException('"path" is required');
        });

        $response = (new MiddlewareStack([$middleware]))->wrap($final)->handle(new RpcRequest('m'));

        self::assertTrue($response->isFailure());
        self::assertInstanceOf(InvalidParamsException::class, $response->error);
        self::assertSame('"path" is required', $response->error->getMessage());
    }

    public function testASubclassOfAMappedClassIsMappedToo(): void
    {
        $middleware = new ErrorMappingMiddleware([RuntimeException::class => ErrorCode::Internal]);
        $final = new ClosureHandler(static function (): never {
            throw new class ('child') extends RuntimeException {};
        });

        $response = (new MiddlewareStack([$middleware]))->wrap($final)->handle(new RpcRequest('m'));

        self::assertInstanceOf(InternalErrorException::class, $response->error);
    }

    public function testTheFirstMatchingClassInTheMapWins(): void
    {
        $middleware = new ErrorMappingMiddleware([
            InvalidArgumentException::class => ErrorCode::InvalidParams,
            \LogicException::class => ErrorCode::Internal,
        ]);
        $final = new ClosureHandler(static function (): never {
            throw new InvalidArgumentException('x');
        });

        $response = (new MiddlewareStack([$middleware]))->wrap($final)->handle(new RpcRequest('m'));

        self::assertInstanceOf(InvalidParamsException::class, $response->error);
    }

    public function testAnExceptionThatMatchesNothingPassesThroughUnchanged(): void
    {
        $middleware = new ErrorMappingMiddleware([InvalidArgumentException::class => ErrorCode::InvalidParams]);
        $final = new ClosureHandler(static function (): never {
            throw new TypeError('not mapped');
        });

        $this->expectException(TypeError::class);

        (new MiddlewareStack([$middleware]))->wrap($final)->handle(new RpcRequest('m'));
    }

    public function testASuccessfulCallIsLeftAlone(): void
    {
        $middleware = new ErrorMappingMiddleware([InvalidArgumentException::class => ErrorCode::InvalidParams]);

        $response = (new MiddlewareStack([$middleware]))->wrap(new ClosureHandler(static fn(): RpcResponse => RpcResponse::success(7)))->handle(new RpcRequest('m'));

        self::assertSame(7, $response->result);
    }
}
