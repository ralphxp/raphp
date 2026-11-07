<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Closure;
use IteratorAggregate;
use Raphp\Filesystem\Internal\TimeSlice;

/**
 * A file read in chunks, the way {@see CooperativeFilesystem} works: after each slice of work the
 * event loop gets a turn. Unlike {@see Filesystem::read()} it never holds more than one chunk, so it is
 * the way to handle a large file.
 *
 * @implements IteratorAggregate<int, string>
 */
final class CooperativeReadStream implements ReadableStream, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    /** @var resource|null */
    private mixed $handle;

    private bool $closed = false;

    /** @var list<Closure(): mixed> */
    private array $onClose = [];

    private readonly TimeSlice $slice;

    /**
     * @param resource $handle an open file, owned by the stream from now on
     * @param int<1, max> $chunkBytes
     */
    public function __construct(mixed $handle, private readonly string $path, private readonly int $chunkBytes, float $slice)
    {
        $this->handle = $handle;
        $this->slice = new TimeSlice($slice);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        while ($this->handle !== null) {
            $this->slice->checkpoint($cancellation);

            $chunk = fread($this->handle, $this->chunkBytes);
            if ($chunk === false) {
                $path = $this->path;
                $this->close();

                throw new FilesystemException("cannot read {$path}: " . (error_get_last()['message'] ?? 'unknown reason'));
            }

            if ($chunk !== '') {
                return $chunk;
            }

            if (feof($this->handle)) {
                $this->close();
            }
        }

        return null;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        $callbacks = $this->onClose;
        $this->onClose = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();

            return;
        }

        $this->onClose[] = $onClose;
    }

    public function __destruct()
    {
        $this->close();
    }
}
