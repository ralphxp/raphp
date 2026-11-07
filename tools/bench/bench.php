<?php

/**
 * Soak and performance baseline (plan P2.13): real master, real worker process, the supervisor's
 * own call path.
 *
 *   php tools/bench/bench.php [--quick] [--json=<file>]
 *   php tools/bench/bench.php --large-concurrency      only: several large messages in flight at once
 *
 * --quick runs a few hundred calls per phase (a smoke test of this script, not a measurement).
 * The exit code is 1 when a memory tolerance is exceeded (the soak, or the plateau check) (see the constants below).
 *
 * The tolerance was fixed BEFORE the first run (docs/BENCHMARKS.md says so). Memory is read from
 * /proc/<pid>/status (VmRSS, and VmHWM for the peak), the same source the supervisor's recycling uses,
 * and from memory_get_usage() on both sides. gc_collect_cycles() runs before each sample so that
 * garbage waiting for the collector is not mistaken for a leak.
 */

declare(strict_types=1);

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

use Psr\Log\NullLogger;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\RpcException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Ipc\Transport\StreamTransport;
use Raphp\Process\ChannelFactory;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SelfCommand;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Supervisor\Supervisor;
use Raphp\Supervisor\WorkerDefinition;
use Revolt\EventLoop;

require __DIR__ . '/../../vendor/autoload.php';

/** Growth allowed in resident memory, per side, over the post-warm-up part of the soak. */
const TOLERANCE_RSS_KIB = 4 * 1024;
/** Growth allowed in memory_get_usage() (what PHP really holds), per side, over the same part. */
const TOLERANCE_USAGE_BYTES = 256 * 1024;
const WORKER = 'bench';

/**
 * @return list<string>
 */
function linesOf(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);

    return $lines === false ? [] : $lines;
}

function floatOf(mixed $value): float
{
    return is_float($value) ? $value : 0.0;
}

function intOf(mixed $value): int
{
    return is_int($value) ? $value : 0;
}

/**
 * @param array<int, float> $values
 */
function maximum(array $values): float
{
    return $values === [] ? 0.0 : max($values);
}

$quick = in_array('--quick', $argv ?? [], true);
$largeMode = in_array('--large-concurrency', $argv ?? [], true);
$jsonPath = null;
foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    }
}

$scale = $quick ? 0.02 : 1.0;

/**
 * @return array{rss: int, peak_rss: int, anon: int}
 */
function residentKilobytes(int $pid): array
{
    $rss = 0;
    $peak = 0;
    $anon = 0;
    foreach (linesOf("/proc/{$pid}/status") as $line) {
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

/**
 * @param array<int, float> $values
 */
function percentile(array $values, float $fraction): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);

    return $values[(int) min(count($values) - 1, floor($fraction * count($values)))];
}

function ms(float $seconds): string
{
    return sprintf('%.3f', $seconds * 1000);
}

function mib(int|float $kib): string
{
    return sprintf('%.1f', $kib / 1024);
}

/**
 * One sample of both sides' memory.
 *
 * @return array{m_rss: int, m_peak: int, m_anon: int, m_usage: int, m_real: int, m_heap_peak: int, w_rss: int, w_peak: int, w_anon: int, w_usage: int, w_real: int, w_heap_peak: int}
 */
function sample(Supervisor $supervisor): array
{
    gc_collect_cycles();
    $master = residentKilobytes(getmypid() === false ? 0 : getmypid());
    $worker = $supervisor->call(WORKER, 'stats', timeout: 10.0);
    if (!is_array($worker)) {
        throw new RuntimeException('the worker answered stats with something else than an array');
    }

    return [
        'm_rss' => $master['rss'],
        'm_peak' => $master['peak_rss'],
        'm_anon' => $master['anon'],
        'm_usage' => memory_get_usage(false),
        'm_real' => memory_get_usage(true),
        'm_heap_peak' => memory_get_peak_usage(true),
        'w_rss' => intOf($worker['rss'] ?? 0),
        'w_peak' => intOf($worker['peak_rss'] ?? 0),
        'w_anon' => intOf($worker['anon'] ?? 0),
        'w_usage' => intOf($worker['usage'] ?? 0),
        'w_real' => intOf($worker['real'] ?? 0),
        'w_heap_peak' => intOf($worker['heap_peak'] ?? 0),
    ];
}

