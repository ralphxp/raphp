<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class OtherService
{
    #[RpcMethod('other.ping')]
    public function ping(): string
    {
        return 'pong';
    }
}
