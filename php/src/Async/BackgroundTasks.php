<?php

declare(strict_types=1);

namespace Raphp\Async;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Runs fire-and-forget work concurrently and supervises it (FRAMEWORK 4.7).
 *
 * A task that fails is logged (PSR-3, level error) with its name and exception. It is never
 * swallowed silently, it never crashes the process, and it can never raise Amp's
 * `UnhandledFutureError`, because the task's own future is always handled here.
 *
 * Tasks receive a {@see Cancellation}. {@see self::cancelAll()} trips it, and a task that
 * ends because of that is logged at debug level, not as a failure. A `CancelledException` from
 * anything else (the task's own timeout, say) is a failure. A task may return a {@see Future}; the
 * task then lasts until that future is done and its failure is logged like any other.
 */
final class BackgroundTasks
{
    /** @var array<int, Future<null>> */
    private array $running = [];

    private int $nextId = 0;

    private DeferredCancellation $cancellation;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->cancellation = new DeferredCancellation();
    }

    /**
     * Queues the task to start in its own Fiber on the next event-loop tick, and returns at
     * once. This method never throws, whatever the task does.
     *
     * @param Closure(Cancellation): mixed $task
     * @param string $name how the task is named in the log
     */
    public function run(Closure $task, string $name = 'task'): void
    {
        $id = $this->nextId++;
        $cancellation = $this->cancellation->getCancellation();

        $future = async(function () use ($task, $name, $id, $cancellation): void {
            try {
                $result = $task($cancellation);
                if ($result instanceof Future) {
                    // A task that hands back a future is not finished until that future is: wait for
                    // it here, so its failure is logged and never left unhandled.
                    $result->await($cancellation);
                }
            } catch (CancelledException $exception) {
                if ($cancellation->isRequested()) {
                    $this->log(LogLevel::DEBUG, 'background task cancelled', ['task' => $name]);
                } else {
                    // Not our cancelAll(): the task's own timeout or some other cancellation. That is a failure.
                    $this->log(LogLevel::ERROR, 'background task failed', ['task' => $name, 'exception' => $exception]);
                }
            } catch (Throwable $exception) {
                $this->log(LogLevel::ERROR, 'background task failed', ['task' => $name, 'exception' => $exception]);
            } finally {
                unset($this->running[$id]);
            }
        });
        $future->ignore();

        // Amp starts the task on the next tick, so it cannot finish, and unset itself, before
        // it is tracked here.
        $this->running[$id] = $future;
    }

    /**
     * Logs without ever throwing: a logger that fails must not turn a task failure into a lost
     * error or make {@see self::awaitAll()} throw. As a last resort the line goes to the error log.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable $loggerFailure) {
            error_log("raphp: could not log \"{$message}\" ({$loggerFailure->getMessage()})");
        }
    }

    /**
     * How many tasks are still running.
     */
    public function count(): int
    {
        return count($this->running);
    }

    /**
     * Suspends the current Fiber until every running task has finished, including tasks started
     * while it waits. It never throws because of a task: task failures are logged.
     *
     * @throws CancelledException when the given cancellation is tripped first
     */
    public function awaitAll(?Cancellation $cancellation = null): void
    {
        while ($this->running !== []) {
            foreach ($this->running as $future) {
                $future->await($cancellation);
            }
        }
    }

    /**
     * Trips the cancellation of every task started so far. Tasks started afterwards get a fresh
     * one.
     */
    public function cancelAll(): void
    {
        $this->cancellation->cancel();
        $this->cancellation = new DeferredCancellation();
    }
}
