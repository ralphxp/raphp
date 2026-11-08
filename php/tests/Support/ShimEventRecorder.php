<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Closure;
use Raphp\Ui\Ffi\Shim;
use Raphp\Ui\Ffi\ShimEvent;

/**
 * Listens to a shim, keeps every event in order, and lets a test wait in the event loop for one.
 */
final class ShimEventRecorder
{
    /** @var list<ShimEvent> */
    public private(set) array $events = [];

    /** @var array<int, array{Closure(ShimEvent): bool, DeferredFuture<mixed>}> */
    private array $waiting = [];

    public function __construct(Shim $shim)
    {
        $shim->listen($this->record(...));
    }

    /**
     * Waits until an event satisfies the test (one already seen counts) and returns all events up to now.
     *
     * @param Closure(ShimEvent): bool $test
     *
     * @return list<ShimEvent>
     */
    public function until(Closure $test, float $seconds = 5.0): array
    {
        foreach ($this->events as $event) {
            if ($test($event)) {
                return $this->events;
            }
        }

        $future = new DeferredFuture();
        $this->waiting[] = [$test, $future];
        $future->getFuture()->await(new TimeoutCancellation($seconds));

        return $this->events;
    }

    /**
     * @return Closure(ShimEvent): bool
     */
    public static function batchDone(int $batch): Closure
    {
        return static fn(ShimEvent $event): bool => $event->type === 'batch.done' && ($event->fields['batch'] ?? null) === $batch;
    }

    /**
     * @return Closure(ShimEvent): bool
     */
    public static function ofType(string $type): Closure
    {
        return static fn(ShimEvent $event): bool => $event->type === $type;
    }

    private function record(ShimEvent $event): void
    {
        $this->events[] = $event;
        foreach ($this->waiting as $index => [$test, $future]) {
            if ($test($event)) {
                unset($this->waiting[$index]);
                $future->complete();
            }
        }
    }
}
