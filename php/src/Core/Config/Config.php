<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * The typed, immutable configuration of a Raphp application.
 *
 * It is built from `raphp.json` plus environment overrides by {@see ConfigLoader}.
 * `logLevel` and `ipcTrace` come from the environment only (FRAMEWORK 9.2, 14).
 */
final readonly class Config
{
    /**
     * @param array<string, WorkerConfig> $workers keyed by worker name
     * @param array<string, string> $libs native library name to version constraint
     */
    public function __construct(
        public string $name,
        public string $entry,
        public string $basePath,
        public array $workers = [],
        public UiConfig $ui = new UiConfig(),
        public array $libs = [],
        public PhpConfig $php = new PhpConfig(),
        public ?BuildConfig $build = null,
        public string $logLevel = 'info',
        public bool $ipcTrace = false,
    ) {}
}
