<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\DeferredFuture;
use Closure;
use Raphp\Rpc\RpcResponse;

/**
 * A call that has been sent and is waiting for its answer.
 *
 * @internal
 */
final readonly class PendingCall
{
    /** @var DeferredFuture<RpcResponse> */
    public DeferredFuture $response;

    /**
     * @param (Closure(array<array-key, mixed>, string): void)|null $onProgress
     */
    public function __construct(public ?Closure $onProgress)
    {
        $this->response = new DeferredFuture();
    }
}
