<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * No worker can take the call now: not ready, or its queue is full (`E_WORKER_UNAVAILABLE`).
 */
final class WorkerUnavailableException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::WorkerUnavailable->value;
    }
}
