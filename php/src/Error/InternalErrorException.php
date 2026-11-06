<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * An unexpected failure inside the framework or a handler (`E_INTERNAL`).
 */
final class InternalErrorException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::Internal->value;
    }
}
