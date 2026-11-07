<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * How a worker process ended: it exited with a code, or a signal ended it.
 */
final readonly class ExitStatus
{
    private function __construct(
        public ?int $code,
        public ?int $signal,
    ) {}

    public static function exited(int $code): self
    {
        return new self($code, null);
    }

    public static function signaled(int $signal): self
    {
        return new self(null, $signal);
    }

    public function isSuccess(): bool
    {
        return $this->code === 0;
    }

    public function describe(): string
    {
        return $this->signal !== null ? "killed by signal {$this->signal}" : "exited with code {$this->code}";
    }
}