function line(string $text = ''): void
{
    fwrite(STDOUT, $text . "\n");
}

function newSupervisor(float $initialDelay = 0.1): Supervisor
{
    $logger = new NullLogger();
    $supervisor = new Supervisor(new ProcessSpawner(new ChannelFactory(), $logger), $logger);
    $supervisor->register(new WorkerDefinition(
        WORKER,
        (new SelfCommand())->resolve(__DIR__ . '/worker.php'),
        restart: new Raphp\Supervisor\RestartPolicy(initialDelay: $initialDelay),
    ));

    return $supervisor;
}

/**
 * Runs `$total` calls with `$concurrency` of them in flight and returns the latencies in seconds.
 *
 * @return array{seconds: float, latencies: list<float>}
 */
function drive(Supervisor $supervisor, int $total, int $concurrency, string $payload = ''): array
{
    $next = 0;
    $latencies = array_fill(0, $total, 0.0);
    $started = hrtime(true);
    $workers = [];
    for ($lane = 0; $lane < $concurrency; $lane++) {
        $workers[] = async(static function () use ($supervisor, $total, &$next, &$latencies, $payload): void {
            while ($next < $total) {
                $index = $next++;
                $begin = hrtime(true);
                $supervisor->call(WORKER, 'echo', ['n' => $index, 'data' => $payload]);
                $latencies[$index] = (hrtime(true) - $begin) / 1e9;
            }
        });
    }

    await($workers);
    $seconds = (hrtime(true) - $started) / 1e9;

    return ['seconds' => $seconds, 'latencies' => array_values($latencies)];
}

$report = [];
$failed = false;

