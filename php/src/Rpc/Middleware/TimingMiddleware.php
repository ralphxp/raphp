<?php

declare(strict_types=1);

namespace Raphp\Rpc\Middleware;

use Amp\Cancellation;
use Psr\Log\LoggerInterface;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcMiddleware;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;
use Throwable;

/**
 * Logs how long each call took and how it ended (`ok`, an error code, or `exception`). It uses
 * the monotonic clock, because wall-clock time can step (FRAMEWORK 4.8).
 */
final readonly class TimingMiddleware implements RpcMiddleware
{
    public function __construct(private LoggerInterface $logger) {}

    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
    {
        $start = hrtime(true);
        $outcome = 'exception';

        try {
            $response = $next->handle($request, $cancellation);
            $outcome = $response->error?->wireCode() ?? 'ok';

            return $response;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $this->logger->info('rpc handled', [
                'method' => $request->method,
                'trace' => $request->trace,
                'ms' => round((hrtime(true) - $start) / 1_000_000, 2),
                'outcome' => $outcome,
            ]);
        }
    }
}
