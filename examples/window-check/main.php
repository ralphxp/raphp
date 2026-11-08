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
$lastEventAt = $started;
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

$lastMoveShown = 0;
$shim->listen(static function (ShimEvent $event) use ($seen, $record, $shim, $finish, &$lastEventAt, &$lastMoveShown): void {
    $lastEventAt = hrtime(true);
    $seen->add($event->type);
    $fields = $event->fields;
    unset($fields['type']);
    // Moving the pointer makes dozens of events a second: all go to the trace, a few a second to the screen.
    if ($event->type !== 'pointer.move' || hrtime(true) - $lastMoveShown > 250_000_000) {
        echo sprintf("%-24s %s\n", $event->type, json_encode($fields, JSON_THROW_ON_ERROR));
        $lastMoveShown = $event->type === 'pointer.move' ? hrtime(true) : $lastMoveShown;
    }

    $record('event', $event->fields);

    if ($event->type === 'pointer.down' && ($event->fields['button'] ?? null) === 'right') {
        $x = $event->fields['x'] ?? null;
        $y = $event->fields['y'] ?? null;
        if ((is_int($x) || is_float($x)) && (is_int($y) || is_float($y))) {
            $shim->send([['op' => 'overlay.show', 'window' => 1, 'widget' => 30, 'x' => $x, 'y' => $y]]);
        }
    }

    // The Save button opens a dropdown menu under it. Choosing an item closes the menu (the shim does that and says
    // `overlay.dismissed` with `activated`); nothing is needed here for it.
    if ($event->type === 'click' && ($event->fields['widget'] ?? null) === 5 && ($event->fields['button'] ?? null) !== 'keyboard') {
        $shim->send([['op' => 'overlay.show', 'window' => 1, 'widget' => 40, 'anchor' => 5]]);
    }

    if ($event->type === 'window.close_requested') {
        $shim->send([['op' => 'window.destroy', 'id' => 1]]);
        $finish('you closed the window');
    }
});

$shim->send([
    ['op' => 'window.create', 'id' => 1, 'title' => 'Raphp window check', 'width' => 480, 'height' => 440],
    // A small form, so that what is drawn can be looked at (nothing here reacts to the mouse yet: that is P3.9 and P3.10).
    ['op' => 'widget.create', 'id' => 1, 'type' => 'column', 'props' => ['padding' => 16, 'gap' => 10]],
    ['op' => 'widget.create', 'id' => 2, 'type' => 'label', 'props' => ['text' => 'Raphp window check']],
    ['op' => 'widget.create', 'id' => 3, 'type' => 'row', 'props' => ['gap' => 8]],
    ['op' => 'widget.create', 'id' => 4, 'type' => 'text_input', 'props' => ['text' => 'Ada Lovelace', 'grow' => 1]],
    ['op' => 'widget.create', 'id' => 5, 'type' => 'button', 'props' => ['label' => 'Save']],
    ['op' => 'widget.create', 'id' => 6, 'type' => 'checkbox', 'props' => ['label' => 'Subscribe', 'checked' => true]],
    ['op' => 'widget.create', 'id' => 7, 'type' => 'progress_bar', 'props' => ['value' => 0.6]],
    ['op' => 'widget.create', 'id' => 8, 'type' => 'button', 'props' => ['label' => 'Disabled', 'enabled' => false]],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 2],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 3],
    ['op' => 'widget.append', 'parent' => 3, 'child' => 4],
    ['op' => 'widget.append', 'parent' => 3, 'child' => 5],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 6],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 7],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 8],
    // A list longer than its place, to turn the mouse wheel over.
    ['op' => 'widget.create', 'id' => 9, 'type' => 'scroll_view', 'props' => ['grow' => 1]],
    ['op' => 'widget.create', 'id' => 10, 'type' => 'column'],
    ['op' => 'widget.append', 'parent' => 1, 'child' => 9],
    ['op' => 'widget.append', 'parent' => 9, 'child' => 10],
    ...array_merge(...array_map(
        static fn(int $number): array => [
            ['op' => 'widget.create', 'id' => 10 + $number, 'type' => 'label', 'props' => ['text' => "List item {$number}"]],
            ['op' => 'widget.append', 'parent' => 10, 'child' => 10 + $number],
        ],
        range(1, 15),
    )),
    // A context menu for the overlay layer: right-click anywhere to show it. Choosing an item, Escape or a click outside closes it.
    ['op' => 'widget.create', 'id' => 30, 'type' => 'column'],
    ['op' => 'widget.create', 'id' => 31, 'type' => 'menu_item', 'props' => ['label' => 'Menu item one', 'shortcut' => 'Ctrl+1']],
    ['op' => 'widget.create', 'id' => 32, 'type' => 'menu_item', 'props' => ['label' => 'Menu item two', 'checked' => true]],
    ['op' => 'widget.create', 'id' => 33, 'type' => 'menu_separator'],
    ['op' => 'widget.create', 'id' => 34, 'type' => 'menu_item', 'props' => ['label' => 'Disabled item', 'enabled' => false]],
    ['op' => 'widget.append', 'parent' => 30, 'child' => 31],
    ['op' => 'widget.append', 'parent' => 30, 'child' => 32],
    ['op' => 'widget.append', 'parent' => 30, 'child' => 33],
    ['op' => 'widget.append', 'parent' => 30, 'child' => 34],
    // A dropdown menu, opened by clicking Save: it appears under the button.
    ['op' => 'widget.create', 'id' => 40, 'type' => 'column'],
    ['op' => 'widget.create', 'id' => 41, 'type' => 'menu_item', 'props' => ['label' => 'Save', 'shortcut' => 'Ctrl+S']],
    ['op' => 'widget.create', 'id' => 42, 'type' => 'menu_item', 'props' => ['label' => 'Save as...']],
    ['op' => 'widget.create', 'id' => 43, 'type' => 'menu_separator'],
    ['op' => 'widget.create', 'id' => 44, 'type' => 'menu_item', 'props' => ['label' => 'Quit', 'shortcut' => 'Ctrl+Q']],
    ['op' => 'widget.append', 'parent' => 40, 'child' => 41],
    ['op' => 'widget.append', 'parent' => 40, 'child' => 42],
    ['op' => 'widget.append', 'parent' => 40, 'child' => 43],
    ['op' => 'widget.append', 'parent' => 40, 'child' => 44],
    ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
]);

