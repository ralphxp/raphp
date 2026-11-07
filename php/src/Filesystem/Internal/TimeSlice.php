<?php

declare(strict_types=1);

namespace Raphp\Filesystem\Internal;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

/**
 * Gives the event loop a turn once a slice of work has used up its time. One instance belongs to one
 * operation (a read, a listing, an open stream).
 *
 * @internal
 */
final class TimeSlice
{
    private int $start;

    /**
     * @param float $seconds how long work may run before the loop gets a turn; 0 gives it a turn at every checkpoint
     */
    public function __construct(private readonly float $seconds)
    {
        $this->start = hrtime(true);
    }

    /**
     * Call it between small pieces of work: checks for cancellation, and lets the loop run if the
     * slice is used up.
     *
     * @throws CancelledException
     */
    public function checkpoint(?Cancellation $cancellation = null): void
    {
        $cancellation?->throwIfRequested();

        if ((hrtime(true) - $this->start) / 1e9 < $this->seconds) {
            return;
        }

        delay(0, true, $cancellation);
        $this->start = hrtime(true);
    }
}
