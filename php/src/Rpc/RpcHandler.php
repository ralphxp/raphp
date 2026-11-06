<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Amp\Cancellation;

/**
 * Something that answers a call: the end of a middleware chain, or the next link in it.
 *
 * It may wait for I/O; because it runs in a Fiber it reads as ordinary sequential code.
 */
interface RpcHandler
{
    public function handle(RpcRequest $request, ?Cancellation $cancellation = null): RpcResponse;
}
