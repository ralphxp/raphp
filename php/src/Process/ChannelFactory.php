<?php

declare(strict_types=1);

namespace Raphp\Process;

use Socket;

/**
 * The only place that creates the socket pairs between processes (FRAMEWORK 5.1, 6.2).
 *
 * `socket_create_pair()` with `SOCK_CLOEXEC`, never `stream_socket_pair()`: without the flag a
 * worker inherits every other end that exists in the master, and then never sees end of input
 * when the master goes away (findings F1, P0.2). Every descriptor Raphp creates for IPC passes
 * through here.
 */
final class ChannelFactory
{
    /**
     * @throws SpawnException when ext-sockets or the close-on-exec flag is missing, or the pair cannot be made
     */
    public function create(): Channel
    {
        if (!extension_loaded('sockets')) {
            throw new SpawnException('ext-sockets is required to connect to a worker');
        }

        if (!defined('SOCK_CLOEXEC')) {
            throw new SpawnException('this PHP has no SOCK_CLOEXEC, so a worker could inherit descriptors it must not have');
        }

        $pair = [];
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, $pair)) {
            throw new SpawnException('could not create a socket pair: ' . socket_strerror(socket_last_error()));
        }

        if (!is_array($pair)) {
            throw new SpawnException('the socket pair is not what was expected');
        }

        $first = $pair[0] ?? null;
        $second = $pair[1] ?? null;
        if (!$first instanceof Socket || !$second instanceof Socket) {
            throw new SpawnException('the socket pair is not what was expected');
        }

        $parent = socket_export_stream($first);
        $child = socket_export_stream($second);
        if ($parent === false || $child === false) {
            throw new SpawnException('could not turn a socket into a stream');
        }

        return new Channel($parent, $child);
    }
}
