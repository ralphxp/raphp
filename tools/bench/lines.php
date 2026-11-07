<?php

/**
 * Line splitting: Raphp's bounded LineBuffer against Amp's splitLines() (plan P2B.3).
 *
 *   php tools/bench/lines.php
 *
 * Case 1: a source that never sends a newline (16 MB in 4 KiB chunks). Amp's splitter re-scans the whole
 * buffer for every chunk. Case 2: ordinary text, 200,000 short lines in 64 KiB chunks.
 */

declare(strict_types=1);

use Amp\ByteStream\ReadableIterableStream;

use function Amp\ByteStream\splitLines;

use Raphp\Stream\LineBuffer;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * @param Closure(): int $work returns how many lines it produced
 */
function timed(string $label, Closure $work): void
{
    $begin = hrtime(true);
    $lines = $work();
    printf("  %-58s %9.1f ms  (%d lines, peak memory %d MiB)\n", $label, (hrtime(true) - $begin) / 1e6, $lines, memory_get_peak_usage(true) / 1048576);
}

/**
 * @return Generator<int, string>
 */
function chunks(string $chunk, int $count): Generator
{
    for ($i = 0; $i < $count; $i++) {
        yield $chunk;
    }
}

echo "== Case 1: 16 MB without a newline, 4 KiB chunks\n";
timed('LineBuffer (limit raised to hold it)', static function (): int {
    $buffer = new LineBuffer(100_000_000);
    foreach (chunks(str_repeat('a', 4096), 4000) as $chunk) {
        $buffer->feed($chunk);
    }

    return $buffer->pendingBytes() > 0 ? 1 : 0;
});
timed('Amp splitLines()', static function (): int {
    $count = 0;
    foreach (splitLines(new ReadableIterableStream(chunks(str_repeat('a', 4096), 4000))) as $line) {
        ++$count;
    }

    return $count;
});

$text = str_repeat("an ordinary line of text, about fifty characters\n", 1300);
$chunk = substr($text, 0, 65_536);
echo "\n== Case 2: ordinary text, 64 KiB chunks, about 1,300 lines each\n";
timed('LineBuffer', static function () use ($chunk): int {
    $buffer = new LineBuffer();
    $count = 0;
    foreach (chunks($chunk, 150) as $piece) {
        $count += count($buffer->feed($piece));
    }

    return $count;
});
timed('Amp splitLines()', static function () use ($chunk): int {
    $count = 0;
    foreach (splitLines(new ReadableIterableStream(chunks($chunk, 150))) as $line) {
        ++$count;
    }

    return $count;
});
