<?php

declare(strict_types=1);

namespace Raphp\Ipc\Codec;

use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;

/**
 * Turns messages into bytes and back (FRAMEWORK 6.3). The wire format is behind this interface
 * so another one, such as MessagePack, can replace JSON lines later.
 */
interface Codec
{
    /**
     * Encodes one message as one line, ending in a newline.
     *
     * @param array<array-key, mixed> $message
     *
     * @throws ProtocolException when the message cannot be encoded (invalid UTF-8, NaN)
     * @throws PayloadTooLargeException when the line would be longer than the limit
     */
    public function encode(array $message): string;

    /**
     * Creates the decoder for one connection. A decoder holds the connection's partial input, so
     * connections must not share one.
     */
    public function createDecoder(): Decoder;
}
