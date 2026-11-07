<?php

/**
 * A large file is read while a timer keeps ticking (checkpoint 2B).
 *
 *   php examples/large-file/main.php                  # streams the file with Raphp's filesystem service
 *   php examples/large-file/main.php --blocking       # the same read with a plain fread() loop, for comparison
 *   php examples/large-file/main.php --mib=512 --dir=/var/tmp
 *
 * A timer that should tick every millisecond records the longest gap between two ticks, which is how long
 * the event loop was stuck. Once a quarter of a second a status line shows how far the read has got.
 */

declare(strict_types=1);

use function Amp\delay;

use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Stream\ProgressStream;
use Revolt\EventLoop;

require __DIR__ . '/../../vendor/autoload.php';

$mib = 512;
$dir = sys_get_temp_dir();
$blocking = false;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/\A--mib=([1-9][0-9]{0,3})\z/', $argument, $match) === 1) {
        $mib = (int) $match[1];
    } elseif (str_starts_with($argument, '--dir=')) {
        $dir = substr($argument, 6);
    } elseif ($argument === '--blocking') {
        $blocking = true;
    } else {
        fwrite(STDERR, "usage: main.php [--blocking] [--mib=N] [--dir=PATH]\n");

        exit(2);
    }
}

EventLoop::queue(static function () use ($mib, $dir, $blocking): void {
    $file = $dir . '/raphp-large-file-' . getmypid();
    $handle = fopen($file, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "cannot write {$file}\n");

        return;
    }

    $block = random_bytes(1024 * 1024);
    for ($i = 0; $i < $mib; $i++) {
        fwrite($handle, $block);
    }

    fclose($handle);
    unset($block);
    $total = $mib * 1024 * 1024;

    $ticks = 0;
    $longest = 0.0;
    $last = hrtime(true);
    $ticker = EventLoop::repeat(0.001, static function () use (&$ticks, &$longest, &$last): void {
        $now = hrtime(true);
        $longest = max($longest, ($now - $last) / 1e6);
        $last = $now;
        ++$ticks;
    });

    $read = 0;
    $status = EventLoop::repeat(0.25, static function () use (&$read, &$ticks, &$longest, $total): void {
        printf("  %4d / %d MiB read, %6d ticks so far, longest gap %6.1f ms\n", $read / 1048576, $total / 1048576, $ticks, $longest);
    });

    echo $blocking
        ? "Reading {$mib} MiB with a plain fread() loop (blocking: the loop cannot run in between)...\n"
        : "Reading {$mib} MiB with Raphp's Filesystem::openRead() (the loop runs between slices)...\n";
    // Everything is opened before the clock starts, so that loading classes is not counted as a stall.
    $in = $blocking ? fopen($file, 'rb') : false;
    $stream = $blocking ? null : new ProgressStream((new CooperativeFilesystem())->openRead($file), static function (int $bytes) use (&$read): void {
        $read = $bytes;
    }, $total, minInterval: 0.0);
    delay(0.005);
    $last = hrtime(true);
    $begin = hrtime(true);

    if ($stream === null) {
        while ($in !== false && !feof($in)) {
            $chunk = fread($in, 65_536);
            $read += $chunk === false ? 0 : strlen($chunk);
        }
    } else {
        while ($stream->read() !== null) {
            // reading to the end; the progress callback keeps $read current
        }
    }

    $seconds = (hrtime(true) - $begin) / 1e9;
    delay(0.005); // a stall that ended with the read is recorded by the next tick
    EventLoop::cancel($ticker);
    EventLoop::cancel($status);
    unlink($file);

    printf("Done: %d MiB in %.2f s (%.0f MiB/s). The 1 ms timer ticked %d times; the longest gap between ticks was %.1f ms.\n", $read / 1048576, $seconds, $read / 1048576 / $seconds, $ticks, $longest);
});

EventLoop::run();
