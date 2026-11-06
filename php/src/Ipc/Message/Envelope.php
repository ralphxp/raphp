<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

/**
 * One message of the IPC protocol: an immutable value that knows its kind and how it looks on
 * the wire. Build one from a decoded line with {@see EnvelopeReader}; hand {@see self::toArray()}
 * to a {@see \Raphp\Ipc\Codec\Codec} to send it.
 */
interface Envelope
{
    public function kind(): Kind;

    /**
     * The message as the object that goes on the wire, keys in the documented order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
