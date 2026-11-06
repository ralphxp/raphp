<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

/**
 * The answer to a {@see Ping}: `{"kind": "pong"}`.
 */
final readonly class Pong implements Envelope
{
    public function kind(): Kind
    {
        return Kind::Pong;
    }

    public function toArray(): array
    {
        return ['kind' => Kind::Pong->value];
    }
}
