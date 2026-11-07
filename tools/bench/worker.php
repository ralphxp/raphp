<?php

/**
 * The worker the benchmark calls (started by tools/bench/bench.php, never by hand).
 * Methods: echo (returns its parameters), stats (this process's memory: resident, private resident, PHP heap).
 */

declare(strict_types=1);

use Psr\Log\NullLogger;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Worker\WorkerArguments;
use Raphp\Worker\WorkerRuntime;

require __DIR__ . '/../../vendor/autoload.php';

try {
    $arguments = WorkerArguments::parse($argv ?? []);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(2);
}

/**
 * @return array{rss: int, peak_rss: int, anon: int}
 */
function ownResidentKilobytes(): array
{
    $rss = 0;
    $peak = 0;
    $anon = 0;
    $lines = file('/proc/self/status', FILE_IGNORE_NEW_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        if (preg_match('/\A(VmRSS|VmHWM|RssAnon):\s+(\d+) kB/', $line, $match) === 1) {
            match ($match[1]) {
                'VmRSS' => $rss = (int) $match[2],
                'VmHWM' => $peak = (int) $match[2],
                default => $anon = (int) $match[2],
            };
        }
    }

    return ['rss' => $rss, 'peak_rss' => $peak, 'anon' => $anon];
}

$handlers = new HandlerRegistry();
$handlers->register('echo', static fn(RpcRequest $request): array => $request->params);
$handlers->register('stats', static function (): array {
    gc_collect_cycles(); // so that what is reported is what is still held, not garbage waiting to be collected

    return ownResidentKilobytes() + ['usage' => memory_get_usage(false), 'real' => memory_get_usage(true), 'heap_peak' => memory_get_peak_usage(true)];
});

// The heartbeat is on in the master (2 s, three misses, the supervised default); the worker needs no options.
exit((new WorkerRuntime(new NullLogger(), new RpcPeerOptions()))->run($arguments, $handlers));
