<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * One named worker from the manifest (FRAMEWORK 5.2, 9.2).
 */
final readonly class WorkerConfig
{
    public function __construct(
        public string $name,
        public string $entry,
        public int $pool = 1,
    ) {}
}
