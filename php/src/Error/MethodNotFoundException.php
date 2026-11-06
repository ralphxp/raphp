<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The called method is not registered on the receiving side (`E_METHOD_NOT_FOUND`).
 */
final class MethodNotFoundException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::MethodNotFound->value;
    }
}
