<?php

declare(strict_types=1);

namespace Raphp\Stream;

use InvalidArgumentException;

/**
 * Cuts incoming chunks of bytes into lines, for callers that are handed chunks (the output callback of
 * {@see \Raphp\Process\ProcessRunner}, a socket's data event). For a stream that can be read, use
 * {@see LineReader}.
 *
 * A line ends at "\n"; one "\r" just before it is dropped too, so "\r\n" works. Other "\r" stay.
 * Empty lines are lines. The bytes are not interpreted: nothing here assumes UTF-8.
 *
 * Unlike `Amp\ByteStream\splitLines()` it has a limit: a source that never sends a newline cannot make
 * it grow without bound (finding F54). A line longer than `$maxLineBytes` (not counting the line
 * ending) raises {@see LineTooLongException} and the buffer is emptied. Each chunk is scanned once,
 * so a very long line arriving in small chunks costs time in proportion to its length, not its square.
 */
final class LineBuffer
{
    /** The longest line accepted by default (my number, unmeasured). */
    public const int DEFAULT_MAX_LINE_BYTES = 1_048_576;

    private string $pending = '';

    /**
     * @param int $maxLineBytes the longest line allowed, at least 1
     *
     * @throws InvalidArgumentException when the limit is below 1
     */
    public function __construct(private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES)
    {
        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException('the longest line must be at least 1 byte');
        }
    }

    /**
     * Adds a chunk and returns the lines it completed, in order, without their line endings.
     *
     * @return list<string>
     *
     * @throws LineTooLongException when a line is longer than the limit
     */
    public function feed(string $chunk): array
    {
        $parts = explode("\n", $chunk);

        if (count($parts) === 1) {
            $this->pending .= $chunk;
            $this->requireWithinLimit($this->pendingLength());

            return [];
        }

        $parts[0] = $this->pending . $parts[0];
        $this->pending = array_pop($parts);

        $lines = [];
        foreach ($parts as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            $this->requireWithinLimit(strlen($line));
            $lines[] = $line;
        }

        $this->requireWithinLimit($this->pendingLength());

        return $lines;
    }

    /**
     * The last line, when the input ended without a final newline, or null when there is nothing left.
     * The buffer is empty afterwards.
     *
     * @throws LineTooLongException when that last line is longer than the limit
     */
    public function finish(): ?string
    {
        $last = $this->pending;
        $this->pending = '';
        if (strlen($last) > $this->maxLineBytes) {
            throw new LineTooLongException("a line is longer than the limit of {$this->maxLineBytes} bytes");
        }

        return $last === '' ? null : $last;
    }

    /**
     * How many bytes of an unfinished line are held.
     */
    public function pendingBytes(): int
    {
        return strlen($this->pending);
    }

    /**
     * The length of the unfinished line, not counting a final "\r": it may be the first half of a "\r\n"
     * whose "\n" has not arrived yet.
     */
    private function pendingLength(): int
    {
        return strlen($this->pending) - (str_ends_with($this->pending, "\r") ? 1 : 0);
    }

    /**
     * @throws LineTooLongException
     */
    private function requireWithinLimit(int $length): void
    {
        if ($length > $this->maxLineBytes) {
            $this->pending = '';

            throw new LineTooLongException("a line is longer than the limit of {$this->maxLineBytes} bytes");
        }
    }
}
