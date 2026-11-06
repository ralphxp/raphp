<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\InternalErrorException;
use Raphp\Rpc\NullProgressReporter;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;

final class RpcValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{array{int, int, int, int}}>
     */
    public static function badLimits(): iterable
    {
        yield 'no calls in flight' => [[0, 1, 1, 1]];
        yield 'no waiting calls' => [[1, 0, 1, 1]];
        yield 'no inbound requests' => [[1, 1, 0, 1]];
        yield 'no outbound buffer' => [[1, 1, 1, 0]];
        yield 'a negative limit' => [[1, 1, 1, -5]];
    }

    public function testARequestKeepsItsFieldsAndTheWithersReturnChangedCopies(): void
    {
        $request = new RpcRequest('files.scan', ['path' => '/x'], 'trace-1', 2.5);

        $changed = $request->withParams(['path' => '/y'])->withTrace('trace-2')->withTimeout(null);

        self::assertSame(['path' => '/x'], $request->params);
        self::assertSame('trace-1', $request->trace);
        self::assertSame(2.5, $request->timeout);
        self::assertSame('files.scan', $changed->method);
        self::assertSame(['path' => '/y'], $changed->params);
        self::assertSame('trace-2', $changed->trace);
        self::assertNull($changed->timeout);
        self::assertNotSame($request, $changed);
    }

    public function testTheWithersKeepTheProgressFieldsOfTheRequest(): void
    {
        $reporter = new NullProgressReporter();
        $onProgress = static function (): void {};
        $request = new RpcRequest('m', [], null, null, $onProgress, $reporter);

        $changed = $request->withParams(['a' => 1])->withTrace('t')->withTimeout(1.0);

        self::assertSame($onProgress, $changed->onProgress);
        self::assertSame($reporter, $changed->progress);
    }

    public function testASuccessCarriesItsResultAndAFailureItsError(): void
    {
        $success = RpcResponse::success(['a' => 1]);
        $error = new InternalErrorException('x');
        $failure = RpcResponse::failure($error);

        self::assertFalse($success->isFailure());
        self::assertSame(['a' => 1], $success->result);
        self::assertNull($success->error);
        self::assertTrue($failure->isFailure());
        self::assertSame($error, $failure->error);
        self::assertNull($failure->result);
        self::assertNull(RpcResponse::success()->result);
    }

    public function testTheNullReporterAcceptsReportsAndDoesNothing(): void
    {
        (new NullProgressReporter())->report(['done' => 1]);

        self::addToAssertionCount(1);
    }

    public function testTheDefaultLimitsAreTheDocumentedOnes(): void
    {
        $options = new RpcPeerOptions();

        self::assertSame(64, $options->maxInFlight);
        self::assertSame(1024, $options->maxQueuedCalls);
        self::assertSame(256, $options->maxInbound);
        self::assertSame(256, $options->outboundBuffer);
    }

    /**
     * @param array{int, int, int, int} $limits
     */
    #[DataProvider('badLimits')]
    public function testALimitBelowOneIsRefused(array $limits): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RpcPeerOptions(...$limits);
    }
}
