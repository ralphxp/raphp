<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use RuntimeException;

/**
 * Runs a throwaway PHP script as a child process, for tests that need a real process: exit
 * codes, signals, parent death.
 *
 * It uses an array command and pipes only, never a shell and never a socket, which is the
 * only reason PHPStan lets test support code call proc_open() (see phpstan.neon). The real
 * worker spawner is a different class (P2.9).
 *
 * The child signals that it is ready by printing a line "READY" (or the marker given to run()). After that, the signals in
 * `$signalsAfterReady` are sent one by one, 200 ms apart.
 */
final class ChildProcess
{
    /**
     * @param list<string> $arguments
     * @param list<int> $signalsAfterReady
     * @param string $readyMarker the text on the child's stdout that means "ready"
     * @param list<string> $phpOptions extra `-d name=value` settings for the child's PHP, as written after -d
     */
    public function run(string $script, array $arguments = [], array $signalsAfterReady = [], float $timeout = 15.0, string $readyMarker = "READY\n", array $phpOptions = []): ChildResult
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', ...array_merge(...array_map(static fn(string $option): array => ['-d', $option], $phpOptions)), $script, ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('could not start ' . $script);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = hrtime(true) / 1e9;
        $ready = false;
        $sent = 0;
        $nextSignalAt = 0.0;
        $exitCode = null;
        $timedOut = false;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 20_000) !== false) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65_536);
                    if ($chunk === false) {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            $now = hrtime(true) / 1e9;
            if (!$ready && str_contains($stdout, $readyMarker)) {
                $ready = true;
                $nextSignalAt = $now + 0.1;
            }

            if ($ready && $sent < count($signalsAfterReady) && $now >= $nextSignalAt) {
                proc_terminate($process, $signalsAfterReady[$sent]);
                $sent++;
                $nextSignalAt = $now + 0.2;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];

                break;
            }

            if ($now - $started > $timeout) {
                proc_terminate($process, SIGKILL);
                $timedOut = true;

                break;
            }
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return new ChildResult($exitCode === -1 ? null : $exitCode, $stdout, $stderr, hrtime(true) / 1e9 - $started, $timedOut);
    }
}
