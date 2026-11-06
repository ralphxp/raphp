<?php

declare(strict_types=1);

namespace Raphp\Ipc\Transport;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Closure;

/**
 * A two-way stream of bytes between two processes (FRAMEWORK 6.1). It knows nothing about
 * messages: framing is the {@see \Raphp\Ipc\Codec\Codec}'s job. The only MVP implementation is
 * {@see StreamTransport}; a Windows named-pipe transport can be added without touching the
 * protocol code.
 *
 * Reading and writing are independent: one fiber may read while others write. Only one read may
 * be pending at a time.
 *
 * Whoever owns the transport closes it. When {@see self::read()} returns null the peer has
 * finished sending, but this side may still write until it calls {@see self::end()} or
 * {@see self::close()}.
 */
interface Transport
{
    /**
     * Waits for the next chunk of bytes. Returns null when the peer has finished sending (end of
     * input), and also after this transport was closed.
     *
     * @throws CancelledException when the cancellation is triggered while waiting
     * @throws PendingReadError when another read is already waiting
     * @throws StreamException when reading fails
     */
    public function read(?Cancellation $cancellation = null): ?string;

    /**
     * Writes all the bytes, in order after every earlier write. When the peer reads slowly this
     * waits, so a fast sender cannot fill memory with data nobody has taken (back-pressure).
     * Several fibers may write at once; each call's bytes stay together.
     *
     * @throws ClosedException when this side can no longer write (ended, closed, or the peer is gone)
     * @throws StreamException when writing fails
     */
    public function write(string $bytes): void;

    /**
     * Says that nothing more will be written. It waits for the writes that are still pending
     * (so none is cut short), then the peer sees end of input. A write started after this call
     * fails. This side can still read. To give up on pending writes instead, call
     * {@see self::close()}.
     */
    public function end(): void;

    /**
     * Closes both directions at once and releases the descriptor. Does nothing when already closed.
     */
    public function close(): void;

    /**
     * True once both directions are finished: the peer has finished sending and this side has
     * ended its own writing, or {@see self::close()} was called.
     */
    public function isClosed(): bool;

    /**
     * Runs the callback once, when {@see self::isClosed()} becomes true (at once if it already is).
     *
     * @param Closure(): void $onClose
     */
    public function onClose(Closure $onClose): void;
}
