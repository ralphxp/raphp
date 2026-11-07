<?php

declare(strict_types=1);

namespace Raphp\Tests\Stream;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;

use function Amp\ByteStream\splitLines;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use InvalidArgumentException;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Stream\LineReader;
use Raphp\Stream\LineTooLongException;
use Raphp\Tests\Support\AsyncTestCase;
use Revolt\EventLoop;

final class LineReaderTest extends AsyncTestCase
{
    /**
     * @param list<string> $chunks
     */
    private function stream(array $chunks): ReadableStream
    {
        return new ReadableIterableStream($chunks);
    }

    public function testItYieldsTheLinesOfAStreamAcrossChunks(): void
    {
        $lines = iterator_to_array((new LineReader())->lines($this->stream(["al", "pha\nbe", "ta\r\n", "gam", "ma"])), false);

        self::assertSame(['alpha', 'beta', 'gamma'], $lines);
    }

    public function testItAgreesWithAmpOnOrdinaryInput(): void
    {
        $text = "one\ntwo\r\nthree\n\nfive";
        $chunks = str_split($text, 3);

        $ours = iterator_to_array((new LineReader())->lines($this->stream($chunks)), false);
        $amp = iterator_to_array(splitLines($this->stream($chunks)), false);

        self::assertSame($amp, $ours);
    }

    public function testItDiffersFromAmpWhereAmpIsLoose(): void
    {
        // Amp strips every trailing carriage return (rtrim); the reader drops the one that belongs to the line ending.
        $ours = iterator_to_array((new LineReader())->lines($this->stream(["a\r\r\n"])), false);
        $amp = iterator_to_array(splitLines($this->stream(["a\r\r\n"])), false);

        self::assertSame(["a\r"], $ours);
        self::assertSame(['a'], $amp);
    }

    public function testALineBeyondTheLimitStopsTheReadingWithAnException(): void
    {
        $reader = new LineReader(8);
        $seen = [];

        try {
            foreach ($reader->lines($this->stream(["short\n", str_repeat('x', 30), "\nlater\n"])) as $line) {
                $seen[] = $line;
            }

            self::fail('expected LineTooLongException');
        } catch (LineTooLongException) {
            self::assertSame(['short'], $seen);
        }
    }

    public function testAnEndlessLineWithoutNewlineIsStopped(): void
    {
        $endless = (static function (): \Generator {
            while (true) {
                yield str_repeat('n', 1000);
            }
        })();

        $this->expectException(LineTooLongException::class);

        foreach ((new LineReader(10_000))->lines(new ReadableIterableStream($endless)) as $line) {
            self::fail("no line expected, got {$line}");
        }
    }

    public function testItReadsLinesOfARealFileThroughTheFilesystemService(): void
    {
        $path = sys_get_temp_dir() . '/raphp-lines-' . bin2hex(random_bytes(6));
        $expected = [];
        $content = '';
        for ($i = 0; $i < 500; $i++) {
            $expected[] = "line {$i} " . str_repeat('#', $i % 50);
            $content .= $expected[$i] . "\n";
        }

        file_put_contents($path, $content);
        try {
            $stream = (new CooperativeFilesystem(chunkBytes: 100, slice: 0.0))->openRead($path);

            self::assertSame($expected, iterator_to_array((new LineReader())->lines($stream), false));
        } finally {
            unlink($path);
        }
    }

    public function testCancellationStopsTheReading(): void
    {
        $slow = (static function (): \Generator {
            for ($i = 0; $i < 100; $i++) {
                delay(0.02);
                yield "line {$i}\n";
            }
        })();
        $cancel = new DeferredCancellation();
        EventLoop::delay(0.1, static fn() => $cancel->cancel());
        $seen = 0;

        try {
            foreach ((new LineReader())->lines(new ReadableIterableStream($slow), $cancel->getCancellation()) as $line) {
                ++$seen;
            }

            self::fail('expected the cancellation');
        } catch (CancelledException) {
            self::assertGreaterThan(0, $seen);
            self::assertLessThan(100, $seen);
        }
    }

    public function testThePipelineOffersMapFilterAndTake(): void
    {
        $lines = (new LineReader())->pipeline($this->stream(["1\n2\n3\n", "4\n5\n6\n"]));

        $result = $lines
            ->map(static fn(string $line): int => (int) $line)
            ->filter(static fn(int $number): bool => $number % 2 === 0)
            ->take(2)
            ->toArray();

        self::assertSame([2, 4], $result);
    }

    public function testThePipelineCarriesTheLineLimitError(): void
    {
        $pipeline = (new LineReader(4))->pipeline($this->stream(["ok\n", "toolongline\n"]));

        $this->expectException(LineTooLongException::class);

        $pipeline->toArray();
    }

    public function testUnusableLimitsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LineReader(0);
    }
}
