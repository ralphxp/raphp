<?php

declare(strict_types=1);

namespace Raphp\Rpc;

/**
 * What the attributes say about one RPC method (see {@see MethodCatalog}).
 */
final readonly class MethodDescriptor
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $method,
        public bool $idempotent,
    ) {}
}
