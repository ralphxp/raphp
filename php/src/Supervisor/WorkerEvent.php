<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

/**
 * A PSR-14 event: something happened to a supervised worker. The application listens for these,
 * for example to show an error when a worker is `degraded` (FRAMEWORK 5.4).
 */
final readonly class WorkerEvent
{
    public function __construct(
        public WorkerEventType $type,
        public string $worker,
        public string $member,
        public ?int $pid = null,
        public string $detail = '',
        public ?float $delay = null,
    ) {}
}
