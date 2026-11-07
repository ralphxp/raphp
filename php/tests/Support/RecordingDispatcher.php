<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Raphp\Supervisor\WorkerEvent;
use Raphp\Supervisor\WorkerEventType;
use RuntimeException;

/**
 * A PSR-14 dispatcher that remembers every worker event, with the time it arrived, and can be made
 * to throw to show that a failing listener does not disturb the supervisor.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<WorkerEvent> */
    public array $events = [];

    /** @var list<float> monotonic seconds, one per event */
    public array $times = [];

    public function __construct(private readonly bool $failing = false) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof WorkerEvent) {
            $this->events[] = $event;
            $this->times[] = hrtime(true) / 1e9;
        }

        if ($this->failing) {
            throw new RuntimeException('the listener is broken');
        }

        return $event;
    }

    /**
     * @return list<WorkerEventType>
     */
    public function types(): array
    {
        return array_map(static fn(WorkerEvent $event): WorkerEventType => $event->type, $this->events);
    }

    public function count(WorkerEventType $type): int
    {
        return count(array_filter($this->events, static fn(WorkerEvent $event): bool => $event->type === $type));
    }

    /**
     * @return list<WorkerEvent>
     */
    public function of(WorkerEventType $type): array
    {
        return array_values(array_filter($this->events, static fn(WorkerEvent $event): bool => $event->type === $type));
    }
}
