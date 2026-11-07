<?php

declare(strict_types=1);

namespace Raphp\Worker;

use InvalidArgumentException;
use Raphp\Process\WorkerSpec;

/**
 * The command line a worker is started with (FRAMEWORK 5.1): `--role=worker`, `--name=<name>` and
 * `--parent-pid=<pid>`, which {@see \Raphp\Process\ProcessSpawner} adds, and `--no-pdeathsig`, which
 * a test may add. Everything else is the application's own: `--flag` and `--option=value` arguments
 * go to {@see self::$options}, anything that does not start with `--` to {@see self::$positional}.
 */
final readonly class WorkerArguments
{
    /**
     * @param array<string, string> $options the application's own `--name=value` options (a flag has the value "1")
     * @param list<string> $positional
     */
    public function __construct(
        public string $name,
        public int $parentPid,
        public bool $armDeathSignal = true,
        public array $options = [],
        public array $positional = [],
    ) {}

    /**
     * @param list<string> $argv as PHP gives it: the script first, then the arguments
     *
     * @throws InvalidArgumentException when this is not a worker command line, with a message fit to print
     */
    public static function parse(array $argv): self
    {
        $role = null;
        $name = null;
        $parentPid = null;
        $armed = true;
        $options = [];
        $positional = [];

        foreach (array_slice($argv, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                $positional[] = $argument;

                continue;
            }

            $parts = explode('=', substr($argument, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? '1';

            match ($key) {
                'role' => $role = $value,
                'name' => $name = $value,
                'parent-pid' => $parentPid = $value,
                'no-pdeathsig' => $armed = false,
                default => $options[$key] = $value,
            };
        }

        if ($role !== 'worker') {
            throw new InvalidArgumentException('this program was not started as a worker: --role=worker is missing');
        }

        if ($name === null || preg_match(WorkerSpec::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('the worker needs --name=<name>: a letter, then letters, digits, "_" or "-" (at most 64)');
        }

        if ($parentPid === null || preg_match('/\A[1-9][0-9]{0,9}\z/', $parentPid) !== 1) {
            throw new InvalidArgumentException('the worker needs --parent-pid=<pid>: the process id of the master that started it');
        }

        return new self($name, (int) $parentPid, $armed, $options, $positional);
    }
}
