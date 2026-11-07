<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class IntersectionParameter
{
    /**
     * @param \Countable&\Traversable<int, mixed> $items
     */
    #[RpcMethod('bad.intersection')]
    public function take(\Countable&\Traversable $items): string
    {
        return 'x';
    }
}
