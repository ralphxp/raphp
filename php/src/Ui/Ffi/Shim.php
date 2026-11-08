<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use Closure;
use FFI\CData;
use JsonException;
use Psr\Log\LoggerInterface;
use Raphp\Ui\Ffi\Internal\Native;
use Revolt\EventLoop;
use WeakReference;

/**
 * One running shim, as PHP uses it: commands go in as batches, events come out through the event loop.
 *
 * - **`send()`** encodes the commands as one batch and hands it to the shim, which applies it on its UI
 *   thread. It returns at once and returns the batch's id; the end of the batch is the `batch.done` event
 *   with that id (docs/protocol/shim-commands.md).
 * - **`listen()`** registers a function for the events. The first one starts a watcher on the shim's wake-up
 *   descriptor in Revolt's loop. When the descriptor is readable the watcher clears it **first** and then
 *   drains with `raphp_poll` until it is empty: that order cannot lose a wake-up (the contract of
 *   `raphp_event_fd`). The events reach each listener in the order they were produced. A listener that throws is
 *   logged and does not stop the others or the events behind.
 * - **`close()`** stops watching and stops the shim (bounded: the shim gives up on a stuck UI thread after 3
 *   seconds). It is safe to call twice, and a shim that PHP forgets is closed when it is destroyed. Events not read
 *   by then are lost.
 *
 * Calls after `close()` throw {@see ShimClosedException}.
 */
final class Shim
{
    private ?CData $handle;

    private int $nextBatch = 1;

    /** @var list<Closure(ShimEvent): void> */
    private array $listeners = [];

    /** @var resource|null the duplicate of the wake-up descriptor that PHP reads */
    private $wakeUp;

    private string $watcher = '';

