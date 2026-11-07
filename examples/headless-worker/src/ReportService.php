<?php

declare(strict_types=1);

namespace Raphp\Examples\HeadlessWorker;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Psr\Log\LoggerInterface;
use Raphp\Rpc\Attribute\Idempotent;
use Raphp\Rpc\Attribute\RpcMethod;
use Raphp\Rpc\ProgressReporter;

/**
 * What the worker can do. It runs in the worker process: the master calls these methods by name
 * (`report.build`, ...) and never loads this class itself.
 */
final readonly class ReportService
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * A job that takes a while: it does `$steps` pieces of work and tells the caller after each one.
     * If the caller cancels, the pause in progress is cut short and the job stops where it is.
     *
     * @return array{steps: int, pid: int}
     */
    #[RpcMethod('report.build')]
    public function build(int $steps = 20, int $stepMilliseconds = 150, ?ProgressReporter $progress = null, ?Cancellation $cancellation = null): array
    {
        for ($step = 1; $step <= $steps; $step++) {
            try {
                delay($stepMilliseconds / 1000, true, $cancellation); // stands in for real work
            } catch (CancelledException $exception) {
                $this->logger->notice("report.build was cancelled during step {$step} of {$steps}; stopping");

                throw $exception;
            }

            $progress?->report(['step' => $step, 'of' => $steps]);
        }

        return ['steps' => $steps, 'pid' => posix_getpid()];
    }

    /**
     * Which process is answering. The crash demonstration compares it before and after.
     */
    #[RpcMethod('report.pid'), Idempotent]
    public function pid(): int
    {
        return posix_getpid();
    }

    /**
     * Kills the worker process on purpose (SIGKILL: no cleanup, no goodbye), as a crash would.
     */
    #[RpcMethod('report.crash')]
    public function crash(): never
    {
        $this->logger->warning('report.crash: this worker is about to kill itself');
        posix_kill(posix_getpid(), SIGKILL);

        exit(1); // not reached: SIGKILL cannot be caught
    }
}
