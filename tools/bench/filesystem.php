<?php

/**
 * How long the event loop stalls while a large file is read (plan P2B.1), and what the slice length
 * costs in speed.
 *
 *   php tools/bench/filesystem.php [--mib=32] [--dir=/path ...]
 *
 * A timer that should tick every millisecond records the longest gap between two ticks. The cache is
 * dropped for the file before the "cold" rows with `dd iflag=nocache` (no root needed); if that does
 * not work on the filesystem, the cold rows say so.
 */

declare(strict_types=1);

use function Amp\delay;

use Raphp\Filesystem\CooperativeFilesystem;
use Revolt\EventLoop;

require __DIR__ . '/../../vendor/autoload.php';

$mib = 32;
$dirs = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (preg_match('/\A--mib=([1-9][0-9]{0,3})\z/', $argument, $match) === 1) {
        $mib = (int) $match[1];
    } elseif (str_starts_with($argument, '--dir=')) {
        $dirs[] = substr($argument, 6);
    }
}

if ($dirs === []) {
    $dirs = [sys_get_temp_dir(), '/var/tmp'];
}

/**
 * @param Closure(): mixed $work
 *
 * @return array{total_ms: float, ticks: int, longest_ms: float}
 */
function watch(Closure $work): array
{
    $last = hrtime(true);
    $longest = 0.0;
    $ticks = 0;
    $timer = EventLoop::repeat(0.001, static function () use (&$last, &$longest, &$ticks): void {
        $now = hrtime(true);
        $longest = max($longest, ($now - $last) / 1e6);
        $last = $now;
        ++$ticks;
    });
    delay(0.003);
    $last = hrtime(true);
    $begin = hrtime(true);
    $work();
    $total = (hrtime(true) - $begin) / 1e6;
    delay(0.003); // a stall that ended with the work is recorded by the next tick
    EventLoop::cancel($timer);

    return ['total_ms' => $total, 'ticks' => $ticks, 'longest_ms' => $longest];
}

function dropCache(string $file): bool
{
    exec('sync ' . escapeshellarg($file) . ' 2>&1; dd if=' . escapeshellarg($file) . ' iflag=nocache count=0 2>&1', $output, $code);

    return $code === 0;
}

/**
 * @param array{total_ms: float, ticks: int, longest_ms: float} $result
 */
function row(string $label, array $result): void
{
    printf("  %-44s %9.1f ms %6d ticks   longest stall %7.1f ms\n", $label, $result['total_ms'], $result['ticks'], $result['longest_ms']);
}

EventLoop::queue(static function () use ($mib, $dirs): void {
    echo "== Filesystem: loop stalls while a {$mib} MiB file is read (timer every 1 ms)\n";
    foreach ($dirs as $dir) {
        $file = $dir . '/raphp-fsbench-' . getmypid();
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            echo "  cannot write in {$dir}, skipped\n";

            continue;
        }

        $block = random_bytes(1024 * 1024);
        for ($i = 0; $i < $mib; $i++) {
            fwrite($handle, $block);
        }

        fclose($handle);
        unset($block);
        echo "\n-- {$dir} (" . trim((string) shell_exec('stat -f -c %T ' . escapeshellarg($dir))) . ")\n";

        foreach (['hot cache' => false, 'cold cache' => true] as $state => $cold) {
            if ($cold && !dropCache($file)) {
                echo "  cold cache: could not drop the cache for this file here, skipped\n";

                continue;
            }

            echo "  [{$state}]\n";
            foreach ([0.0, 0.0005, 0.002, 0.010, 0.050] as $slice) {
                if ($cold) {
                    dropCache($file);
                }

                $fs = new CooperativeFilesystem(slice: $slice);
                $result = watch(static function () use ($fs, $file): void {
                    $stream = $fs->openRead($file);
                    while ($stream->read() !== null) {
                        // dropped on purpose: only the stalls matter here
                    }
                });
                row(sprintf('openRead(), slice %.1f ms%s', $slice * 1000, $slice === CooperativeFilesystem::DEFAULT_SLICE ? ' (default)' : ''), $result);
            }

            if ($cold) {
                dropCache($file);
            }

            row('read() (the whole file as a string)', watch(static function () use ($file): void {
                (new CooperativeFilesystem())->read($file);
            }));
            if ($cold) {
                dropCache($file);
            }

            row('file_get_contents() (the built-in)', watch(static function () use ($file): void {
                file_get_contents($file);
            }));
            if ($cold) {
                dropCache($file);
            }

            row('fread() loop, 64 KiB, no yield', watch(static function () use ($file): void {
                $in = fopen($file, 'rb');
                while ($in !== false && !feof($in)) {
                    fread($in, 65_536);
                }
            }));
        }

        unlink($file);
    }
});

EventLoop::run();
