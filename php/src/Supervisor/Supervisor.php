<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

use function Amp\async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;

use function Amp\Future\await;

use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Raphp\Error\RpcException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Process\ProcessSpawner;
use Raphp\Rpc\Internal\WaitList;
use Raphp\Supervisor\Internal\Member;
use Raphp\Supervisor\Internal\Pool;
use Raphp\Supervisor\Internal\ProcessMemory;
use Throwable;

/**
 * Starts the declared workers, keeps them alive, and sends calls to them (FRAMEWORK 5.2 to 5.5).
 *
 * - **Pools.** A name is one process or a pool of several, and calls are spread by the definition's
 *   {@see Routing}. Only a worker in the `ready` state is given a call.
 * - **When no member is ready,** a call waits up to the definition's ready timeout and then fails with
 *   `E_WORKER_UNAVAILABLE`. If every member is degraded it fails at once: no restart is coming.
 * - **Crashes.** The calls in flight on a worker that dies fail with `E_WORKER_CRASHED` (the connection
 *   sees to that). Whether to repeat one is the caller's decision. The worker is restarted after a
 *   growing wait, until it crashes too often ({@see RestartPolicy}).
 * - **Recycling.** A worker is replaced on purpose after so many calls or when it grows too large
 *   ({@see RecyclePolicy}), after the calls in flight have finished.
 * - **Stopping.** {@see self::stop()} is the same orderly sequence for every worker: no new calls, the
 *   calls in flight finish, `bye`, a grace period, SIGTERM, SIGKILL.
 * - **Events.** Everything above is reported to the PSR-14 dispatcher as a {@see WorkerEvent}. A listener
 *   that throws is logged and does not disturb the supervision.
 */
final class Supervisor
{
    /** @var array<string, Pool> */
    private array $pools = [];

    private bool $started = false;

    private bool $stopping = false;

    public function __construct(
        private readonly ProcessSpawner $spawner,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly Codec $codec = new JsonLineCodec(),
        private readonly EnvelopeReader $reader = new EnvelopeReader(),
        private readonly ProcessMemory $memory = new ProcessMemory(),
    ) {}

    /**
     * Declares a worker. Do it before {@see self::start()}.
     *
     * @throws LogicException when supervision has started or the name is taken
     */
    public function register(WorkerDefinition $definition): void
    {
        if ($this->started) {
            throw new LogicException('workers must be declared before the supervisor starts');
        }

        if (isset($this->pools[$definition->name])) {
            throw new LogicException("a worker named \"{$definition->name}\" is already declared");
        }

        $changed = new WaitList();
        $wake = static function () use ($changed): void {
            $changed->wakeAll();
        };
        $emit = $this->emit(...);

        $members = [];
        for ($index = 1; $index <= $definition->pool; $index++) {
            $members[] = new Member($definition, $index, $this->spawner, $this->codec, $this->reader, $this->logger, $this->memory, $emit, $wake);
        }

        $this->pools[$definition->name] = new Pool($definition, $members, $changed);
    }

    /**
     * Starts every declared worker. Returns at once; use {@see self::awaitReady()} to wait for them.
     *
     * @throws LogicException when called twice
     */
    public function start(): void
    {
        if ($this->started) {
            throw new LogicException('the supervisor was already started');
        }

        $this->started = true;

        foreach ($this->pools as $pool) {
            foreach ($pool->members as $member) {
                $pool->runs[] = async(function () use ($member): void {
                    try {
                        $member->run();
                    } catch (Throwable $exception) {
                        $this->logger->error('supervising a worker failed', ['worker' => $member->name, 'exception' => $exception]);
                    }
                });
            }
        }
    }

    /**
     * Waits until every member of the worker is ready.
     *
     * @throws WorkerUnavailableException when a member is degraded, or the supervisor is stopping
     * @throws CancelledException
     */
    public function awaitReady(string $worker, ?Cancellation $cancellation = null): void
    {
        $pool = $this->pool($worker);

        while (true) {
            $waiting = false;
            foreach ($pool->members as $member) {
                if ($member->state() === WorkerState::Degraded || $this->stopping) {
                    throw new WorkerUnavailableException("the worker \"{$worker}\" is not going to become ready");
                }

                $waiting = $waiting || $member->state() !== WorkerState::Ready;
            }

            if (!$waiting) {
                return;
            }

            $pool->changed->wait($cancellation);
        }
    }

