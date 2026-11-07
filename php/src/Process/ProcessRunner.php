<?php

declare(strict_types=1);

namespace Raphp\Process;

use function Amp\async;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;

use function Amp\delay;
use function Amp\Future\await;

use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use Raphp\Process\Internal\ExecutableFinder;
use Raphp\Process\Internal\RunningCommand;
use Throwable;

/**
 * Runs the commands an application needs (`git`, `tar`, a converter), over `amphp/process`
 * (FRAMEWORK 5.1). It is **not** how Raphp starts its own workers: those go through
 * {@see ProcessSpawner} only (decision D18), because a worker needs a channel, a death signal and a
 * supervisor, none of which a command has.
 *
 * - **No shell.** A command is a list: the program and its arguments, each passed as it is. Nothing is
 *   split, expanded or quoted. A missing program is reported by name before anything starts.
 * - **Its own session.** Each command runs under `setsid`, as the leader of a new process group, so that
 *   stopping it stops everything it started. Without that, signalling a command that runs children (a
 *   shell script, `make`) would leave them running (finding F53). A command is also no longer in the
 *   terminal's process group: Ctrl-C does not reach it, and the application decides what to do (cancel
 *   the call, or {@see self::stopAll()}).
 * - **Output** is read from both streams at once, so a command that writes a lot to one cannot block on
 *   the other. It can be captured (bounded: more than `maxOutputBytes` per stream stops the command),
 *   passed to a callback as it arrives, or both.
 * - **Stopping.** Cancellation, a timeout, too much output, a failing callback or {@see self::stopAll()}
 *   send SIGTERM to the whole group, wait up to `terminateGrace` seconds for it to empty, then SIGKILL.
 * - **Descriptors.** `amphp/process` hands the command only descriptors 0 to 2, so a command cannot
 *   inherit the master's sockets.
 *
 * Commands are not tied to the master's life: if the master is killed outright (SIGKILL) a running
 * command keeps running. Call {@see self::stopAll()} from a shutdown hook for an orderly end.
 */
final class ProcessRunner
{
    /** Output kept per stream before the command is stopped (my number: unmeasured). */
    public const int DEFAULT_MAX_OUTPUT_BYTES = 16_777_216;

    public const float DEFAULT_TERMINATE_GRACE = 2.0;

    /** Seconds the output pipes are given to close after the command has ended. */
    public const float DEFAULT_OUTPUT_GRACE = 1.0;

    /** @var array<int, RunningCommand> */
    private array $running = [];

    /**
     * @param int $maxOutputBytes captured output allowed per stream
     * @param float $terminateGrace seconds a group has to end after SIGTERM before SIGKILL
     * @param float $outputGrace seconds the output pipes have to close after the command ended
     *
     * @throws InvalidArgumentException when a value is not usable
     */
    public function __construct(
        private readonly int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private readonly float $terminateGrace = self::DEFAULT_TERMINATE_GRACE,
        private readonly float $outputGrace = self::DEFAULT_OUTPUT_GRACE,
    ) {
        if ($maxOutputBytes < 1 || $terminateGrace <= 0.0 || $outputGrace <= 0.0) {
            throw new InvalidArgumentException('the output limit must be at least 1 byte and the grace periods above 0');
        }
    }

