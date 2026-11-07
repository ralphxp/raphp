<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

use InvalidArgumentException;

/**
 * When a healthy worker is replaced on purpose, to keep a slow leak from growing (FRAMEWORK 4.6).
 * After `maxCalls` calls, or when its memory use passes `maxMemoryBytes`, the worker stops taking new
 * calls, the ones in flight finish (for at most `drainTimeout` seconds), it is stopped gracefully and
 * a new one starts at once. Nothing is recycled unless a limit is set.
 */
final readonly class RecyclePolicy
{
    /**
     * @param int|null $maxCalls calls after which a worker is replaced
     * @param int|null $maxMemoryBytes resident memory of the worker process above which it is replaced
     * @param float $drainTimeout seconds the in-flight calls are given to finish
     *
     * @throws InvalidArgumentException when a limit is set and is not above 0
     */
    public function __construct(
        public ?int $maxCalls = null,
        public ?int $maxMemoryBytes = null,
        public float $drainTimeout = 30.0,
    ) {
        if (($maxCalls !== null && $maxCalls < 1) || ($maxMemoryBytes !== null && $maxMemoryBytes < 1) || $drainTimeout <= 0.0) {
            throw new InvalidArgumentException('a recycling limit and the drain timeout must be above 0');
        }
    }
}
