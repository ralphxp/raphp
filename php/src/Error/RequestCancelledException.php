<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The call was cancelled and its handler stopped (`E_CANCELLED`). Not Amp's own cancellation exception, which the RPC layer converts.
 */
final class RequestCancelledException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::Cancelled->value;
    }
}
