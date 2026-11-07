<?php

declare(strict_types=1);

namespace Raphp\Process;

use function Amp\async;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Future;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads everything a worker writes to one of its output pipes, from start to end of the process.
 *
 * Draining never stops: a worker that writes more than a pipe holds (about 64 KiB) would block
 * until somebody reads, so the master reads all the time, even if nobody asks for the output
 * (FRAMEWORK 5.1, risk R18). What it reads is logged one line at a time with the worker's name,
 * and the most recent bytes are kept for diagnostics, so output is bounded in memory however
 * much a worker writes. A line longer than the limit is cut.
 *
 * @internal
 */
final class OutputCapture
{
    public const int KEEP_BYTES = 65_536;

    public const int MAX_LINE_BYTES = 8_192;

    private string $recent = '';

    private string $pending = '';

    /** @var Future<null> */
    private readonly Future $done;

    /**
     * @param string $level the PSR-3 level each line is logged at
     */
    public function __construct(
        private readonly ReadableStream $stream,
        private readonly string $worker,
        private readonly string $name,
        private readonly string $level,
        private readonly LoggerInterface $logger,
    ) {
        $this->done = async($this->drain(...));
        $this->done->ignore();
    }

    /**
     * The last bytes the worker wrote, at most {@see self::KEEP_BYTES} of them.
     */
    public function recent(): string
    {
        return substr($this->recent, -self::KEEP_BYTES);
    }

    /**
     * Waits until the pipe has reached its end (every process holding it has exited or closed it).
     *
     * @throws \Amp\CancelledException
     */
    public function await(?Cancellation $cancellation = null): void
    {
        $this->done->await($cancellation);
    }

    /**
     * Stops reading and releases the pipe.
     */
    public function close(): void
    {
        $this->stream->close();
    }

    private function drain(): void
    {
        try {
            while (($chunk = $this->stream->read()) !== null) {
                $this->recent .= $chunk;
                if (strlen($this->recent) > self::KEEP_BYTES * 2) {
                    $this->recent = substr($this->recent, -self::KEEP_BYTES);
                }

                $this->lines($chunk);
            }
        } catch (Throwable) {
            // The pipe was closed from our side or broke; what was read is kept.
        }

        if ($this->pending !== '') {
            $this->emit($this->pending);
            $this->pending = '';
        }
    }

    private function lines(string $chunk): void
    {
        $this->pending .= $chunk;

        while (($newline = strpos($this->pending, "\n")) !== false) {
            $this->emit(substr($this->pending, 0, $newline));
            $this->pending = substr($this->pending, $newline + 1);
        }

        // A line that never ends must not grow without bound: log what there is and go on.
        if (strlen($this->pending) > self::MAX_LINE_BYTES) {
            $this->emit($this->pending);
            $this->pending = '';
        }
    }

    private function emit(string $line): void
    {
        $line = rtrim($line, "\r");
        if ($line === '') {
            return;
        }

        $this->logger->log($this->level, 'worker output', [
            'worker' => $this->worker,
            'stream' => $this->name,
            'line' => \Raphp\Error\Text::truncate($line, self::MAX_LINE_BYTES),
        ]);
    }
}
