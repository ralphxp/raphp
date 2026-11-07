<?php

declare(strict_types=1);

namespace Raphp\Rpc\Middleware;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use InvalidArgumentException;
use Raphp\Error\ErrorCode;
use Raphp\Error\RequestCancelledException;
use Raphp\Rpc\MethodCatalog;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcMiddleware;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;

/**
 * Repeats a failed call, but only a call to a method the catalog declares `#[Idempotent]` and only
 * for a failure that may go away by itself (FRAMEWORK 4.8). Anything else is tried once: a method
 * that is not marked could do its work twice, and an error about the call itself (bad params, an
 * unknown method, a rule of the application) would only repeat.
 *
 * By default a failure is retried when it is `E_WORKER_UNAVAILABLE`, `E_WORKER_CRASHED` or
 * `E_TIMEOUT`. Between attempts it waits `$delay` seconds, doubling each time, and stops at once
 * if the caller cancels. Put it in the outbound stack.
 */
final readonly class RetryMiddleware implements RpcMiddleware
{
    /**
     * @param int $maxAttempts how many times a call is tried in all, at least 1
     * @param float $delay seconds before the second attempt, doubled before each later one
     * @param list<ErrorCode> $retryOn the failures worth another attempt
     *
     * @throws InvalidArgumentException when the attempts are fewer than 1 or the delay is negative
     */
    public function __construct(
        private MethodCatalog $catalog,
        private int $maxAttempts = 3,
        private float $delay = 0.1,
        private array $retryOn = [ErrorCode::WorkerUnavailable, ErrorCode::WorkerCrashed, ErrorCode::Timeout],
    ) {
        if ($maxAttempts < 1 || $delay < 0.0) {
            throw new InvalidArgumentException('a call needs at least one attempt and the delay cannot be negative');
        }
    }

    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
    {
        $attempts = $this->catalog->isIdempotent($request->method) ? $this->maxAttempts : 1;
        $wait = $this->delay;

        for ($attempt = 1;; $attempt++) {
            $response = $next->handle($request, $cancellation);

            if ($attempt >= $attempts || $response->error === null || !$this->worthRetrying($response)) {
                return $response;
            }

            try {
                delay($wait, true, $cancellation);
            } catch (CancelledException $exception) {
                return RpcResponse::failure(new RequestCancelledException("the call to {$request->method} was cancelled while waiting to try again", ['method' => $request->method], $exception));
            }

            $wait *= 2;
        }
    }

    private function worthRetrying(RpcResponse $response): bool
    {
        foreach ($this->retryOn as $code) {
            if ($response->error?->wireCode() === $code->value) {
                return true;
            }
        }

        return false;
    }
}
