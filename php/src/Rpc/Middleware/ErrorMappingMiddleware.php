<?php

declare(strict_types=1);

namespace Raphp\Rpc\Middleware;

use Amp\Cancellation;
use InvalidArgumentException;
use Raphp\Error\ErrorCode;
use Raphp\Error\RpcException;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcMiddleware;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;
use Throwable;

/**
 * Turns chosen exception classes into error responses with a framework code (FRAMEWORK 6.6), for
 * example `InvalidArgumentException` into `E_INVALID_PARAMS`. The first class in the map that the
 * exception is an instance of wins; the exception's message becomes the error's message. An
 * exception that matches nothing passes through unchanged. Put it in the inbound stack.
 */
final readonly class ErrorMappingMiddleware implements RpcMiddleware
{
    /**
     * @param array<class-string<Throwable>, ErrorCode> $map
     */
    public function __construct(private array $map) {}

    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
    {
        try {
            return $next->handle($request, $cancellation);
        } catch (Throwable $exception) {
            foreach ($this->map as $class => $code) {
                if ($exception instanceof $class) {
                    return RpcResponse::failure(RpcException::fromWire(['code' => $code->value, 'message' => $exception->getMessage()]));
                }
            }

            throw $exception;
        }
    }
}
