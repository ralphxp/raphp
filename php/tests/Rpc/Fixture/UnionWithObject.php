<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class UnionWithObject
{
    #[RpcMethod('bad.union')]
    public function take(int|\stdClass $value): string
    {
        return 'x';
    }
}
