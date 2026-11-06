<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\Cancellation;
use Closure;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;

/**
 * An {@see RpcHandler} that calls a closure.
 *
 * @internal
 */
final readonly class ClosureHandler implements RpcHandler
{
    /**
     * @param Closure(RpcRequest, ?Cancellation): RpcResponse $handler
     */
    public function __construct(private Closure $handler) {}

    public function handle(RpcRequest $request, ?Cancellation $cancellation = null): RpcResponse
    {
        return ($this->handler)($request, $cancellation);
    }
}
