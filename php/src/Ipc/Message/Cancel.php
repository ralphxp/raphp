<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;

/**
 * Asks the other side to stop the request with this id: `{"kind": "cancel", "ref": 17}`. The
 * request still gets its one {@see Response}, usually `E_CANCELLED` (FRAMEWORK 6.7).
 */
final readonly class Cancel implements Envelope
{
    /**
     * @throws InvalidArgumentException when the ref is not positive
     */
    public function __construct(public int $ref)
    {
        Validation::positive($ref, '"ref"');
    }

    public function kind(): Kind
    {
        return Kind::Cancel;
    }

    public function toArray(): array
    {
        return ['kind' => Kind::Cancel->value, 'ref' => $this->ref];
    }
}
