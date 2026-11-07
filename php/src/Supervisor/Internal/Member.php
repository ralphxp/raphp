<?php

declare(strict_types=1);

namespace Raphp\Supervisor\Internal;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;

use function Amp\delay;
use function Amp\Future\awaitFirst;

use Amp\TimeoutCancellation;
use Closure;
use Psr\Log\LoggerInterface;
use Raphp\Error\RpcException;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SpawnException;
use Raphp\Process\WorkerHandle;
use Raphp\Process\WorkerSpec;
use Raphp\Rpc\Internal\WaitList;
use Raphp\Rpc\RpcPeer;
use Raphp\Supervisor\WorkerDefinition;
use Raphp\Supervisor\WorkerEvent;
use Raphp\Supervisor\WorkerEventType;
use Raphp\Supervisor\WorkerState;

/**
 * One supervised process of a pool, through all its lives: started, made ready, used, replaced or
 * restarted, stopped (FRAMEWORK 5.3 to 5.5). {@see self::run()} is its loop; the rest is what the
 * supervisor needs to route calls to it.
 *
 * A call counts against the *incarnation* it started on, so a late answer from a process that was
 * replaced never disturbs the count of its successor.
 *
 * @internal
 */
final class Member
{
    public readonly string $name;

    private WorkerState $state = WorkerState::Starting;

    private ?WorkerHandle $handle = null;

    private ?RpcPeer $peer = null;

    private int $incarnation = 0;

    private int $inFlight = 0;

    private int $served = 0;

    private float $memoryCheckedAt = 0.0;

    /** @var list<float> monotonic seconds of the crashes that are still inside the window */
    private array $crashes = [];

    private bool $stopRequested = false;

    private float $stopGrace = 5.0;

    private ?string $recycleReason = null;

    /** @var DeferredFuture<null> completed to end the current process on purpose */
    private DeferredFuture $trigger;

    private readonly DeferredCancellation $stopSignal;

    private readonly WaitList $idle;

    /**
     * @param Closure(WorkerEvent): void $emit
     * @param Closure(): void $changed called whenever the state changes, to wake whoever waits for a member
     */
    public function __construct(
        private readonly WorkerDefinition $definition,
        private readonly int $index,
        private readonly ProcessSpawner $spawner,
        private readonly Codec $codec,
        private readonly EnvelopeReader $reader,
        private readonly LoggerInterface $logger,
        private readonly ProcessMemory $memory,
        private readonly Closure $emit,
        private readonly Closure $changed,
    ) {
        $this->name = $definition->memberName($index);
        $this->trigger = new DeferredFuture();
        $this->stopSignal = new DeferredCancellation();
        $this->idle = new WaitList();
    }

    public function state(): WorkerState
    {
        return $this->state;
    }

