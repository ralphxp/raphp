<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Amp\Cancellation;
use Closure;
use LogicException;
use Raphp\Error\MethodNotFoundException;

/**
 * Maps method names to handlers, and is itself the {@see RpcHandler} at the end of a chain.
 *
 * A handler is a closure that takes the {@see RpcRequest} and the cancellation and returns the
 * result (anything JSON can carry). Whatever it throws is turned into an error response by the
 * middleware or by the connection. An unknown method is answered with `E_METHOD_NOT_FOUND`.
 */
final class HandlerRegistry implements RpcHandler
{
    /** @var array<string, Closure(RpcRequest, ?Cancellation): mixed> */
    private array $handlers = [];

    /**
     * @param Closure(RpcRequest, ?Cancellation): mixed $handler
     *
     * @throws LogicException when the method already has a handler
     */
    public function register(string $method, Closure $handler): void
    {
        if ($method === '') {
            throw new LogicException('a method name must not be empty');
        }

        if (isset($this->handlers[$method])) {
            throw new LogicException("the method \"{$method}\" already has a handler");
        }

        $this->handlers[$method] = $handler;
    }

    public function has(string $method): bool
    {
        return isset($this->handlers[$method]);
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_keys($this->handlers);
    }

    public function handle(RpcRequest $request, ?Cancellation $cancellation = null): RpcResponse
    {
        $handler = $this->handlers[$request->method] ?? null;
        if ($handler === null) {
            return RpcResponse::failure(new MethodNotFoundException('there is no method with that name', ['method' => $request->method]));
        }

        return RpcResponse::success($handler($request, $cancellation));
    }
}
