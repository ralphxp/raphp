<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

/**
 * Announces an orderly close: `{"kind": "bye", "reason": "shutdown"}` (FRAMEWORK 5.5).
 */
final readonly class Bye implements Envelope
{
    public function __construct(public string $reason = '') {}

    public function kind(): Kind
    {
        return Kind::Bye;
    }

    public function toArray(): array
    {
        return ['kind' => Kind::Bye->value, 'reason' => $this->reason];
    }
}
