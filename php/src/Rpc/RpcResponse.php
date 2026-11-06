<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Raphp\Error\RpcException;

/**
 * The outcome of a call as middleware sees it: a result, or an error. Build it with
 * {@see self::success()} or {@see self::failure()}. Immutable.
 */
final readonly class RpcResponse
{
    private function __construct(
        public mixed $result,
        public ?RpcException $error,
    ) {}

    public static function success(mixed $result = null): self
    {
        return new self($result, null);
    }

    public static function failure(RpcException $error): self
    {
        return new self(null, $error);
    }

    public function isFailure(): bool
    {
        return $this->error !== null;
    }
}
