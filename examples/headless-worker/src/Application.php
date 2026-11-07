<?php

declare(strict_types=1);

namespace Raphp\Examples\HeadlessWorker;

use function Amp\async;

use Amp\DeferredCancellation;

use function Amp\delay;

use Raphp\Core\Kernel;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RpcException;
use Raphp\Supervisor\Supervisor;
use Revolt\EventLoop;

/**
 * The master's side of the example: calls the worker, shows progress, cancels on Ctrl-C, and
 * survives a crash of the worker. Everything here is plain use of the framework's public pieces.
 */
final readonly class Application
{
    public const string WORKER = 'reports';

    /**
     * @param resource $output where the human-readable output goes (stdout; the logs go to stderr)
     */
    public function __construct(
        private Supervisor $supervisor,
        private Kernel $kernel,
        private mixed $output,
    ) {}

    /**
     * One job. Ctrl-C (or `$cancelAfter` seconds, for a scripted run) cancels it.
     *
     * @return bool true when the job finished, false when it was cancelled or failed
     */
    public function job(int $steps, float $cancelAfter = 0.0): bool
    {
        $cancel = new DeferredCancellation();

        // Ctrl-C does not kill the master here: the kernel turns it into a stop request, and this
        // fiber turns the stop request into a cancellation of the call.
        async(function () use ($cancel): void {
            $this->kernel->awaitStop();
            $cancel->cancel();
        })->ignore();

        $timer = $cancelAfter > 0.0 ? EventLoop::delay($cancelAfter, static fn() => $cancel->cancel()) : null;
        $tty = stream_isatty($this->output);
        $started = hrtime(true);

        $this->say("job: {$steps} steps" . ($cancelAfter > 0.0 ? ", cancelled after {$cancelAfter} s" : ' (press Ctrl-C to cancel)'));

        try {
            $result = $this->supervisor->call(
                self::WORKER,
                'report.build',
                ['steps' => $steps],
                onProgress: function (array $progress) use ($tty): void {
                    $step = is_int($progress['step'] ?? null) ? $progress['step'] : 0;
                    $of = is_int($progress['of'] ?? null) ? $progress['of'] : 0;
                    $bar = str_repeat('#', $step) . str_repeat('.', max(0, $of - $step));
                    fwrite($this->output, ($tty ? "\r" : '') . "  [{$bar}] {$step}/{$of}" . ($tty ? '' : "\n"));
                },
                cancellation: $cancel->getCancellation(),
            );
        } catch (RequestCancelledException) {
            $this->say(($tty ? "\n" : '') . sprintf('job cancelled after %.2f s', (hrtime(true) - $started) / 1e9));

            return false;
        } catch (RpcException $exception) {
            $this->say(($tty ? "\n" : '') . "job failed: {$exception->wireCode()}: {$exception->getMessage()}");

            return false;
        } finally {
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }
        }

        $pid = is_array($result) && is_int($result['pid'] ?? null) ? $result['pid'] : 0;
        $this->say(($tty ? "\n" : '') . sprintf('job finished in %.2f s, done by worker pid %d', (hrtime(true) - $started) / 1e9, $pid));

        return true;
    }

    /**
     * Makes the worker kill itself in the middle of a call, then shows that the supervisor brings a
     * new one and that calls work again.
     *
     * @return bool true when a new worker answered
     */
    public function crash(): bool
    {
        $before = $this->pid();
        $this->say("crash: the worker (pid {$before}) will kill itself during a call");

        try {
            $this->supervisor->call(self::WORKER, 'report.crash');
            $this->say('the call returned: not expected');
        } catch (RpcException $exception) {
            $this->say("the call failed as it should: {$exception->wireCode()}");
        }

        // The supervisor restarts it after a short wait. A call waits for a ready worker for up to
        // the definition's readyTimeout, so it is enough to keep calling until a new process answers.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                $after = $this->pid();
            } catch (RpcException) {
                delay(0.1);

                continue;
            }

            if ($after !== $before) {
                $this->say("recovered: a new worker (pid {$after}) answers");

                return true;
            }

            delay(0.1);
        }

        $this->say('the worker did not come back');

        return false;
    }

    /**
     * The scripted tour: a complete job, a cancelled job, a crash and the recovery, a job again.
     */
    public function demo(int $steps): bool
    {
        $this->say('== 1. a job that runs to the end');
        $ok = $this->job($steps);

        $this->say('');
        $this->say('== 2. a job cancelled half way (what Ctrl-C does)');
        $this->job($steps * 2, $steps * 0.15); // half of the work takes about this long

        $this->say('');
        $this->say('== 3. a worker that crashes, and comes back');
        $ok = $this->crash() && $ok;

        $this->say('');
        $this->say('== 4. a job on the new worker');

        return $this->job($steps) && $ok;
    }

    private function pid(): int
    {
        $pid = $this->supervisor->call(self::WORKER, 'report.pid', timeout: 5.0);

        return is_int($pid) ? $pid : 0;
    }

    private function say(string $line): void
    {
        fwrite($this->output, $line . "\n");
    }
}
