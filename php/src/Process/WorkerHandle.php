<?php

declare(strict_types=1);

namespace Raphp\Process;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Raphp\Ipc\Transport\StreamTransport;

/**
 * A running worker process, as its master sees it: the channel to it, its output, and the means
 * to signal it and to learn how it ended.
 *
 * **Reaping (risk R18).** The exit status is collected with `proc_get_status`, which reaps the
 * process (no zombie is left) and reports a code or a signal. This class is the only thing that
 * collects it; nothing else may call `pcntl_waitpid` on a worker, because the status would then
 * be lost. {@see self::waitForExit()} polls, quickly at first and then every 50 ms at most, and
 * returns once the process is reaped and its output has been read to the end (or, if some other
 * process still holds the pipes, after a short grace).
 */
final class WorkerHandle
{
    /** How long output may still arrive after the process exited, in seconds. */
    private const float OUTPUT_GRACE = 1.0;

    private ?ExitStatus $status = null;

    private bool $closed = false;

    /**
     * @param resource $process the `proc_open` handle
     *
     * @internal built by {@see ProcessSpawner}
     */
    public function __construct(
        public readonly string $name,
        public readonly int $pid,
        private mixed $process,
        public readonly StreamTransport $transport,
        private readonly OutputCapture $stdout,
        private readonly OutputCapture $stderr,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether the process is still running.
     */
    public function isRunning(): bool
    {
        return !$this->poll();
    }

    /**
     * How the process ended, or null while it runs or before anyone has looked.
     */
    public function exitStatus(): ?ExitStatus
    {
        $this->poll();

        return $this->status;
    }

    /**
     * Sends a signal. Returns false when the process is already gone.
     */
    public function signal(int $signal): bool
    {
        if ($this->poll()) {
            return false;
        }

        return proc_terminate($this->process, $signal);
    }

    /**
     * Asks the worker to end (SIGTERM).
     */
    public function terminate(): bool
    {
        return $this->signal(SIGTERM);
    }

    /**
     * Ends the worker at once (SIGKILL). It cannot be caught or ignored.
     */
    public function kill(): bool
    {
        return $this->signal(SIGKILL);
    }

    /**
     * Ends the worker: SIGTERM, then SIGKILL if it is still running after the grace time.
     *
     * @param float $grace seconds a worker gets to end after SIGTERM
     */
    public function stop(float $grace = 5.0): ExitStatus
    {
        if (!$this->poll()) {
            $this->terminate();

            try {
                return $this->waitForExit(new TimeoutCancellation($grace));
            } catch (CancelledException) {
                $this->logger->warning('worker did not end after SIGTERM, killing it', ['worker' => $this->name, 'pid' => $this->pid]);
                $this->kill();
            }
        }

        return $this->waitForExit();
    }

    /**
     * Waits until the process has ended and been reaped, and its output has been read.
     *
     * @throws CancelledException when the cancellation is tripped first
     */
    public function waitForExit(?Cancellation $cancellation = null): ExitStatus
    {
        $interval = 0.005;
        while (!$this->poll()) {
            delay($interval, true, $cancellation);
            $interval = min($interval * 1.5, 0.05);
        }

        $this->finishOutput();

        return $this->status ?? ExitStatus::exited(-1);
    }

    /**
     * The last bytes the worker wrote to the given stream (`stdout` or `stderr`).
     */
    public function output(string $stream = 'stdout'): string
    {
        return $stream === 'stderr' ? $this->stderr->recent() : $this->stdout->recent();
    }

    /**
     * Closes the channel and the output pipes. It does not signal the process: use {@see self::stop()}
     * for that. Closing the channel is what tells a worker its master is done with it.
     */
    public function close(): void
    {
        $this->transport->close();
        $this->stdout->close();
        $this->stderr->close();
    }

    private function poll(): bool
    {
        if ($this->status !== null) {
            return true;
        }

        if ($this->closed) {
            return true;
        }

        $info = proc_get_status($this->process);
        if ($info['running']) {
            return false;
        }

        $this->status = $info['signaled'] ? ExitStatus::signaled($info['termsig']) : ExitStatus::exited($info['exitcode']);

        return true;
    }

    /**
     * Reads the output to the end. If another process (a grandchild) still holds the pipes, the
     * wait is cut short and the pipes are closed from here.
     */
    private function finishOutput(): void
    {
        try {
            $this->stdout->await(new TimeoutCancellation(self::OUTPUT_GRACE));
            $this->stderr->await(new TimeoutCancellation(self::OUTPUT_GRACE));
        } catch (CancelledException) {
            $this->logger->warning('another process still holds the output of a worker that has ended', ['worker' => $this->name]);
            $this->stdout->close();
            $this->stderr->close();
        }

        if (!$this->closed) {
            $this->closed = true;
            proc_close($this->process);
        }
    }
}
