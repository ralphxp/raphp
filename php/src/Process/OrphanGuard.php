<?php

declare(strict_types=1);

namespace Raphp\Process;

use Raphp\Runtime\Libc\LibcException;
use Raphp\Runtime\Libc\ParentDeathSignal;

/**
 * What a worker does first so it cannot outlive its master (FRAMEWORK 5.6): ask the kernel for
 * a signal when the parent dies, then check that the parent is still the one that started it.
 * The check closes the startup race in which the master died before the request was made, and
 * the signal would then never come.
 */
final readonly class OrphanGuard
{
    /** The exit code of a worker whose parent is not the master that started it. */
    public const int EXIT_PARENT_MISMATCH = 4;

    /**
     * @param ParentDeathSignal|null $signal null skips the kernel request (for tests that must
     *                                       show what happens without it); the parent check still runs
     */
    public function __construct(private ?ParentDeathSignal $signal = new ParentDeathSignal()) {}

    /**
     * @param int $expectedParentPid the pid the master put on the command line
     *
     * @return bool false when the parent is not the expected one: the worker must exit
     *
     * @throws LibcException when the signal cannot be requested
     */
    public function protect(int $expectedParentPid): bool
    {
        $this->signal?->arm();

        return posix_getppid() === $expectedParentPid;
    }
}
