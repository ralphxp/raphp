<?php

declare(strict_types=1);

namespace Raphp\Process;

use Raphp\Ipc\Transport\StreamTransport;

/**
 * The worker's side of the channel: the socket its master handed over as descriptor 3.
 */
final class InheritedChannel
{
    /**
     * Opens descriptor 3 as a {@see StreamTransport}. PHP duplicates the descriptor, so the
     * original stays open in this process until it ends.
     *
     * @throws SpawnException when there is no descriptor 3: this process was not started by a Raphp master
     */
    public function open(int $descriptor = 3): StreamTransport
    {
        $stream = @fopen("php://fd/{$descriptor}", 'r+');
        if ($stream === false) {
            throw new SpawnException("there is no channel on descriptor {$descriptor}: this process was not started by a Raphp master");
        }

        return StreamTransport::fromResource($stream);
    }
}
