<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * Options for building a standalone binary (FRAMEWORK 10).
 */
final readonly class BuildConfig
{
    public function __construct(
        public string $output,
        public bool $embedShim = true,
    ) {}
}
