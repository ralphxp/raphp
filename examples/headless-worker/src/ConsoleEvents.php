<?php

declare(strict_types=1);

namespace Raphp\Examples\HeadlessWorker;

use Psr\EventDispatcher\EventDispatcherInterface;
use Raphp\Supervisor\WorkerEvent;

/**
 * Prints what the supervisor reports (a PSR-14 listener, FRAMEWORK 5.2) so the demonstration shows
 * a worker starting, crashing and coming back.
 */
final readonly class ConsoleEvents implements EventDispatcherInterface
{
    /**
     * @param resource $output
     */
    public function __construct(private mixed $output) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof WorkerEvent) {
            $pid = $event->pid === null ? '' : " pid {$event->pid}";
            $delay = $event->delay === null ? '' : sprintf(' in %.1f s', $event->delay);
            $detail = $event->detail === '' ? '' : " ({$event->detail})";
            fwrite($this->output, "  [supervisor] {$event->member}: {$event->type->value}{$pid}{$delay}{$detail}\n");
        }

        return $event;
    }
}
