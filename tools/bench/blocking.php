<?php

/**
 * How long the event loop stalls for common blocking PHP calls, and for their Raphp replacements
 * (plan P2B.5, `docs/BLOCKING_CALLS.md`).
 *
 *   php tools/bench/blocking.php
 *
 * A timer that should tick every millisecond records the longest gap between two ticks while the call
 * runs. Nothing here uses the network.
 */

declare(strict_types=1);

use function Amp\delay;

use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Process\ProcessRunner;
use Revolt\EventLoop;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * @param Closure(): mixed $work
 */
function stall(string $label, string $replacement, Closure $work): void
{
    $last = hrtime(true);
    $longest = 0.0;
    $timer = EventLoop::repeat(0.001, static function () use (&$last, &$longest): void {
        $now = hrtime(true);
        $longest = max($longest, ($now - $last) / 1e6);
        $last = $now;
    });
    delay(0.003);
    $last = hrtime(true);
    $work();
    delay(0.003); // a stall that ended with the work is recorded by the next tick
    EventLoop::cancel($timer);
    printf("  %-52s %8.1f ms   %s\n", $label, $longest, $replacement);
}

EventLoop::queue(static function (): void {
    $dir = '/var/tmp/raphp-blocking-' . getmypid();
    mkdir($dir);
    $big = $dir . '/big';
    file_put_contents($big, random_bytes(32 * 1024 * 1024));
    for ($i = 0; $i < 20_000; $i++) {
        touch(sprintf('%s/f%05d', $dir . '/', $i));
    }

    echo "== Longest stall of the event loop (1 ms timer), on this machine\n";
    echo "  -- blocking built-ins\n";
    stall('usleep(50_000)', 'Amp\delay(0.05)', static fn() => usleep(50_000));
    stall('time_nanosleep(0, 50_000_000)', 'Amp\delay(0.05)', static fn() => time_nanosleep(0, 50_000_000));
    stall("exec('sleep 0.05')", 'ProcessRunner::run()', static fn() => exec('sleep 0.05'));
    stall("shell_exec('sleep 0.05')", 'ProcessRunner::run()', static fn() => shell_exec('sleep 0.05'));
    stall("system('sleep 0.05 > /dev/null')", 'ProcessRunner::run()', static fn() => system('sleep 0.05 > /dev/null'));
    stall("popen('sleep 0.05; echo x') + fgets()", 'ProcessRunner::run()', static function (): void {
        $pipe = popen('sleep 0.05; echo x', 'r');
        if ($pipe !== false) {
            fgets($pipe);
            pclose($pipe);
        }
    });
    stall('file_get_contents() of 32 MiB', 'Filesystem::openRead()', static fn() => file_get_contents($big));
    stall('file_put_contents() of 32 MiB', 'Filesystem::write()', static fn() => file_put_contents($dir . '/copy-a', (string) file_get_contents($big)));
    stall('copy() of 32 MiB', 'none yet (no chunked write)', static fn() => copy($big, $dir . '/copy-b'));
    stall('scandir() of 20,000 entries', 'Filesystem::list()', static fn() => scandir($dir));
    stall('glob() of 20,000 entries', 'Filesystem::list() and a filter', static fn() => glob($dir . '/f*'));
    stall('password_hash() (default cost)', 'a worker (CPU work)', static fn() => password_hash('secret', PASSWORD_DEFAULT));
    stall("hash_pbkdf2('sha256', ..., 200_000)", 'a worker (CPU work)', static fn() => hash_pbkdf2('sha256', 'secret', 'salt', 200_000));
    stall('json_decode() of 8 MB of numbers', 'a worker (CPU work)', static fn() => json_decode('[' . implode(',', range(1, 1_000_000)) . ']'));
    stall('sort() of 1,000,000 numbers', 'a worker (CPU work)', static function (): void {
        $numbers = range(1, 1_000_000);
        shuffle($numbers);
        sort($numbers);
    });

    echo "  -- their replacements\n";
    $fs = new CooperativeFilesystem();
    $runner = new ProcessRunner();
    stall('Amp\delay(0.05)', '', static fn() => delay(0.05));
    stall("ProcessRunner::run(['sleep', '0.05']), the first call", 'loads classes, starts the machinery', static fn() => $runner->run(['sleep', '0.05']));
    stall("ProcessRunner::run(['sleep', '0.05']), later calls", 'the fork is the stall', static fn() => $runner->run(['sleep', '0.05']));
    stall("ProcessRunner::run(['sleep', '0.05']), later calls", 'the fork is the stall', static fn() => $runner->run(['sleep', '0.05']));
    stall('Filesystem::openRead() of 32 MiB, read to the end', '', static function () use ($fs, $big): void {
        $stream = $fs->openRead($big);
        while ($stream->read() !== null) {
            // dropped
        }
    });
    stall('Filesystem::list() of 20,000 entries', '', static fn() => $fs->list($dir));

    foreach (array_diff(scandir($dir) === false ? [] : scandir($dir), ['.', '..']) as $name) {
        unlink($dir . '/' . $name);
    }

    rmdir($dir);
});

EventLoop::run();
