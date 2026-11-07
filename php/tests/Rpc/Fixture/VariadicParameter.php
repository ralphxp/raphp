<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class VariadicParameter
{
    #[RpcMethod('bad.variadic')]
    public function take(int ...$numbers): string
    {
        return 'x';
    }
}
