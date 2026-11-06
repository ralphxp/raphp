<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

/**
 * A liveness probe: `{"kind": "ping"}`. The other side answers with {@see Pong} (FRAMEWORK 6.8).
 */
final readonly class Ping implements Envelope
{
    public function kind(): Kind
    {
        return Kind::Ping;
    }

    public function toArray(): array
    {
        return ['kind' => Kind::Ping->value];
    }
}
