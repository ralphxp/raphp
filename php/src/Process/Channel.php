<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * The two ends of one connection between a master and a worker, as stream resources. Both are
 * close-on-exec: only the end that is explicitly handed to a child reaches it.
 */
final readonly class Channel
{
    /**
     * @param resource $parent the master's end
     * @param resource $child the end the worker gets as descriptor 3
     */
    public function __construct(
        public mixed $parent,
        public mixed $child,
    ) {}
}
