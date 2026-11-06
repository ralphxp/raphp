<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

/**
 * The `kind` field of an envelope (FRAMEWORK 6.4). The values are the exact strings on the wire.
 */
enum Kind: string
{
    case Request = 'req';
    case Response = 'res';
    case Event = 'evt';
    case Cancel = 'cancel';
    case Hello = 'hello';
    case Bye = 'bye';
    case Ping = 'ping';
    case Pong = 'pong';
}
