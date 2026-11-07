<?php

declare(strict_types=1);

namespace Raphp\Tests\Stream;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raphp\Stream\LineBuffer;
use Raphp\Stream\LineTooLongException;

final class LineBufferTest extends TestCase
{
    /**
     * @param list<string> $chunks
     *
     * @return list<string> every line, including a last one without a newline
     */
    private function linesOf(array $chunks, int $max = 1000): array
    {
        $buffer = new LineBuffer($max);
        $lines = [];
        foreach ($chunks as $chunk) {
            array_push($lines, ...$buffer->feed($chunk));
        }

        $last = $buffer->finish();
        if ($last !== null) {
            $lines[] = $last;
        }

        return $lines;
    }

    public function testItCutsLinesAndKeepsAPartialLineForTheNextChunk(): void
    {
        $buffer = new LineBuffer();

        self::assertSame(['one', 'two'], $buffer->feed("one\ntwo\nthr"));
        self::assertSame(3, $buffer->pendingBytes());
        self::assertSame([], $buffer->feed('ee'));
        self::assertSame(['three'], $buffer->feed("\n"));
        self::assertSame(0, $buffer->pendingBytes());
        self::assertNull($buffer->finish());
    }

    public function testACarriageReturnBeforeTheNewlineIsDroppedButOnlyOne(): void
    {
        self::assertSame(['a', 'b', "c\r", 'd\re'], $this->linesOf(["a\r\nb\n", "c\r\r\n", 'd\re']));
    }

    public function testAnEmptyLineIsALineAndASingleCrLfIsOne(): void
    {
        self::assertSame(['', 'x', '', ''], $this->linesOf(["\nx\n\r\n\n"]));
    }

    public function testAnInputEndingWithANewlineHasNoExtraEmptyLine(): void
    {
        self::assertSame(['a'], $this->linesOf(["a\n"]));
        self::assertSame(['a', 'b'], $this->linesOf(["a\nb"]));
        self::assertSame([], $this->linesOf(['']));
        self::assertSame([], $this->linesOf([]));
    }

    public function testBytesAreNotInterpreted(): void
    {
        $binary = "\xff\xfe\x00\x80\n\xc3\x28\n";

        self::assertSame(["\xff\xfe\x00\x80", "\xc3\x28"], $this->linesOf([$binary]));
    }

    public function testTheResultDoesNotDependOnWhereTheChunksAreCut(): void
    {
        $text = '';
        mt_srand(7);
        $endings = ["\n", "\r\n", "\n", "\r\n", "\n"];
        for ($i = 0; $i < 60; $i++) {
            $text .= implode('', array_map(static fn(int $i): string => chr(mt_rand(0, 255)), range(1, mt_rand(0, 12)))) . $endings[mt_rand(0, 4)];
        }

        $text .= 'tail without newline';
        $reference = $this->linesOf([$text], 100);

        for ($round = 0; $round < 200; $round++) {
            $chunks = [];
            for ($offset = 0; $offset < strlen($text);) {
                $size = mt_rand(1, 40);
                $chunks[] = substr($text, $offset, $size);
                $offset += $size;
            }

            self::assertSame($reference, $this->linesOf($chunks, 100), "round {$round}");
        }

        self::assertSame($reference, $this->linesOf(str_split($text), 100), 'one byte at a time');
    }

    public function testALineExactlyAtTheLimitIsAllowedWithEitherEnding(): void
    {
        $line = str_repeat('x', 10);

        self::assertSame([$line], $this->linesOf(["{$line}\n"], 10));
        self::assertSame([$line], $this->linesOf(["{$line}\r\n"], 10));
        self::assertSame([$line], $this->linesOf([$line], 10), 'a last line without newline too');
        self::assertSame([$line], $this->linesOf([$line . "\r", "\n"], 10), 'the carriage return arrives before the newline');
    }

    public function testALineOneByteOverTheLimitIsRefused(): void
    {
        foreach (["01234567890\n", "01234567890\r\n", '01234567890'] as $input) {
            try {
                $this->linesOf([$input], 10);
                self::fail('expected LineTooLongException for ' . json_encode($input));
            } catch (LineTooLongException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFinalLineEndingInACarriageReturnCountsItWhenNoNewlineFollows(): void
    {
        // Without a "\n" the "\r" is part of the line's content, so this last line is 11 bytes.
        $this->expectException(LineTooLongException::class);

        $this->linesOf(["0123456789\r"], 10);
    }

    public function testALongLineArrivingInSmallChunksIsRefusedBeforeItEnds(): void
    {
        $buffer = new LineBuffer(100);

        try {
            for ($i = 0; $i < 10_000; $i++) {
                $buffer->feed(str_repeat('y', 7));
                self::assertLessThanOrEqual(101, $buffer->pendingBytes(), 'memory stays bounded');
            }

            self::fail('expected LineTooLongException');
        } catch (LineTooLongException $exception) {
            self::assertStringContainsString('100 bytes', $exception->getMessage());
            self::assertSame(0, $buffer->pendingBytes(), 'the buffer is emptied');
        }

        self::assertSame(['fresh'], $buffer->feed("fresh\n"), 'and can be used again');
    }

    public function testAnOverlongLineAfterShortOnesStillRefuses(): void
    {
        $this->expectException(LineTooLongException::class);

        $this->linesOf(["ok\nfine\n" . str_repeat('z', 50) . "\n"], 20);
    }

    public function testUnusableLimitsAreRefused(): void
    {
        foreach ([0, -1] as $limit) {
            try {
                new LineBuffer($limit);
                self::fail('expected an InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        new LineBuffer(1);
    }

    public function testALongLineInSmallChunksCostsTimeInProportionToItsLength(): void
    {
        $buffer = new LineBuffer(200_000_000);
        $chunk = str_repeat('a', 4096);
        $begin = hrtime(true);
        for ($i = 0; $i < 4000; $i++) { // 16 MB without a newline
            $buffer->feed($chunk);
        }

        $seconds = (hrtime(true) - $begin) / 1e9;

        self::assertSame(4096 * 4000, $buffer->pendingBytes());
        self::assertLessThan(2.0, $seconds, 'quadratic scanning would take far longer');
    }
}
