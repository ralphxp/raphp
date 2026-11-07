<?php

declare(strict_types=1);

namespace Raphp\Supervisor\Internal;

/**
 * How much memory a process holds, read from `/proc/<pid>/status` (Linux).
 *
 * @internal
 */
final readonly class ProcessMemory
{
    public function __construct(private string $procRoot = '/proc') {}

    /**
     * The resident set size in bytes, or null when the process or the file cannot be read.
     */
    public function residentBytes(int $pid): ?int
    {
        $status = @file_get_contents("{$this->procRoot}/{$pid}/status");
        if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $found) !== 1) {
            return null;
        }

        return (int) $found[1] * 1024;
    }
}
