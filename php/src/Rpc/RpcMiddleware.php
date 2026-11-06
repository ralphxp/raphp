<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Amp\Cancellation;

/**
 * One layer around a call, shaped like PSR-15 (FRAMEWORK 4.8). It may change the request, pass it
 * on with `$next->handle()`, change the response, or answer without calling `$next` at all (a
 * short circuit). Layers run in registration order, the first outermost.
 */
interface RpcMiddleware
{
    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse;
}
