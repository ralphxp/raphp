<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Closure;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Transport\StreamTransport;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\MiddlewareStack;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;

/**
 * Two connected peers in one process, over an in-process socket pair (allowed because no child
 * process is spawned, so the descriptors cannot be inherited), each with its own handler
 * registry and its own log.
 */
final readonly class PeerPair
{
    private function __construct(
        public RpcPeer $client,
        public RpcPeer $server,
        public HandlerRegistry $clientHandlers,
        public HandlerRegistry $serverHandlers,
        public TestHandler $clientLogs,
        public TestHandler $serverLogs,
    ) {}

    /**
     * @param (Closure(string, array<array-key, mixed>): void)|null $clientOnEvent
     * @param (Closure(string, array<array-key, mixed>): void)|null $serverOnEvent
     */
    public static function open(
        RpcPeerOptions $clientOptions = new RpcPeerOptions(),
        RpcPeerOptions $serverOptions = new RpcPeerOptions(),
        MiddlewareStack $clientOutbound = new MiddlewareStack(),
        MiddlewareStack $serverInbound = new MiddlewareStack(),
        Codec $serverCodec = new JsonLineCodec(),
        ?Closure $clientOnEvent = null,
        ?Closure $serverOnEvent = null,
        ?Hello $clientHello = null,
        ?Hello $serverHello = null,
    ): self {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new \RuntimeException('no socket pair');
        }

        $clientLogs = new TestHandler();
        $serverLogs = new TestHandler();
        $clientHandlers = new HandlerRegistry();
        $serverHandlers = new HandlerRegistry();
        $reader = new EnvelopeReader();

        $client = new RpcPeer(StreamTransport::fromResource($sockets[0]), new JsonLineCodec(), $reader, new Logger('client', [$clientLogs]), $clientHandlers, $clientOptions, new MiddlewareStack(), $clientOutbound, $clientOnEvent, $clientHello);
        $server = new RpcPeer(StreamTransport::fromResource($sockets[1]), $serverCodec, $reader, new Logger('server', [$serverLogs]), $serverHandlers, $serverOptions, $serverInbound, new MiddlewareStack(), $serverOnEvent, $serverHello);
        $client->start();
        $server->start();

        return new self($client, $server, $clientHandlers, $serverHandlers, $clientLogs, $serverLogs);
    }

    public function close(): void
    {
        $this->client->close();
        $this->server->close();
    }
}
