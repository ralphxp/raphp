<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class ByReferenceParameter
{
    /**
     * @param array<array-key, mixed> $items
     */
    #[RpcMethod('bad.reference')]
    public function take(array &$items): string
    {
        return 'x';
    }
}
