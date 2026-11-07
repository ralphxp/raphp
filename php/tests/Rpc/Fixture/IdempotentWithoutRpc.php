<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Raphp\Rpc\Attribute\Idempotent;

final class IdempotentWithoutRpc
{
    #[Idempotent]
    public function lonely(): string
    {
        return 'x';
    }
}