    public function pid(): ?int
    {
        return $this->handle?->pid;
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    public function isRoutable(): bool
    {
        return $this->state === WorkerState::Ready && $this->peer !== null && !$this->peer->isClosed();
    }

    public function peer(): ?RpcPeer
    {
        return $this->peer;
    }

    /**
     * Counts a call as started and says which incarnation it belongs to.
     */
    public function begin(): int
    {
        ++$this->inFlight;

        return $this->incarnation;
    }

    /**
     * Counts a call as finished, and replaces this worker if it has now done its share.
     */
    public function finish(int $incarnation): void
    {
        if ($incarnation !== $this->incarnation) {
            return;
        }

        --$this->inFlight;
        ++$this->served;
        if ($this->inFlight === 0) {
            $this->idle->wakeAll();
        }

        $this->considerRecycling();
    }

    /**
     * Ends this member's life on purpose: no new calls, the ones in flight finish, then it is
     * stopped gracefully (or, if it is waiting to restart, does not restart).
     */
    public function requestStop(float $grace): void
    {
        $this->stopRequested = true;
        $this->stopGrace = $grace;
        $this->stopSignal->cancel();
        if (!$this->trigger->isComplete()) {
            $this->trigger->complete();
        }
    }

    /**
     * Supervises the process until it is stopped on purpose or declared degraded.
     */
    public function run(): void
    {
        while (!$this->stopRequested) {
            $outcome = $this->live();

            if ($outcome === 'stopped') {
                break;
            }

            if ($outcome === 'crashed' && !$this->backoffOrGiveUp()) {
                if ($this->state === WorkerState::Degraded) {
                    return;
                }

                break;
            }
        }

        $this->setState(WorkerState::Exited);
    }

    /**
     * One process, from start to end. Returns `crashed`, `recycled` or `stopped`.
     */
    private function live(): string
    {
        $this->trigger = new DeferredFuture();
        $this->recycleReason = null;
        ++$this->incarnation;
        $this->inFlight = 0;
        $this->served = 0;
        $this->peer = null;
        $this->handle = null;
        $this->setState(WorkerState::Starting);

        $definition = $this->definition;

        try {
            $handle = $this->spawner->spawn(new WorkerSpec($this->name, $definition->command, $definition->environment, $definition->cwd, $definition->armDeathSignal, null, $definition->extraArguments));
        } catch (SpawnException $exception) {
            $this->logger->error('a worker could not be started', ['worker' => $this->name, 'problem' => $exception->getMessage()]);
            $this->emitEvent(WorkerEventType::Crashed, null, $exception->getMessage());

            return 'crashed';
        }

        $peer = new RpcPeer($handle->transport, $this->codec, $this->reader, $this->logger, $definition->handler, $definition->rpc, hello: new Hello(Protocol::VERSION, 'master', 'master', posix_getpid()));
        $this->handle = $handle;
        $this->peer = $peer;
        $peer->start();
        $this->emitEvent(WorkerEventType::Started, $handle->pid);
        $this->setState(WorkerState::Handshaking);

        try {
            $peer->awaitHandshake(new TimeoutCancellation($definition->handshakeTimeout));
        } catch (CancelledException|RpcException $exception) {
            $detail = 'did not complete the handshake: ' . $exception->getMessage();
            $this->logger->warning('a worker is not usable', ['worker' => $this->name, 'problem' => $detail]);
            $peer->close();
            $handle->stop($definition->terminateGrace);
            $handle->close();
            $this->emitEvent(WorkerEventType::Crashed, $handle->pid, $detail);

            return 'crashed';
        }

        $this->setState(WorkerState::Ready);
        $this->emitEvent(WorkerEventType::Ready, $handle->pid);

        $trigger = $this->trigger;
        $watching = [
            async(static function () use ($handle): string {
                $handle->waitForExit();

                return 'exit';
            }),
            async(static function () use ($peer): string {
                $peer->awaitClosed();

                return 'closed';
            }),
            async(static function () use ($trigger): string {
                $trigger->getFuture()->await();

                return 'trigger';
            }),
        ];
        $which = awaitFirst($watching);
        foreach ($watching as $future) {
            $future->ignore();
        }

        if ($which === 'trigger') {
            return $this->leave($handle, $peer);
        }

        // The process ended, or its connection did, and nobody asked it to.
        $status = $which === 'closed' && $handle->isRunning() ? $handle->stop($definition->terminateGrace) : $handle->waitForExit();
        $peer->close();
        $handle->close();
        $this->logger->warning('a worker ended without being asked to', ['worker' => $this->name, 'pid' => $handle->pid, 'status' => $status->describe(), 'first' => $which]);
        $this->emitEvent(WorkerEventType::Crashed, $handle->pid, $status->describe() . ($which === 'closed' ? ' (the connection was lost)' : ''));

        return 'crashed';
    }

    /**
     * Ends a healthy process on purpose, for a stop or a recycle: no new calls, wait for the calls
     * in flight, then `bye`, then SIGTERM and SIGKILL if it does not go.
     */
    private function leave(WorkerHandle $handle, RpcPeer $peer): string
    {
        $stopping = $this->stopRequested;
        $this->setState(WorkerState::Draining);
        if ($stopping) {
            $this->emitEvent(WorkerEventType::Draining, $handle->pid, 'stopping');
        }

        $this->waitIdle($stopping ? $this->stopGrace : $this->definition->recycle->drainTimeout);

        $grace = $stopping ? $this->stopGrace : $this->definition->shutdownGrace;
        $peer->shutdown($stopping ? 'shutdown' : 'recycled');

        try {
            $handle->waitForExit(new TimeoutCancellation($grace));
        } catch (CancelledException) {
            $this->emitEvent(WorkerEventType::Terminating, $handle->pid, "still running {$grace} seconds after bye");
            $handle->terminate();

            try {
                $handle->waitForExit(new TimeoutCancellation($this->definition->terminateGrace));
            } catch (CancelledException) {
                $this->emitEvent(WorkerEventType::Killing, $handle->pid, "still running {$this->definition->terminateGrace} seconds after SIGTERM");
                $handle->kill();
                $handle->waitForExit();
            }
        }

        $peer->close();
        $handle->close();

        if ($stopping) {
            $this->emitEvent(WorkerEventType::Exited, $handle->pid);

            return 'stopped';
        }

        $this->emitEvent(WorkerEventType::Recycled, $handle->pid, $this->recycleReason ?? 'recycled');

        return 'recycled';
    }

    /**
     * Counts the crash, then either waits before the next start or gives up.
     *
     * @return bool true to start again, false when the worker is degraded or was stopped meanwhile
     */
    private function backoffOrGiveUp(): bool
    {
        $policy = $this->definition->restart;
        $now = hrtime(true) / 1e9;
        $this->crashes[] = $now;
        $this->crashes = array_values(array_filter($this->crashes, static fn(float $at): bool => $now - $at <= $policy->window));
        $count = count($this->crashes);

        if ($count > $policy->maxRestarts) {
            $this->setState(WorkerState::Degraded);
            $this->logger->error('a worker crashes too often and is not restarted any more', ['worker' => $this->name, 'crashes' => $count, 'window' => $policy->window]);
            $this->emitEvent(WorkerEventType::Degraded, null, "{$count} crashes within {$policy->window} seconds");

            return false;
        }

        $delay = $policy->delayAfter($count);
        $this->setState(WorkerState::Crashed);
        $this->emitEvent(WorkerEventType::Restarting, null, "crash {$count} of {$policy->maxRestarts} allowed in {$policy->window} seconds", $delay);

        try {
            delay($delay, true, $this->stopSignal->getCancellation());
        } catch (CancelledException) {
            return false;
        }

        return true;
    }

    /**
     * Replaces the worker if its calls or its memory have passed the limit. It stops being routable
     * at once; the loop does the rest.
     */
    private function considerRecycling(): void
    {
        $policy = $this->definition->recycle;
        if ($this->state !== WorkerState::Ready || $this->recycleReason !== null) {
            return;
        }

        $reason = null;
        if ($policy->maxCalls !== null && $this->served >= $policy->maxCalls) {
            $reason = "{$this->served} calls served";
        } elseif ($policy->maxMemoryBytes !== null && $this->handle !== null && hrtime(true) / 1e9 - $this->memoryCheckedAt >= 1.0) {
            $this->memoryCheckedAt = hrtime(true) / 1e9;
            $bytes = $this->memory->residentBytes($this->handle->pid);
            if ($bytes !== null && $bytes > $policy->maxMemoryBytes) {
                $reason = "using {$bytes} bytes of memory";
            }
        }

        if ($reason === null) {
            return;
        }

        $this->recycleReason = $reason;
        $this->setState(WorkerState::Draining);
        if (!$this->trigger->isComplete()) {
            $this->trigger->complete();
        }
    }

    private function waitIdle(float $seconds): void
    {
        if ($this->inFlight === 0) {
            return;
        }

        $timeout = new TimeoutCancellation($seconds);

        try {
            while ($this->inFlight > 0) {
                $this->idle->wait($timeout);
            }
        } catch (CancelledException) {
            $this->logger->warning('calls in flight did not finish in time', ['worker' => $this->name, 'calls' => $this->inFlight, 'seconds' => $seconds]);
        }
    }

    private function setState(WorkerState $state): void
    {
        $this->state = $state;
        ($this->changed)();
    }

    private function emitEvent(WorkerEventType $type, ?int $pid = null, string $detail = '', ?float $delay = null): void
    {
        ($this->emit)(new WorkerEvent($type, $this->definition->name, $this->name, $pid, $detail, $delay));
    }
}
