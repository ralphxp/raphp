<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Raphp\Process\SelfCommand;
use Raphp\Process\WorkerSpec;

/**
 * Helpers for the tests that start real worker processes: the fixture scripts, and facts about a
 * process read from /proc.
 */
final class WorkerFixture
{
    public static function script(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    /**
     * @param list<string> $extraArguments
     * @param array<string, string> $environment
     */
    public static function spec(string $name = 'w1', bool $armDeathSignal = true, array $extraArguments = [], ?int $parentPid = null, array $environment = [], ?string $cwd = null): WorkerSpec
    {
        return new WorkerSpec($name, (new SelfCommand())->resolve(self::script('worker.php')), environment: $environment, cwd: $cwd, armDeathSignal: $armDeathSignal, parentPid: $parentPid, extraArguments: $extraArguments);
    }

    /**
     * Whether the process exists and is not a zombie (a zombie has ended and waits to be reaped).
     */
    public static function isAlive(int $pid): bool
    {
        $state = self::stat($pid)[0] ?? null;

        return $state !== null && $state !== 'Z' && $state !== 'X';
    }

    /**
     * The parent pid, from /proc/<pid>/stat.
     */
    public static function parentPidOf(int $pid): ?int
    {
        $parent = self::stat($pid)[1] ?? null;

        return $parent === null ? null : (int) $parent;
    }

    /**
     * The inode numbers of the sockets a process holds open.
     *
     * @return list<string>
     */
    public static function socketInodes(int $pid): array
    {
        $inodes = [];
        $entries = @scandir("/proc/{$pid}/fd");
        foreach ($entries === false ? [] : $entries as $fd) {
            $target = $fd === '.' || $fd === '..' ? false : @readlink("/proc/{$pid}/fd/{$fd}");
            if ($target !== false && preg_match('/\Asocket:\[(\d+)\]\z/', $target, $found) === 1) {
                $inodes[] = $found[1];
            }
        }

        return array_values(array_unique($inodes));
    }

    /**
     * The state letter and the parent pid, the first two fields after the command name.
     *
     * @return list<string>
     */
    private static function stat(int $pid): array
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return [];
        }

        // The command name is in parentheses and may itself hold spaces or parentheses.
        $end = strrpos($stat, ')');
        if ($end === false) {
            return [];
        }

        return explode(' ', substr($stat, $end + 2));
    }
}
