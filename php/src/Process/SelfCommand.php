<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * The command that starts another copy of this very program (FRAMEWORK 5.1, decision D19).
 *
 * The executable is whatever `/proc/self/exe` points to, because `PHP_BINARY` is empty inside a
 * phpmicro binary. In a standalone binary that executable is the whole program; under a plain PHP
 * it is only the interpreter, so the entry script has to be named as well.
 */
final readonly class SelfCommand
{
    public function __construct(
        private string $selfExecutable = '/proc/self/exe',
        private string $sapi = PHP_SAPI,
    ) {}

    /**
     * @param string|null $entryScript the script to run; needed unless this is a standalone binary
     *
     * @return list<string> the executable and the fixed arguments, ready for a command array
     *
     * @throws SpawnException when the executable cannot be found or a script is needed and not given
     */
    public function resolve(?string $entryScript = null): array
    {
        $executable = @readlink($this->selfExecutable);
        if ($executable === false) {
            throw new SpawnException("cannot tell which program is running: {$this->selfExecutable} cannot be read");
        }

        if ($this->sapi === 'micro') {
            return [$executable];
        }

        if ($entryScript === null || $entryScript === '') {
            throw new SpawnException('an entry script is needed to start a worker when this is not a standalone binary');
        }

        return [$executable, $entryScript];
    }
}
