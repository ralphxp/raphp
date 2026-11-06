<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The worker died while the call was in flight (`E_WORKER_CRASHED`). The call may or may not have run.
 */
final class WorkerCrashedException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::WorkerCrashed->value;
    }
}
