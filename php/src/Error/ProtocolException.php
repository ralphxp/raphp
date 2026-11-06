<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * A message broke the protocol: malformed, unknown kind, or wrong version (`E_PROTOCOL`).
 */
final class ProtocolException extends RpcException
{
    public function wireCode(): string
    {
        return ErrorCode::Protocol->value;
    }
}
