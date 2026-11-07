<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

/**
 * Inherits the RPC methods of {@see FileService} and adds one: the inherited ones belong to the
 * parent and are not declared a second time.
 */
final class ChildService extends FileService
{
    #[RpcMethod('child.extra')]
    public function extra(): string
    {
        return 'extra';
    }
}
