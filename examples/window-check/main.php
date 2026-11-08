<?php

/**
 * The manual check of the real window (plan P3.6). It opens one window on YOUR session, prints what the window
 * system tells the shim, measures an idle window, and ends when you click the window's close button.
 *
 *   make shim && php examples/window-check/main.php
 *   php examples/window-check/main.php --seconds=300 --trace=/tmp/window-trace.jsonl
 *
 * What to do while it runs is in docs/WINDOW_CHECKLIST.md. Every event is printed and also written, one JSON
 * object per line, to the trace file, which is what you attach to the result.
 */

declare(strict_types=1);

use Psr\Log\NullLogger;
use Raphp\Ui\Ffi\Loader;
use Raphp\Ui\Ffi\Shim;
use Raphp\Ui\Ffi\ShimEvent;
use Revolt\EventLoop;

require __DIR__ . '/../../vendor/autoload.php';

$library = __DIR__ . '/../../shim/target/debug/libraphp.so';
$seconds = 180;
$trace = sys_get_temp_dir() . '/raphp-window-check-trace.jsonl';
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--library=')) {
        $library = substr($argument, 10);
    } elseif (str_starts_with($argument, '--trace=')) {
        $trace = substr($argument, 8);
    } elseif (preg_match('/\A--seconds=([1-9][0-9]{0,3})\z/', $argument, $match) === 1) {
        $seconds = (int) $match[1];
    } else {
        fwrite(STDERR, "usage: main.php [--library=PATH] [--trace=PATH] [--seconds=N]\n");

        exit(2);
    }
}

/**
 * How many events of each type were seen.
 */
final class Tally
{
    /** @var array<string, int> */
    public array $counts = [];

    public function add(string $type): void
    {
        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;
    }
}

$started = hrtime(true);
$seen = new Tally();
$traceFile = fopen($trace, 'w');
if ($traceFile === false) {
    fwrite(STDERR, "cannot write the trace file {$trace}\n");

    exit(2);
}

$record = static function (string $kind, array $fields) use ($traceFile, $started): void {
    $line = ['t_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'kind' => $kind] + $fields;
    fwrite($traceFile, json_encode($line, JSON_THROW_ON_ERROR) . "\n");
};

$counters = static function (Shim $shim): array {
    $windows = $shim->diagnostics()['windows'] ?? [];
    $entry = is_array($windows) ? ($windows['1'] ?? []) : [];

    return [
        is_array($entry) && is_int($entry['redraws_requested'] ?? null) ? $entry['redraws_requested'] : 0,
        is_array($entry) && is_int($entry['frames_drawn'] ?? null) ? $entry['frames_drawn'] : 0,
    ];
};

$cpuMillis = static function (): int {
    $usage = getrusage();
    if ($usage === false) {
        return 0;
    }

    $read = static fn(string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;

    return ($read('ru_utime.tv_sec') + $read('ru_stime.tv_sec')) * 1000 + intdiv($read('ru_utime.tv_usec') + $read('ru_stime.tv_usec'), 1000);
};

$shim = (new Loader(new NullLogger()))
    ->load($library, __DIR__ . '/../../shim/include/raphp.h')
    ->start(['backend' => 'window']);

$finish = static function (string $why) use ($shim, $seen, $counters, $record, $trace): never {
    [$requested, $drawn] = $counters($shim);
    echo "\n== Finished ({$why}) ==\n";
    ksort($seen->counts);
    foreach ($seen->counts as $type => $count) {
        echo sprintf("  %-26s %d\n", $type, $count);
    }

    echo "  redraws requested {$requested}, frames drawn {$drawn}\n  trace: {$trace}\n";
    $record('finished', ['why' => $why, 'redraws_requested' => $requested, 'frames_drawn' => $drawn]);
    $shim->close();

    exit(0);
};

$shim->listen(static function (ShimEvent $event) use ($seen, $record, $shim, $finish): void {
    $seen->add($event->type);
    $fields = $event->fields;
    unset($fields['type']);
    echo sprintf("%-24s %s\n", $event->type, json_encode($fields, JSON_THROW_ON_ERROR));
    $record('event', $event->fields);

    if ($event->type === 'window.close_requested') {
        $shim->send([['op' => 'window.destroy', 'id' => 1]]);
        $finish('you closed the window');
    }
});

$shim->send([
    ['op' => 'window.create', 'id' => 1, 'title' => 'Raphp window check', 'width' => 480, 'height' => 320],
    ['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => 'Raphp']],
    ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
]);

echo "A window titled \"Raphp window check\" should now be open (blank white: drawing arrives later).\n"
    . "Follow docs/WINDOW_CHECKLIST.md. Close the window with its close button to end the check.\n"
    . "The idle measurement starts in 4 seconds and takes 5; please leave the window alone for those 9 seconds.\n\n";

EventLoop::delay(4.0, static function () use ($shim, $counters, $cpuMillis, $record): void {
    $before = $counters($shim);
    $cpu = $cpuMillis();
    $wall = hrtime(true);
    echo ">> idle measurement started: (requested, drawn) = " . json_encode($before) . "\n";

    EventLoop::delay(5.0, static function () use ($shim, $counters, $cpuMillis, $record, $before, $cpu, $wall): void {
        $after = $counters($shim);
        $cpuUsed = $cpuMillis() - $cpu;
        $wallMs = (int) ((hrtime(true) - $wall) / 1_000_000);
        $verdict = $before === $after ? 'PASS: no frame was drawn or asked for while idle' : 'FAIL: the idle window drew or asked for frames (did you touch it?)';
        echo ">> idle measurement ended: (requested, drawn) = " . json_encode($after) . ", {$cpuUsed} ms of CPU in {$wallMs} ms (the PHP process, which includes the shim)\n>> {$verdict}\n";
        $record('idle', ['before' => $before, 'after' => $after, 'cpu_ms' => $cpuUsed, 'wall_ms' => $wallMs, 'pass' => $before === $after]);
    });
});

// Once in a while the counters are written to the trace, so that what minimizing or hiding the window does shows.
EventLoop::repeat(2.0, static function () use ($shim, $counters, $record): void {
    [$requested, $drawn] = $counters($shim);
    $record('counters', ['redraws_requested' => $requested, 'frames_drawn' => $drawn]);
});

EventLoop::delay($seconds, static function () use ($finish, $shim, $seconds): void {
    $shim->send([['op' => 'window.destroy', 'id' => 1]]);
    $finish("{$seconds} seconds passed");
});

EventLoop::run();
