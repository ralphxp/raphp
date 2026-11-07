<?php

declare(strict_types=1);

namespace Raphp\Runtime;

use Closure;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

/**
 * Which optional accelerators are present, and which event-loop driver is actually running (plan P2B.4).
 *
 * Raphp selects nothing itself. Revolt picks a driver when the loop is first used: ext-uv, else ext-ev,
 * else ext-event, else its own `stream_select` driver, unless the `REVOLT_DRIVER` environment variable
 * names a class. So loading an extension is enough, and removing it changes nothing but speed. This class
 * reports the result, for `rlm doctor` (Phase 5) and for anyone who wants a line in the log.
 *
 * ext-eio is detected and reported but not used: Raphp's filesystem service
 * ({@see \Raphp\Filesystem\CooperativeFilesystem}) works in slices and has no eio variant yet.
 */
final readonly class Accelerators
{
    /**
     * @param Closure(string): bool $extensionLoaded whether a PHP extension is loaded
     * @param class-string|string $loopDriver the class of the running event-loop driver
     */
    public function __construct(private Closure $extensionLoaded, private string $loopDriver) {}

    /**
     * What this process has. Calling it starts the event loop's driver selection if that has not happened.
     */
    public static function current(): self
    {
        return new self(extension_loaded(...), EventLoop::getDriver()::class);
    }

    /**
     * The accelerators whose extension is loaded.
     *
     * @return list<Accelerator>
     */
    public function loaded(): array
    {
        return array_values(array_filter(Accelerator::cases(), fn(Accelerator $accelerator): bool => ($this->extensionLoaded)($accelerator->value)));
    }

    /**
     * The class of the event-loop driver in use.
     */
    public function loopDriver(): string
    {
        return $this->loopDriver;
    }

    /**
     * The accelerator behind the running driver, or null when it is Revolt's own `stream_select` driver
     * (or a driver Raphp does not know).
     */
    public function loopAccelerator(): ?Accelerator
    {
        foreach (Accelerator::cases() as $accelerator) {
            if ($accelerator->loopDriver() === $this->loopDriver) {
                return $accelerator;
            }
        }

        return null;
    }

    /**
     * Whether the loop runs on one of the extensions.
     */
    public function loopIsAccelerated(): bool
    {
        return $this->loopAccelerator() !== null;
    }

    /**
     * Loaded accelerators that the running loop does not use, because Revolt prefers another one or
     * `REVOLT_DRIVER` names a different driver; ext-eio is always one of these when loaded.
     *
     * @return list<Accelerator>
     */
    public function unused(): array
    {
        $running = $this->loopAccelerator();

        return array_values(array_filter($this->loaded(), static fn(Accelerator $accelerator): bool => $accelerator !== $running));
    }

    /**
     * One line for a log or a diagnostic.
     */
    public function summary(): string
    {
        $driver = $this->loopDriver === StreamSelectDriver::class ? 'stream_select (no accelerator)' : $this->loopDriver;
        $loaded = $this->loaded();
        $names = $loaded === [] ? 'none' : implode(', ', array_map(static fn(Accelerator $accelerator): string => 'ext-' . $accelerator->value, $loaded));
        $unused = array_map(static fn(Accelerator $accelerator): string => 'ext-' . $accelerator->value, $this->unused());

        return "event loop: {$driver}; accelerators loaded: {$names}" . ($unused === [] ? '' : '; not used: ' . implode(', ', $unused));
    }
}
