<?php

declare(strict_types=1);

namespace Raphp\Rpc\Middleware;

use Amp\Cancellation;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcMiddleware;
use Raphp\Rpc\RpcRequest;
use Raphp\Rpc\RpcResponse;

/**
 * Gives every outgoing call a trace id when it has none, so log lines on both sides can be
 * matched. Put it in the outbound stack.
 */
final readonly class TraceIdMiddleware implements RpcMiddleware
{
    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
    {
        if ($request->trace === null) {
            $request = $request->withTrace(bin2hex(random_bytes(8)));
        }

        return $next->handle($request, $cancellation);
    }
}
