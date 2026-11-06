<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use Closure;

/**
 * A call as middleware and handlers see it (FRAMEWORK 4.8). Immutable: the `with*()` methods
 * return a changed copy.
 *
 * Some fields belong to one side only. `timeout` and `onProgress` are read when this process is
 * the caller; `progress` is what a handler uses when this process is the callee. `trace` follows
 * the call across both.
 */
final readonly class RpcRequest
{
    /**
     * @param array<array-key, mixed> $params
     * @param float|null $timeout seconds the caller waits for the answer; null waits without limit
     * @param (Closure(array<array-key, mixed>, string): void)|null $onProgress called by the caller for each progress event, with its params and method name; it runs on the connection's read loop, so it must be quick
     */
    public function __construct(
        public string $method,
        public array $params = [],
        public ?string $trace = null,
        public ?float $timeout = null,
        public ?Closure $onProgress = null,
        public ProgressReporter $progress = new NullProgressReporter(),
    ) {}

    /**
     * @param array<array-key, mixed> $params
     */
    public function withParams(array $params): self
    {
        return new self($this->method, $params, $this->trace, $this->timeout, $this->onProgress, $this->progress);
    }

    public function withTrace(?string $trace): self
    {
        return new self($this->method, $this->params, $trace, $this->timeout, $this->onProgress, $this->progress);
    }

    public function withTimeout(?float $timeout): self
    {
        return new self($this->method, $this->params, $this->trace, $timeout, $this->onProgress, $this->progress);
    }
}