echo "A window titled \"Raphp window check\" should now be open, showing a small form (it does not react to the mouse yet).\n"
    . "Follow docs/WINDOW_CHECKLIST.md. Close the window with its close button to end the check.\n"
    . "The idle measurement starts once the window has settled (it has drawn a frame and nothing has happened for 2 seconds)\n"
    . "and takes 5 seconds: please leave the window alone from the start until the measurement has ended.\n\n";

// Not after a fixed time: a busy machine can take seconds to put the window up, and a window that is still opening is not idle.
$settleWatcher = '';
$settleWatcher = EventLoop::repeat(0.5, static function () use ($shim, $counters, $cpuMillis, $record, &$lastEventAt, &$settleWatcher, $started): void {
    $quietFor = (hrtime(true) - $lastEventAt) / 1e9;
    if ($counters($shim)[1] < 1 || $quietFor < 2.0) {
        if ((hrtime(true) - $started) / 1e9 > 30.0) {
            EventLoop::cancel($settleWatcher);
            echo ">> the window did not settle within 30 seconds: no idle measurement was made\n";
            $record('idle', ['settled' => false]);
        }

        return;
    }

    EventLoop::cancel($settleWatcher);
    $before = $counters($shim);
    $cpu = $cpuMillis();
    $wall = hrtime(true);
    echo ">> the window has settled; idle measurement started: (requested, drawn) = " . json_encode($before) . "\n";

    EventLoop::delay(5.0, static function () use ($shim, $counters, $cpuMillis, $record, $before, $cpu, $wall): void {
        $after = $counters($shim);
        $cpuUsed = $cpuMillis() - $cpu;
        $wallMs = (int) ((hrtime(true) - $wall) / 1_000_000);
        $verdict = $before === $after ? 'PASS: no frame was drawn or asked for while idle' : 'FAIL: the idle window drew or asked for frames (did you touch it?)';
        echo ">> idle measurement ended: (requested, drawn) = " . json_encode($after) . ", {$cpuUsed} ms of CPU in {$wallMs} ms (the PHP process, which includes the shim)\n>> {$verdict}\n";
        $record('idle', ['before' => $before, 'after' => $after, 'cpu_ms' => $cpuUsed, 'wall_ms' => $wallMs, 'pass' => $before === $after]);

        // Then an animation, for the measurement P3.10 asks for: a progress bar sweeping for a while, drawn by the shim
        // alone. The frames it needs must come no faster than the monitor's refresh rate, and PHP does nothing meanwhile.
        echo ">> animation: a progress bar sweeps for 7 seconds (the first 2 are not counted); leave the window alone\n";
        $shim->send([['op' => 'widget.set', 'id' => 7, 'props' => ['indeterminate' => true]]]);
        EventLoop::delay(2.0, static function () use ($shim, $counters, $cpuMillis, $record): void {
            $before = $counters($shim);
            $cpu = $cpuMillis();
            $wall = hrtime(true);
            EventLoop::delay(5.0, static function () use ($shim, $counters, $cpuMillis, $record, $before, $cpu, $wall): void {
                $after = $counters($shim);
                $seconds = (hrtime(true) - $wall) / 1e9;
                $frames = $after[1] - $before[1];
                $rate = $frames / $seconds;
                $cpuUsed = $cpuMillis() - $cpu;
                $shim->send([['op' => 'widget.set', 'id' => 7, 'props' => ['indeterminate' => false, 'value' => 0.6]]]);
                echo sprintf(">> animation ended: %d frames in %.2f s = %.1f frames a second, %d ms of CPU (the PHP process).\n"
                    . ">> A monitor refreshing at 60 Hz should give about 60 and never more than its refresh rate: tell me your monitor's rate.\n", $frames, $seconds, $rate, $cpuUsed);
                $record('animation', ['frames' => $frames, 'seconds' => round($seconds, 3), 'frames_per_second' => round($rate, 1), 'cpu_ms' => $cpuUsed]);
            });
        });
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
