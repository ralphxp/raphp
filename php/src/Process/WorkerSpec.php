<?php

declare(strict_types=1);

namespace Raphp\Process;

use InvalidArgumentException;

/**
 * What to start for one worker (FRAMEWORK 5.1, 5.2).
 *
 * `command` is the program and its fixed arguments, for example from {@see SelfCommand}; the
 * spawner appends `--role=worker`, `--name=<name>` and `--parent-pid=<pid>` itself.
 */
final readonly class WorkerSpec
{
    public const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/';

    /**
     * @param list<string> $command the executable first, then its fixed arguments; never a single string for a shell
     * @param array<string, string> $environment added to this process's environment for the worker
     * @param list<string> $extraArguments appended after the standard ones (the worker's own flags)
     * @param bool $armDeathSignal false adds `--no-pdeathsig`, so the worker does not ask the kernel to
     *                             signal it when the master dies: for tests that must show the other safeguard
     * @param int|null $parentPid the pid told to the worker as its parent; this process unless a test says otherwise
     *
     * @throws InvalidArgumentException when the name is not usable or the command is empty
     */
    public function __construct(
        public string $name,
        public array $command,
        public array $environment = [],
        public ?string $cwd = null,
        public bool $armDeathSignal = true,
        public ?int $parentPid = null,
        public array $extraArguments = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('a worker name starts with a letter and holds only letters, digits, "_" and "-" (at most 64)');
        }

        if ($command === [] || $command[0] === '') {
            throw new InvalidArgumentException('the command needs at least an executable');
        }
    }
}
