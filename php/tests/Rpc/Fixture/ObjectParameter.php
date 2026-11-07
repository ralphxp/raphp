<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class ObjectParameter
{
    #[RpcMethod('bad.object')]
    public function take(\DateTimeImmutable $at): string
    {
        return 'x';
    }
}
