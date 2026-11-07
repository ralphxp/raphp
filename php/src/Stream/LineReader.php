<?php

declare(strict_types=1);

namespace Raphp\Stream;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\Pipeline;
use Generator;
use InvalidArgumentException;

/**
 * Reads a stream line by line, with a limit on the length of a line (see {@see LineBuffer}).
 *
 * Reading a stream to the end consumes it; stopping early leaves the stream open, and closing it is the
 * caller's job.
 */
final readonly class LineReader
{
    /**
     * @param int $maxLineBytes the longest line allowed, at least 1
     *
     * @throws InvalidArgumentException when the limit is below 1
     */
    public function __construct(private int $maxLineBytes = LineBuffer::DEFAULT_MAX_LINE_BYTES)
    {
        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException('the longest line must be at least 1 byte');
        }
    }

    /**
     * The lines of the stream, one at a time, as they arrive. A last line without a newline is delivered
     * too; an input that ends with a newline has no extra empty line.
     *
     * @return Generator<int, string>
     *
     * @throws LineTooLongException when a line is longer than the limit
     * @throws CancelledException
     */
    public function lines(ReadableStream $stream, ?Cancellation $cancellation = null): Generator
    {
        $buffer = new LineBuffer($this->maxLineBytes);

        while (($chunk = $stream->read($cancellation)) !== null) {
            foreach ($buffer->feed($chunk) as $line) {
                yield $line;
            }
        }

        $last = $buffer->finish();
        if ($last !== null) {
            yield $last;
        }
    }

    /**
     * The same lines as an `amphp/pipeline` pipeline, for `map`, `filter`, `take`, `concurrent` and the
     * rest of what a pipeline offers.
     *
     * @return Pipeline<string>
     */
    public function pipeline(ReadableStream $stream, ?Cancellation $cancellation = null): Pipeline
    {
        return Pipeline::fromIterable(fn(): Generator => $this->lines($stream, $cancellation));
    }
}
