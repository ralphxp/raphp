<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * The PHP requirements of an application: extensions and ini settings.
 */
final readonly class PhpConfig
{
    /**
     * @param list<string> $extensions
     * @param array<string, string> $ini
     */
    public function __construct(
        public array $extensions = [],
        public array $ini = [],
    ) {}
}
