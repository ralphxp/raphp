<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use Psr\Log\LoggerInterface;
use Raphp\Ui\Ffi\Internal\Native;

/**
 * A shim library that has been loaded and checked ({@see Loader}). It starts shims; PHP may start more than
 * one (the tests do), each with its own UI thread, windows and events.
 */
final readonly class Library
{
    /**
     * @internal made by {@see Loader}
     */
    public function __construct(
        private Native $native,
        public AbiVersion $version,
        private LoggerInterface $logger,
    ) {}

    /**
     * Starts a shim and its UI thread.
     *
     * @param array<string, mixed> $configuration the settings of `raphp_init` (docs/protocol/shim-abi.md): an empty array means the defaults
     *
     * @throws ShimException when the shim does not start (a bad setting, or the UI thread cannot be made)
     */
    public function start(array $configuration = []): Shim
    {
        $text = $configuration === [] ? '{}' : json_encode($configuration, JSON_THROW_ON_ERROR);
        $handle = $this->native->init($text);
        if ($handle === null) {
            throw new ShimException('the shim did not start: ' . $this->native->lastError(null));
        }

        return new Shim($this->native, $handle, $this->logger);
    }
}
