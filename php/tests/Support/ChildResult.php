<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

/**
 * What a throwaway child process did, as seen from the test.
 */
final readonly class ChildResult
{
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $seconds,
        public bool $timedOut,
    ) {}

    /**
     * @return list<string>
     */
    public function stdoutLines(): array
    {
        return $this->stdout === '' ? [] : explode("\n", rtrim($this->stdout, "\n"));
    }
}