EventLoop::queue(static function () use ($scale, $quick, $largeMode, &$report, &$failed): void {
    try {
        // ------------------------------------------------------------------ machine
        $cpu = 'unknown';
        foreach (linesOf('/proc/cpuinfo') as $cpuLine) {
            if (str_starts_with($cpuLine, 'model name')) {
                $cpu = trim(substr($cpuLine, (int) strpos($cpuLine, ':') + 1));

                break;
            }
        }

        $memTotal = 0;
        foreach (linesOf('/proc/meminfo') as $memLine) {
            if (preg_match('/\AMemTotal:\s+(\d+) kB/', $memLine, $match) === 1) {
                $memTotal = (int) $match[1];
            }
        }

        $load = sys_getloadavg();
        $machine = [
            'cpu' => $cpu,
            'logical_cpus' => (int) shell_exec('nproc'),
            'ram_gib' => round($memTotal / 1024 / 1024, 1),
            'kernel' => php_uname('r'),
            'php' => PHP_VERSION,
            'opcache_cli' => ini_get('opcache.enable_cli'),
            'jit' => ini_get('opcache.jit'),
            'xdebug' => extension_loaded('xdebug') ? 'loaded' : 'not loaded',
            'load_average_at_start' => $load === false ? null : array_map(static fn(float $v): float => round($v, 2), $load),
            'quick_mode' => $quick,
        ];
        $report['machine'] = $machine;
        line('== Machine');
        foreach ($machine as $key => $value) {
            line(sprintf('  %-22s %s', $key, is_array($value) ? implode(' ', $value) : var_export($value, true)));
        }

        if ($largeMode) {
            ini_set('memory_limit', '-1'); // the master only, so its own peak can be seen; workers keep the php.ini limit
            line('');
            line('== L. Several 4 MiB echoes in flight at once (master memory_limit lifted; each row is a fresh master and worker)');
            line('   the worker keeps the php.ini memory_limit, which is ' . trim((string) shell_exec('php -r "echo ini_get(\'memory_limit\');"')));
            line(sprintf('  %-9s %12s %12s %10s %18s %18s', 'in flight', 'payload sum', 'wall ms', 'failed', 'master PHP peak', 'worker PHP peak'));
            $report['large_concurrency'] = [];
            foreach ([1, 2, 4, 8, 16] as $count) {
                $one = newSupervisor();
                $one->start();
                $one->awaitReady(WORKER);
                $payload = str_repeat('x', 4_194_304);
                $begin = hrtime(true);
                $calls = [];
                for ($i = 0; $i < $count; $i++) {
                    $calls[] = async(static function () use ($one, $payload): bool {
                        try {
                            $one->call(WORKER, 'echo', ['data' => $payload], timeout: 60.0);

                            return true;
                        } catch (RpcException) {
                            return false;
                        }
                    });
                }

                $results = await($calls);
                $wall = (hrtime(true) - $begin) / 1e9;
                $failures = count(array_filter($results, static fn($ok) => $ok === false));
                delay(0.5); // a crashed worker is restarted; ask the one that is there now
                try {
                    $now = sample($one);
                    $workerPeak = mib($now['w_heap_peak'] / 1024) . ' MiB' . ($failures > 0 ? ' (a new process)' : '');
                } catch (RpcException) {
                    $now = ['m_heap_peak' => memory_get_peak_usage(true), 'w_heap_peak' => 0];
                    $workerPeak = 'no answer';
                }

                $report['large_concurrency'][] = ['in_flight' => $count, 'failed' => $failures, 'wall_ms' => ms($wall), 'master_heap_peak' => $now['m_heap_peak'], 'worker_heap_peak' => $now['w_heap_peak']];
                line(sprintf('  %-9d %9d MiB %12s %10d %15s MiB %18s', $count, $count * 4, ms($wall), $failures, mib($now['m_heap_peak'] / 1024), $workerPeak));
                unset($payload, $calls, $results);
                $one->stop();
            }

            $report['result'] = 'PASS';

            return;
        }

        $supervisor = newSupervisor();
        $supervisor->start();
        $supervisor->awaitReady(WORKER);

        // ------------------------------------------------------------------ boot and warm-up
        $boot = sample($supervisor);
        $report['boot'] = $boot;

        // ------------------------------------------------------------------ A. soak, one call at a time
        $soakTotal = max(2000, (int) (50_000 * $scale));
        $warm = intdiv($soakTotal, 10);
        $step = $warm;
        line('');
        line("== A. Soak: {$soakTotal} sequential round trips (small payload); warm-up = first {$warm}");
        line(sprintf('  %-8s %10s %10s %12s %12s   %10s %10s %12s %12s', 'calls', 'master RSS', 'private', 'usage', 'usage(true)', 'worker RSS', 'private', 'usage', 'usage(true)'));
        $all = array_fill(0, $soakTotal, 0.0);
        $snapshots = [];
        $done = 0;
        $started = hrtime(true);
        while ($done < $soakTotal) {
            $chunk = min($step, $soakTotal - $done);
            $result = drive($supervisor, $chunk, 1);
            foreach ($result['latencies'] as $i => $latency) {
                $all[$done + $i] = $latency;
            }

            $done += $chunk;
            $s = sample($supervisor);
            $snapshots[$done] = $s;
            line(sprintf(
                '  %-8d %8s MiB %7s MiB %9d KiB %9s MiB   %7s MiB %7s MiB %9d KiB %9s MiB',
                $done,
                mib($s['m_rss']),
                mib($s['m_anon']),
                intdiv($s['m_usage'], 1024),
                mib($s['m_real'] / 1024),
                mib($s['w_rss']),
                mib($s['w_anon']),
                intdiv($s['w_usage'], 1024),
                mib($s['w_real'] / 1024),
            ));
        }

        $soakSeconds = (hrtime(true) - $started) / 1e9;
        $first = $snapshots[$warm];
        $last = $snapshots[$soakTotal];
        $growth = [
            'm_rss_kib' => $last['m_rss'] - $first['m_rss'],
            'w_rss_kib' => $last['w_rss'] - $first['w_rss'],
            'm_anon_kib' => $last['m_anon'] - $first['m_anon'],
            'w_anon_kib' => $last['w_anon'] - $first['w_anon'],
            'm_usage_bytes' => $last['m_usage'] - $first['m_usage'],
            'w_usage_bytes' => $last['w_usage'] - $first['w_usage'],
        ];
        $sequential = $all;
        $idleP50 = percentile($sequential, 0.50);
        $report['soak'] = [
            'calls' => $soakTotal,
            'warmup' => $warm,
            'seconds' => round($soakSeconds, 2),
            'calls_per_second' => round($soakTotal / $soakSeconds),
            'latency_ms' => ['p50' => ms($idleP50), 'p95' => ms(percentile($sequential, 0.95)), 'p99' => ms(percentile($sequential, 0.99)), 'max' => ms(maximum($sequential))],
            'growth_after_warmup' => $growth,
            'snapshots' => $snapshots,
        ];
        unset($all, $sequential);
        $latency = $report['soak']['latency_ms'];
        line(sprintf('  %d calls in %.1f s = %d calls/s; latency ms: p50 %s, p95 %s, p99 %s, max %s', $soakTotal, $soakSeconds, $soakTotal / $soakSeconds, $latency['p50'], $latency['p95'], $latency['p99'], $latency['max']));
        $verdicts = [
            ['master resident memory', $growth['m_rss_kib'], TOLERANCE_RSS_KIB, 'KiB'],
            ['worker resident memory', $growth['w_rss_kib'], TOLERANCE_RSS_KIB, 'KiB'],
            ['master private resident memory', $growth['m_anon_kib'], TOLERANCE_RSS_KIB, 'KiB'],
            ['worker private resident memory', $growth['w_anon_kib'], TOLERANCE_RSS_KIB, 'KiB'],
            ['master memory_get_usage()', $growth['m_usage_bytes'], TOLERANCE_USAGE_BYTES, 'B'],
            ['worker memory_get_usage()', $growth['w_usage_bytes'], TOLERANCE_USAGE_BYTES, 'B'],
        ];
        foreach ($verdicts as [$what, $value, $limit, $unit]) {
            $ok = $value <= $limit;
            $failed = $failed || !$ok;
            line(sprintf('  growth after warm-up, %-27s %+8d %-3s (tolerance %d)  %s', $what, $value, $unit, $limit, $ok ? 'PASS' : 'FAIL'));
        }

        // ------------------------------------------------------------------ B. concurrency
        $each = max(500, (int) (20_000 * $scale));
        line('');
        line("== B. Concurrency: {$each} calls per level (the in-flight cap is " . RpcPeerOptions::DEFAULT_MAX_IN_FLIGHT . ', the waiting list ' . RpcPeerOptions::DEFAULT_MAX_QUEUED_CALLS . ')');
        line(sprintf('  %-12s %12s %10s %10s %10s %10s', 'in flight', 'calls/s', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms'));
        $before = sample($supervisor);
        $report['concurrency'] = [];
        foreach ([1, 4, 16, 64, 256, 1000] as $level) {
            $result = drive($supervisor, $each, $level);
            $rate = $each / $result['seconds'];
            $row = ['level' => $level, 'calls_per_second' => round($rate), 'p50' => ms(percentile($result['latencies'], 0.5)), 'p95' => ms(percentile($result['latencies'], 0.95)), 'p99' => ms(percentile($result['latencies'], 0.99)), 'max' => ms(maximum($result['latencies']))];
            $report['concurrency'][] = $row;
            line(sprintf('  %-12d %12d %10s %10s %10s %10s', $level, $rate, $row['p50'], $row['p95'], $row['p99'], $row['max']));
        }

        $after = sample($supervisor);
        $report['concurrency_memory'] = ['before' => $before, 'after' => $after];
        line(sprintf(
            '  after all levels: master RSS %s MiB (%+d KiB), usage %+d B; worker RSS %s MiB (%+d KiB), usage %+d B (reported, not asserted)',
            mib($after['m_rss']),
            $after['m_rss'] - $before['m_rss'],
            $after['m_usage'] - $before['m_usage'],
            mib($after['w_rss']),
            $after['w_rss'] - $before['w_rss'],
            $after['w_usage'] - $before['w_usage'],
        ));

        // ------------------------------------------------------------------ C. large payloads
        line('');
        line('== C. Large payloads: echo of a string (sent, then received back). The line limit is 8 MiB.');
        line(sprintf('  %-12s %5s %11s %12s %16s %16s %16s', 'payload', 'reps', 'median ms', 'MiB/s (both)', 'master PHP peak', 'worker PHP peak', 'master peak RSS'));
        $report['payloads'] = [];
        foreach ([[1024, 300], [65_536, 200], [1_048_576, 40], [4_194_304, 20], [7_340_032, 12], [8_388_608 - 4096, 8]] as [$size, $reps]) {
            $reps = max(3, (int) ($reps * max($scale, 0.1)));
            $payload = str_repeat('x', $size);
            $times = [];
            for ($i = 0; $i < $reps; $i++) {
                $begin = hrtime(true);
                $supervisor->call(WORKER, 'echo', ['data' => $payload], timeout: 30.0);
                $times[] = (hrtime(true) - $begin) / 1e9;
            }

            $median = percentile($times, 0.5);
            $now = sample($supervisor);
            $row = ['bytes' => $size, 'reps' => $reps, 'median_ms' => ms($median), 'mib_per_s' => round(2 * $size / 1048576 / $median, 1), 'master_peak_kib' => $now['m_peak'], 'worker_peak_kib' => $now['w_peak'], 'master_heap_peak_bytes' => $now['m_heap_peak'], 'worker_heap_peak_bytes' => $now['w_heap_peak']];
            $report['payloads'][] = $row;
            line(sprintf('  %-12s %5d %11s %12s %13s MiB %13s MiB %13s MiB', $size >= 1048576 ? round($size / 1048576, 2) . ' MiB' : ($size / 1024) . ' KiB', $reps, $row['median_ms'], $row['mib_per_s'], mib($now['m_heap_peak'] / 1024), mib($now['w_heap_peak'] / 1024), mib($now['m_peak'])));
        }

        unset($payload);
        drive($supervisor, 1000, 1);
        $retained = sample($supervisor);
        $report['after_payloads'] = $retained;
        line(sprintf(
            '  after the largest payloads and 1,000 small calls: master RSS %s MiB (usage %d KiB), worker RSS %s MiB (usage %d KiB); boot was %s and %s MiB',
            mib($retained['m_rss']),
            intdiv($retained['m_usage'], 1024),
            mib($retained['w_rss']),
            intdiv($retained['w_usage'], 1024),
            mib($boot['m_rss']),
            mib($boot['w_rss']),
        ));

        try {
            $supervisor->call(WORKER, 'echo', ['data' => str_repeat('x', 9 * 1048576)], timeout: 30.0);
            $refusal = 'NOT REFUSED';
        } catch (PayloadTooLargeException $exception) {
            $refusal = $exception->wireCode();
        } catch (RpcException $exception) {
            $refusal = 'other: ' . $exception->wireCode();
        }

        $stillAlive = $supervisor->call(WORKER, 'echo', ['ok' => true], timeout: 10.0) === ['ok' => true];
        $report['oversize'] = ['refusal' => $refusal, 'connection_still_works' => $stillAlive];
        line("  a 9 MiB payload is refused with {$refusal}; the connection still works afterwards: " . ($stillAlive ? 'yes' : 'NO'));

        // ------------------------------------------------------------------ D. head-of-line: small calls during a large transfer
        line('');
        line('== D. Small calls while a 7 MiB echo is in flight (one ordered stream: R19 numbers)');
        $big = str_repeat('x', 7_340_032);
        $during = [];
        $transfers = [];
        $rounds = max(3, (int) (8 * max($scale, 0.4)));
        for ($round = 0; $round < $rounds; $round++) {
            $finished = false;
            $transfer = async(static function () use ($supervisor, $big, &$finished): float {
                $begin = hrtime(true);
                $supervisor->call(WORKER, 'echo', ['data' => $big], timeout: 30.0);
                $finished = true;

                return (hrtime(true) - $begin) / 1e9;
            });
            delay(0.002); // let the transfer start
            while (!$finished) {
                $begin = hrtime(true);
                $supervisor->call(WORKER, 'echo', ['n' => 1], timeout: 30.0);
                $during[] = (hrtime(true) - $begin) / 1e9;
            }

            $transfers[] = floatOf($transfer->await());
        }

        unset($big);
        $report['head_of_line'] = [
            'rounds' => $rounds,
            'transfer_ms_median' => ms(percentile($transfers, 0.5)),
            'small_calls_completed_during' => count($during),
            'small_ms' => ['p50' => ms(percentile($during, 0.5)), 'p95' => ms(percentile($during, 0.95)), 'max' => ms(maximum($during))],
            'idle_small_p50_ms' => ms($idleP50),
        ];
        $hol = $report['head_of_line'];
        line(sprintf(
            '  %d transfers of 7 MiB, median %s ms each; %d small calls completed during them: p50 %s ms, p95 %s ms, max %s ms (idle p50 was %s ms)',
            $rounds,
            $hol['transfer_ms_median'],
            $hol['small_calls_completed_during'],
            $hol['small_ms']['p50'],
            $hol['small_ms']['p95'],
            $hol['small_ms']['max'],
            $hol['idle_small_p50_ms'],
        ));
        line('  (a ping or pong shares this stream and queue with these calls; its own round trip is not measured separately)');

        // ------------------------------------------------------------------ G. plateau: the same bursts again
        line('');
        line('== G. Plateau: the bursts of B and C repeated twice more (high-water memory must level off, not keep growing)');
        $plateau = [sample($supervisor)];
        for ($round = 1; $round <= 2; $round++) {
            drive($supervisor, max(500, (int) (5000 * $scale)), 256);
            drive($supervisor, max(500, (int) (5000 * $scale)), 1000);
            $bigPayload = str_repeat('x', 7_340_032);
            for ($i = 0; $i < 5; $i++) {
                $supervisor->call(WORKER, 'echo', ['data' => $bigPayload], timeout: 30.0);
            }

            unset($bigPayload);
            drive($supervisor, 1000, 1);
            $plateau[] = sample($supervisor);
        }

        foreach ($plateau as $index => $point) {
            line(sprintf('  %-18s master RSS %s MiB, private %s MiB, usage(true) %s MiB, usage %d KiB   worker RSS %s MiB, private %s MiB', $index === 0 ? 'before the repeats' : "after repeat {$index}", mib($point['m_rss']), mib($point['m_anon']), mib($point['m_real'] / 1024), intdiv($point['m_usage'], 1024), mib($point['w_rss']), mib($point['w_anon'])));
        }

        $plateauGrowth = ['m_rss_kib' => $plateau[2]['m_rss'] - $plateau[1]['m_rss'], 'w_rss_kib' => $plateau[2]['w_rss'] - $plateau[1]['w_rss'], 'm_usage_bytes' => $plateau[2]['m_usage'] - $plateau[1]['m_usage']];
        $report['plateau'] = ['points' => $plateau, 'growth_between_repeat_1_and_2' => $plateauGrowth];
        foreach ([['master resident memory', $plateauGrowth['m_rss_kib'], TOLERANCE_RSS_KIB, 'KiB'], ['worker resident memory', $plateauGrowth['w_rss_kib'], TOLERANCE_RSS_KIB, 'KiB'], ['master memory_get_usage()', $plateauGrowth['m_usage_bytes'], TOLERANCE_USAGE_BYTES, 'B']] as [$what, $value, $limit, $unit]) {
            $ok = $value <= $limit;
            $failed = $failed || !$ok;
            line(sprintf('  growth from repeat 1 to repeat 2, %-27s %+8d %-3s (tolerance %d)  %s', $what, $value, $unit, $limit, $ok ? 'PASS' : 'FAIL'));
        }

        // ------------------------------------------------------------------ final master memory (R20)
        $final = sample($supervisor);
        $report['final'] = $final;
        line('');
        line('== Master memory over the whole run (R20 baseline)');
        line(sprintf('  boot:  RSS %s MiB, usage %d KiB, usage(true) %s MiB', mib($boot['m_rss']), intdiv($boot['m_usage'], 1024), mib($boot['m_real'] / 1024)));
        line(sprintf('  end:   RSS %s MiB, usage %d KiB, usage(true) %s MiB, peak RSS %s MiB', mib($final['m_rss']), intdiv($final['m_usage'], 1024), mib($final['m_real'] / 1024), mib($final['m_peak'])));

        $supervisor->stop();

        // ------------------------------------------------------------------ E. read chunk size, in one process
        line('');
        line('== E. Read chunk size (both ends in this process, 4 MiB echo, median of ' . max(5, (int) (15 * max($scale, 0.4))) . ')');
        line(sprintf('  %-12s %12s', 'chunk', 'median ms'));
        $report['chunk'] = [];
        foreach ([4096, 16_384, 65_536, 262_144, 1_048_576] as $chunk) {
            $channel = (new ChannelFactory())->create();
            $reader = new EnvelopeReader();
            $codec = new JsonLineCodec();
            $handlers = new HandlerRegistry();
            $handlers->register('echo', static fn(RpcRequest $request): array => $request->params);
            $server = new RpcPeer(StreamTransport::fromResource($channel->child, $chunk), $codec, $reader, new NullLogger(), $handlers, new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'worker', 'e-server', 1));
            $client = new RpcPeer(StreamTransport::fromResource($channel->parent, $chunk), $codec, $reader, new NullLogger(), new HandlerRegistry(), new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'e-client', 2));
            $server->start();
            $client->start();
            $client->awaitHandshake();
            $payload = str_repeat('x', 4_194_304);
            $times = [];
            for ($i = 0, $n = max(5, (int) (15 * max($scale, 0.4))); $i < $n; $i++) {
                $begin = hrtime(true);
                $client->call('echo', ['data' => $payload], 30.0);
                $times[] = (hrtime(true) - $begin) / 1e9;
            }

            $client->close();
            $server->close();
            $report['chunk'][] = ['bytes' => $chunk, 'median_ms' => ms(percentile($times, 0.5))];
            line(sprintf('  %-12s %12s', ($chunk / 1024) . ' KiB', ms(percentile($times, 0.5))));
        }

        // ------------------------------------------------------------------ F. start-up and restart times
        $spawns = $quick ? 3 : 10;
        line('');
        line("== F. Worker start-up: {$spawns} cold starts (start() to ready), then 5 first-crash restarts for each wait setting (SIGKILL to a call answered by the new process)");
        $ready = [];
        for ($i = 0; $i < $spawns; $i++) {
            $one = newSupervisor();
            $begin = hrtime(true);
            $one->start();
            $one->awaitReady(WORKER);
            $ready[] = (hrtime(true) - $begin) / 1e9;
            $one->stop();
        }

        $restartRows = [];
        foreach ([['no wait (initialDelay 0)', 0.0], ['default wait (0.1 s)', 0.1]] as [$label, $delayBefore]) {
            $times = [];
            for ($i = 0; $i < ($quick ? 2 : 5); $i++) {
                $target = newSupervisor($delayBefore); // a fresh one each time: the first crash, not a repeated one
                $target->start();
                $target->awaitReady(WORKER);
                $pid = array_values($target->pids(WORKER))[0] ?? 0;
                $begin = hrtime(true);
                posix_kill($pid, SIGKILL);
                while (true) {
                    try {
                        $answer = $target->call(WORKER, 'stats', timeout: 5.0);
                        $newPid = array_values($target->pids(WORKER))[0] ?? 0;
                        if (is_array($answer) && $newPid !== $pid && $newPid !== 0) {
                            break;
                        }
                    } catch (RpcException) {
                        // the old connection is gone or the new worker is not ready yet; ask again
                    }

                    delay(0.002);
                }

                $times[] = (hrtime(true) - $begin) / 1e9;
                $target->stop();
            }

            $restartRows[] = ['label' => $label, 'median_ms' => ms(percentile($times, 0.5)), 'max_ms' => ms(maximum($times))];
        }

        $report['startup'] = ['cold_start_ms' => ['median' => ms(percentile($ready, 0.5)), 'max' => ms(maximum($ready))], 'restart' => $restartRows];
        line(sprintf('  cold start to ready: median %s ms, max %s ms', ms(percentile($ready, 0.5)), ms(maximum($ready))));
        foreach ($restartRows as $row) {
            line(sprintf('  restart after SIGKILL, %-26s median %s ms, max %s ms', $row['label'] . ':', $row['median_ms'], $row['max_ms']));
        }

        line('');
        line($failed ? 'RESULT: FAIL, a memory tolerance was exceeded' : 'RESULT: PASS, the soak and the plateau stayed within the memory tolerance');
        $report['result'] = $failed ? 'FAIL' : 'PASS';
    } catch (Throwable $exception) {
        fwrite(STDERR, 'the benchmark failed: ' . $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
        $failed = true;
        $report['result'] = 'ERROR';
    }
});

EventLoop::run();

if ($jsonPath !== null) {
    file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
}

exit($failed ? 1 : 0);
