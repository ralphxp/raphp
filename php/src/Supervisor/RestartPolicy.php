<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

use InvalidArgumentException;

/**
 * What happens after a worker crashes (FRAMEWORK 5.4). It restarts after a wait that doubles with
 * each crash, from `initialDelay` up to `maxDelay`. If it crashes more than `maxRestarts` times
 * within `window` seconds it is marked degraded and left alone. The defaults are my numbers
 * (100 ms to 10 s, 5 crashes in 60 s), not measured ones.
 */
final readonly class RestartPolicy
{
    /**
     * @param float $initialDelay seconds before the first restart
     * @param float $maxDelay the longest wait between restarts
     * @param int $maxRestarts crashes allowed within the window; one more makes the worker degraded
     * @param float $window seconds over which crashes are counted
     *
     * @throws InvalidArgumentException when a value makes no sense
     */
    public function __construct(
        public float $initialDelay = 0.1,
        public float $maxDelay = 10.0,
        public int $maxRestarts = 5,
        public float $window = 60.0,
    ) {
        if ($initialDelay < 0.0 || $maxDelay < $initialDelay || $maxRestarts < 1 || $window <= 0.0) {
            throw new InvalidArgumentException('a restart policy needs delays from 0 up, a longest delay not shorter than the first, at least one restart, and a window above 0');
        }
    }

    /**
     * The wait before the restart that follows the given number of crashes within the window.
     */
    public function delayAfter(int $crashesInWindow): float
    {
        return min($this->maxDelay, $this->initialDelay * (2 ** max(0, $crashesInWindow - 1)));
    }
}
