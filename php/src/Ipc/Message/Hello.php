<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;

/**
 * The first message each side sends: protocol version, who it is and what it can do
 * (FRAMEWORK 6.5). The handshake itself is built in P2.7; this is only the message.
 *
 * `role` is a free string for now ("worker", "master"), because attachable clients such as
 * inspectors may add roles. P2.7 decides which roles it accepts.
 */
final readonly class Hello implements Envelope
{
    /**
     * @param list<string> $caps
     *
     * @throws InvalidArgumentException when the version or pid is not positive, or the role or name is empty
     */
    public function __construct(
        public int $proto,
        public string $role,
        public string $name,
        public int $pid,
        public array $caps = [],
    ) {
        Validation::positive($proto, '"proto"');
        Validation::notEmpty($role, '"role"');
        Validation::notEmpty($name, '"name"');
        Validation::positive($pid, '"pid"');
    }

    public function kind(): Kind
    {
        return Kind::Hello;
    }

    public function toArray(): array
    {
        return ['kind' => Kind::Hello->value, 'proto' => $this->proto, 'role' => $this->role, 'name' => $this->name, 'pid' => $this->pid, 'caps' => $this->caps];
    }
}
