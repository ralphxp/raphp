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
 * {@see self::pushNow()}, which never waits. That path is bounded too: it may fill a reserve
 * beyond the capacity, and past the reserve it refuses, so a peer that sends and never reads
 * cannot make the queue grow without end. A message pushed with a key is added only if none with
 * that key is still waiting, so repeated pings cost one pong, not one each.
 *
 * @internal
 */
final class OutboundQueue
{
    /** @var SplQueue<array{string, string|null}> */
    private SplQueue $items;

    /** @var array<string, true> keys of the messages waiting that were pushed with a key */
    private array $keys = [];

    private bool $closed = false;

    private bool $finishing = false;

    private readonly WaitList $space;

    private readonly WaitList $readers;

    /**
     * @param int $capacity messages that may wait before {@see self::push()} waits
     * @param int $reserve extra messages {@see self::pushNow()} may add beyond the capacity
     */
    public function __construct(private readonly int $capacity, private readonly int $reserve = 0)
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
        while (!$this->closed && !$this->finishing && $this->items->count() >= $this->capacity) {
            $this->space->wait($cancellation);
        }

        if (!$this->pushNow($line)) {
            throw new WorkerUnavailableException('the connection is closed');
        }
    }

    /**
     * Adds a line without ever waiting.
     *
     * @param string|null $key when given, the line is dropped as a duplicate if a line with this key is still waiting
     *
     * @return bool false when the line was refused: the queue is closed, or full beyond its reserve
     */
    public function pushNow(string $line, ?string $key = null): bool
    {
        if ($this->closed || $this->finishing) {
            return false;
        }

        if ($key !== null && isset($this->keys[$key])) {
            return true;
        }

        if ($this->items->count() >= $this->capacity + $this->reserve) {
            return false;
        }

        $this->items->enqueue([$line, $key]);
        if ($key !== null) {
            $this->keys[$key] = true;
        }

        $this->readers->wakeOne();

        return true;
    }

    /**
     * Waits for the next line. Returns null once the queue is closed.
     */
    public function pop(): ?string
    {
        while ($this->items->isEmpty()) {
            if ($this->closed || $this->finishing) {
                return null;
            }

            $this->readers->wait();
        }

        [$line, $key] = $this->items->dequeue();
        if ($key !== null) {
            unset($this->keys[$key]);
        }

        $this->space->wakeOne();

        return $line;
    }

    /**
     * Accepts nothing more but keeps what is waiting: the writer takes it all, then gets null.
     * Senders that wait for room are refused. Used to send a last message before closing.
     */
    public function finish(): void
    {
        $this->finishing = true;
        $this->space->wakeAll();
        $this->readers->wakeAll();
    }

    /**
     * Drops what is waiting and wakes everyone: senders are refused, the writer gets null.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->items = new SplQueue();
        $this->keys = [];
        $this->space->wakeAll();
        $this->readers->wakeAll();
    }
}
