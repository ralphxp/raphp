<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * What a finished command left behind.
 */
final readonly class ProcessResult
{
    /**
     * @param int $exitCode the command's exit code; by shell convention 128 + n means it was ended by signal n
     * @param string $stdout empty when output was not captured
     * @param string $stderr empty when output was not captured
     * @param float $seconds how long it ran
     * @param bool $outputComplete false when something kept the output pipes open after the command ended (a
     *                             background process it started), so the output may be missing its tail
     * @param bool $stopped true when {@see ProcessRunner::stopAll()} ended it
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $seconds,
        public bool $outputComplete = true,
        public bool $stopped = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->stopped;
    }
}
