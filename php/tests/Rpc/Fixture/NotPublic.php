<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\RpcMethod;

final class NotPublic
{
    #[RpcMethod('hidden')]
    private function hidden(): string
    {
        return 'x';
    }

    public function visible(): string
    {
        return $this->hidden();
    }
}
