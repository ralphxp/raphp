<?php

declare(strict_types=1);

namespace Raphp\Core;

use function Amp\async;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * The process's lifetime: starts the application inside the event loop, turns signals and
 * uncaught errors into an orderly shutdown, and returns the exit code (FRAMEWORK 4.5, 4.7).
 *
 * It is the same for a master and for a worker (decision O12): an uncaught exception is
 * logged, the shutdown hooks run, and the exit code is non-zero, so a supervisor can restart a
 * worker.
 *
 * Exit codes: {@see self::EXIT_OK} when the application's main returns or stop() is called
 * with 0; {@see self::EXIT_ERROR} after an uncaught exception or a failed shutdown hook;
 * {@see self::EXIT_SIGINT} (130) and {@see self::EXIT_SIGTERM} (143) after those signals,
 * following the 128 + signal convention; or the code given to {@see self::stop()}.
 * The first reason to stop decides the code.
 *
 * Shutdown hooks run one at a time, last registered first, so something acquired later is
 * released earlier. Together they get a time budget (default 5 seconds). A hook that fails is
 * logged and the rest still run. A hook that runs out of the budget is logged and abandoned.
 * Either makes an otherwise clean exit code non-zero. A second SIGINT or SIGTERM while
 * shutting down does not wait: it ends the process at once with that signal's code.
 *
 * The main closure is the application's lifetime. When it returns, the process shuts down. A
 * server that should keep running calls {@see self::awaitStop()} to wait until a stop is
 * requested.
 *
 * Signals need ext-pcntl (a required extension).
 */
final class Kernel
{
    public const int EXIT_OK = 0;

    public const int EXIT_ERROR = 1;

    public const int EXIT_SIGINT = 130;

    public const int EXIT_SIGTERM = 143;

    private const float DEFAULT_SHUTDOWN_TIMEOUT = 5.0;

    /** @var list<array{string, Closure(): mixed}> */
    private array $hooks = [];

    /** @var list<Suspension<null>> */
    private array $stopWaiters = [];

    private bool $stopping = false;

    private int $exitCode = self::EXIT_OK;

    /** @var Suspension<null>|null */
    private ?Suspension $finished = null;

    /** @var Closure(int): mixed */
    private readonly Closure $forceExit;

    /**
     * @param float $shutdownTimeout seconds the shutdown hooks may take together
     * @param (Closure(int): mixed)|null $forceExit how a second signal ends the process; the
     *                                              default is exit(). Tests may replace it.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly float $shutdownTimeout = self::DEFAULT_SHUTDOWN_TIMEOUT,
        ?Closure $forceExit = null,
    ) {
        $this->forceExit = $forceExit ?? static function (int $code): never {
            exit($code);
        };
    }

    /**
     * Registers work to do at shutdown. Hooks run last registered first.
     *
     * @param Closure(): mixed $hook may suspend; it runs inside a Fiber
     */
    public function onShutdown(Closure $hook, string $name = 'hook'): void
    {
        $this->hooks[] = [$name, $hook];
    }

    /**
     * Runs the application until it returns or a stop is requested, then runs the shutdown
     * hooks and returns the exit code. Call it once, from outside the event loop.
     *
     * @param Closure(self): mixed $main
     *
     * @throws LogicException when the kernel is already running
     */
    public function run(Closure $main): int
    {
        if ($this->finished !== null) {
            throw new LogicException('The kernel is already running.');
        }

        $this->stopping = false;
        $this->exitCode = self::EXIT_OK;
        $finished = EventLoop::getSuspension();
        $this->finished = $finished;

        EventLoop::setErrorHandler($this->onUncaught(...));
        $watchers = [];
        foreach ([\SIGINT => self::EXIT_SIGINT, \SIGTERM => self::EXIT_SIGTERM] as $signal => $code) {
            $watchers[] = EventLoop::onSignal($signal, fn() => $this->onSignal($signal, $code));
        }

        EventLoop::queue(function () use ($main): void {
            try {
                $main($this);
            } catch (Throwable $exception) {
                $this->logger->error('uncaught exception in the application', ['exception' => $exception]);
                $this->stop(self::EXIT_ERROR);

                return;
            }

            $this->stop(self::EXIT_OK);
        });

        $finished->suspend();

        foreach ($watchers as $watcher) {
            EventLoop::cancel($watcher);
        }

        EventLoop::setErrorHandler(null);
        $this->finished = null;

        return $this->exitCode;
    }

    /**
     * Asks for a shutdown. The first request decides the exit code; later ones are ignored.
     */
    public function stop(int $exitCode = self::EXIT_OK): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->exitCode = $exitCode;

        foreach ($this->stopWaiters as $waiter) {
            $waiter->resume();
        }

        $this->stopWaiters = [];

        EventLoop::queue(function (): void {
            $this->runShutdownHooks();
            $this->finished?->resume();
        });
    }

    /**
     * Suspends the current Fiber until a stop is requested, then returns. Returns at once if
     * one was already requested.
     */
    public function awaitStop(): void
    {
        if ($this->stopping) {
            return;
        }

        $suspension = EventLoop::getSuspension();
        $this->stopWaiters[] = $suspension;
        $suspension->suspend();
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    private function onUncaught(Throwable $exception): void
    {
        $this->logger->error('uncaught exception in an event-loop callback', ['exception' => $exception]);
        $this->stop(self::EXIT_ERROR);
    }

    private function onSignal(int $signal, int $exitCode): void
    {
        $name = $signal === \SIGINT ? 'SIGINT' : 'SIGTERM';
        if ($this->stopping) {
            $this->logger->warning("received {$name} again while shutting down, ending now");
            ($this->forceExit)($exitCode);

            return;
        }

        $this->logger->notice("received {$name}, shutting down");
        $this->stop($exitCode);
    }

    private function runShutdownHooks(): void
    {
        $deadline = $this->now() + $this->shutdownTimeout;
        $failed = false;

        foreach (array_reverse($this->hooks) as [$name, $hook]) {
            $remaining = $deadline - $this->now();
            if ($remaining <= 0.0) {
                $this->logger->error('shutdown hook skipped, the time budget is used up', ['hook' => $name]);
                $failed = true;

                continue;
            }

            $future = async($hook);
            $future->ignore();

            try {
                $future->await(new TimeoutCancellation($remaining));
            } catch (CancelledException) {
                $this->logger->error('shutdown hook did not finish in time and was abandoned', ['hook' => $name]);
                $failed = true;
            } catch (Throwable $exception) {
                $this->logger->error('shutdown hook failed', ['hook' => $name, 'exception' => $exception]);
                $failed = true;
            }
        }

        if ($failed && $this->exitCode === self::EXIT_OK) {
            $this->exitCode = self::EXIT_ERROR;
        }
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
