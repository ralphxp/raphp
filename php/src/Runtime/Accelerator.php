<?php

declare(strict_types=1);

namespace Raphp\Runtime;

/**
 * The optional PHP extensions that make asynchronous I/O faster (FRAMEWORK 3.3). None is required:
 * everything works without them.
 */
enum Accelerator: string
{
    /** libuv: an event-loop driver, chosen by Revolt before ext-ev and ext-event. */
    case Uv = 'uv';

    /** libev: an event-loop driver. */
    case Ev = 'ev';

    /** libevent: an event-loop driver. */
    case Event = 'event';

    /** libeio: asynchronous file operations. Raphp has no filesystem driver for it yet. */
    case Eio = 'eio';

    /**
     * The class of the Revolt event-loop driver that this extension provides, or null when it provides none.
     */
    public function loopDriver(): ?string
    {
        return match ($this) {
            self::Uv => 'Revolt\EventLoop\Driver\UvDriver',
            self::Ev => 'Revolt\EventLoop\Driver\EvDriver',
            self::Event => 'Revolt\EventLoop\Driver\EventDriver',
            self::Eio => null,
        };
    }

    /**
     * Whether Raphp makes any use of the extension if it is loaded.
     */
    public function usedByRaphp(): bool
    {
        return $this->loopDriver() !== null;
    }
}
