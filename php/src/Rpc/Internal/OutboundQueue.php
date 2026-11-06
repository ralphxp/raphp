<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\Cancellation;
use Amp\CancelledException;
use Raphp\Error\WorkerUnavailableException;
use SplQueue;

/**
 * Encoded messages waiting for the one fiber that writes them, in the order they were added.
 *
 * Senders that may wait use {@see self::push()}, which waits while the queue is full. The read
 * loop must never wait on a write (two peers that both do would block each other), so it uses
 * {@see self::pushNow()}, which always adds.
 *
 * @internal
 */
final class OutboundQueue
{
    /** @var SplQueue<string> */
    private SplQueue $items;

    private bool $closed = false;

    private readonly WaitList $space;

    private readonly WaitList $readers;

    public function __construct(private readonly int $capacity)
    {
        $this->items = new SplQueue();
        $this->space = new WaitList();
        $this->readers = new WaitList();
    }

    /**
     * @throws WorkerUnavailableException when the queue is closed
     * @throws CancelledException
     */
    public function push(string $line, ?Cancellation $cancellation = null): void
    {
        while (!$this->closed && $this->items->count() >= $this->capacity) {
            $this->space->wait($cancellation);
        }

        if (!$this->pushNow($line)) {
            throw new WorkerUnavailableException('the connection is closed');
        }
    }

    /**
     * @return bool false when the queue is closed and the line was dropped
     */
    public function pushNow(string $line): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->items->enqueue($line);
        $this->readers->wakeOne();

        return true;
    }

    /**
     * Waits for the next line. Returns null once the queue is closed.
     */
    public function pop(): ?string
    {
        while ($this->items->isEmpty()) {
            if ($this->closed) {
                return null;
            }

            $this->readers->wait();
        }

        $line = $this->items->dequeue();
        $this->space->wakeOne();

        return $line;
    }

    /**
     * Drops what is waiting and wakes everyone: senders are refused, the writer gets null.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->items = new SplQueue();
        $this->space->wakeAll();
        $this->readers->wakeAll();
    }
}
