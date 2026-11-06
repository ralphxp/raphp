<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Amp\TimeoutCancellation;
use Raphp\Error\RpcException;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\Decoder;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Transport\StreamTransport;

/**
 * The far end of a connection played by hand: a test sends exact bytes (good or broken) to the
 * peer under test and reads what it sends back.
 */
final class RawEnd
{
    private readonly Decoder $decoder;

    public function __construct(public readonly StreamTransport $transport, private readonly Codec $codec = new JsonLineCodec())
    {
        $this->decoder = $codec->createDecoder();
    }

    /**
     * @param array<array-key, mixed> $message
     */
    public function send(array $message): void
    {
        $this->transport->write($this->codec->encode($message));
    }

    public function sendRaw(string $bytes): void
    {
        $this->transport->write($bytes);
    }

    /**
     * Waits for the next message from the peer. Returns null at end of input.
     *
     * @return array<array-key, mixed>|RpcException|null
     */
    public function receive(float $timeout = 5.0): array|RpcException|null
    {
        $cancellation = new TimeoutCancellation($timeout, 'the peer sent nothing');

        while (true) {
            $item = $this->decoder->next();
            if ($item !== null) {
                return $item;
            }

            $chunk = $this->transport->read($cancellation);
            if ($chunk === null) {
                return null;
            }

            $this->decoder->feed($chunk);
        }
    }

    public function close(): void
    {
        $this->transport->close();
    }
}
