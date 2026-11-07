<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class StaticRpc
{
    #[RpcMethod('static.one')]
    public static function one(): string
    {
        return 'x';
    }
}