    /**
     * Calls a method on one member of the worker's pool and waits for the result.
     *
     * @param array<array-key, mixed> $params
     * @param float|null $timeout seconds to wait for the answer once a member has the call; null waits without limit
     * @param (Closure(array<array-key, mixed>, string): void)|null $onProgress
     *
     * @throws RpcException when the call fails, including `E_WORKER_UNAVAILABLE` when no member is ready in time
     * @throws InvalidArgumentException when no worker has that name
     */
    public function call(string $worker, string $method, array $params = [], ?float $timeout = null, ?Closure $onProgress = null, ?Cancellation $cancellation = null): mixed
    {
        $pool = $this->pool($worker);
        $member = $this->pick($pool, $cancellation);
        $peer = $member->peer();
        if ($peer === null) {
            throw new WorkerUnavailableException("the worker \"{$worker}\" has no connection");
        }

        $incarnation = $member->begin();

        try {
            return $peer->call($method, $params, $timeout, $onProgress, $cancellation);
        } finally {
            $member->finish($incarnation);
        }
    }

    /**
     * Stops every worker in order and waits until they have ended: no new calls, the calls in flight
     * finish (for up to `$grace` seconds), `bye`, then up to `$grace` seconds to end, then SIGTERM
     * and SIGKILL (the definition says how long after SIGTERM).
     */
    public function stop(float $grace = 5.0): void
    {
        $this->stopping = true;

        $runs = [];
        foreach ($this->pools as $pool) {
            foreach ($pool->members as $member) {
                $member->requestStop($grace);
            }

            array_push($runs, ...$pool->runs);
            $pool->changed->wakeAll();
        }

        await($runs);
    }

    /**
     * The state of every member, by worker name and member name.
     *
     * @return array<string, array<string, WorkerState>>
     */
    public function states(): array
    {
        $states = [];
        foreach ($this->pools as $name => $pool) {
            foreach ($pool->members as $member) {
                $states[$name][$member->name] = $member->state();
            }
        }

        return $states;
    }

    /**
     * The process ids of the members that are running, by member name.
     *
     * @return array<string, int>
     */
    public function pids(string $worker): array
    {
        $pids = [];
        foreach ($this->pool($worker)->members as $member) {
            $pid = $member->pid();
            if ($pid !== null && $member->state() !== WorkerState::Crashed && $member->state() !== WorkerState::Exited) {
                $pids[$member->name] = $pid;
            }
        }

        return $pids;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function pool(string $worker): Pool
    {
        return $this->pools[$worker] ?? throw new InvalidArgumentException("no worker is declared with the name \"{$worker}\"");
    }

    /**
     * Chooses a ready member, waiting for one if there is none.
     *
     * @throws WorkerUnavailableException
     */
    private function pick(Pool $pool, ?Cancellation $cancellation): Member
    {
        $definition = $pool->definition;
        $deadline = new TimeoutCancellation($definition->readyTimeout);
        $combined = $cancellation === null ? $deadline : new CompositeCancellation($deadline, $cancellation);

        while (true) {
            $member = $this->choose($pool);
            if ($member !== null) {
                return $member;
            }

            if ($this->stopping) {
                throw new WorkerUnavailableException("the worker \"{$definition->name}\" is shutting down");
            }

            if ($this->allDegraded($pool)) {
                throw new WorkerUnavailableException("the worker \"{$definition->name}\" is degraded: it crashed too often and is not restarted");
            }

            try {
                $pool->changed->wait($combined);
            } catch (CancelledException $exception) {
                throw new WorkerUnavailableException("no \"{$definition->name}\" worker was ready within {$definition->readyTimeout} seconds", null, $exception);
            }
        }
    }

    private function choose(Pool $pool): ?Member
    {
        $routable = array_values(array_filter($pool->members, static fn(Member $member): bool => $member->isRoutable()));
        if ($routable === []) {
            return null;
        }

        if ($pool->definition->routing === Routing::LeastBusy) {
            usort($routable, static fn(Member $a, Member $b): int => $a->inFlight() <=> $b->inFlight());

            return $routable[0];
        }

        $count = count($pool->members);
        for ($step = 0; $step < $count; $step++) {
            $member = $pool->members[($pool->cursor + $step) % $count];
            if ($member->isRoutable()) {
                $pool->cursor = ($pool->cursor + $step + 1) % $count;

                return $member;
            }
        }

        return null;
    }

    private function allDegraded(Pool $pool): bool
    {
        foreach ($pool->members as $member) {
            if ($member->state() !== WorkerState::Degraded) {
                return false;
            }
        }

        return true;
    }

    private function emit(WorkerEvent $event): void
    {
        $this->logger->info('worker event', ['type' => $event->type->value, 'worker' => $event->member, 'pid' => $event->pid, 'detail' => $event->detail, 'delay' => $event->delay]);

        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (Throwable $exception) {
            $this->logger->error('a listener of a worker event failed', ['type' => $event->type->value, 'exception' => $exception]);
        }
    }
}
