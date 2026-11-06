<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The parameters of a call are not valid for the method (`E_INVALID_PARAMS`).
 */
final class InvalidParamsException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::InvalidParams->value;
    }
}
