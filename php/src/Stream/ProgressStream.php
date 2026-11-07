<?php

declare(strict_types=1);

namespace Raphp\Stream;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Closure;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * A stream that tells how far it has been read: wrap a source, pass it to `Amp\ByteStream\pipe()` or
 * read it yourself, and report to a progress callback, for example a worker's
 * {@see \Raphp\Rpc\ProgressReporter} (FRAMEWORK 6.4).
 *
 * Reports are throttled to one per `$minInterval` seconds, so a fast stream does not flood the
 * connection with progress events; the end of the stream is always reported, so the last report
 * is exact. The callback runs inside `read()`: a callback that throws makes `read()` throw.
 *
 * @implements IteratorAggregate<int, string>
 */
final class ProgressStream implements ReadableStream, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private int $bytes = 0;

    private int $reportedBytes = -1;

    private int $lastReport;

    /**
     * @param Closure(int, int|null): void $onProgress called with the bytes read so far and the total, when it is known
     * @param int|null $total the size of the source, when known (a file's size), only passed along
     * @param float $minInterval seconds between two reports; 0 reports after every chunk
     *
     * @throws InvalidArgumentException when the interval is negative or the total is negative
     */
    public function __construct(
        private readonly ReadableStream $source,
        private readonly Closure $onProgress,
        private readonly ?int $total = null,
        private readonly float $minInterval = 0.1,
    ) {
        if ($minInterval < 0.0 || ($total !== null && $total < 0)) {
            throw new InvalidArgumentException('the interval and the total must not be negative');
        }

        $this->lastReport = hrtime(true);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $chunk = $this->source->read($cancellation);

        if ($chunk === null) {
            $this->report(true);

            return null;
        }

        $this->bytes += strlen($chunk);
        $this->report(false);

        return $chunk;
    }

    /**
     * The bytes read so far.
     */
    public function bytesRead(): int
    {
        return $this->bytes;
    }

    public function isReadable(): bool
    {
        return $this->source->isReadable();
    }

    public function close(): void
    {
        $this->source->close();
    }

    public function isClosed(): bool
    {
        return $this->source->isClosed();
    }

    public function onClose(Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }

    private function report(bool $final): void
    {
        $now = hrtime(true);
        if (!$final && ($now - $this->lastReport) / 1e9 < $this->minInterval) {
            return;
        }

        if ($this->bytes === $this->reportedBytes) {
            return; // nothing new since the last report (the end of a stream that was just reported)
        }

        $this->lastReport = $now;
        $this->reportedBytes = $this->bytes;
        ($this->onProgress)($this->bytes, $this->total);
    }
}
