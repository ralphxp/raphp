<?php

declare(strict_types=1);

namespace Raphp\Ipc\Transport;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Closure;
use InvalidArgumentException;

/**
 * A {@see Transport} over Amp byte streams, normally the two directions of one stream socket.
 *
 * Closing cancels the event-loop watchers before the descriptor is released, so a read that is
 * waiting never meets a closed stream (finding F5). When it was built from a resource with
 * {@see self::fromResource()} it also closes that resource, so the descriptor is freed at once
 * and does not wait for garbage collection.
 */
final class StreamTransport implements Transport
{
    /** Bytes asked for per read. Not measured yet: P2.13 tunes it. */
    public const int DEFAULT_CHUNK_SIZE = 65_536;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $closed;

    private int $sidesOpen = 2;

    /** How many write() calls have not returned yet. */
    private int $pendingWrites = 0;

    /** Set when end() is called, so no new write can slip in behind it. */
    private bool $ending = false;

    /** @var DeferredFuture<null>|null waited on by end() until the pending writes are done */
    private ?DeferredFuture $drained = null;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $ended;

    /**
     * @param resource|null $resource a resource both streams use, closed after them when set
     */
    public function __construct(
        private readonly ReadableStream $input,
        private readonly WritableStream $output,
        private readonly mixed $resource = null,
    ) {
        $this->closed = new DeferredFuture();
        $this->ended = new DeferredFuture();
        $input->onClose($this->sideClosed(...));
        $output->onClose($this->sideClosed(...));
    }

    /**
     * Wraps a stream socket (for example one end of a socket pair) for reading and writing.
     * The transport takes over the resource: do not read, write or close it yourself afterwards.
     *
     * @param mixed $resource a stream resource; anything else is refused
     * @param int $chunkSize bytes asked for per read, at least 1
     *
     * @throws InvalidArgumentException when it is not an open stream or the chunk size is not positive
     */
    public static function fromResource(mixed $resource, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException('a transport needs an open stream resource');
        }

        if ($chunkSize < 1) {
            throw new InvalidArgumentException('the chunk size must be positive');
        }

        return new self(new ReadableResourceStream($resource, $chunkSize), new WritableResourceStream($resource), $resource);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->input->read($cancellation);
    }

    public function write(string $bytes): void
    {
        if ($this->ending) {
            throw new ClosedException('the transport has been ended, nothing more can be written');
        }

        ++$this->pendingWrites;

        try {
            $this->output->write($bytes);
        } finally {
            if (--$this->pendingWrites === 0) {
                $this->drained?->complete();
                $this->drained = null;
            }
        }
    }

    /**
     * Waits for the writes that are still pending, then ends the output. Amp's own `end()` does
     * not wait: it fails the writes still queued, which would lose the tail of a message.
     */
    public function end(): void
    {
        if ($this->ending) {
            $this->ended->getFuture()->await();

            return;
        }

        $this->ending = true;

        if ($this->pendingWrites > 0) {
            $this->drained = new DeferredFuture();
            $this->drained->getFuture()->await();
        }

        if ($this->output->isWritable()) {
            $this->output->end();
        }

        $this->ended->complete();
    }

    public function close(): void
    {
        $this->input->close();
        $this->output->close();
        $this->release();
    }

    public function isClosed(): bool
    {
        return $this->input->isClosed() && $this->output->isClosed();
    }

    /**
     * @param Closure(): void $onClose
     */
    public function onClose(Closure $onClose): void
    {
        $this->closed->getFuture()->finally($onClose);
    }

    private function sideClosed(): void
    {
        if (--$this->sidesOpen === 0) {
            $this->release();
            $this->closed->complete();
        }
    }

    /**
     * Frees the descriptor. Called only when both streams are closed, which means their
     * watchers are already cancelled (finding F5).
     */
    private function release(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }
}
