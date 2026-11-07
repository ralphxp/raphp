<?php

declare(strict_types=1);

namespace Raphp\Process\Internal;

/**
 * Finds a program the way a shell would, so a missing one is reported by name before anything starts.
 *
 * @internal
 */
final class ExecutableFinder
{
    /**
     * @param array<string, string> $environment its PATH is searched
     *
     * @return string|null the full path, or null when there is no such executable file
     */
    public static function find(string $name, array $environment, ?string $workingDirectory = null): ?string
    {
        if (str_contains($name, '/')) {
            $path = $name[0] === '/' || $workingDirectory === null ? $name : rtrim($workingDirectory, '/') . '/' . $name;

            return is_file($path) && is_executable($path) ? $path : null;
        }

        foreach (explode(':', $environment['PATH'] ?? '') as $directory) {
            $path = ($directory === '' ? '.' : $directory) . '/' . $name;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
