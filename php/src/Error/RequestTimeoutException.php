<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The call did not finish in time (`E_TIMEOUT`).
 */
final class RequestTimeoutException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::Timeout->value;
    }
}
