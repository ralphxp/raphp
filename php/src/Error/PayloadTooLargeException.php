<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * A message is larger than the configured limit (`E_PAYLOAD_TOO_LARGE`).
 */
final class PayloadTooLargeException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::PayloadTooLarge->value;
    }
}
