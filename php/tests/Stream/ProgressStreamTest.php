<?php

declare(strict_types=1);

namespace Raphp\Tests\Stream;

use function Amp\ByteStream\pipe;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\WritableBuffer;
use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use InvalidArgumentException;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Stream\ProgressStream;
use Raphp\Tests\Support\AsyncTestCase;
use RuntimeException;

final class ProgressStreamTest extends AsyncTestCase
{
    /**
     * @param list<string> $chunks
     */
    private function source(array $chunks, float $pause = 0.0): ReadableIterableStream
    {
        return new ReadableIterableStream((static function () use ($chunks, $pause): \Generator {
            foreach ($chunks as $chunk) {
                if ($pause > 0.0) {
                    delay($pause);
                }

                yield $chunk;
            }
        })());
    }

    public function testItReportsAfterEveryChunkWhenTheIntervalIsZero(): void
    {
        $reports = [];
        $stream = new ProgressStream($this->source(['aaa', 'bb', 'c']), static function (int $bytes, ?int $total) use (&$reports): void {
            $reports[] = [$bytes, $total];
        }, total: 6, minInterval: 0.0);

        while ($stream->read() !== null) {
            // reading to the end
        }

        self::assertSame([[3, 6], [5, 6], [6, 6]], $reports, 'the end adds no duplicate of the last report');
        self::assertSame(6, $stream->bytesRead());
    }

    public function testTheEndIsAlwaysReportedEvenWhenReportsAreThrottled(): void
    {
        $reports = [];
        $stream = new ProgressStream($this->source(['aaa', 'bb', 'c']), static function (int $bytes, ?int $total) use (&$reports): void {
            $reports[] = [$bytes, $total];
        }, minInterval: 3600.0);

        while ($stream->read() !== null) {
            // reading to the end
        }

        self::assertSame([[6, null]], $reports, 'one exact report at the end; the total is null when unknown');
    }

    public function testReportsAreThrottledByTime(): void
    {
        $reports = [];
        $chunks = array_fill(0, 12, 'x');
        $stream = new ProgressStream($this->source($chunks, 0.02), static function (int $bytes) use (&$reports): void {
            $reports[] = $bytes;
        }, minInterval: 0.07);

        while ($stream->read() !== null) {
            // reading to the end
        }

        self::assertGreaterThanOrEqual(2, count($reports));
        self::assertLessThanOrEqual(7, count($reports), 'about one report per 70 ms of a 240 ms read, plus the end');
        self::assertSame(12, end($reports), 'the last report is the exact total');
        self::assertSame($reports, array_values(array_unique($reports)), 'every report moves forward');
    }

    public function testAnEmptyStreamReportsZero(): void
    {
        $reports = [];
        $stream = new ProgressStream($this->source([]), static function (int $bytes) use (&$reports): void {
            $reports[] = $bytes;
        });

        self::assertNull($stream->read());
        self::assertSame([0], $reports);
    }

    public function testItPassesTheDataThroughUnchangedAndWorksWithAmpsPipe(): void
    {
        $data = random_bytes(5000);
        $reports = [];
        $stream = new ProgressStream($this->source(str_split($data, 700)), static function (int $bytes, ?int $total) use (&$reports): void {
            $reports[] = $bytes;
        }, total: 5000, minInterval: 0.0);
        $sink = new WritableBuffer();

        $copied = pipe($stream, $sink);
        $sink->end();

        self::assertSame(5000, $copied);
        self::assertSame($data, $sink->buffer());
        self::assertSame(5000, end($reports));
        self::assertCount(8, $reports);
    }

    public function testItReportsTheProgressOfAFileRead(): void
    {
        $path = sys_get_temp_dir() . '/raphp-progress-' . bin2hex(random_bytes(6));
        file_put_contents($path, str_repeat('p', 250_000));
        $fs = new CooperativeFilesystem(chunkBytes: 65_536, slice: 0.0);
        $last = null;

        try {
            $stream = new ProgressStream($fs->openRead($path), static function (int $bytes, ?int $total) use (&$last): void {
                $last = [$bytes, $total];
            }, total: $fs->stat($path)->size, minInterval: 0.0);
            while ($stream->read() !== null) {
                // reading to the end
            }
        } finally {
            unlink($path);
        }

        self::assertSame([250_000, 250_000], $last);
    }

    public function testACallbackThatThrowsMakesReadThrow(): void
    {
        $stream = new ProgressStream($this->source(['a', 'b']), static function (): never {
            throw new RuntimeException('stop reading');
        }, minInterval: 0.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/stop reading/');

        $stream->read();
    }

    public function testClosingAndTheCloseListenerGoToTheSource(): void
    {
        $source = $this->source(['a']);
        $stream = new ProgressStream($source, static function (): void {}, minInterval: 0.0);
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isClosed());

        $stream->close();

        self::assertTrue($stream->isClosed());
        self::assertTrue($source->isClosed());
        self::assertFalse($stream->isReadable());
        delay(0); // the source runs its close listeners on the next turn of the loop
        self::assertSame(1, $closed);
    }

    public function testCancellationReachesTheSource(): void
    {
        $cancel = new DeferredCancellation();
        $cancel->cancel();
        $stream = new ProgressStream($this->source(['a'], 0.05), static function (): void {}, minInterval: 0.0);

        $this->expectException(CancelledException::class);

        $stream->read($cancel->getCancellation());
    }

    public function testAZeroTotalIsAllowed(): void
    {
        $reports = [];
        $stream = new ProgressStream($this->source([]), static function (int $bytes, ?int $total) use (&$reports): void {
            $reports[] = [$bytes, $total];
        }, total: 0);

        $stream->read();

        self::assertSame([[0, 0]], $reports);
    }

    public function testUnusableSettingsAreRefused(): void
    {
        foreach ([[-0.1, null], [0.1, -1]] as [$interval, $total]) {
            try {
                new ProgressStream($this->source([]), static function (): void {}, $total, $interval);
                self::fail('expected an InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
