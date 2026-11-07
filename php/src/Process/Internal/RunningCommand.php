<?php

declare(strict_types=1);

namespace Raphp\Process\Internal;

use Amp\Process\Process;

/**
 * One command in flight, as {@see \Raphp\Process\ProcessRunner} tracks it.
 *
 * @internal
 */
final class RunningCommand
{
    public bool $stopRequested = false;

    public function __construct(public readonly Process $process, public readonly int $pid) {}
}
