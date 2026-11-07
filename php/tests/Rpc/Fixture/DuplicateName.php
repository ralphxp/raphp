<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class DuplicateName
{
    #[RpcMethod('files.scan')]
    public function again(): string
    {
        return 'again';
    }
}
