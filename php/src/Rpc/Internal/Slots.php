<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Raphp\Error\WorkerUnavailableException;

/**
 * A fixed number of slots with a bounded line of fibers waiting for one. A released slot goes
 * straight to the first fiber in line, so nobody overtakes.
 *
 * @internal
 */
final class Slots
{
    private int $free;

    private bool $closed = false;

    private readonly WaitList $waiting;

    public function __construct(int $capacity, private readonly int $maxWaiting)
    {
        $this->free = $capacity;
        $this->waiting = new WaitList();
    }

    /**
     * @throws WorkerUnavailableException when the line is already full, or the slots were closed
     * @throws CancelledException
     */
    public function acquire(?Cancellation $cancellation = null): void
    {
        $this->refuseWhenClosed();

        if ($this->free > 0) {
            --$this->free;

            return;
        }

        if ($this->waiting->count() >= $this->maxWaiting) {
            throw new WorkerUnavailableException('too many calls are waiting for a free slot');
        }

        $this->waiting->wait($cancellation);
        $this->refuseWhenClosed();
    }

    public function release(): void
    {
        if (!$this->waiting->wakeOne()) {
            ++$this->free;
        }
    }

    private function refuseWhenClosed(): void
    {
        if ($this->closed) {
            throw new WorkerUnavailableException('the connection is closed');
        }
    }

    /**
     * Refuses further acquisitions and wakes everyone waiting, who will then be refused.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->waiting->wakeAll();
    }
}
