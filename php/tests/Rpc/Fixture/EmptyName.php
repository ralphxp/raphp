<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class EmptyName
{
    #[RpcMethod('')]
    public function nameless(): string
    {
        return 'x';
    }
}