    /**
     * @internal made by {@see Library::start()}
     */
    public function __construct(
        private readonly Native $native,
        CData $handle,
        private readonly LoggerInterface $logger,
    ) {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Sends a batch of commands.
     *
     * @param list<array<string, mixed>> $commands objects as docs/protocol/shim-commands.md lists them (use `new \stdClass()` for an empty `props`)
     *
     * @return int the batch's id, counted up from 1; `batch.done` carries it
     *
     * @throws ShimClosedException   when the shim is closed or has stopped taking commands
     * @throws ShimPoisonedException when the shim failed earlier
     * @throws ShimException         for a batch the shim refuses (not valid, too large) or that cannot be encoded
     */
    public function send(array $commands): int
    {
        $handle = $this->open();
        $id = $this->nextBatch;

        try {
            $text = json_encode(['batch' => $id, 'commands' => $commands], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ShimException('the commands cannot be encoded as JSON: ' . $exception->getMessage(), 0, $exception);
        }

        $status = $this->native->send($handle, $text);
        if ($status !== Native::STATUS_OK) {
            throw $this->failure($handle, $status, 'the shim refused the batch');
        }

        // Counted only once it was accepted: an id the shim never saw is not skipped.
        $this->nextBatch++;

        return $id;
    }

    /**
     * Registers a function for the events, and starts watching for them in the loop if nothing is yet.
     *
     * @param Closure(ShimEvent): void $listener
     *
     * @throws ShimClosedException when the shim is closed
     */
    public function listen(Closure $listener): void
    {
        $handle = $this->open();
        $this->listeners[] = $listener;
        if ($this->watcher !== '') {
            return;
        }

        $descriptor = $this->native->eventFd($handle);
        $stream = $descriptor < 0 ? false : @fopen("php://fd/{$descriptor}", 'r'); // PHP duplicates it, so closing this never closes the shim's
        if ($stream === false) {
            array_pop($this->listeners);

            throw new ShimException('PHP cannot open the shim\'s wake-up descriptor ' . $descriptor);
        }

        stream_set_blocking($stream, false);
        $this->wakeUp = $stream;

        // The loop holds only a weak reference, so a forgotten Shim can still be destroyed (and closed).
        $self = WeakReference::create($this);
        $this->watcher = EventLoop::onReadable($stream, static function () use ($self): void {
            $self->get()?->pump();
        });
    }

    /**
     * Reads and delivers what is queued now. The watcher calls it; it can also be called directly (the order
     * of delivery is the same).
     */
    public function pump(): void
    {
        if ($this->wakeUp !== null) {
            fread($this->wakeUp, 8); // clear first, drain after: the order that cannot lose a wake-up
        }

        foreach ($this->drain() as $event) {
            foreach ($this->listeners as $listener) {
                try {
                    $listener($event);
                } catch (\Throwable $error) {
                    $this->logger->error('a shim event listener failed', ['event' => $event->type, 'error' => $error]);
                }
            }
        }
    }

    /**
     * Reads every event that is queued now and returns them, without delivering them to the listeners. Use it
     * when nobody listens (a test, a tool); mixing it with `listen()` splits the events between the two.
     *
     * @return list<ShimEvent>
     *
     * @throws ShimException when the shim hands back something that is not an event
     */
    public function drain(): array
    {
        if ($this->handle === null) {
            return [];
        }

        $events = [];
        while (true) {
            $text = $this->native->poll($this->handle);
            if ($text === Native::STATUS_BUFFER_TOO_SMALL) {
                $text = $this->native->pollLarge($this->handle);
            }

            if ($text === 0) {
                return $events;
            }

            if (is_int($text)) {
                throw $this->failure($this->handle, $text, 'reading the shim\'s events failed');
            }

            foreach (explode("\n", rtrim($text, "\n")) as $line) {
                try {
                    $events[] = ShimEvent::fromLine($line);
                } catch (ShimException $exception) {
                    // A bug in the shim, not a reason to lose the events around it.
                    $this->logger->error('the shim produced an unreadable event', ['line' => substr($line, 0, 200), 'error' => $exception->getMessage()]);
                }
            }
        }
    }

    /**
     * The shim's counters, as it reports them (docs/protocol/shim-commands.md, "The diagnostic call").
     *
     * @return array<string, mixed>
     *
     * @throws ShimClosedException when the shim is closed
     */
    public function diagnostics(): array
    {
        $text = $this->native->diagnostics($this->open());
        $decoded = json_decode($text, true, 64);

        return is_array($decoded) ? $this->stringKeys($decoded) : [];
    }

    /**
     * Headless backend only: moves the shim's clock forward and waits until it has applied everything sent
     * before and drawn the frames then due. With 0 it is a barrier. It is the one call that waits for the UI
     * thread (the shim gives up after 10 seconds), so it blocks the event loop while it waits: for tests and
     * tools, not for the application.
     *
     * @throws ShimException when the backend is not headless, or the UI thread is stuck
     */
    public function advance(int $milliseconds = 0): void
    {
        $handle = $this->open();
        $status = $this->native->headlessAdvance($handle, max(0, $milliseconds));
        if ($status !== Native::STATUS_OK) {
            throw $this->failure($handle, $status, 'the headless clock could not be advanced');
        }
    }

    /**
     * Headless backend only: the picture of a window as last drawn (call `advance(0)` first to be sure it is
     * the one you want).
     *
     * @throws ShimException for a window that does not exist, or a backend that is not headless
     */
    public function frame(int $window): Frame
    {
        $handle = $this->open();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $size = $this->native->headlessFrameSize($handle, $window);
            if ($size < 0) {
                throw $this->failure($handle, $size, "the picture of window {$window} cannot be read");
            }

            $frame = $this->native->headlessFrame($handle, $window, $size);
            if (is_array($frame)) {
                return new Frame($frame[0], $frame[1], $frame[2]);
            }

            if ($frame < 0) {
                throw $this->failure($handle, $frame, "the picture of window {$window} cannot be read");
            }
        }

        throw new ShimException("the window {$window} kept changing size while its picture was read");
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    /**
     * Stops watching and stops the shim. A shim whose UI thread does not stop in time is left to end with the
     * process; that is logged, not thrown, because there is nothing a caller could do about it.
     */
    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        if ($this->watcher !== '') {
            EventLoop::cancel($this->watcher);
            $this->watcher = '';
        }

        if ($this->wakeUp !== null) {
            fclose($this->wakeUp);
            $this->wakeUp = null;
        }

        $handle = $this->handle;
        $this->handle = null;
        $this->listeners = [];

        $status = $this->native->shutdown($handle);
        if ($status !== Native::STATUS_OK) {
            $this->logger->warning('the shim did not stop cleanly', ['status' => $status]);
        }
    }

    private function open(): CData
    {
        return $this->handle ?? throw new ShimClosedException('the shim is closed', Native::STATUS_CLOSED);
    }

    private function failure(CData $handle, int $status, string $what): ShimException
    {
        $reason = $this->native->lastError($handle);
        $message = $reason === '' ? $what : "{$what}: {$reason}";

        return match ($status) {
            Native::STATUS_CLOSED => new ShimClosedException($message, $status),
            Native::STATUS_POISONED => new ShimPoisonedException($message, $status),
            default => new ShimException($message, $status),
        };
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $decoded): array
    {
        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
