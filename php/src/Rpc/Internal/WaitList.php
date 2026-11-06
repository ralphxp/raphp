<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;

/**
 * Fibers waiting for a signal, woken one at a time in arrival order. A waiter that is cancelled
 * leaves the list, so a later wake-up is never wasted on it.
 *
 * @internal
 */
final class WaitList
{
    /** @var array<int, DeferredFuture<mixed>> */
    private array $waiters = [];

    /**
     * Waits until {@see self::wakeOne()} or {@see self::wakeAll()} wakes this fiber. If it is
     * woken and cancelled at the same moment, the wake-up wins.
     *
     * @throws CancelledException
     */
    public function wait(?Cancellation $cancellation = null): void
    {
        $waiter = new DeferredFuture();
        $id = spl_object_id($waiter);
        $this->waiters[$id] = $waiter;

        try {
            $waiter->getFuture()->await($cancellation);
        } catch (CancelledException $exception) {
            unset($this->waiters[$id]);
            if (!$waiter->isComplete()) {
                throw $exception;
            }
        }
    }

    /**
     * @return bool whether a fiber was waiting
     */
    public function wakeOne(): bool
    {
        foreach ($this->waiters as $id => $waiter) {
            unset($this->waiters[$id]);
            $waiter->complete();

            return true;
        }

        return false;
    }

    public function wakeAll(): void
    {
        while ($this->wakeOne()) {
        }
    }

    public function count(): int
    {
        return count($this->waiters);
    }
}