    /**
     * Runs a command to its end and returns what it left behind. A non-zero exit code is a result, not
     * an exception.
     *
     * @param list<string> $command the program and its arguments
     * @param string|null $workingDirectory where it runs; this process's directory when null
     * @param array<string, string> $environment added to (and overriding) this process's environment
     * @param string|null $input written to the command's standard input, which is then closed; with null it is closed at once
     * @param float|null $timeout seconds after which the command is stopped
     * @param bool $captureOutput keep the output in the result; when false only the callback sees it
     * @param (Closure(OutputStream, string): void)|null $onOutput called with each piece of output as it arrives; if it throws, the command is stopped and the exception is rethrown
     *
     * @throws InvalidArgumentException when the arguments are not usable
     * @throws CommandException when the command cannot be found or started
     * @throws CommandTimeoutException when it took longer than `$timeout`
     * @throws CommandOutputLimitException when it wrote more than the limit
     * @throws CancelledException when `$cancellation` was triggered; the command has been stopped
     */
    public function run(
        array $command,
        ?Cancellation $cancellation = null,
        ?string $workingDirectory = null,
        array $environment = [],
        ?string $input = null,
        ?float $timeout = null,
        bool $captureOutput = true,
        ?Closure $onOutput = null,
    ): ProcessResult {
        $this->validate($command, $timeout);

        $merged = $environment + self::inheritedEnvironment();
        if ($workingDirectory !== null && !is_dir($workingDirectory)) {
            throw new CommandException("cannot run {$command[0]}: the working directory {$workingDirectory} does not exist");
        }

        if (ExecutableFinder::find($command[0], $merged, $workingDirectory) === null) {
            throw new CommandException("cannot run {$command[0]}: no such executable (searched " . (str_contains($command[0], '/') ? 'that path' : 'PATH') . ')');
        }

        $setsid = ExecutableFinder::find('setsid', $merged) ?? throw new CommandException('cannot run commands: the setsid program (util-linux) was not found in PATH');

        $cancellation?->throwIfRequested();

        try {
            $process = Process::start([$setsid, ...$command], $workingDirectory, $merged, cancellation: $cancellation);
        } catch (ProcessException $exception) {
            throw new CommandException("cannot run {$command[0]}: {$exception->getMessage()}", 0, $exception);
        }

        $running = new RunningCommand($process, $process->getPid());
        $this->running[spl_object_id($running)] = $running;
        $started = hrtime(true);

        $stop = new DeferredCancellation();
        $timeoutToken = $timeout === null ? null : new TimeoutCancellation($timeout);
        $token = $stop->getCancellation();
        foreach ([$cancellation, $timeoutToken] as $other) {
            if ($other !== null) {
                $token = new CompositeCancellation($token, $other);
            }
        }

        $stdout = '';
        $stderr = '';
        $limitHit = null;
        $callbackError = null;

        $drain = function (ReadableStream $stream, OutputStream $which) use (&$stdout, &$stderr, &$limitHit, &$callbackError, $captureOutput, $onOutput, $stop): void {
            try {
                while (($chunk = $stream->read()) !== null) {
                    if ($onOutput !== null) {
                        $onOutput($which, $chunk);
                    }

                    if (!$captureOutput) {
                        continue;
                    }

                    if ($which === OutputStream::Stdout) {
                        $stdout .= $chunk;
                        $size = strlen($stdout);
                    } else {
                        $stderr .= $chunk;
                        $size = strlen($stderr);
                    }

                    if ($size > $this->maxOutputBytes) {
                        $limitHit ??= $which;
                        $stop->cancel();

                        return;
                    }
                }
            } catch (StreamException) {
                // the pipe was closed under us (after the output grace ran out)
            } catch (Throwable $exception) {
                $callbackError ??= $exception;
                $stop->cancel();
            }
        };

        $readers = [
            async($drain, $process->getStdout(), OutputStream::Stdout),
            async($drain, $process->getStderr(), OutputStream::Stderr),
            async(static function () use ($process, $input): void {
                try {
                    if ($input !== null && $input !== '') {
                        $process->getStdin()->write($input);
                    }

                    $process->getStdin()->end();
                } catch (StreamException) {
                    // the command did not read its input, or ended first
                }
            }),
        ];

        $ended = false;
        try {
            try {
                $exitCode = $process->join($token);
            } catch (CancelledException) {
                $this->stopGroup($running, $this->terminateGrace);
                $exitCode = $process->join();
            }

            $ended = true;
        } finally {
            if (!$ended) {
                $this->stopGroup($running, $this->terminateGrace); // an unexpected failure must not leave the command running
            }
        }

        $outputComplete = true;
        try {
            await($readers, new TimeoutCancellation($this->outputGrace));
        } catch (CancelledException) {
            // Something the command started still holds the pipes. Take what has arrived and go on.
            $outputComplete = false;
            $process->getStdout()->close();
            $process->getStderr()->close();
            await($readers);
        } finally {
            unset($this->running[spl_object_id($running)]);
        }

        if ($callbackError !== null) {
            throw $callbackError;
        }

        if ($limitHit !== null) {
            throw new CommandOutputLimitException("{$command[0]} wrote more than {$this->maxOutputBytes} bytes to {$limitHit->value} and was stopped");
        }

        $cancellation?->throwIfRequested();
        if ($timeoutToken?->isRequested() === true) {
            throw new CommandTimeoutException("{$command[0]} did not finish within {$timeout} seconds and was stopped");
        }

        return new ProcessResult($exitCode, $stdout, $stderr, (hrtime(true) - $started) / 1e9, $outputComplete, $running->stopRequested);
    }

    /**
     * Stops every command that is running: SIGTERM to each group, up to `$grace` seconds for it to
     * empty (the runner's `terminateGrace` when null), then SIGKILL. The calls of {@see self::run()} that
     * were waiting for them return, with {@see ProcessResult::$stopped} set. Meant for shutdown hooks.
     */
    public function stopAll(?float $grace = null): void
    {
        $futures = [];
        foreach ($this->running as $command) {
            $command->stopRequested = true;
            $futures[] = async($this->stopGroup(...), $command, $grace ?? $this->terminateGrace);
        }

        await($futures);
    }

    /**
     * How many commands are running now.
     */
    public function runningCount(): int
    {
        return count($this->running);
    }

    private function stopGroup(RunningCommand $command, float $grace): void
    {
        $this->signalGroup($command->pid, SIGTERM);

        $deadline = hrtime(true) + (int) ($grace * 1e9);
        while ($this->groupExists($command->pid) && hrtime(true) < $deadline) {
            delay(0.01);
        }

        if ($this->groupExists($command->pid)) {
            $this->signalGroup($command->pid, SIGKILL);
        }
    }

    private function signalGroup(int $pid, int $signal): void
    {
        @posix_kill(-$pid, $signal);
    }

    private function groupExists(int $pid): bool
    {
        return @posix_kill(-$pid, 0);
    }

    /**
     * Checks what the types do not guarantee (callers need not obey them).
     *
     * @param array<array-key, mixed> $command
     *
     * @throws InvalidArgumentException
     */
    private function validate(array $command, ?float $timeout): void
    {
        if ($command === [] || !array_is_list($command)) {
            throw new InvalidArgumentException('a command is a list: the program, then its arguments');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('every part of a command must be a string without a NUL byte');
            }
        }

        if ($command[0] === '') {
            throw new InvalidArgumentException('the program name is empty');
        }

        if ($timeout !== null && $timeout <= 0.0) {
            throw new InvalidArgumentException('the timeout must be above 0');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function inheritedEnvironment(): array
    {
        return getenv();
    }
}
