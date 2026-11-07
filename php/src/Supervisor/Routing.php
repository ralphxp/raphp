<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

/**
 * How calls are spread over the members of a pool (FRAMEWORK 5.2).
 */
enum Routing: string
{
    /** One member after the other, skipping any that are not ready. */
    case RoundRobin = 'round-robin';

    /** The ready member with the fewest calls in flight. */
    case LeastBusy = 'least-busy';
}
