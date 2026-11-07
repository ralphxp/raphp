<?php

declare(strict_types=1);

namespace Raphp\Process;

use Raphp\Ipc\Transport\StreamTransport;

/**
 * The worker's side of the channel: the socket its master handed over as descriptor 3.
 */
final class InheritedChannel
{
    private const int TYPE_MASK = 0o170000;

    private const int TYPE_SOCKET = 0o140000;

    /**
     * Opens descriptor 3 as a {@see StreamTransport}. PHP duplicates the descriptor, so the
     * original stays open in this process until it ends.
     *
     * @throws SpawnException when there is no descriptor 3, or it is not a socket: this process was not started by a Raphp master
     */
    public function open(int $descriptor = 3): StreamTransport
    {
        $stream = @fopen("php://fd/{$descriptor}", 'r+');
        if ($stream === false) {
            throw new SpawnException("there is no channel on descriptor {$descriptor}: this process was not started by a Raphp master");
        }

        // Descriptor 3 can be something else in a process that was not started by a master (PHP's own
        // handle on the script file, for one), and opening it would then succeed.
        $stat = fstat($stream);
        if ($stat === false || ($stat['mode'] & self::TYPE_MASK) !== self::TYPE_SOCKET) {
            fclose($stream);

            throw new SpawnException("descriptor {$descriptor} is not a socket: this process was not started by a Raphp master");
        }

        return StreamTransport::fromResource($stream);
    }
}
