<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Closure;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\Envelope;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Transport\StreamTransport;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\MiddlewareStack;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;

/**
 * A started {@see RpcPeer} whose other end is a {@see RawEnd} the test plays by hand.
 */
final readonly class RawConnection
{
    private function __construct(
        public RpcPeer $peer,
        public RawEnd $raw,
        public HandlerRegistry $handlers,
        public TestHandler $logs,
    ) {}

    /**
     * @param (Closure(string, array<array-key, mixed>): void)|null $onEvent
     * @param (Closure(Envelope): void)|null $onControl
     */
    public static function open(
        RpcPeerOptions $options = new RpcPeerOptions(),
        Codec $codec = new JsonLineCodec(),
        MiddlewareStack $outbound = new MiddlewareStack(),
        ?Closure $onEvent = null,
        ?Closure $onControl = null,
    ): self {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('no socket pair');
        }

        $logs = new TestHandler();
        $handlers = new HandlerRegistry();
        $peer = new RpcPeer(StreamTransport::fromResource($sockets[0]), $codec, new EnvelopeReader(), new Logger('peer', [$logs]), $handlers, $options, new MiddlewareStack(), $outbound, $onEvent, $onControl);
        $peer->start();

        return new self($peer, new RawEnd(StreamTransport::fromResource($sockets[1])), $handlers, $logs);
    }

    public function close(): void
    {
        $this->peer->close();
        $this->raw->close();
    }
}
