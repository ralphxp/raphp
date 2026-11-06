<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Amp\Cancellation;

/**
 * An ordered list of {@see RpcMiddleware}. {@see self::wrap()} builds the onion around a final
 * handler: the first middleware in the list runs first and sees the response last.
 */
final readonly class MiddlewareStack
{
    /**
     * @param list<RpcMiddleware> $middleware
     */
    public function __construct(private array $middleware = []) {}

    public function wrap(RpcHandler $final): RpcHandler
    {
        $handler = $final;
        foreach (array_reverse($this->middleware) as $middleware) {
            $handler = new class ($middleware, $handler) implements RpcHandler {
                public function __construct(private readonly RpcMiddleware $middleware, private readonly RpcHandler $next) {}

                public function handle(RpcRequest $request, ?Cancellation $cancellation = null): RpcResponse
                {
                    return $this->middleware->process($request, $this->next, $cancellation);
                }
            };
        }

        return $handler;
    }
}
