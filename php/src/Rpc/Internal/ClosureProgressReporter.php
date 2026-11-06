<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Closure;
use Raphp\Rpc\ProgressReporter;

/**
 * A {@see ProgressReporter} that calls a closure.
 *
 * @internal
 */
final readonly class ClosureProgressReporter implements ProgressReporter
{
    /**
     * @param Closure(array<array-key, mixed>): void $report
     */
    public function __construct(private Closure $report) {}

    public function report(array $params = []): void
    {
        ($this->report)($params);
    }
}
