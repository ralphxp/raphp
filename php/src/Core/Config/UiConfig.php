<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * The optional GUI section of the manifest. Headless is the default.
 */
final readonly class UiConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $backend = 'default',
    ) {}
}
