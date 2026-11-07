<?php

declare(strict_types=1);

namespace Raphp\Ipc;

/**
 * Facts about the IPC protocol that both ends must agree on (FRAMEWORK 6.5).
 */
final class Protocol
{
    /**
     * The protocol version sent in `hello`. Breaking changes to the wire format bump it, and two
     * ends with different versions refuse each other.
     */
    public const int VERSION = 1;
}
